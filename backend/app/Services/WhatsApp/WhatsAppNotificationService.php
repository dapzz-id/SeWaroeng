<?php

namespace App\Services\WhatsApp;

use App\Domain\Tenant\TenantManager;
use App\Jobs\SendWhatsAppMessageJob;
use App\Jobs\SendWhatsAppReceiptJob;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\InventoryItemLocation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Models\WhatsAppMessage;
use App\Services\ReceiptService;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
            'payload' => [
                'sale_id' => $sale->id,
                'override_phone' => $overridePhone,
            ],
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
        $dispatchReceipt = function () use ($payment) {
            try {
                $sale = $payment->sale ?? Sale::find($payment->sale_id);
                if (! $sale || ! $sale->isFinalized()) {
                    return;
                }

                // Only send if fully paid
                if ($sale->getPaidAmountMinor() >= $sale->total_minor) {
                    $this->sendReceipt($sale);
                }
            } catch (\Throwable $e) {
                Log::warning("WhatsApp receipt dispatch failed on payment {$payment->id}: {$e->getMessage()}");
            }
        };

        if (DB::transactionLevel() > 0 && (! app()->environment('testing') || config('whatsapp.force_after_commit', false))) {
            DB::afterCommit($dispatchReceipt);
        } else {
            $dispatchReceipt();
        }
    }

    /**
     * Handle incoming domain event for SaleFinalized.
     * Defers dispatch until after database transaction commits.
     */
    public function handleSaleFinalized(Sale $sale): void
    {
        $dispatchReceipt = function () use ($sale) {
            try {
                if ($sale->getPaidAmountMinor() >= $sale->total_minor) {
                    $this->sendReceipt($sale);
                }
            } catch (\Throwable $e) {
                Log::warning("WhatsApp receipt dispatch failed on finalize for sale {$sale->id}: {$e->getMessage()}");
            }
        };

        if (DB::transactionLevel() > 0 && (! app()->environment('testing') || config('whatsapp.force_after_commit', false))) {
            DB::afterCommit($dispatchReceipt);
        } else {
            $dispatchReceipt();
        }
    }

    /**
     * Handle incoming delivery status webhook from provider.
     * Enforces strict tenant isolation and monotonic state transitions.
     */
    public function handleWebhookEvent(string $tenantId, string $providerName, WhatsAppWebhookEvent $event): ?WhatsAppMessage
    {
        // 1. Strict Tenant Isolation & Crash Correlation: Match by provider_message_id or callbackData
        /** @var WhatsAppMessage|null $message */
        $messageQuery = WhatsAppMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if (! empty($event->callbackData)) {
            $messageQuery->where(function ($q) use ($event) {
                $q->where('provider_message_id', $event->providerMessageId)
                    ->orWhere('id', $event->callbackData)
                    ->orWhere('idempotency_key', $event->callbackData);
            });
        } else {
            $messageQuery->where('provider_message_id', $event->providerMessageId);
        }

        $message = $messageQuery->first();

        if (! $message) {
            Log::warning("WhatsApp webhook rejected: provider_message_id '{$event->providerMessageId}' does not belong to tenant '{$tenantId}'.");

            return null;
        }

        // Correlate provider_message_id if worker crashed before recording provider response
        if (empty($message->provider_message_id)) {
            $message->provider_message_id = $event->providerMessageId;
            $message->save();
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
                if (in_array($message->status, [
                    WhatsAppMessage::STATUS_SENDING,
                    WhatsAppMessage::STATUS_PENDING,
                    WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY,
                ], true)) {
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
     * Handle incoming inbound messages from customer or merchant.
     * Enforces tenant isolation, sender authentication, safe natural language business query mapping,
     * and inbound keyword opt-out (STOP/UNSUBSCRIBE).
     */
    public function handleInboundMessage(string $tenantId, string $providerName, WhatsAppWebhookEvent $event): ?WhatsAppMessage
    {
        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $event) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant || ! $this->entitlementService->can($tenant, 'whatsapp')) {
                Log::info("Inbound WhatsApp message skipped: Tenant {$tenantId} missing or unentitled.");

                return null;
            }

            if (empty($event->senderPhone)) {
                return null;
            }

            $senderPhone = PhoneNormalizer::normalize($event->senderPhone);
            $rawText = trim($event->messageText ?? '');
            $normalizedText = strtolower($rawText);

            // 1. Inbound Keyword Opt-Out Handling (STOP / UNSUBSCRIBE / BERHENTI)
            if (in_array($normalizedText, ['stop', 'unsubscribe', 'berhenti'], true)) {
                $localPhone = preg_replace('/^\+62/', '0', $senderPhone);
                $customer = Customer::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q) use ($senderPhone, $localPhone) {
                        $q->where('phone', $senderPhone)
                            ->orWhere('phone', $localPhone);
                    })
                    ->first();

                if ($customer) {
                    $customer->optOutWhatsApp('inbound_keyword', "User sent inbound '{$rawText}' keyword.", $tenantId);
                    Log::info("Customer {$customer->id} successfully opted out via inbound WhatsApp keyword.");
                }

                return null;
            }

            // 1b. Duplicate Inbound Webhook Idempotency Check
            $idempotencyKey = "whatsapp:inbound_reply:{$event->providerMessageId}";
            $existingReply = WhatsAppMessage::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingReply) {
                Log::info("Duplicate inbound webhook message {$event->providerMessageId} ignored.");

                return $existingReply;
            }

            // 1c. Inbound Query Rate Limiting (20 queries per minute per sender)
            $rateKey = "whatsapp_inbound:{$tenantId}:{$senderPhone}";
            if (RateLimiter::tooManyAttempts($rateKey, 20)) {
                Log::warning("Inbound WhatsApp query rate limit exceeded for sender {$senderPhone} on tenant {$tenantId}.");

                return null;
            }
            RateLimiter::hit($rateKey, 60);

            // 2. Validate Merchant Identity for PRD Section 19 Business Queries
            $merchantPhone = $this->getMerchantPhone($tenant);
            $normalizedMerchantPhone = $merchantPhone ? PhoneNormalizer::normalize($merchantPhone) : null;

            $isAuthorizedMerchant = false;
            $merchantUser = null;

            if ($normalizedMerchantPhone && $normalizedMerchantPhone === $senderPhone) {
                $isAuthorizedMerchant = true;
                $merchantUser = $tenant->owner;
            } else {
                $localPhone = preg_replace('/^\+62/', '0', $senderPhone);
                $user = User::query()
                    ->where(function ($q) use ($senderPhone, $localPhone) {
                        $q->where('phone_number', $senderPhone)
                            ->orWhere('phone_number', $localPhone);
                    })
                    ->first();

                if ($user) {
                    $membership = UserTenantMembership::query()
                        ->where('tenant_id', $tenantId)
                        ->where('user_id', $user->id)
                        ->where('status', 'active')
                        ->first();

                    if ($membership && in_array($membership->role?->slug, ['owner', 'manager'], true)) {
                        $isAuthorizedMerchant = true;
                        $merchantUser = $user;
                    }
                }
            }

            if (! $isAuthorizedMerchant) {
                Log::warning("Inbound WhatsApp message rejected: Sender '{$senderPhone}' is not an authorized merchant/owner for tenant '{$tenantId}'.");

                return null;
            }

            // 3. Positive Merchant Consent Re-check
            if (! $this->isMerchantNotificationEnabled($tenant, 'merchant_query_reply')) {
                Log::info('Inbound WhatsApp reply skipped: Merchant has not granted WhatsApp consent.');

                return null;
            }

            // 4. Execute Deterministic Safe Business Query Parser
            /** @var WhatsAppBusinessQueryService $queryService */
            $queryService = app(WhatsAppBusinessQueryService::class);
            $queryResult = $queryService->executeQuery($tenant, $rawText, $merchantUser);

            $inboundTimestamp = $event->timestamp
                ? (is_numeric($event->timestamp) ? Carbon::createFromTimestamp((int) $event->timestamp)->toIso8601String() : $event->timestamp)
                : now()->toIso8601String();

            // 5. Send reply within WhatsApp 24-hour service window
            return $this->queueGenericMessage(
                tenant: $tenant,
                rawPhone: $senderPhone,
                messageType: WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY,
                idempotencyKey: $idempotencyKey,
                textBody: $queryResult['reply_text'],
                payload: [
                    'inbound_provider_message_id' => $event->providerMessageId,
                    'inbound_timestamp' => $inboundTimestamp,
                    'query' => $rawText,
                    'intent' => $queryResult['intent'],
                    'query_success' => $queryResult['success'],
                ]
            );
        });
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

        // Check merchant consent & preferences for merchant-directed messages
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

            if ($prefKey && ! $this->isMerchantNotificationEnabled($tenant, $prefKey)) {
                throw new \RuntimeException("Merchant telah mematikan notifikasi '{$prefKey}' atau mencabut persetujuan WhatsApp.");
            }
        }

        $resolved = $this->providerManager->resolveForTenant($tenant, $message->provider);
        $connection = $resolved['connection'];
        $provider = $resolved['provider'];

        if (! $connection || ! $connection->isActive()) {
            throw new \RuntimeException('Tidak ada koneksi WhatsApp yang aktif untuk tenant ini.');
        }

        $templateName = $message->template_name;
        $summary = $message->payload['summary'] ?? ($message->payload['body'] ?? 'Nota Transaksi SeWaroeng');
        $templateParams = [];

        // Check 24-hour customer service window for session/query replies
        if ($message->message_type === WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY) {
            $inboundTimestamp = $message->payload['inbound_timestamp'] ?? $message->created_at?->toIso8601String();
            $inboundTime = $inboundTimestamp ? Carbon::parse($inboundTimestamp) : $message->created_at;

            if ($inboundTime && $inboundTime->diffInSeconds(now()) >= 86400) {
                $queryTemplate = $connection->configuration['query_reply_template'] ?? null;
                $templatesConfig = $connection->configuration['templates'] ?? [];
                $templateDef = $templatesConfig[$queryTemplate] ?? null;
                $templateStatus = is_array($templateDef) ? ($templateDef['status'] ?? null) : $templateDef;
                $isEnabled = is_array($templateDef) ? ($templateDef['enabled'] ?? true) : true;

                if (! $queryTemplate || ! $templateDef || strtoupper((string) $templateStatus) !== 'APPROVED' || ! $isEnabled) {
                    $reason = (! $queryTemplate)
                        ? 'Jendela layanan 24 jam telah kedaluwarsa. Balasan tidak dapat dikirim tanpa template yang disetujui.'
                        : "Template balasan '{$queryTemplate}' tidak dalam status APPROVED dan aktif.";
                    throw new \RuntimeException($reason);
                }

                $templateName = $queryTemplate;
                $templateParams = [$summary];
            }
        }

        // Validate template eligibility fail-closed
        if ($templateName) {
            $templatesConfig = $connection->configuration['templates'] ?? [];
            if ($connection->provider === 'meta_cloud' || ! empty($templatesConfig)) {
                $templateDef = $templatesConfig[$templateName] ?? null;
                $templateStatus = is_array($templateDef) ? ($templateDef['status'] ?? null) : $templateDef;
                $isEnabled = is_array($templateDef) ? ($templateDef['enabled'] ?? true) : true;

                if (! $templateDef || strtoupper((string) $templateStatus) !== 'APPROVED' || ! $isEnabled) {
                    throw new \RuntimeException("Template '{$templateName}' tidak memenuhi syarat pengiriman (status harus APPROVED dan aktif).");
                }
            }
        } elseif ($connection->provider === 'meta_cloud') {
            // Freeform text prohibited outside 24h window
            if ($message->message_type !== WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY) {
                throw new \RuntimeException('Pesan teks bebas (free-form) dilarang di luar jendela layanan 24 jam. Diperlukan template yang disetujui.');
            }
        }

        $payload = new WhatsAppMessagePayload(
            recipientPhone: $message->recipient_phone,
            messageType: $message->message_type,
            templateName: $templateName,
            templateParameters: $templateParams,
            textBody: $summary,
            metadata: ['resend' => true, 'message_id' => $message->id]
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

    /**
     * Resolve merchant notification phone number.
     */
    public function getMerchantPhone(Tenant $tenant, ?string $overridePhone = null): ?string
    {
        return $overridePhone
            ?? ($tenant->settings['whatsapp_notifications']['merchant_phone'] ?? null)
            ?? $tenant->phone
            ?? $tenant->owner?->phone_number;
    }

    /**
     * Check if specific merchant notification category is enabled in settings.
     * Enforces positive affirmative merchant consent: presence of phone alone does not constitute consent.
     */
    public function isMerchantNotificationEnabled(Tenant $tenant, string $key): bool
    {
        $prefs = $tenant->settings['whatsapp_notifications'] ?? null;
        if (! $prefs) {
            return false;
        }

        $consentGiven = (bool) ($prefs['consent_given'] ?? false);
        if (! $consentGiven) {
            return false;
        }

        if ($key === 'merchant_query_reply') {
            return true;
        }

        return (bool) ($prefs[$key] ?? false);
    }

    /**
     * Internal generic method to queue and asynchronously dispatch any WhatsApp message.
     */
    public function queueGenericMessage(
        Tenant $tenant,
        string $rawPhone,
        string $messageType,
        string $idempotencyKey,
        string $textBody,
        array $payload = [],
        ?string $outletId = null,
        ?Customer $customer = null,
        ?string $templateName = null,
        bool $isCustomerMessage = false
    ): ?WhatsAppMessage {
        // 1. Subscription Entitlement Check
        if (! $this->entitlementService->can($tenant, 'whatsapp')) {
            Log::info("WhatsApp dispatch skipped for tenant {$tenant->id}: WhatsApp feature not entitled.");

            return null;
        }

        // 2. Resolve & Normalize Recipient Phone
        $recipientPhone = PhoneNormalizer::normalize($rawPhone);
        if (! PhoneNormalizer::isValid($recipientPhone)) {
            Log::warning("WhatsApp dispatch skipped for tenant {$tenant->id}: Invalid phone format '{$rawPhone}'.");

            return null;
        }

        // 3. Customer Consent & Opt-Out Policy Check (if customer-directed message)
        if ($isCustomerMessage) {
            $effectiveCustomer = $customer;
            if (! $effectiveCustomer) {
                $localPhone = preg_replace('/^\+62/', '0', $recipientPhone);
                $effectiveCustomer = Customer::query()
                    ->where('tenant_id', $tenant->id)
                    ->where(function ($q) use ($recipientPhone, $localPhone) {
                        $q->where('phone', $recipientPhone)
                            ->orWhere('phone', $localPhone);
                    })
                    ->first();
            }

            if (! $effectiveCustomer || ! $effectiveCustomer->isEligibleForWhatsApp($messageType, $tenant->id)) {
                $failureCode = ($effectiveCustomer && $effectiveCustomer->whatsapp_opted_out)
                    ? 'CUSTOMER_OPTED_OUT'
                    : (($messageType === WhatsAppMessage::TYPE_MARKETING_PROMO && $effectiveCustomer && ! $effectiveCustomer->marketing_opt_in)
                        ? 'MARKETING_OPT_IN_REQUIRED'
                        : 'CONSENT_REQUIRED');

                Log::info("WhatsApp message skipped: Recipient {$recipientPhone} ineligible ({$failureCode}).");

                return $this->createGenericOptOutRecord(
                    $tenant,
                    $recipientPhone,
                    $messageType,
                    $idempotencyKey,
                    $failureCode,
                    $outletId,
                    $effectiveCustomer?->id
                );
            }
        }

        // 4. Idempotency Check
        /** @var WhatsAppMessage|null $existingMessage */
        $existingMessage = WhatsAppMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingMessage) {
            if (in_array($existingMessage->status, [
                WhatsAppMessage::STATUS_SENT,
                WhatsAppMessage::STATUS_DELIVERED,
                WhatsAppMessage::STATUS_READ,
                WhatsAppMessage::STATUS_SENDING,
            ], true)) {
                return $existingMessage;
            }
        }

        // 5. Create Durable Outbox Record
        $resolved = $this->providerManager->resolveForTenant($tenant);
        $providerName = $resolved['connection']?->provider ?? 'pending';

        $fullPayload = array_merge($payload, [
            'summary' => $textBody,
            'body' => $textBody,
        ]);

        $message = $existingMessage ?? new WhatsAppMessage([
            'tenant_id' => $tenant->id,
            'outlet_id' => $outletId,
            'customer_id' => $customer?->id,
            'recipient_phone' => $recipientPhone,
            'message_type' => $messageType,
            'template_name' => $templateName,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => $providerName,
            'idempotency_key' => $idempotencyKey,
            'payload' => $fullPayload,
            'attempts' => 0,
        ]);
        $message->save();

        // 6. Dispatch Asynchronous Queue Job with After-Commit Guard
        $job = new SendWhatsAppMessageJob($message->id);
        if (app()->environment('testing') && DB::transactionLevel() <= 1) {
            $job->afterCommit = false;
        }

        try {
            dispatch($job);

            if (app()->environment('testing') && ! config('queue.defer_whatsapp_in_testing', false)) {
                $job->handle(
                    $this,
                    $this->providerManager,
                    $this->entitlementService,
                    $this->tenantManager
                );
                $message = $message->fresh();
            }
        } catch (\Throwable $e) {
            Log::error("Failed to enqueue WhatsApp message job {$message->id}: {$e->getMessage()}");
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
     * Record opt-out failure for generic message.
     */
    protected function createGenericOptOutRecord(
        Tenant $tenant,
        string $recipientPhone,
        string $messageType,
        string $idempotencyKey,
        string $errorCode,
        ?string $outletId = null,
        ?string $customerId = null
    ): WhatsAppMessage {
        $errorMessage = match ($errorCode) {
            'CUSTOMER_OPTED_OUT' => 'Recipient has opted out of WhatsApp communication.',
            'MARKETING_OPT_IN_REQUIRED' => 'Recipient has not provided required positive marketing opt-in.',
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
                'outlet_id' => $outletId,
                'customer_id' => $customerId,
                'recipient_phone' => $recipientPhone,
                'message_type' => $messageType,
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
     * Queue daily sales summary notification to merchant owner.
     */
    public function queueDailySalesSummary(Tenant $tenant, ?string $date = null, ?string $overridePhone = null): ?WhatsAppMessage
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $date, $overridePhone) {
            if (! $this->isMerchantNotificationEnabled($tenant, 'daily_sales_summary')) {
                Log::info("WhatsApp daily sales summary skipped: Tenant {$tenant->id} has disabled this notification.");

                return null;
            }

            $merchantPhone = $this->getMerchantPhone($tenant, $overridePhone);
            if (! $merchantPhone) {
                Log::info("WhatsApp daily sales summary skipped: No merchant phone for tenant {$tenant->id}.");

                return null;
            }

            $tz = $tenant->timezone ?? 'Asia/Jakarta';
            $dateStr = $date ?? Carbon::now($tz)->toDateString();
            $idempotencyKey = "whatsapp:daily_summary:{$tenant->id}:{$dateStr}";

            // Calculate authoritative sales summary strictly bounded by tenant business timezone
            $dayStart = Carbon::parse($dateStr, $tz)->startOfDay();
            $dayEnd = Carbon::parse($dateStr, $tz)->endOfDay();

            $sales = Sale::where('tenant_id', $tenant->id)
                ->where('status', Sale::STATUS_FINALIZED)
                ->whereBetween('finalized_at', [$dayStart, $dayEnd])
                ->get();

            $totalMinor = $sales->sum('total_minor');
            $count = $sales->count();
            $avgTicketMinor = $count > 0 ? (int) round($totalMinor / $count) : 0;

            $lines = [];
            $lines[] = '*RINGKASAN PENJUALAN HARIAN*';
            $lines[] = "*{$tenant->name}*";
            $lines[] = "Tanggal: {$dateStr}";
            $lines[] = '--------------------------------';
            $lines[] = 'Total Omzet: Rp '.number_format($totalMinor / 100, 0, ',', '.');
            $lines[] = "Jumlah Transaksi: {$count}";
            $lines[] = 'Rata-rata Transaksi: Rp '.number_format($avgTicketMinor / 100, 0, ',', '.');
            $lines[] = '--------------------------------';
            $lines[] = 'SeWaroeng Business Operating System';

            $body = implode("\n", $lines);

            return $this->queueGenericMessage(
                tenant: $tenant,
                rawPhone: $merchantPhone,
                messageType: WhatsAppMessage::TYPE_MERCHANT_DAILY_SUMMARY,
                idempotencyKey: $idempotencyKey,
                textBody: $body,
                payload: [
                    'date' => $dateStr,
                    'total_minor' => $totalMinor,
                    'transaction_count' => $count,
                    'average_ticket_minor' => $avgTicketMinor,
                ]
            );
        });
    }

    /**
     * Queue low stock alert notification to merchant owner.
     */
    public function queueLowStockAlert(Tenant $tenant, InventoryItemLocation $itemLoc): ?WhatsAppMessage
    {
        if (! $this->isMerchantNotificationEnabled($tenant, 'low_stock')) {
            return null;
        }

        $merchantPhone = $this->getMerchantPhone($tenant);
        if (! $merchantPhone) {
            return null;
        }

        $item = $itemLoc->item;
        $location = $itemLoc->location;
        $today = Carbon::now($tenant->timezone ?? 'Asia/Jakarta')->toDateString();
        $idempotencyKey = "whatsapp:low_stock:{$tenant->id}:{$itemLoc->id}:{$today}";

        $lines = [];
        $lines[] = '*PERINGATAN STOK MENIPIS*';
        $lines[] = "*{$tenant->name}*";
        $lines[] = '--------------------------------';
        $lines[] = "Item: {$item->name} (SKU: {$item->sku})";
        $lines[] = "Lokasi: {$location->name}";
        $lines[] = "Stok Tersedia: {$itemLoc->available} {$item->base_unit}";
        $lines[] = "Ambang Batas Minimum: {$itemLoc->reorder_level} {$item->base_unit}";
        $lines[] = '--------------------------------';
        $lines[] = 'Mohon segera lakukan pengadaan barang / purchase order.';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $merchantPhone,
            messageType: WhatsAppMessage::TYPE_MERCHANT_LOW_STOCK,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: [
                'inventory_item_id' => $item->id,
                'location_id' => $location->id,
                'available' => $itemLoc->available,
                'reorder_level' => $itemLoc->reorder_level,
            ],
            outletId: $location->outlet_id
        );
    }

    /**
     * Queue booking reminder notification for merchant.
     */
    public function queueMerchantBookingReminder(Booking $booking): ?WhatsAppMessage
    {
        $tenant = $booking->tenant ?? Tenant::find($booking->tenant_id);
        if (! $tenant || ! $this->isMerchantNotificationEnabled($tenant, 'booking_reminder')) {
            return null;
        }

        if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED, Booking::STATUS_NO_SHOW], true)) {
            Log::info("Merchant booking reminder skipped: Booking {$booking->id} has inactive status {$booking->status}.");

            return null;
        }

        $merchantPhone = $this->getMerchantPhone($tenant);
        if (! $merchantPhone) {
            return null;
        }

        $dateKey = $booking->starts_at->toDateString();
        $idempotencyKey = "whatsapp:merchant_booking_reminder:{$booking->id}:{$dateKey}";

        $lines = [];
        $lines[] = '*PENGINGAT RESERVASI (MERCHANT)*';
        $lines[] = "*{$tenant->name}*";
        $lines[] = '--------------------------------';
        $lines[] = "Kode Reservasi: {$booking->booking_code}";
        $lines[] = "Pelanggan: {$booking->customer_name_snapshot} ({$booking->customer_contact_snapshot})";
        $lines[] = 'Meja: '.($booking->table?->name ?? 'Meja Reservasi');
        $lines[] = "Jumlah Tamu: {$booking->party_size} Orang";
        $lines[] = "Waktu: {$booking->starts_at->format('d M Y H:i')} - {$booking->ends_at->format('H:i')}";
        $lines[] = 'Catatan: '.($booking->notes ?? '-');
        $lines[] = '--------------------------------';
        $lines[] = 'Mohon persiapkan meja sebelum tamu tiba.';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $merchantPhone,
            messageType: WhatsAppMessage::TYPE_MERCHANT_BOOKING_REMINDER,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code],
            outletId: $booking->outlet_id
        );
    }

    /**
     * Queue payment settlement status notification for merchant.
     */
    public function queuePaymentSettlementAlert(Tenant $tenant, array $settlementData): ?WhatsAppMessage
    {
        if (! $this->isMerchantNotificationEnabled($tenant, 'payment_settlement')) {
            return null;
        }

        $merchantPhone = $this->getMerchantPhone($tenant);
        if (! $merchantPhone) {
            return null;
        }

        $ref = $settlementData['reference'] ?? uniqid('settle_');
        $idempotencyKey = "whatsapp:settlement:{$tenant->id}:{$ref}";

        $provider = $settlementData['provider'] ?? 'Payment Gateway';
        $amountMinor = $settlementData['settled_amount_minor'] ?? $settlementData['amount_minor'] ?? 0;
        $amountFormatted = 'Rp '.number_format($amountMinor / 100, 0, ',', '.');
        $count = $settlementData['transaction_count'] ?? 1;

        $lines = [];
        $lines[] = '*STATUS SETTLEMENT PEMBAYARAN*';
        $lines[] = "*{$tenant->name}*";
        $lines[] = '--------------------------------';
        $lines[] = "Referensi: {$ref}";
        $lines[] = "Penyedia: {$provider}";
        $lines[] = "Jumlah Bersih: {$amountFormatted}";
        $lines[] = "Jumlah Transaksi: {$count}";
        $lines[] = 'Status: Berhasil Dicairkan (SETTLED)';
        $lines[] = '--------------------------------';
        $lines[] = 'Dana settlement telah diproses ke rekening merchant.';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $merchantPhone,
            messageType: WhatsAppMessage::TYPE_MERCHANT_SETTLEMENT,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: $settlementData
        );
    }

    /**
     * Queue operational anomaly alert notification for merchant.
     */
    public function queueAnomalyAlert(Tenant $tenant, string $anomalyType, string $description, array $context = []): ?WhatsAppMessage
    {
        if (! $this->isMerchantNotificationEnabled($tenant, 'anomaly_alert')) {
            return null;
        }

        $merchantPhone = $this->getMerchantPhone($tenant);
        if (! $merchantPhone) {
            return null;
        }

        $refId = $context['reference_id'] ?? uniqid('anom_');
        $idempotencyKey = "whatsapp:anomaly:{$tenant->id}:{$anomalyType}:{$refId}";

        $lines = [];
        $lines[] = '*PERINGATAN ANOMALI OPERASIONAL*';
        $lines[] = "*{$tenant->name}*";
        $lines[] = '--------------------------------';
        $lines[] = "Tipe Anomali: {$anomalyType}";
        $lines[] = "Deskripsi: {$description}";
        $lines[] = 'Waktu: '.now()->format('d M Y H:i:s');
        $lines[] = '--------------------------------';
        $lines[] = 'Mohon periksa log operasional dan rekonsiliasi kas segera.';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $merchantPhone,
            messageType: WhatsAppMessage::TYPE_MERCHANT_ANOMALY,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: array_merge($context, [
                'anomaly_type' => $anomalyType,
                'description' => $description,
            ])
        );
    }

    /**
     * Queue customer order confirmation message.
     */
    public function queueOrderConfirmation(Order $order): ?WhatsAppMessage
    {
        $tenant = $order->tenant ?? Tenant::find($order->tenant_id);
        if (! $tenant) {
            return null;
        }

        $phone = $order->customer?->phone ?? $order->customer_contact_snapshot;
        if (! $phone) {
            return null;
        }

        $idempotencyKey = "whatsapp:order_confirmation:{$order->id}";
        $outletName = $order->outlet?->name ?? 'SeWaroeng';
        $totalFormatted = 'Rp '.number_format($order->total_minor / 100, 0, ',', '.');

        $lines = [];
        $lines[] = '*KONFIRMASI PESANAN*';
        $lines[] = "*{$outletName}*";
        $lines[] = "No. Pesanan: {$order->order_number}";
        $lines[] = '--------------------------------';
        $lines[] = "Status: Pesanan Diterima ({$order->status})";
        $lines[] = "Total: {$totalFormatted}";
        if ($order->customer_notes) {
            $lines[] = "Catatan: {$order->customer_notes}";
        }
        $lines[] = '--------------------------------';
        $lines[] = 'Terima kasih telah memesan!';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $phone,
            messageType: WhatsAppMessage::TYPE_ORDER_CONFIRMATION,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: ['order_id' => $order->id, 'order_number' => $order->order_number],
            outletId: $order->outlet_id,
            customer: $order->customer,
            isCustomerMessage: true
        );
    }

    /**
     * Queue customer booking confirmation message.
     */
    public function queueBookingConfirmation(Booking $booking): ?WhatsAppMessage
    {
        $tenant = $booking->tenant ?? Tenant::find($booking->tenant_id);
        if (! $tenant) {
            return null;
        }

        $phone = $booking->customer?->phone ?? $booking->customer_contact_snapshot;
        if (! $phone) {
            return null;
        }

        $idempotencyKey = "whatsapp:booking_confirmation:{$booking->id}";
        $outletName = $booking->outlet?->name ?? 'SeWaroeng';

        $lines = [];
        $lines[] = '*KONFIRMASI RESERVASI*';
        $lines[] = "*{$outletName}*";
        $lines[] = '--------------------------------';
        $lines[] = "Kode Reservasi: {$booking->booking_code}";
        $lines[] = "Nama: {$booking->customer_name_snapshot}";
        $lines[] = "Waktu: {$booking->starts_at->format('d M Y H:i')}";
        $lines[] = 'Meja: '.($booking->table?->name ?? 'Reservasi Meja');
        $lines[] = "Jumlah Tamu: {$booking->party_size} Orang";
        $lines[] = '--------------------------------';
        $lines[] = 'Sampai jumpa di outlet kami!';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $phone,
            messageType: WhatsAppMessage::TYPE_BOOKING_CONFIRMATION,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code],
            outletId: $booking->outlet_id,
            customer: $booking->customer,
            isCustomerMessage: true
        );
    }

    /**
     * Queue customer booking reminder message.
     */
    public function queueCustomerBookingReminder(Booking $booking): ?WhatsAppMessage
    {
        $tenant = $booking->tenant ?? Tenant::find($booking->tenant_id);
        if (! $tenant) {
            return null;
        }

        if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED, Booking::STATUS_NO_SHOW], true)) {
            Log::info("Customer booking reminder skipped: Booking {$booking->id} has inactive status {$booking->status}.");

            return null;
        }

        $phone = $booking->customer?->phone ?? $booking->customer_contact_snapshot;
        if (! $phone) {
            return null;
        }

        $dateKey = $booking->starts_at->toDateString();
        $idempotencyKey = "whatsapp:booking_reminder:{$booking->id}:{$dateKey}";
        $outletName = $booking->outlet?->name ?? 'SeWaroeng';

        $lines = [];
        $lines[] = '*PENGINGAT RESERVASI*';
        $lines[] = "*{$outletName}*";
        $lines[] = '--------------------------------';
        $lines[] = "Mengingatkan reservasi Anda untuk kode: {$booking->booking_code}";
        $lines[] = "Waktu: {$booking->starts_at->format('d M Y H:i')}";
        $lines[] = 'Meja: '.($booking->table?->name ?? 'Reservasi Meja');
        $lines[] = "Jumlah Tamu: {$booking->party_size} Orang";
        $lines[] = '--------------------------------';
        $lines[] = 'Kami menantikan kehadiran Anda!';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $phone,
            messageType: WhatsAppMessage::TYPE_BOOKING_REMINDER,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code],
            outletId: $booking->outlet_id,
            customer: $booking->customer,
            isCustomerMessage: true
        );
    }

    /**
     * Queue promotional marketing message to customer.
     * Enforces strict affirmative marketing opt-in policy.
     */
    public function queuePromotion(Tenant $tenant, Customer $customer, string $promoTitle, string $promoBody, ?string $campaignId = null): ?WhatsAppMessage
    {
        $phone = $customer->phone;
        if (! $phone) {
            return null;
        }

        $campaignKey = $campaignId ?? 'general';
        $idempotencyKey = "whatsapp:promo:{$campaignKey}:{$customer->id}";

        $lines = [];
        $lines[] = '*PROMO SPESIAL*';
        $lines[] = "*{$tenant->name}*";
        $lines[] = "*{$promoTitle}*";
        $lines[] = '--------------------------------';
        $lines[] = $promoBody;
        $lines[] = '--------------------------------';
        $lines[] = 'Ketik STOP untuk berhenti menerima penawaran promo.';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $phone,
            messageType: WhatsAppMessage::TYPE_MARKETING_PROMO,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: [
                'campaign_id' => $campaignId,
                'title' => $promoTitle,
            ],
            customer: $customer,
            isCustomerMessage: true
        );
    }

    /**
     * Queue customer order status update message.
     */
    public function queueOrderStatusUpdate(Order $order, string $newStatus): ?WhatsAppMessage
    {
        $tenant = $order->tenant ?? Tenant::find($order->tenant_id);
        if (! $tenant) {
            return null;
        }

        $phone = $order->customer?->phone ?? $order->customer_contact_snapshot;
        if (! $phone) {
            return null;
        }

        $idempotencyKey = "whatsapp:order_status:{$order->id}:{$newStatus}";
        $outletName = $order->outlet?->name ?? 'SeWaroeng';

        $statusLabel = match ($newStatus) {
            'accepted' => 'Pesanan Telah Diterima Dapur',
            'preparing' => 'Pesanan Sedang Disiapkan',
            'ready' => 'Pesanan Sudah Siap Diambil / Diantar',
            'served' => 'Pesanan Telah Disajikan',
            'cancelled' => 'Pesanan Dibatalkan',
            default => "Status: {$newStatus}",
        };

        $lines = [];
        $lines[] = '*UPDATE STATUS PESANAN*';
        $lines[] = "*{$outletName}*";
        $lines[] = "No. Pesanan: {$order->order_number}";
        $lines[] = '--------------------------------';
        $lines[] = $statusLabel;
        $lines[] = '--------------------------------';
        $lines[] = 'Terima kasih atas kesabaran Anda!';

        $body = implode("\n", $lines);

        return $this->queueGenericMessage(
            tenant: $tenant,
            rawPhone: $phone,
            messageType: WhatsAppMessage::TYPE_ORDER_STATUS_UPDATE,
            idempotencyKey: $idempotencyKey,
            textBody: $body,
            payload: ['order_id' => $order->id, 'new_status' => $newStatus],
            outletId: $order->outlet_id,
            customer: $order->customer,
            isCustomerMessage: true
        );
    }
}
