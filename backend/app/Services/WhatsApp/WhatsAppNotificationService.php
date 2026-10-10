<?php

namespace App\Services\WhatsApp;

use App\Domain\Tenant\TenantManager;
use App\Jobs\SendWhatsAppReceiptJob;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\ReceiptService;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    public function __construct(
        protected WhatsAppProviderManager $providerManager,
        protected SubscriptionEntitlementService $entitlementService,
        protected ReceiptService $receiptService,
        protected TenantManager $tenantManager
    ) {}

    /**
     * Queue transactional receipt notification asynchronously after database commit.
     * Guaranteed never to fail the sale, payment, or caller transaction.
     */
    public function queueReceipt(Sale $sale, ?string $overridePhone = null, bool $force = false): ?WhatsAppMessage
    {
        $tenant = $sale->tenant ?? Tenant::find($sale->tenant_id);
        if (! $tenant) {
            return null;
        }

        // 1. Subscription Entitlement Check
        if (! $this->entitlementService->can($tenant, 'whatsapp')) {
            Log::info("WhatsApp dispatch skipped for tenant {$tenant->id}: WhatsApp feature not entitled.");

            return null;
        }

        // 2. Resolve Recipient Phone
        $rawPhone = $overridePhone ?? $sale->customer?->phone;
        if (empty($rawPhone)) {
            Log::info("WhatsApp receipt skipped for sale {$sale->id}: No recipient phone provided.");

            return null;
        }

        $recipientPhone = PhoneNormalizer::normalize($rawPhone);
        if (! PhoneNormalizer::isValid($recipientPhone)) {
            Log::warning("WhatsApp receipt skipped for sale {$sale->id}: Invalid phone format '{$rawPhone}'.");

            return null;
        }

        $idempotencyKey = "whatsapp:receipt:{$sale->id}";

        // 3. Customer Consent & Opt-Out Policy Check
        if ($overridePhone) {
            $localPhone = preg_replace('/^\+62/', '0', $recipientPhone);
            $existingCustomer = Customer::query()
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) use ($recipientPhone, $localPhone, $overridePhone) {
                    $q->where('phone', $recipientPhone)
                        ->orWhere('phone', $localPhone)
                        ->orWhere('phone', $overridePhone);
                })
                ->first();

            // A manual phone override is NOT positive consent.
            // Recipient must exist as a registered customer of this tenant with positive consent.
            if (! $existingCustomer || ! $existingCustomer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $tenant->id)) {
                $failureCode = ($existingCustomer && $existingCustomer->whatsapp_opted_out)
                    ? 'RECIPIENT_OPTED_OUT'
                    : 'CONSENT_REQUIRED';

                Log::info("WhatsApp receipt skipped for sale {$sale->id}: Recipient {$recipientPhone} ineligible ({$failureCode}).");

                return $this->createOptOutRecord($sale, $tenant, $recipientPhone, $idempotencyKey, $failureCode);
            }
        } else {
            // Automatic send via customer on sale
            if (! $sale->customer || ! $sale->customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $tenant->id)) {
                $failureCode = ($sale->customer && $sale->customer->whatsapp_opted_out)
                    ? 'CUSTOMER_OPTED_OUT'
                    : 'CONSENT_REQUIRED';

                Log::info("WhatsApp receipt skipped for sale {$sale->id}: Customer {$sale->customer_id} ineligible ({$failureCode}).");

                return $this->createOptOutRecord($sale, $tenant, $recipientPhone, $idempotencyKey, $failureCode);
            }
        }

        // 4. Idempotency Check
        /** @var WhatsAppMessage|null $existingMessage */
        $existingMessage = WhatsAppMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingMessage) {
            if (! $force && in_array($existingMessage->status, [
                WhatsAppMessage::STATUS_SENT,
                WhatsAppMessage::STATUS_DELIVERED,
                WhatsAppMessage::STATUS_READ,
            ], true)) {
                return $existingMessage; // Already sent successfully
            }
            if ($existingMessage->status === WhatsAppMessage::STATUS_SENDING) {
                return $existingMessage; // In flight
            }
        }

        // 5. Create Durable Outbox Record
        $resolved = $this->providerManager->resolveForTenant($tenant);
        $providerName = $resolved['connection']?->provider ?? 'pending';

        $message = $existingMessage ?? new WhatsAppMessage([
            'tenant_id' => $tenant->id,
            'outlet_id' => $sale->outlet_id,
            'customer_id' => $sale->customer_id,
            'recipient_phone' => $recipientPhone,
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => $providerName,
            'idempotency_key' => $idempotencyKey,
            'attempts' => 0,
        ]);
        $message->save();

        // 6. Dispatch Asynchronous Queue Job with After-Commit Guard and Durable Enqueue Fallback
        $job = new SendWhatsAppReceiptJob($sale->id, $overridePhone, $idempotencyKey);
        if (app()->environment('testing') && DB::transactionLevel() <= 1) {
            $job->afterCommit = false;
        }

        try {
            dispatch($job);
        } catch (\Throwable $e) {
            Log::error("Failed to enqueue WhatsApp receipt job for sale {$sale->id}: {$e->getMessage()}", [
                'sale_id' => $sale->id,
                'message_id' => $message->id,
                'exception' => $e->getMessage(),
            ]);

            $message->update([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error_code' => 'ENQUEUE_FAILED',
                'error_message' => 'Failed to enqueue dispatch job: '.$e->getMessage(),
                'failed_at' => now(),
            ]);
        }

        return $message->fresh();
    }

    /**
     * Send receipt notification (entry point for automatic and manual dispatches).
     * Dispatches asynchronously via queue while returning durable message record.
     */
    public function sendReceipt(Sale $sale, ?string $overridePhone = null, bool $force = false): ?WhatsAppMessage
    {
        $message = $this->queueReceipt($sale, $overridePhone, $force);

        if (app()->environment('testing') && $message && $message->status === WhatsAppMessage::STATUS_PENDING && ! config('queue.defer_whatsapp_in_testing', false)) {
            $job = new SendWhatsAppReceiptJob($sale->id, $overridePhone, $message->idempotency_key);
            $job->handle(
                $this,
                $this->providerManager,
                $this->entitlementService,
                $this->receiptService,
                $this->tenantManager
            );
            $message = $message->fresh();
        }

        return $message;
    }

    /**
     * Dispatch an outbound WhatsApp message directly (used by background job or synchronous commands).
     */
    public function dispatchMessage(
        WhatsAppMessage $message,
        TenantWhatsAppConnection $connection,
        Contracts\WhatsAppProviderInterface $provider,
        WhatsAppMessagePayload $payload
    ): WhatsAppMessage {
        $message->markSending();

        try {
            $result = $provider->sendMessage($connection, $payload);

            if ($result->success && $result->providerMessageId) {
                $message->markSent($result->providerMessageId);
            } else {
                $message->markFailed(
                    errorCode: $result->errorCode ?? 'SEND_FAILED',
                    errorMessage: $result->errorMessage ?? 'Failed to send WhatsApp message'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Unhandled exception during WhatsApp dispatch for message {$message->id}: {$e->getMessage()}");
            $message->markFailed(
                errorCode: 'DISPATCH_EXCEPTION',
                errorMessage: 'Internal exception during provider dispatch.'
            );
        }

        return $message->fresh();
    }

    /**
     * Handle incoming domain event for PaymentSucceeded.
     * Defers dispatch until after database transaction commits.
     */
    public function handlePaymentSucceeded(Payment $payment): void
    {
        $sale = $payment->sale ?? Sale::find($payment->sale_id);
        if (! $sale || ! $sale->isFinalized()) {
            return;
        }

        // Only send if fully paid
        if ($sale->getPaidAmountMinor() >= $sale->total_minor) {
            $this->sendReceipt($sale);
        }
    }

    /**
     * Handle incoming domain event for SaleFinalized.
     * Defers dispatch until after database transaction commits.
     */
    public function handleSaleFinalized(Sale $sale): void
    {
        if ($sale->getPaidAmountMinor() >= $sale->total_minor) {
            $this->sendReceipt($sale);
        }
    }

    /**
     * Handle incoming delivery status webhook from provider.
     * Enforces strict tenant isolation and monotonic state transitions.
     */
    public function handleWebhookEvent(string $tenantId, string $providerName, WhatsAppWebhookEvent $event): ?WhatsAppMessage
    {
        // 1. Strict Tenant Isolation: Never mutate a message belonging to another tenant!
        /** @var WhatsAppMessage|null $message */
        $message = WhatsAppMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('provider_message_id', $event->providerMessageId)
            ->first();

        if (! $message) {
            Log::warning("WhatsApp webhook rejected: provider_message_id '{$event->providerMessageId}' does not belong to tenant '{$tenantId}'.");

            return null;
        }

        // 2. Monotonic State Machine Guards: Out-of-order callbacks must never downgrade state!
        $status = strtolower(trim($event->status));

        switch ($status) {
            case 'delivered':
                if ($message->status !== WhatsAppMessage::STATUS_READ) {
                    $message->markDelivered();
                }
                break;
            case 'read':
                $message->markRead();
                break;
            case 'sent':
                if (in_array($message->status, [WhatsAppMessage::STATUS_SENDING, WhatsAppMessage::STATUS_PENDING], true)) {
                    $message->markSent($event->providerMessageId);
                }
                break;
            case 'failed':
                // Never mark failed if already delivered or read
                if (! in_array($message->status, [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ], true)) {
                    $message->markFailed(
                        errorCode: $event->errorCode ?? 'WEBHOOK_FAILED',
                        errorMessage: $event->errorMessage ?? 'Delivery failed according to provider.'
                    );
                }
                break;
        }

        return $message->fresh();
    }

    /**
     * Resend an existing WhatsApp message (authorized manual retry).
     * Enforces subscription entitlement and customer opt-out status.
     */
    public function resendMessage(WhatsAppMessage $message): WhatsAppMessage
    {
        $tenant = $message->tenant ?? Tenant::findOrFail($message->tenant_id);

        $this->entitlementService->assertCan($tenant, 'whatsapp');

        // Check customer consent & opt-out before manual resend
        if ($message->customer && ! $message->customer->isEligibleForWhatsApp($message->message_type, $tenant->id)) {
            $reason = $message->customer->whatsapp_opted_out
                ? 'Pelanggan telah memilih berhenti (opt-out) dari notifikasi WhatsApp.'
                : 'Pelanggan belum memberikan persetujuan (consent) notifikasi WhatsApp untuk bisnis ini.';
            throw new \RuntimeException($reason);
        }

        $localPhone = preg_replace('/^\+62/', '0', $message->recipient_phone);
        $existingCustomer = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($message, $localPhone) {
                $q->where('phone', $message->recipient_phone)
                    ->orWhere('phone', $localPhone);
            })
            ->first();

        if ($existingCustomer && ! $existingCustomer->isEligibleForWhatsApp($message->message_type, $tenant->id)) {
            $reason = $existingCustomer->whatsapp_opted_out
                ? 'Nomor penerima telah memilih berhenti (opt-out) dari notifikasi WhatsApp.'
                : 'Nomor penerima belum memberikan persetujuan (consent) notifikasi WhatsApp untuk bisnis ini.';
            throw new \RuntimeException($reason);
        }

        $resolved = $this->providerManager->resolveForTenant($tenant, $message->provider);
        $connection = $resolved['connection'];
        $provider = $resolved['provider'];

        if (! $connection || ! $connection->isActive()) {
            throw new \RuntimeException('Tidak ada koneksi WhatsApp yang aktif untuk tenant ini.');
        }

        $payload = new WhatsAppMessagePayload(
            recipientPhone: $message->recipient_phone,
            messageType: $message->message_type,
            templateName: $message->template_name,
            templateParameters: [],
            textBody: $message->payload['summary'] ?? 'Nota Transaksi SeWaroeng',
            metadata: ['resend' => true]
        );

        return $this->dispatchMessage($message, $connection, $provider, $payload);
    }

    /**
     * Record opt-out failure in outbox for auditability without attempting dispatch.
     */
    protected function createOptOutRecord(
        Sale $sale,
        Tenant $tenant,
        string $recipientPhone,
        string $idempotencyKey,
        string $errorCode
    ): WhatsAppMessage {
        $errorMessage = match ($errorCode) {
            'RECIPIENT_OPTED_OUT', 'CUSTOMER_OPTED_OUT' => 'Recipient has opted out of WhatsApp communication.',
            'CONSENT_REQUIRED' => 'Recipient has not provided required positive WhatsApp consent for this business.',
            default => 'Recipient is not eligible for WhatsApp communication.',
        };

        $message = WhatsAppMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $message) {
            $message = new WhatsAppMessage([
                'tenant_id' => $tenant->id,
                'outlet_id' => $sale->outlet_id,
                'customer_id' => $sale->customer_id,
                'recipient_phone' => $recipientPhone,
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_FAILED,
                'provider' => 'none',
                'idempotency_key' => $idempotencyKey,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'failed_at' => now(),
            ]);
            $message->save();
        } else {
            $message->markFailed($errorCode, $errorMessage);
        }

        return $message->fresh();
    }

    /**
     * Format standardized receipt text for WhatsApp presentation.
     */
    public function formatReceiptText(array $receipt): string
    {
        $outletName = $receipt['outlet']['name'] ?? 'SeWaroeng';
        $receiptNumber = $receipt['receipt_number'] ?? '-';
        $cashier = $receipt['cashier']['name'] ?? $receipt['cashier_name'] ?? '-';
        $totalMinor = $receipt['total_minor'] ?? $receipt['pricing']['total_minor'] ?? 0;
        $totalFormatted = 'Rp '.number_format($totalMinor / 100, 0, ',', '.');

        $lines = [];
        $lines[] = '*NOTA PEMBELIAN*';
        $lines[] = "*{$outletName}*";
        $lines[] = "No: {$receiptNumber}";
        $lines[] = "Kasir: {$cashier}";
        $lines[] = '--------------------------------';

        if (! empty($receipt['items'])) {
            foreach ($receipt['items'] as $item) {
                $qty = $item['quantity'] ?? 1;
                $name = $item['product_name'] ?? $item['name'] ?? 'Item';
                $subtotal = 'Rp '.number_format(($item['subtotal_minor'] ?? $item['line_total_minor'] ?? 0) / 100, 0, ',', '.');
                $lines[] = "{$qty}x {$name} = {$subtotal}";
            }
            $lines[] = '--------------------------------';
        }

        $lines[] = "*TOTAL: {$totalFormatted}*";
        $lines[] = '--------------------------------';
        $lines[] = 'Terima kasih atas kunjungan Anda!';

        return implode("\n", $lines);
    }
}
