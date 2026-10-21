<?php

namespace App\Jobs;

use App\Domain\Tenant\TenantManager;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\ReceiptService;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use App\Support\PhoneNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounded retry budget for transient provider failures.
     */
    public int $tries = 3;

    /**
     * Bounded exponential backoff with jitter in seconds.
     */
    public array $backoff = [5, 15, 45];

    /**
     * Max execution timeout per attempt.
     */
    public int $timeout = 30;

    public function __construct(
        public string $saleId,
        public ?string $overridePhone = null,
        public ?string $idempotencyKey = null
    ) {
        $this->afterCommit = true;
        $this->idempotencyKey = $idempotencyKey ?? "whatsapp:receipt:{$saleId}";
    }

    /**
     * Execute the job.
     */
    public function handle(
        WhatsAppNotificationService $notificationService,
        WhatsAppProviderManager $providerManager,
        SubscriptionEntitlementService $entitlementService,
        ReceiptService $receiptService,
        TenantManager $tenantManager
    ): void {
        /** @var Sale|null $sale */
        $sale = Sale::with(['customer', 'tenant', 'outlet'])->find($this->saleId);
        if (! $sale || ! $sale->isFinalized()) {
            Log::info("WhatsApp receipt job skipped: Sale {$this->saleId} not found or not finalized.");

            return;
        }

        $tenant = $sale->tenant ?? Tenant::find($sale->tenant_id);
        if (! $tenant) {
            return;
        }

        $tenantManager->runForTenant($tenant, function () use (
            $sale,
            $tenant,
            $notificationService,
            $providerManager,
            $entitlementService,
            $receiptService
        ) {
            // 1. Subscription Entitlement Verification
            if (! $entitlementService->can($tenant, 'whatsapp')) {
                Log::info("WhatsApp receipt job skipped for tenant {$tenant->id}: Not entitled to WhatsApp.");

                return;
            }

            // 2. Resolve Recipient Phone
            $rawPhone = $this->overridePhone ?? $sale->customer?->phone;
            if (empty($rawPhone)) {
                Log::info("WhatsApp receipt job skipped for sale {$sale->id}: No recipient phone provided.");

                return;
            }

            $recipientPhone = PhoneNormalizer::normalize($rawPhone);
            if (! PhoneNormalizer::isValid($recipientPhone)) {
                Log::warning("WhatsApp receipt job skipped for sale {$sale->id}: Invalid phone format '{$rawPhone}'.");

                return;
            }

            // 3. Customer Consent & Opt-Out Enforcement
            if ($this->overridePhone) {
                $localPhone = preg_replace('/^\+62/', '0', $recipientPhone);
                $existingCustomer = Customer::query()
                    ->where('tenant_id', $tenant->id)
                    ->where(function ($q) use ($recipientPhone, $localPhone) {
                        $q->where('phone', $recipientPhone)
                            ->orWhere('phone', $localPhone)
                            ->orWhere('phone', $this->overridePhone);
                    })
                    ->first();

                if (! $existingCustomer || ! $existingCustomer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $tenant->id)) {
                    $failureCode = ($existingCustomer && $existingCustomer->whatsapp_opted_out)
                        ? 'RECIPIENT_OPTED_OUT'
                        : 'CONSENT_REQUIRED';

                    Log::info("WhatsApp receipt job skipped for sale {$sale->id}: Recipient {$recipientPhone} ineligible ({$failureCode}).");
                    $this->recordOptOutFailure($sale, $tenant, $recipientPhone, $failureCode);

                    return;
                }
            } else {
                if (! $sale->customer || ! $sale->customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $tenant->id)) {
                    $failureCode = ($sale->customer && $sale->customer->whatsapp_opted_out)
                        ? 'CUSTOMER_OPTED_OUT'
                        : 'CONSENT_REQUIRED';

                    Log::info("WhatsApp receipt job skipped for sale {$sale->id}: Customer {$sale->customer_id} ineligible ({$failureCode}).");
                    $this->recordOptOutFailure($sale, $tenant, $recipientPhone, $failureCode);

                    return;
                }
            }

            // 4. Resolve Active Provider Connection
            $resolved = $providerManager->resolveForTenant($tenant);
            /** @var TenantWhatsAppConnection|null $connection */
            $connection = $resolved['connection'];
            $provider = $resolved['provider'];

            if (! $connection || ! $connection->isActive()) {
                Log::info("WhatsApp receipt job skipped for tenant {$tenant->id}: No active connection.");

                return;
            }

            // 5. Idempotency Check
            $idempotencyKey = $this->idempotencyKey;
            /** @var WhatsAppMessage|null $existingMessage */
            $existingMessage = WhatsAppMessage::query()
                ->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingMessage && in_array($existingMessage->status, [
                WhatsAppMessage::STATUS_SENT,
                WhatsAppMessage::STATUS_DELIVERED,
                WhatsAppMessage::STATUS_READ,
            ], true)) {
                // Already dispatched successfully
                return;
            }

            // 6. Generate Authoritative Receipt Document
            try {
                $receiptData = $receiptService->getReceiptForSale($tenant->id, $sale->id);
            } catch (\Throwable $e) {
                Log::warning("WhatsApp receipt job could not obtain presentation for sale {$sale->id}: {$e->getMessage()}");

                return;
            }

            $formattedText = $notificationService->formatReceiptText($receiptData);

            // 7. Get or Create Outbox Record
            $message = $existingMessage;
            if ($message) {
                $message->update([
                    'outlet_id' => $sale->outlet_id,
                    'customer_id' => $sale->customer_id,
                    'provider' => $provider->getProviderName(),
                    'template_name' => $connection->configuration['receipt_template_name'] ?? 'transactional_receipt',
                    'payload' => [
                        'sale_id' => $sale->id,
                        'receipt_number' => $receiptData['receipt_number'] ?? null,
                        'total_minor' => $sale->total_minor,
                        'summary' => $formattedText,
                    ],
                ]);
            } else {
                $message = new WhatsAppMessage([
                    'tenant_id' => $tenant->id,
                    'outlet_id' => $sale->outlet_id,
                    'customer_id' => $sale->customer_id,
                    'recipient_phone' => $recipientPhone,
                    'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                    'template_name' => $connection->configuration['receipt_template_name'] ?? 'transactional_receipt',
                    'payload' => [
                        'sale_id' => $sale->id,
                        'receipt_number' => $receiptData['receipt_number'] ?? null,
                        'total_minor' => $sale->total_minor,
                        'summary' => $formattedText,
                    ],
                    'status' => WhatsAppMessage::STATUS_PENDING,
                    'provider' => $provider->getProviderName(),
                    'idempotency_key' => $idempotencyKey,
                    'attempts' => 0,
                ]);
                $message->save();
            }

            // 8. Build Payload and Dispatch
            $payload = new WhatsAppMessagePayload(
                recipientPhone: $recipientPhone,
                messageType: WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                templateName: $connection->configuration['receipt_template_name'] ?? null,
                templateParameters: [
                    $receiptData['outlet']['name'] ?? 'SeWaroeng',
                    $receiptData['receipt_number'] ?? '-',
                    'Rp '.number_format($sale->total_minor / 100, 0, ',', '.'),
                ],
                textBody: $formattedText,
                metadata: [
                    'sale_id' => $sale->id,
                    'message_id' => $message->id,
                ]
            );

            $message->markSending();

            $result = $provider->sendMessage($connection, $payload);

            if ($result->success && $result->providerMessageId) {
                $message->markSent($result->providerMessageId);
            } else {
                // Record failure details or ambiguous delivery state
                if ($result->errorCode === 'AMBIGUOUS_TIMEOUT') {
                    $message->markAmbiguousDelivery($result->errorMessage, $result->errorCode);
                } else {
                    $message->markFailed(
                        errorCode: $result->errorCode ?? 'SEND_FAILED',
                        errorMessage: $result->errorMessage ?? 'Failed to send WhatsApp message'
                    );
                }

                if ($result->isRetryable && $this->attempts() < $this->tries && $this->job) {
                    $delay = $this->backoff[$this->attempts() - 1] ?? 30;
                    Log::warning("WhatsApp dispatch transient failure for message {$message->id}. Retrying in {$delay}s.", [
                        'error_code' => $result->errorCode,
                        'attempt' => $this->attempts(),
                    ]);
                    $this->release($delay);

                    return;
                }
            }
        });
    }

    /**
     * Record opt-out failure for observability.
     */
    protected function recordOptOutFailure(Sale $sale, Tenant $tenant, string $recipientPhone, string $errorCode): void
    {
        $idempotencyKey = $this->idempotencyKey;
        $message = WhatsAppMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $errorMessage = match ($errorCode) {
            'RECIPIENT_OPTED_OUT', 'CUSTOMER_OPTED_OUT' => 'Recipient has opted out of WhatsApp communication.',
            'CONSENT_REQUIRED' => 'Recipient has not provided required positive WhatsApp consent for this business.',
            default => 'Recipient is not eligible for WhatsApp communication.',
        };

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
    }

    /**
     * Handle job failure when retry budget is exhausted or unhandled exception occurs.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("SendWhatsAppReceiptJob exhausted retries for sale {$this->saleId}: ".($exception?->getMessage() ?? 'Unknown error'));

        if ($this->idempotencyKey) {
            $message = WhatsAppMessage::where('idempotency_key', $this->idempotencyKey)->first();
            if ($message && $message->status !== WhatsAppMessage::STATUS_SENT) {
                $message->markFailed(
                    errorCode: 'RETRIES_EXHAUSTED',
                    errorMessage: 'Max retry attempts exhausted for WhatsApp receipt delivery.'
                );
            }
        }
    }
}
