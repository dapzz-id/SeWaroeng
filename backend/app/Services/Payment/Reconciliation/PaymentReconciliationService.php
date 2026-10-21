<?php

namespace App\Services\Payment\Reconciliation;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\PaymentReconciliationInvalidStateException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliation;
use App\Models\PaymentRefund;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentReconciliationService
{
    /**
     * Reconcile normalized external provider evidence against internal payment records.
     */
    public function reconcileEvidence(
        string $tenantId,
        ProviderEvidence $evidence,
        ?string $userId = null,
        ?string $outletId = null
    ): PaymentReconciliation {
        return app(TenantManager::class)->runForTenant($tenantId, function () use ($tenantId, $evidence, $userId, $outletId) {
            // 1. Locate internal Payment candidate
            /** @var Payment|null $matchedPayment */
            $matchedPayment = null;
            /** @var PaymentRefund|null $matchedRefund */
            $matchedRefund = null;

            // Strategy A: Direct provider_payment_id or reference_number on Payment
            if ($evidence->providerReference !== '') {
                $matchedPayment = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $evidence->provider)
                    ->where(function ($q) use ($evidence) {
                        $q->where('provider_payment_id', $evidence->providerReference)
                            ->orWhere('reference_number', $evidence->providerReference);
                        if (Str::isUuid($evidence->providerReference)) {
                            $q->orWhere('id', $evidence->providerReference);
                        }
                    })
                    ->first();
            }

            // Strategy B: Match through PaymentAttempt provider_reference
            if (! $matchedPayment && $evidence->providerReference !== '') {
                $attempt = PaymentAttempt::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $evidence->provider)
                    ->where('provider_reference', $evidence->providerReference)
                    ->first();

                if ($attempt) {
                    $matchedPayment = $attempt->payment;
                }
            }

            // Strategy C: Match via internal reference (e.g. PAY-YYYYMMDD-XXXXXX)
            if (! $matchedPayment && $evidence->internalReference !== null && $evidence->internalReference !== '') {
                $matchedPayment = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($evidence) {
                        $q->where('reference_number', $evidence->internalReference)
                            ->orWhere('client_reference', $evidence->internalReference);
                        if (Str::isUuid($evidence->internalReference)) {
                            $q->orWhere('id', $evidence->internalReference);
                        }
                    })
                    ->first();
            }

            // Strategy D: Match via PaymentRefund
            if (! $matchedPayment && $evidence->providerReference !== '') {
                $matchedRefund = PaymentRefund::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($evidence) {
                        $q->where('provider_reference', $evidence->providerReference)
                            ->orWhere('reference_number', $evidence->providerReference);
                    })
                    ->first();

                if ($matchedRefund) {
                    $matchedPayment = $matchedRefund->payment;
                }
            }

            // Determine effective outlet ID
            $effectiveOutletId = $outletId;
            if ($matchedPayment && $matchedPayment->sale) {
                $effectiveOutletId = $matchedPayment->sale->outlet_id;
            }

            // 2. Perform deterministic matching rules
            $reconciliationStatus = PaymentReconciliation::STATUS_UNMATCHED;
            $settlementStatus = $evidence->settlementStatus;
            $mismatchReason = null;
            $expectedAmountMinor = null;
            $paymentStatus = null;

            if ($matchedPayment) {
                $paymentStatus = $matchedPayment->status;
                $expectedAmountMinor = $matchedRefund ? $matchedRefund->amount_minor : $matchedPayment->amount_minor;

                // Rule 1: Currency validation
                if ($evidence->currency !== 'IDR' || $matchedPayment->currency !== $evidence->currency) {
                    $reconciliationStatus = PaymentReconciliation::STATUS_MISMATCHED;
                    $mismatchReason = PaymentReconciliation::MISMATCH_CURRENCY;
                }
                // Rule 2: Amount validation
                elseif ($evidence->amountMinor !== $expectedAmountMinor) {
                    $reconciliationStatus = PaymentReconciliation::STATUS_MISMATCHED;
                    $mismatchReason = PaymentReconciliation::MISMATCH_AMOUNT;
                }
                // Rule 3: Lifecycle state consistency
                elseif (
                    in_array($evidence->settlementStatus, [PaymentReconciliation::SETTLEMENT_SETTLED], true)
                    && in_array($matchedPayment->status, [Payment::STATUS_CANCELLED, Payment::STATUS_FAILED], true)
                ) {
                    $reconciliationStatus = PaymentReconciliation::STATUS_MISMATCHED;
                    $mismatchReason = PaymentReconciliation::MISMATCH_STATUS_CONFLICT;
                } elseif (
                    $evidence->settlementStatus === PaymentReconciliation::SETTLEMENT_FAILED
                    && $matchedPayment->status === Payment::STATUS_SUCCEEDED
                ) {
                    $reconciliationStatus = PaymentReconciliation::STATUS_MISMATCHED;
                    $mismatchReason = PaymentReconciliation::MISMATCH_STATUS_CONFLICT;
                } else {
                    // All rules matched
                    $reconciliationStatus = PaymentReconciliation::STATUS_MATCHED;
                    $settlementStatus = PaymentReconciliation::SETTLEMENT_SETTLED;
                }
            } else {
                $reconciliationStatus = PaymentReconciliation::STATUS_UNMATCHED;
                $mismatchReason = PaymentReconciliation::MISMATCH_PAYMENT_NOT_FOUND;
            }

            // 3. Atomically upsert reconciliation record for idempotent replay
            /** @var PaymentReconciliation|null $existing */
            $existing = PaymentReconciliation::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', $evidence->provider)
                ->where('provider_reference', $evidence->providerReference)
                ->lockForUpdate()
                ->first();

            $data = [
                'tenant_id' => $tenantId,
                'outlet_id' => $effectiveOutletId,
                'payment_id' => $matchedPayment?->id,
                'provider' => $evidence->provider,
                'provider_reference' => $evidence->providerReference,
                'channel' => $evidence->channel ?? $matchedPayment?->method,
                'expected_amount_minor' => $expectedAmountMinor,
                'provider_amount_minor' => $evidence->amountMinor,
                'fee_amount_minor' => $evidence->feeMinor,
                'net_amount_minor' => $evidence->netAmountMinor(),
                'currency' => $evidence->currency,
                'payment_status' => $paymentStatus,
                'settlement_status' => $settlementStatus,
                'reconciliation_status' => $existing?->isResolved() ? PaymentReconciliation::STATUS_RESOLVED : $reconciliationStatus,
                'mismatch_reason' => $existing?->isResolved() ? $existing->mismatch_reason : $mismatchReason,
                'reconciled_at' => $existing?->reconciled_at ?? now(),
                'reconciled_by' => $existing?->reconciled_by ?? $userId,
                'raw_evidence' => $evidence->rawMetadata,
                'metadata' => [
                    'internal_reference' => $evidence->internalReference,
                    'occurred_at' => $evidence->occurredAt?->toIso8601String(),
                ],
            ];

            if ($existing) {
                $existing->update($data);
                $reconciliation = $existing;
            } else {
                $reconciliation = PaymentReconciliation::create($data);
            }

            // Audit log
            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId,
                'action' => 'payment_reconciliation_recorded',
                'entity_type' => PaymentReconciliation::class,
                'entity_id' => $reconciliation->id,
                'after' => [
                    'provider' => $reconciliation->provider,
                    'provider_reference' => $reconciliation->provider_reference,
                    'status' => $reconciliation->reconciliation_status,
                    'mismatch_reason' => $reconciliation->mismatch_reason,
                    'provider_amount_minor' => $reconciliation->provider_amount_minor,
                    'expected_amount_minor' => $reconciliation->expected_amount_minor,
                ],
                'created_at' => now(),
            ]);

            // Async WhatsApp Payment Settlement Alert (Failure-isolated)
            if ($settlementStatus === PaymentReconciliation::SETTLEMENT_SETTLED) {
                try {
                    $tenant = Tenant::find($tenantId);
                    if ($tenant) {
                        $dispatchSettlement = function () use ($tenant, $reconciliation) {
                            try {
                                app(WhatsAppNotificationService::class)->queuePaymentSettlementAlert($tenant, [
                                    'reference' => $reconciliation->provider_reference,
                                    'provider' => $reconciliation->provider,
                                    'settled_amount_minor' => $reconciliation->provider_amount_minor,
                                    'transaction_count' => 1,
                                ]);
                            } catch (\Throwable $e) {
                                Log::warning("WhatsApp settlement alert dispatch failed for recon {$reconciliation->id}: {$e->getMessage()}");
                            }
                        };
                        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
                            DB::afterCommit($dispatchSettlement);
                        } else {
                            $dispatchSettlement();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("WhatsApp settlement alert dispatch failed for recon {$reconciliation->id}: {$e->getMessage()}");
                }
            }

            return $reconciliation->fresh(['payment.sale', 'outlet', 'reconciler', 'resolver']);
        });
    }

    /**
     * Reconcile an existing internal Payment record based on its recorded provider attempts.
     */
    public function reconcilePayment(Payment $payment, ?string $userId = null): PaymentReconciliation
    {
        $providerRef = $payment->provider_payment_id
            ?? $payment->attempts()->whereNotNull('provider_reference')->latest()->value('provider_reference')
            ?? $payment->reference_number;

        $evidence = new ProviderEvidence(
            provider: $payment->provider ?? 'manual',
            providerReference: $providerRef,
            amountMinor: $payment->amount_minor,
            internalReference: $payment->reference_number,
            feeMinor: 0,
            currency: $payment->currency,
            channel: $payment->method,
            settlementStatus: $payment->isSucceeded() ? PaymentReconciliation::SETTLEMENT_SETTLED : ($payment->isFailed() ? PaymentReconciliation::SETTLEMENT_FAILED : PaymentReconciliation::SETTLEMENT_PENDING),
            occurredAt: $payment->succeeded_at ?? $payment->created_at,
            rawMetadata: $payment->metadata ?? [],
        );

        return $this->reconcileEvidence(
            tenantId: $payment->tenant_id,
            evidence: $evidence,
            userId: $userId,
            outletId: $payment->sale?->outlet_id
        );
    }

    /**
     * Ingest and reconcile a batch of normalized provider evidence items.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{
     *     total_processed: int,
     *     matched_count: int,
     *     unmatched_count: int,
     *     mismatched_count: int,
     *     resolved_count: int,
     *     errors: array<int, array<string, mixed>>,
     *     reconciliations: array<int, PaymentReconciliation>
     * }
     */
    public function importBatch(
        string $tenantId,
        array $items,
        ?string $userId = null,
        ?string $outletId = null
    ): array {
        $matched = 0;
        $unmatched = 0;
        $mismatched = 0;
        $resolved = 0;
        $errors = [];
        $reconciliations = [];

        foreach ($items as $index => $item) {
            try {
                if (empty($item['provider']) || empty($item['provider_reference'])) {
                    throw new \InvalidArgumentException("Row {$index}: 'provider' and 'provider_reference' are required.");
                }

                $evidence = ProviderEvidence::fromArray($item);
                $recon = $this->reconcileEvidence($tenantId, $evidence, $userId, $outletId);

                match ($recon->reconciliation_status) {
                    PaymentReconciliation::STATUS_MATCHED => $matched++,
                    PaymentReconciliation::STATUS_UNMATCHED => $unmatched++,
                    PaymentReconciliation::STATUS_MISMATCHED => $mismatched++,
                    PaymentReconciliation::STATUS_RESOLVED => $resolved++,
                    default => null,
                };

                $reconciliations[] = $recon;
            } catch (\Throwable $e) {
                $errors[] = [
                    'index' => $index,
                    'item' => $item,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_processed' => count($items),
            'matched_count' => $matched,
            'unmatched_count' => $unmatched,
            'mismatched_count' => $mismatched,
            'resolved_count' => $resolved,
            'errors' => $errors,
            'reconciliations' => $reconciliations,
        ];
    }

    /**
     * Administratively resolve a mismatched or unmatched reconciliation record.
     */
    public function resolveDiscrepancy(
        PaymentReconciliation $reconciliation,
        string $notes,
        string $userId
    ): PaymentReconciliation {
        if ($reconciliation->isMatched()) {
            throw new PaymentReconciliationInvalidStateException(
                'Record is already matched and does not require resolution.'
            );
        }

        return app(TenantManager::class)->runForTenant($reconciliation->tenant_id, function () use ($reconciliation, $notes, $userId) {
            /** @var PaymentReconciliation $locked */
            $locked = PaymentReconciliation::query()
                ->where('id', $reconciliation->id)
                ->where('tenant_id', $reconciliation->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $beforeStatus = $locked->reconciliation_status;

            $locked->update([
                'reconciliation_status' => PaymentReconciliation::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by' => $userId,
                'resolution_notes' => trim($notes),
            ]);

            AuditLog::create([
                'tenant_id' => $locked->tenant_id,
                'actor_user_id' => $userId,
                'action' => 'payment_reconciliation_resolved',
                'entity_type' => PaymentReconciliation::class,
                'entity_id' => $locked->id,
                'before' => ['status' => $beforeStatus],
                'after' => [
                    'status' => PaymentReconciliation::STATUS_RESOLVED,
                    'resolution_notes' => $notes,
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh(['payment.sale', 'outlet', 'reconciler', 'resolver']);
        });
    }

    /**
     * Compute aggregate metrics for reconciliation domain.
     *
     * @param  array<string>|null  $assignedOutletIds
     * @return array<string, mixed>
     */
    public function getSummary(string $tenantId, ?string $outletId = null, ?array $assignedOutletIds = null): array
    {
        $query = PaymentReconciliation::query()->where('tenant_id', $tenantId);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        } elseif ($assignedOutletIds !== null) {
            $query->whereIn('outlet_id', $assignedOutletIds);
        }

        $records = $query->get();

        return [
            'total_records' => $records->count(),
            'matched_count' => $records->where('reconciliation_status', PaymentReconciliation::STATUS_MATCHED)->count(),
            'unmatched_count' => $records->where('reconciliation_status', PaymentReconciliation::STATUS_UNMATCHED)->count(),
            'mismatched_count' => $records->where('reconciliation_status', PaymentReconciliation::STATUS_MISMATCHED)->count(),
            'needs_review_count' => $records->where('reconciliation_status', PaymentReconciliation::STATUS_NEEDS_REVIEW)->count(),
            'resolved_count' => $records->where('reconciliation_status', PaymentReconciliation::STATUS_RESOLVED)->count(),
            'total_provider_amount_minor' => (int) $records->sum('provider_amount_minor'),
            'total_expected_amount_minor' => (int) $records->sum('expected_amount_minor'),
            'total_fee_minor' => (int) $records->sum('fee_amount_minor'),
            'total_net_amount_minor' => (int) $records->sum('net_amount_minor'),
        ];
    }

    /**
     * Retrieve paginated reconciliation records with filtering.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string>|null  $assignedOutletIds
     */
    public function listReconciliations(
        string $tenantId,
        array $filters = [],
        int $perPage = 20,
        ?array $assignedOutletIds = null
    ): LengthAwarePaginator {
        $query = PaymentReconciliation::query()
            ->where('tenant_id', $tenantId)
            ->with(['payment.sale', 'outlet', 'reconciler', 'resolver']);

        if (! empty($filters['outlet_id'])) {
            $query->where('outlet_id', $filters['outlet_id']);
        } elseif ($assignedOutletIds !== null) {
            $query->whereIn('outlet_id', $assignedOutletIds);
        }

        if (! empty($filters['status'])) {
            $query->where('reconciliation_status', $filters['status']);
        }

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }

        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }

        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
