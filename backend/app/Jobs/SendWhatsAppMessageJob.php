<?php

namespace App\Jobs;

use App\Domain\Tenant\TenantManager;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessageJob implements ShouldQueue
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
        public string $messageId
    ) {
        $this->afterCommit = true;
    }

    /**
     * Execute the job.
     */
    public function handle(
        WhatsAppNotificationService $notificationService,
        WhatsAppProviderManager $providerManager,
        SubscriptionEntitlementService $entitlementService,
        TenantManager $tenantManager
    ): void {
        /** @var WhatsAppMessage|null $message */
        $message = WhatsAppMessage::find($this->messageId);
        if (! $message) {
            Log::info("WhatsApp dispatch job skipped: Message {$this->messageId} not found.");

            return;
        }

        // Terminal State Invariant
        if (in_array($message->status, [
            WhatsAppMessage::STATUS_SENT,
            WhatsAppMessage::STATUS_DELIVERED,
            WhatsAppMessage::STATUS_READ,
        ], true)) {
            return;
        }

        $tenant = $message->tenant ?? Tenant::find($message->tenant_id);
        if (! $tenant) {
            $message->markFailed('TENANT_NOT_FOUND', 'Tenant no longer exists.');

            return;
        }

        $tenantManager->runForTenant($tenant, function () use (
            $message,
            $tenant,
            $providerManager,
            $entitlementService,
            $notificationService
        ) {
            // 1. Subscription Entitlement Verification
            if (! $entitlementService->can($tenant, 'whatsapp')) {
                Log::info("WhatsApp dispatch job skipped for tenant {$tenant->id}: Not entitled to WhatsApp.");
                $message->markFailed('FEATURE_UNENTITLED', 'Tenant subscription does not include WhatsApp module.');

                return;
            }

            // 2. Resolve Recipient Phone
            $rawPhone = $message->recipient_phone;
            if (empty($rawPhone)) {
                $message->markFailed('INVALID_PHONE', 'No recipient phone provided.');

                return;
            }

            $recipientPhone = PhoneNormalizer::normalize($rawPhone);
            if (! PhoneNormalizer::isValid($recipientPhone)) {
                $message->markFailed('INVALID_PHONE', "Invalid recipient phone format: {$rawPhone}");

                return;
            }

            // 3. Customer Consent & Opt-Out Enforcement (For Customer-directed messages)
            if ($message->customer_id || in_array($message->message_type, [
                WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                WhatsAppMessage::TYPE_ORDER_CONFIRMATION,
                WhatsAppMessage::TYPE_BOOKING_CONFIRMATION,
                WhatsAppMessage::TYPE_BOOKING_REMINDER,
                WhatsAppMessage::TYPE_ORDER_STATUS_UPDATE,
                WhatsAppMessage::TYPE_MARKETING_PROMO,
            ], true)) {
                $customer = $message->customer ?? Customer::find($message->customer_id);

                if (! $customer) {
                    $localPhone = preg_replace('/^\+62/', '0', $recipientPhone);
                    $customer = Customer::query()
                        ->where('tenant_id', $tenant->id)
                        ->where(function ($q) use ($recipientPhone, $localPhone) {
                            $q->where('phone', $recipientPhone)
                                ->orWhere('phone', $localPhone);
                        })
                        ->first();
                }

                if (! $customer || ! $customer->isEligibleForWhatsApp($message->message_type, $tenant->id)) {
                    $failureCode = ($customer && $customer->whatsapp_opted_out)
                        ? 'CUSTOMER_OPTED_OUT'
                        : (($message->message_type === WhatsAppMessage::TYPE_MARKETING_PROMO && $customer && ! $customer->marketing_opt_in)
                            ? 'MARKETING_OPT_IN_REQUIRED'
                            : 'CONSENT_REQUIRED');

                    Log::info("WhatsApp dispatch skipped for message {$message->id}: Recipient {$recipientPhone} ineligible ({$failureCode}).");
                    $message->markFailed($failureCode, 'Recipient is not eligible or has opted out of WhatsApp communication.');

                    return;
                }
            }

            // 3b. Merchant Preference & Positive Consent Re-Check (Prior to Delivery)
            if ($message->isMerchantMessage()) {
                $prefKey = match ($message->message_type) {
                    WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY => 'daily_sales_summary',
                    WhatsAppMessage::TYPE_MERCHANT_LOW_STOCK => 'low_stock',
                    WhatsAppMessage::TYPE_MERCHANT_BOOKING_REMINDER => 'booking_reminder',
                    WhatsAppMessage::TYPE_MERCHANT_SETTLEMENT => 'payment_settlement',
                    WhatsAppMessage::TYPE_MERCHANT_ANOMALY => 'anomaly_alert',
                    WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY => 'merchant_query_reply',
                    default => null,
                };

                if ($prefKey && ! $notificationService->isMerchantNotificationEnabled($tenant, $prefKey)) {
                    Log::info("WhatsApp dispatch skipped for message {$message->id}: Merchant disabled '{$prefKey}' notification or revoked consent.");
                    $message->markFailed('MERCHANT_OPTED_OUT', "Merchant has disabled '{$prefKey}' notifications or revoked consent.");

                    return;
                }
            }

            // 4. Resolve Active Provider Connection
            $resolved = $providerManager->resolveForTenant($tenant, $message->provider !== 'pending' ? $message->provider : null);
            /** @var TenantWhatsAppConnection|null $connection */
            $connection = $resolved['connection'];
            $provider = $resolved['provider'];

            if (! $connection || ! $connection->isActive()) {
                Log::info("WhatsApp dispatch skipped for tenant {$tenant->id}: No active connection.");
                $message->markFailed('NO_ACTIVE_CONNECTION', 'No active WhatsApp connection configured.');

                return;
            }

            // 5. Construct Payload
            $payloadData = $message->payload ?? [];
            $summary = $payloadData['summary'] ?? $payloadData['body'] ?? 'Notifikasi SeWaroeng';
            $templateParameters = $payloadData['template_parameters'] ?? [];

            $payload = new WhatsAppMessagePayload(
                recipientPhone: $recipientPhone,
                messageType: $message->message_type,
                templateName: $message->template_name,
                templateParameters: $templateParameters,
                textBody: $summary,
                metadata: array_merge($payloadData['metadata'] ?? [], ['message_id' => $message->id])
            );

            // 5b. Customer Service Window Enforcement (24-Hour Rule for Inbound Replies)
            if ($message->message_type === WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY) {
                $inboundTimestamp = $payloadData['inbound_timestamp'] ?? $message->created_at?->toIso8601String();
                $inboundTime = $inboundTimestamp ? Carbon::parse($inboundTimestamp) : $message->created_at;

                if ($inboundTime && $inboundTime->diffInSeconds(now()) >= 86400) {
                    $queryTemplate = $connection->configuration['query_reply_template'] ?? null;
                    $templatesConfig = $connection->configuration['templates'] ?? [];
                    $templateDef = $templatesConfig[$queryTemplate] ?? null;
                    $templateStatus = is_array($templateDef) ? ($templateDef['status'] ?? null) : $templateDef;
                    $isEnabled = is_array($templateDef) ? ($templateDef['enabled'] ?? true) : true;

                    if (! $queryTemplate || ! $templateDef || strtoupper((string) $templateStatus) !== 'APPROVED' || ! $isEnabled) {
                        $errorCode = (! $queryTemplate)
                            ? 'EXPIRED_SERVICE_WINDOW'
                            : (! $templateDef
                                ? 'TEMPLATE_MISSING'
                                : (! $isEnabled
                                    ? 'TEMPLATE_DISABLED'
                                    : match (strtoupper((string) $templateStatus)) {
                                        'REJECTED' => 'TEMPLATE_REJECTED',
                                        'PENDING' => 'TEMPLATE_PENDING',
                                        'PAUSED' => 'TEMPLATE_PAUSED',
                                        'DISABLED' => 'TEMPLATE_DISABLED',
                                        default => 'TEMPLATE_NOT_APPROVED',
                                    }));
                        $errorMessage = (! $queryTemplate)
                            ? 'Jendela layanan 24 jam telah kedaluwarsa. Balasan tidak dapat dikirim tanpa template yang disetujui.'
                            : "Template '{$queryTemplate}' tidak dalam status APPROVED dan aktif (status: '{$templateStatus}').";

                        $message->markFailed(
                            errorCode: $errorCode,
                            errorMessage: $errorMessage
                        );
                        Log::warning("WhatsApp query reply {$message->id} aborted: {$errorMessage}");

                        return;
                    }

                    $payload = new WhatsAppMessagePayload(
                        recipientPhone: $recipientPhone,
                        messageType: $message->message_type,
                        templateName: $queryTemplate,
                        templateParameters: [$summary],
                        textBody: $summary,
                        metadata: array_merge($payloadData['metadata'] ?? [], ['message_id' => $message->id])
                    );
                }
            }

            // 6. Dispatch to Provider
            $message->markSending();

            $result = $provider->sendMessage($connection, $payload);

            if ($result->success && $result->providerMessageId) {
                $message->markSent($result->providerMessageId);
            } else {
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
                }
            }
        });
    }
}
