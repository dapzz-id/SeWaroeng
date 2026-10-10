<?php

namespace App\Services\Payment;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentFailed;
use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Exceptions\PaymentAmountExceedsOutstandingException;
use App\Exceptions\PaymentInvalidStateException;
use App\Exceptions\PaymentRefundExceedsPaidException;
use App\Exceptions\PaymentRefundNotSupportedException;
use App\Exceptions\PaymentSaleNotFinalizedException;
use App\Exceptions\PaymentWebhookAmountMismatchException;
use App\Exceptions\PaymentWebhookCurrencyMismatchException;
use App\Exceptions\WebhookSignatureInvalidException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\PaymentWebhookEvent;
use App\Models\Sale;
use App\Models\TenantPaymentConnection;
use App\Models\User;
use App\Services\Shift\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        protected PaymentProviderManager $providerManager,
        protected TenantManager $tenantManager,
        protected ShiftService $shiftService
    ) {}

    /**
     * Create a payment record against a finalized sale.
     * Enforces strict sale-finalized and outstanding amount invariants.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPayment(Sale $sale, array $data, ?User $user = null): Payment
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            // Pessimistic lock on Sale to serialize concurrent payment creation
            /** @var Sale $lockedSale */
            $lockedSale = Sale::query()
                ->where('id', $sale->id)
                ->where('tenant_id', $sale->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedSale->isFinalized()) {
                throw new PaymentSaleNotFinalizedException(
                    "Cannot accept payment for sale '{$lockedSale->reference_number}' in status '{$lockedSale->status}'. Sale must be finalized."
                );
            }

            $outstanding = $lockedSale->getOutstandingAmountMinor();
            $amountMinor = (int) ($data['amount_minor'] ?? 0);

            if ($amountMinor <= 0) {
                throw new PaymentInvalidStateException('Payment amount must be greater than zero.');
            }

            if ($outstanding <= 0) {
                throw new PaymentAmountExceedsOutstandingException(
                    "Sale '{$lockedSale->reference_number}' is already fully paid. Outstanding amount is 0."
                );
            }

            if ($amountMinor > $outstanding) {
                throw new PaymentAmountExceedsOutstandingException(
                    "Payment amount ({$amountMinor}) exceeds remaining outstanding balance ({$outstanding})."
                );
            }

            $method = (string) ($data['method'] ?? Payment::METHOD_CASH);

            if (isset($data['provider'])) {
                $provider = strtolower(trim((string) $data['provider']));
            } elseif ($method === Payment::METHOD_CASH) {
                $provider = 'manual';
            } else {
                $effectiveDefault = $this->providerManager
                    ->getAvailabilityResolver()
                    ->resolveEffectiveDefaultProvider($lockedSale->tenant_id);

                $provider = $effectiveDefault ?? (app()->environment('testing') ? 'mock' : 'midtrans');
            }

            $datePart = Carbon::now()->format('Ymd');
            $randomPart = strtoupper(Str::random(6));
            $referenceNumber = "PAY-{$datePart}-{$randomPart}";

            $payment = Payment::create([
                'tenant_id' => $lockedSale->tenant_id,
                'sale_id' => $lockedSale->id,
                'reference_number' => $referenceNumber,
                'status' => Payment::STATUS_PENDING,
                'amount_minor' => $amountMinor,
                'currency' => Payment::CURRENCY_IDR,
                'method' => $method,
                'provider' => $provider,
                'client_reference' => $data['client_reference'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'initiated_at' => now(),
                'metadata' => isset($data['metadata']) ? AuditLog::sanitizePayload($data['metadata']) : null,
                'created_by' => $user?->id,
            ]);

            AuditLog::create([
                'tenant_id' => $lockedSale->tenant_id,
                'actor_user_id' => $user?->id,
                'action' => 'payment_created',
                'entity_type' => Payment::class,
                'entity_id' => $payment->id,
                'before' => null,
                'after' => [
                    'reference_number' => $payment->reference_number,
                    'sale_id' => $payment->sale_id,
                    'amount_minor' => $payment->amount_minor,
                    'method' => $payment->method,
                    'provider' => $payment->provider,
                    'status' => $payment->status,
                ],
                'created_at' => now(),
            ]);

            return $payment;
        });
    }

    /**
     * Process an existing pending or processing payment through its provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function processPayment(Payment $payment, array $options = []): Payment
    {
        return DB::transaction(function () use ($payment, $options) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->where('id', $payment->id)
                ->where('tenant_id', $payment->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->isSucceeded()) {
                throw new PaymentInvalidStateException(
                    "Payment '{$lockedPayment->reference_number}' is already succeeded."
                );
            }

            if (in_array($lockedPayment->status, [Payment::STATUS_CANCELLED, Payment::STATUS_REFUNDED], true)) {
                throw new PaymentInvalidStateException(
                    "Payment '{$lockedPayment->reference_number}' is in terminal status '{$lockedPayment->status}' and cannot be processed."
                );
            }

            /** @var Sale $lockedSale */
            $lockedSale = Sale::query()
                ->where('id', $lockedPayment->sale_id)
                ->where('tenant_id', $lockedPayment->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Check outstanding invariant: other payments could have succeeded in the meantime
            $otherPaid = (int) $lockedSale->payments()
                ->where('id', '!=', $lockedPayment->id)
                ->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED])
                ->sum('amount_minor');

            $otherRefunded = (int) PaymentRefund::query()
                ->where('tenant_id', $lockedSale->tenant_id)
                ->whereIn('payment_id', $lockedSale->payments()->where('id', '!=', $lockedPayment->id)->select('id'))
                ->where('status', PaymentRefund::STATUS_COMPLETED)
                ->sum('amount_minor');

            $currentMaxPermissible = $lockedSale->total_minor - ($otherPaid - $otherRefunded);
            if ($lockedPayment->amount_minor > $currentMaxPermissible) {
                throw new PaymentAmountExceedsOutstandingException(
                    "Cannot complete payment. Amount ({$lockedPayment->amount_minor}) exceeds remaining available outstanding balance ({$currentMaxPermissible})."
                );
            }

            $attemptNumber = $lockedPayment->attempts()->count() + 1;
            $provider = $this->providerManager->resolveForTenant($lockedPayment->provider, $lockedPayment->tenant_id);

            // Record initial attempt
            $attempt = PaymentAttempt::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'payment_id' => $lockedPayment->id,
                'attempt_number' => $attemptNumber,
                'status' => PaymentAttempt::STATUS_PROCESSING,
                'provider' => $provider->getProviderName(),
                'requested_amount_minor' => $lockedPayment->amount_minor,
                'requested_at' => now(),
            ]);

            $lockedPayment->update(['status' => Payment::STATUS_PROCESSING]);

            AuditLog::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'actor_user_id' => auth()->id(),
                'action' => 'payment_processing',
                'entity_type' => Payment::class,
                'entity_id' => $lockedPayment->id,
                'before' => ['status' => Payment::STATUS_PENDING],
                'after' => ['status' => Payment::STATUS_PROCESSING, 'attempt' => $attemptNumber],
                'created_at' => now(),
            ]);

            // Call provider adapter
            $result = $provider->initiatePayment($lockedPayment, $options);

            if ($result['status'] === Payment::STATUS_SUCCEEDED) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_SUCCEEDED,
                    'provider_reference' => $result['provider_reference'],
                    'response_code' => $result['response_code'] ?? 'OK',
                    'response_payload' => isset($result['response_payload']) ? AuditLog::sanitizePayload($result['response_payload']) : null,
                    'completed_at' => now(),
                ]);

                $lockedPayment->update([
                    'status' => Payment::STATUS_SUCCEEDED,
                    'provider_payment_id' => $result['provider_reference'],
                    'succeeded_at' => now(),
                    'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                        'provider_result' => $result['response_payload'] ?? null,
                    ])),
                ]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => auth()->id(),
                    'action' => 'payment_succeeded',
                    'entity_type' => Payment::class,
                    'entity_id' => $lockedPayment->id,
                    'before' => ['status' => Payment::STATUS_PROCESSING],
                    'after' => [
                        'status' => Payment::STATUS_SUCCEEDED,
                        'provider_reference' => $result['provider_reference'],
                    ],
                    'created_at' => now(),
                ]);

                $this->shiftService->recordSaleCashPayment($lockedPayment);

                event(new PaymentSucceeded($lockedPayment));
            } elseif ($result['status'] === Payment::STATUS_PROCESSING) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_PROCESSING,
                    'provider_reference' => $result['provider_reference'],
                    'response_code' => $result['response_code'] ?? 'PENDING',
                    'response_payload' => isset($result['response_payload']) ? AuditLog::sanitizePayload($result['response_payload']) : null,
                ]);

                $lockedPayment->update([
                    'provider_payment_id' => $result['provider_reference'],
                    'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                        'provider_result' => $result['response_payload'] ?? null,
                    ])),
                ]);
            } else {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_FAILED,
                    'provider_reference' => $result['provider_reference'],
                    'response_code' => $result['response_code'] ?? 'FAILED',
                    'response_payload' => isset($result['response_payload']) ? AuditLog::sanitizePayload($result['response_payload']) : null,
                    'completed_at' => now(),
                ]);

                $lockedPayment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failed_at' => now(),
                    'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                        'failure_reason' => $result['response_payload']['reason'] ?? $result['response_code'] ?? 'Failed',
                    ])),
                ]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => auth()->id(),
                    'action' => 'payment_failed',
                    'entity_type' => Payment::class,
                    'entity_id' => $lockedPayment->id,
                    'before' => ['status' => Payment::STATUS_PROCESSING],
                    'after' => [
                        'status' => Payment::STATUS_FAILED,
                        'response_code' => $result['response_code'] ?? 'FAILED',
                    ],
                    'created_at' => now(),
                ]);

                event(new PaymentFailed($lockedPayment, $result['response_code'] ?? 'Payment failed'));
            }

            return $lockedPayment->fresh(['attempts', 'sale']);
        });
    }

    /**
     * Cancel a pending or processing payment.
     */
    public function cancelPayment(Payment $payment, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->where('id', $payment->id)
                ->where('tenant_id', $payment->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->isSucceeded()) {
                throw new PaymentInvalidStateException(
                    "Payment '{$lockedPayment->reference_number}' has already succeeded and cannot be cancelled. Use refund instead."
                );
            }

            if ($lockedPayment->isCancelled()) {
                return $lockedPayment;
            }

            $lockedPayment->update([
                'status' => Payment::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'metadata' => array_merge($lockedPayment->metadata ?? [], [
                    'cancel_reason' => $reason,
                ]),
            ]);

            AuditLog::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'actor_user_id' => auth()->id(),
                'action' => 'payment_cancelled',
                'entity_type' => Payment::class,
                'entity_id' => $lockedPayment->id,
                'before' => ['status' => $payment->status],
                'after' => ['status' => Payment::STATUS_CANCELLED, 'reason' => $reason],
                'created_at' => now(),
            ]);

            return $lockedPayment;
        });
    }

    /**
     * Query provider status for a payment and synchronize internal state
     * preserving monotonic state rules.
     */
    public function syncPaymentStatus(Payment $payment, array $options = []): Payment
    {
        return DB::transaction(function () use ($payment, $options) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->where('id', $payment->id)
                ->where('tenant_id', $payment->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasProcessingRefunds = $lockedPayment->refunds()
                ->where('status', PaymentRefund::STATUS_PROCESSING)
                ->exists();

            // Terminal check: if cancelled or fully refunded without processing refunds, no query needed
            if ($lockedPayment->isCancelled() || ($lockedPayment->status === Payment::STATUS_REFUNDED && ! $hasProcessingRefunds)) {
                return $lockedPayment;
            }

            if ($lockedPayment->isSucceeded() && $lockedPayment->refundableAmountMinor() === 0 && ! $hasProcessingRefunds) {
                return $lockedPayment;
            }

            $provider = $this->providerManager->resolveForTenant($lockedPayment->provider, $lockedPayment->tenant_id);
            $result = $provider->queryPayment($lockedPayment, $options);
            $newStatus = $result['status'];

            // Monotonic state protection:
            if ($lockedPayment->isSucceeded() && in_array($newStatus, [Payment::STATUS_PROCESSING, Payment::STATUS_PENDING, Payment::STATUS_FAILED, Payment::STATUS_CANCELLED], true)) {
                // Succeeded cannot regress, but we may still reconcile refunds below
            } elseif ($newStatus === Payment::STATUS_SUCCEEDED && ! $lockedPayment->isSucceeded()) {
                $lockedPayment->update([
                    'status' => Payment::STATUS_SUCCEEDED,
                    'provider_payment_id' => $result['provider_reference'] ?? $lockedPayment->provider_payment_id,
                    'succeeded_at' => now(),
                    'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                        'status_query_sync' => $result['response_payload'] ?? null,
                    ])),
                ]);

                $attempt = $lockedPayment->attempts()
                    ->whereIn('status', [PaymentAttempt::STATUS_PROCESSING, PaymentAttempt::STATUS_PENDING])
                    ->latest()
                    ->first();

                if ($attempt) {
                    $attempt->update([
                        'status' => PaymentAttempt::STATUS_SUCCEEDED,
                        'completed_at' => now(),
                        'response_code' => $result['response_code'] ?? 'SYNC_SUCCEEDED',
                        'response_payload' => isset($result['response_payload']) ? AuditLog::sanitizePayload($result['response_payload']) : null,
                    ]);
                }

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => auth()->id(),
                    'action' => 'payment_synced_succeeded',
                    'entity_type' => Payment::class,
                    'entity_id' => $lockedPayment->id,
                    'before' => ['status' => Payment::STATUS_PROCESSING],
                    'after' => ['status' => Payment::STATUS_SUCCEEDED, 'source' => 'status_query'],
                    'created_at' => now(),
                ]);

                $this->shiftService->recordSaleCashPayment($lockedPayment);

                event(new PaymentSucceeded($lockedPayment));
            } elseif ($newStatus === Payment::STATUS_FAILED && ! $lockedPayment->isSucceeded()) {
                $lockedPayment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failed_at' => now(),
                    'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                        'failure_reason' => $result['response_payload']['status_message'] ?? 'Provider reported failure on query',
                    ])),
                ]);

                $attempt = $lockedPayment->attempts()
                    ->whereIn('status', [PaymentAttempt::STATUS_PROCESSING, PaymentAttempt::STATUS_PENDING])
                    ->latest()
                    ->first();

                if ($attempt) {
                    $attempt->update([
                        'status' => PaymentAttempt::STATUS_FAILED,
                        'completed_at' => now(),
                        'response_code' => $result['response_code'] ?? 'SYNC_FAILED',
                        'response_payload' => isset($result['response_payload']) ? AuditLog::sanitizePayload($result['response_payload']) : null,
                    ]);
                }

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => auth()->id(),
                    'action' => 'payment_synced_failed',
                    'entity_type' => Payment::class,
                    'entity_id' => $lockedPayment->id,
                    'before' => ['status' => $lockedPayment->status],
                    'after' => ['status' => Payment::STATUS_FAILED, 'source' => 'status_query'],
                    'created_at' => now(),
                ]);

                event(new PaymentFailed($lockedPayment, 'Provider reported failure on query'));
            }

            // Reconcile any processing refunds if provider query returns refund history
            if ($hasProcessingRefunds && ! empty($result['response_payload']['refunds']) && is_array($result['response_payload']['refunds'])) {
                $providerRefunds = collect($result['response_payload']['refunds']);
                $processingRefunds = $lockedPayment->refunds()
                    ->where('status', PaymentRefund::STATUS_PROCESSING)
                    ->get();

                foreach ($processingRefunds as $procRefund) {
                    $matched = $providerRefunds->firstWhere('refund_key', $procRefund->reference_number);
                    if ($matched && ! empty($matched['bank_confirmed_at'])) {
                        $pAmount = isset($matched['refund_amount']) ? (int) round((float) $matched['refund_amount']) : null;
                        if ($pAmount === null || $pAmount === (int) $procRefund->amount_minor) {
                            $procRefund->update([
                                'status' => PaymentRefund::STATUS_COMPLETED,
                                'bank_confirmed_at' => Carbon::parse($matched['bank_confirmed_at']),
                                'completed_at' => now(),
                                'provider_reference' => $matched['refund_charge_id'] ?? $procRefund->provider_reference,
                            ]);

                            $newTotal = $lockedPayment->totalRefundedMinor();
                            $newPayStatus = ($newTotal >= $lockedPayment->amount_minor)
                                ? Payment::STATUS_REFUNDED
                                : Payment::STATUS_PARTIALLY_REFUNDED;
                            $lockedPayment->update(['status' => $newPayStatus]);

                            AuditLog::create([
                                'tenant_id' => $lockedPayment->tenant_id,
                                'actor_user_id' => auth()->id(),
                                'action' => 'payment_refund_confirmed_sync',
                                'entity_type' => PaymentRefund::class,
                                'entity_id' => $procRefund->id,
                                'after' => [
                                    'status' => PaymentRefund::STATUS_COMPLETED,
                                    'bank_confirmed_at' => $matched['bank_confirmed_at'],
                                    'payment_status' => $newPayStatus,
                                ],
                                'created_at' => now(),
                            ]);

                            event(new PaymentRefunded($lockedPayment, $procRefund));
                        }
                    }
                }
            }

            return $lockedPayment->fresh(['attempts', 'sale']);
        });
    }

    /**
     * Issue a full or partial refund against a succeeded payment.
     */
    public function refundPayment(Payment $payment, int $amountMinor, string $reason, ?User $user = null): PaymentRefund
    {
        return DB::transaction(function () use ($payment, $amountMinor, $reason, $user) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->where('id', $payment->id)
                ->where('tenant_id', $payment->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === Payment::STATUS_REFUNDED || $lockedPayment->refundableAmountMinor() <= 0) {
                throw new PaymentRefundExceedsPaidException(
                    'Payment has already been fully refunded. Refundable balance is 0.'
                );
            }

            if (! in_array($lockedPayment->status, [Payment::STATUS_SUCCEEDED, Payment::STATUS_PARTIALLY_REFUNDED], true)) {
                throw new PaymentInvalidStateException(
                    "Only succeeded or partially refunded payments can be refunded. Current status is '{$lockedPayment->status}'."
                );
            }

            $pendingOrProcessing = (int) $lockedPayment->refunds()
                ->whereIn('status', [PaymentRefund::STATUS_PROCESSING, PaymentRefund::STATUS_PENDING])
                ->sum('amount_minor');
            $availableToRefund = max(0, $lockedPayment->refundableAmountMinor() - $pendingOrProcessing);
            if ($amountMinor <= 0 || $amountMinor > $availableToRefund) {
                throw new PaymentRefundExceedsPaidException(
                    "Refund amount ({$amountMinor}) exceeds available refundable balance ({$availableToRefund})."
                );
            }

            $datePart = Carbon::now()->format('Ymd');
            $randomPart = strtoupper(Str::random(6));
            $referenceNumber = "REF-{$datePart}-{$randomPart}";

            $refund = PaymentRefund::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'payment_id' => $lockedPayment->id,
                'reference_number' => $referenceNumber,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'status' => PaymentRefund::STATUS_PENDING,
                'created_by' => $user?->id,
            ]);

            $provider = $this->providerManager->resolveForTenant($lockedPayment->provider, $lockedPayment->tenant_id);
            $result = $provider->processRefund($refund);

            if (($result['response_code'] ?? '') === 'PAYMENT_REFUND_NOT_SUPPORTED') {
                $refund->update([
                    'status' => PaymentRefund::STATUS_FAILED,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'metadata' => AuditLog::sanitizePayload([
                        'response_code' => 'PAYMENT_REFUND_NOT_SUPPORTED',
                        'response_payload' => $result['response_payload'] ?? null,
                    ]),
                ]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => $user?->id,
                    'action' => 'payment_refund_not_supported',
                    'entity_type' => PaymentRefund::class,
                    'entity_id' => $refund->id,
                    'after' => ['reason' => 'PAYMENT_REFUND_NOT_SUPPORTED'],
                    'created_at' => now(),
                ]);

                throw new PaymentRefundNotSupportedException(
                    "PAYMENT_REFUND_NOT_SUPPORTED: Provider does not support direct refunds for {$lockedPayment->method}."
                );
            }

            if ($result['status'] === PaymentRefund::STATUS_COMPLETED) {
                $refund->update([
                    'status' => PaymentRefund::STATUS_COMPLETED,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'completed_at' => now(),
                    'bank_confirmed_at' => isset($result['bank_confirmed_at']) ? Carbon::parse($result['bank_confirmed_at']) : now(),
                    'metadata' => AuditLog::sanitizePayload([
                        'response_code' => $result['response_code'] ?? null,
                        'response_payload' => $result['response_payload'] ?? null,
                    ]),
                ]);

                // Update payment status (partially_refunded vs refunded)
                $newTotalRefunded = $lockedPayment->totalRefundedMinor();
                $newStatus = ($newTotalRefunded >= $lockedPayment->amount_minor)
                    ? Payment::STATUS_REFUNDED
                    : Payment::STATUS_PARTIALLY_REFUNDED;

                $lockedPayment->update(['status' => $newStatus]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => $user?->id,
                    'action' => 'payment_refunded',
                    'entity_type' => PaymentRefund::class,
                    'entity_id' => $refund->id,
                    'before' => ['payment_status' => $lockedPayment->status],
                    'after' => [
                        'refund_reference' => $refund->reference_number,
                        'refund_amount' => $refund->amount_minor,
                        'payment_status' => $newStatus,
                    ],
                    'created_at' => now(),
                ]);

                $this->shiftService->recordRefundCashPayment($refund);

                event(new PaymentRefunded($lockedPayment, $refund));
            } elseif ($result['status'] === PaymentRefund::STATUS_PROCESSING) {
                $refund->update([
                    'status' => PaymentRefund::STATUS_PROCESSING,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'metadata' => AuditLog::sanitizePayload([
                        'response_code' => $result['response_code'] ?? null,
                        'response_payload' => $result['response_payload'] ?? null,
                    ]),
                ]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => $user?->id,
                    'action' => 'payment_refund_processing',
                    'entity_type' => PaymentRefund::class,
                    'entity_id' => $refund->id,
                    'before' => ['status' => PaymentRefund::STATUS_PENDING],
                    'after' => [
                        'refund_reference' => $refund->reference_number,
                        'refund_amount' => $refund->amount_minor,
                        'status' => PaymentRefund::STATUS_PROCESSING,
                    ],
                    'created_at' => now(),
                ]);
            } else {
                $refund->update([
                    'status' => PaymentRefund::STATUS_FAILED,
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'metadata' => AuditLog::sanitizePayload([
                        'response_code' => $result['response_code'] ?? null,
                        'response_payload' => $result['response_payload'] ?? null,
                    ]),
                ]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'actor_user_id' => $user?->id,
                    'action' => 'payment_refund_failed',
                    'entity_type' => PaymentRefund::class,
                    'entity_id' => $refund->id,
                    'before' => ['status' => PaymentRefund::STATUS_PENDING],
                    'after' => [
                        'refund_reference' => $refund->reference_number,
                        'refund_amount' => $refund->amount_minor,
                        'status' => PaymentRefund::STATUS_FAILED,
                    ],
                    'created_at' => now(),
                ]);
            }

            return $refund;
        });
    }

    /**
     * Process an external provider webhook with signature verification,
     * duplicate event rejection, and monotonic out-of-order state guards.
     *
     * @return array{
     *     processed: bool,
     *     status: string,
     *     event_id?: string,
     *     payment_id?: string
     * }
     */
    public function handleWebhook(string $providerName, Request $request): array
    {
        $normalizedProvider = strtolower(trim($providerName));
        $provider = $this->providerManager->resolve($normalizedProvider);

        // Multi-tenant connection resolution:
        $merchantIdentifier = $provider->extractMerchantIdentifier($request);
        $tenantConnection = null;

        if ($merchantIdentifier !== null) {
            $expectedEnv = config("services.{$normalizedProvider}.is_production", false) ? 'production' : 'sandbox';

            DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
            try {
                $tenantConnection = TenantPaymentConnection::query()
                    ->withoutGlobalScopes()
                    ->where('provider', $normalizedProvider)
                    ->where('merchant_identifier', $merchantIdentifier)
                    ->first();
            } finally {
                DB::statement("SELECT set_config('app.is_platform_context', '', true)");
            }

            if (! $tenantConnection) {
                throw new WebhookSignatureInvalidException("Unknown merchant identifier '{$merchantIdentifier}'.");
            }

            if ($tenantConnection->environment !== $expectedEnv) {
                throw new WebhookSignatureInvalidException("Webhook received for wrong environment '{$tenantConnection->environment}'.");
            }

            $provider = $provider->withConnection($tenantConnection);
        }

        if (! $provider->verifyWebhookSignature($request)) {
            throw new WebhookSignatureInvalidException('Provider webhook signature verification failed.');
        }

        $eventData = $provider->parseWebhookEvent($request);
        $externalEventId = $eventData['external_event_id'];

        // Deduplication check: (provider, external_event_id)
        $existingEvent = PaymentWebhookEvent::query()
            ->where('provider', $providerName)
            ->where('external_event_id', $externalEventId)
            ->first();

        if ($existingEvent) {
            // Deterministic rejection on retry of previously failed/mismatched event
            if ($existingEvent->processing_status === PaymentWebhookEvent::STATUS_FAILED) {
                if (in_array($existingEvent->error_code, ['PAYMENT_WEBHOOK_AMOUNT_MISMATCH', 'REFUND_AMOUNT_MISMATCH'], true)) {
                    throw new PaymentWebhookAmountMismatchException('Webhook payment amount does not match internal payment record.');
                }
                if ($existingEvent->error_code === 'PAYMENT_WEBHOOK_CURRENCY_MISMATCH') {
                    throw new PaymentWebhookCurrencyMismatchException('Webhook payment currency does not match internal payment record.');
                }

                return [
                    'processed' => false,
                    'status' => 'rejected',
                    'error_code' => $existingEvent->error_code,
                    'event_id' => $existingEvent->id,
                ];
            }

            return [
                'processed' => false,
                'status' => 'duplicate_ignored',
                'event_id' => $existingEvent->id,
            ];
        }

        $webhookRecord = PaymentWebhookEvent::create([
            'provider' => $providerName,
            'external_event_id' => $externalEventId,
            'payload' => AuditLog::sanitizePayload($eventData['payload']),
            'signature_verified' => true,
            'processing_status' => PaymentWebhookEvent::STATUS_PENDING,
            'received_at' => now(),
        ]);

        // Resolve target Payment
        $providerPaymentId = $eventData['provider_payment_id'];
        $payment = null;

        if ($providerPaymentId) {
            DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
            try {
                $payment = Payment::query()
                    ->withoutGlobalScopes()
                    ->where('provider', $providerName)
                    ->where(function ($q) use ($providerPaymentId) {
                        $q->where('provider_payment_id', $providerPaymentId)
                            ->orWhere('reference_number', $providerPaymentId);
                        if (Str::isUuid($providerPaymentId)) {
                            $q->orWhere('id', $providerPaymentId);
                        }
                    })
                    ->first();
            } finally {
                DB::statement("SELECT set_config('app.is_platform_context', '', true)");
            }
        }

        if (! $payment) {
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'PAYMENT_NOT_FOUND',
            ]);

            return [
                'processed' => false,
                'status' => 'payment_not_found',
                'event_id' => $webhookRecord->id,
            ];
        }

        // Multi-tenant cross-tenant protection
        if ($tenantConnection && $payment->tenant_id !== $tenantConnection->tenant_id) {
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'CROSS_TENANT_INJECTION_DETECTED',
            ]);

            throw new WebhookSignatureInvalidException('Cross-tenant webhook mismatch detected.');
        }

        // Establish tenant context for RLS safety and execute inside tenant boundary
        $webhookRecord->update(['tenant_id' => $payment->tenant_id]);

        try {
            return $this->tenantManager->runForTenant($payment->tenant_id, function () use ($webhookRecord, $payment, $eventData) {
                /** @var Payment $lockedPayment */
                $lockedPayment = Payment::query()
                    ->where('id', $payment->id)
                    ->where('tenant_id', $payment->tenant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $incomingStatus = $eventData['status'];
                $incomingAmount = $eventData['amount_minor'] ?? null;
                $incomingCurrency = $eventData['currency'] ?? ($eventData['payload']['currency'] ?? null);

                // 1. Currency Validation
                if ($incomingCurrency !== null && strtoupper((string) $incomingCurrency) !== strtoupper((string) $lockedPayment->currency)) {
                    AuditLog::create([
                        'tenant_id' => $lockedPayment->tenant_id,
                        'action' => 'payment_webhook_rejected_currency_mismatch',
                        'entity_type' => Payment::class,
                        'entity_id' => $lockedPayment->id,
                        'before' => ['currency' => $lockedPayment->currency],
                        'after' => [
                            'incoming_currency' => $incomingCurrency,
                            'event_id' => $webhookRecord->external_event_id,
                        ],
                        'created_at' => now(),
                    ]);

                    throw new PaymentWebhookCurrencyMismatchException(
                        "Webhook currency '{$incomingCurrency}' does not match payment currency '{$lockedPayment->currency}'."
                    );
                }

                // Check if this webhook notification is a refund event
                $isRefundEvent = in_array($incomingStatus, [Payment::STATUS_REFUNDED, Payment::STATUS_PARTIALLY_REFUNDED], true)
                    || ! empty($eventData['refund_data']);

                if ($isRefundEvent) {
                    return $this->handleRefundWebhook($lockedPayment, $webhookRecord, $eventData);
                }

                // 2. Amount Validation
                // Strict invariant: verified webhook + resolved payment + incoming amount == payment.amount_minor
                // When status is succeeded, amount is required and must match stored payment amount.
                // When amount is provided on any status, it must match stored payment amount.
                $amountMismatch = false;
                if ($incomingStatus === Payment::STATUS_SUCCEEDED) {
                    if ($incomingAmount === null || (int) $incomingAmount !== (int) $lockedPayment->amount_minor) {
                        $amountMismatch = true;
                    }
                } elseif ($incomingAmount !== null && (int) $incomingAmount !== (int) $lockedPayment->amount_minor) {
                    $amountMismatch = true;
                }

                if ($amountMismatch) {
                    AuditLog::create([
                        'tenant_id' => $lockedPayment->tenant_id,
                        'action' => 'payment_webhook_rejected_amount_mismatch',
                        'entity_type' => Payment::class,
                        'entity_id' => $lockedPayment->id,
                        'before' => ['amount_minor' => $lockedPayment->amount_minor],
                        'after' => [
                            'incoming_amount_minor' => $incomingAmount,
                            'event_id' => $webhookRecord->external_event_id,
                        ],
                        'created_at' => now(),
                    ]);

                    throw new PaymentWebhookAmountMismatchException(
                        "Webhook payment amount ({$incomingAmount}) does not match payment amount ({$lockedPayment->amount_minor})."
                    );
                }

                // 3. Monotonic Out-of-Order & Terminal State Guards (Requirement 7):
                // Explicitly prevent:
                // - succeeded → processing
                // - succeeded → pending
                // - succeeded → failed
                // - refunded → succeeded
                // - refunded → processing
                // - refunded → failed
                // - partially_refunded → failed
                $isInvalidTransition = false;
                if ($lockedPayment->isSucceeded()) {
                    if (in_array($incomingStatus, [Payment::STATUS_PROCESSING, Payment::STATUS_PENDING, Payment::STATUS_FAILED, Payment::STATUS_CANCELLED], true)) {
                        $isInvalidTransition = true;
                    }
                } elseif ($lockedPayment->status === Payment::STATUS_REFUNDED) {
                    if (in_array($incomingStatus, [Payment::STATUS_SUCCEEDED, Payment::STATUS_PROCESSING, Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
                        $isInvalidTransition = true;
                    }
                } elseif ($lockedPayment->status === Payment::STATUS_PARTIALLY_REFUNDED) {
                    if (in_array($incomingStatus, [Payment::STATUS_FAILED, Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
                        $isInvalidTransition = true;
                    }
                } elseif ($lockedPayment->isCancelled()) {
                    $isInvalidTransition = true;
                }

                if ($isInvalidTransition) {
                    $webhookRecord->update([
                        'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
                        'processed_at' => now(),
                        'error_code' => 'OUT_OF_ORDER_IGNORED',
                    ]);

                    return [
                        'processed' => true,
                        'status' => 'out_of_order_ignored',
                        'payment_id' => $lockedPayment->id,
                    ];
                }

                // 4. Valid State Transitions
                if ($incomingStatus === Payment::STATUS_SUCCEEDED && ! $lockedPayment->isSucceeded()) {
                    $lockedPayment->update([
                        'status' => Payment::STATUS_SUCCEEDED,
                        'succeeded_at' => now(),
                        'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                            'webhook_event_id' => $webhookRecord->external_event_id,
                        ])),
                    ]);

                    // Update matching or latest pending attempt (Requirement 14 & 16)
                    $attempt = $lockedPayment->attempts()
                        ->whereIn('status', [PaymentAttempt::STATUS_PROCESSING, PaymentAttempt::STATUS_PENDING])
                        ->latest()
                        ->first();

                    if ($attempt) {
                        $attempt->update([
                            'status' => PaymentAttempt::STATUS_SUCCEEDED,
                            'completed_at' => now(),
                            'response_code' => $eventData['payload']['response_code'] ?? 'WEBHOOK_SUCCEEDED',
                            'response_payload' => AuditLog::sanitizePayload($eventData['payload']),
                        ]);
                    }

                    AuditLog::create([
                        'tenant_id' => $lockedPayment->tenant_id,
                        'action' => 'payment_succeeded_webhook',
                        'entity_type' => Payment::class,
                        'entity_id' => $lockedPayment->id,
                        'after' => ['status' => Payment::STATUS_SUCCEEDED, 'event_id' => $webhookRecord->external_event_id],
                        'created_at' => now(),
                    ]);

                    $this->shiftService->recordSaleCashPayment($lockedPayment);

                    event(new PaymentSucceeded($lockedPayment));
                } elseif ($incomingStatus === Payment::STATUS_FAILED && ! $lockedPayment->isSucceeded()) {
                    $lockedPayment->update([
                        'status' => Payment::STATUS_FAILED,
                        'failed_at' => now(),
                        'metadata' => AuditLog::sanitizePayload(array_merge($lockedPayment->metadata ?? [], [
                            'failure_reason' => $eventData['payload']['reason'] ?? 'Provider reported failure via webhook',
                        ])),
                    ]);

                    $attempt = $lockedPayment->attempts()
                        ->whereIn('status', [PaymentAttempt::STATUS_PROCESSING, PaymentAttempt::STATUS_PENDING])
                        ->latest()
                        ->first();

                    if ($attempt) {
                        $attempt->update([
                            'status' => PaymentAttempt::STATUS_FAILED,
                            'completed_at' => now(),
                            'response_code' => $eventData['payload']['response_code'] ?? 'WEBHOOK_FAILED',
                            'response_payload' => AuditLog::sanitizePayload($eventData['payload']),
                        ]);
                    }

                    AuditLog::create([
                        'tenant_id' => $lockedPayment->tenant_id,
                        'action' => 'payment_failed_webhook',
                        'entity_type' => Payment::class,
                        'entity_id' => $lockedPayment->id,
                        'after' => ['status' => Payment::STATUS_FAILED, 'event_id' => $webhookRecord->external_event_id],
                        'created_at' => now(),
                    ]);

                    event(new PaymentFailed($lockedPayment, 'Provider reported failure via webhook'));
                }

                $webhookRecord->update([
                    'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
                    'processed_at' => now(),
                ]);

                return [
                    'processed' => true,
                    'status' => 'processed',
                    'event_id' => $webhookRecord->id,
                    'payment_id' => $lockedPayment->id,
                ];
            });
        } catch (PaymentWebhookAmountMismatchException $e) {
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'PAYMENT_WEBHOOK_AMOUNT_MISMATCH',
            ]);

            throw $e;
        } catch (PaymentWebhookCurrencyMismatchException $e) {
            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'PAYMENT_WEBHOOK_CURRENCY_MISMATCH',
            ]);

            throw $e;
        }
    }

    /**
     * Process an incoming refund notification mapped to an exact PaymentRefund record.
     *
     * @param  array<string, mixed>  $eventData
     * @return array{
     *     processed: bool,
     *     status: string,
     *     event_id?: string,
     *     payment_id?: string
     * }
     */
    protected function handleRefundWebhook(Payment $lockedPayment, PaymentWebhookEvent $webhookRecord, array $eventData): array
    {
        $refundData = $eventData['refund_data'] ?? null;
        $refundKey = $refundData['refund_key'] ?? null;

        if (! $refundKey) {
            AuditLog::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'action' => 'payment_webhook_refund_missing_key',
                'entity_type' => Payment::class,
                'entity_id' => $lockedPayment->id,
                'after' => ['event_id' => $webhookRecord->external_event_id],
                'created_at' => now(),
            ]);

            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'REFUND_KEY_MISSING',
            ]);

            return [
                'processed' => false,
                'status' => 'refund_key_missing',
                'event_id' => $webhookRecord->id,
                'payment_id' => $lockedPayment->id,
            ];
        }

        /** @var PaymentRefund|null $targetRefund */
        $targetRefund = $lockedPayment->refunds()
            ->where('reference_number', $refundKey)
            ->lockForUpdate()
            ->first();

        if (! $targetRefund) {
            AuditLog::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'action' => 'payment_webhook_refund_not_found',
                'entity_type' => PaymentRefund::class,
                'after' => ['refund_key' => $refundKey, 'event_id' => $webhookRecord->external_event_id],
                'created_at' => now(),
            ]);

            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'REFUND_NOT_FOUND',
            ]);

            return [
                'processed' => false,
                'status' => 'refund_not_found',
                'event_id' => $webhookRecord->id,
                'payment_id' => $lockedPayment->id,
            ];
        }

        // Amount validation on refund:
        $incomingRefundAmount = $refundData['refund_amount_minor'] ?? null;
        if ($incomingRefundAmount !== null && (int) $incomingRefundAmount !== (int) $targetRefund->amount_minor) {
            AuditLog::create([
                'tenant_id' => $lockedPayment->tenant_id,
                'action' => 'payment_webhook_rejected_refund_amount_mismatch',
                'entity_type' => PaymentRefund::class,
                'entity_id' => $targetRefund->id,
                'before' => ['amount_minor' => $targetRefund->amount_minor],
                'after' => [
                    'incoming_refund_amount' => $incomingRefundAmount,
                    'event_id' => $webhookRecord->external_event_id,
                ],
                'created_at' => now(),
            ]);

            $webhookRecord->update([
                'processing_status' => PaymentWebhookEvent::STATUS_FAILED,
                'error_code' => 'REFUND_AMOUNT_MISMATCH',
            ]);

            throw new PaymentWebhookAmountMismatchException(
                "Webhook refund amount ({$incomingRefundAmount}) does not match internal refund amount ({$targetRefund->amount_minor})."
            );
        }

        // Check bank confirmation
        $bankConfirmedAt = $refundData['bank_confirmed_at'] ?? null;
        if ($bankConfirmedAt) {
            if (! $targetRefund->isCompleted()) {
                $targetRefund->update([
                    'status' => PaymentRefund::STATUS_COMPLETED,
                    'bank_confirmed_at' => Carbon::parse($bankConfirmedAt),
                    'completed_at' => now(),
                    'provider_reference' => $refundData['provider_refund_id'] ?? $targetRefund->provider_reference,
                    'metadata' => AuditLog::sanitizePayload(array_merge($targetRefund->metadata ?? [], [
                        'webhook_event_id' => $webhookRecord->external_event_id,
                    ])),
                ]);

                $newTotalRefunded = $lockedPayment->totalRefundedMinor();
                $newPaymentStatus = ($newTotalRefunded >= $lockedPayment->amount_minor)
                    ? Payment::STATUS_REFUNDED
                    : Payment::STATUS_PARTIALLY_REFUNDED;

                $lockedPayment->update(['status' => $newPaymentStatus]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'action' => 'payment_refund_confirmed_webhook',
                    'entity_type' => PaymentRefund::class,
                    'entity_id' => $targetRefund->id,
                    'after' => [
                        'refund_status' => PaymentRefund::STATUS_COMPLETED,
                        'payment_status' => $newPaymentStatus,
                        'bank_confirmed_at' => $bankConfirmedAt,
                        'event_id' => $webhookRecord->external_event_id,
                    ],
                    'created_at' => now(),
                ]);

                $this->shiftService->recordRefundCashPayment($targetRefund);

                event(new PaymentRefunded($lockedPayment, $targetRefund));
            }
        } else {
            // Without bank confirmation -> remains/transitions to processing
            if (! $targetRefund->isCompleted()) {
                $targetRefund->update([
                    'status' => PaymentRefund::STATUS_PROCESSING,
                    'provider_reference' => $refundData['provider_refund_id'] ?? $targetRefund->provider_reference,
                    'metadata' => AuditLog::sanitizePayload(array_merge($targetRefund->metadata ?? [], [
                        'webhook_event_id' => $webhookRecord->external_event_id,
                        'unconfirmed_notice' => 'Refund notification received without bank confirmation.',
                    ])),
                ]);

                AuditLog::create([
                    'tenant_id' => $lockedPayment->tenant_id,
                    'action' => 'payment_refund_processing_webhook',
                    'entity_type' => PaymentRefund::class,
                    'entity_id' => $targetRefund->id,
                    'after' => [
                        'refund_status' => PaymentRefund::STATUS_PROCESSING,
                        'payment_status' => $lockedPayment->status,
                        'event_id' => $webhookRecord->external_event_id,
                    ],
                    'created_at' => now(),
                ]);
            }
        }

        $webhookRecord->update([
            'processing_status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        return [
            'processed' => true,
            'status' => 'processed',
            'event_id' => $webhookRecord->id,
            'payment_id' => $lockedPayment->id,
        ];
    }

    /**
     * Compute the read-model settlement summary for a sale.
     * Note: Does NOT modify Sale.status.
     *
     * @return array<string, mixed>
     */
    public function getSalePaymentSummary(Sale $sale): array
    {
        $paid = $sale->getPaidAmountMinor();
        $refunded = $sale->getRefundedAmountMinor();
        $outstanding = $sale->getOutstandingAmountMinor();
        $total = (int) $sale->total_minor;

        $settlementStatus = 'unpaid';
        if ($paid >= $total && $total > 0 && $refunded >= $paid) {
            $settlementStatus = 'refunded';
        } elseif ($refunded > 0 && $refunded < $total) {
            $settlementStatus = 'partially_refunded';
        } elseif ($outstanding === 0 && $refunded === 0 && $paid > 0) {
            $settlementStatus = 'paid';
        } elseif ($paid > 0 && $outstanding > 0) {
            $settlementStatus = 'partially_paid';
        }

        return [
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference_number,
            'sale_status' => $sale->status, // immutable sales lifecycle
            'total_minor' => $total,
            'paid_minor' => $paid,
            'refunded_minor' => $refunded,
            'net_paid_minor' => max(0, $paid - $refunded),
            'outstanding_minor' => $outstanding,
            'settlement_status' => $settlementStatus,
            'payments_count' => $sale->payments()->count(),
        ];
    }
}
