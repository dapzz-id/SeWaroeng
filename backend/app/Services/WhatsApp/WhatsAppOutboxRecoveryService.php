<?php

namespace App\Services\WhatsApp;

use App\Domain\Tenant\TenantManager;
use App\Jobs\SendWhatsAppMessageJob;
use App\Jobs\SendWhatsAppReceiptJob;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\InventoryItemLocation;
use App\Models\Order;
use App\Models\PaymentReconciliation;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\ReceiptService;
use App\Services\Subscription\SubscriptionEntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppOutboxRecoveryService
{
    public const DEFAULT_STUCK_THRESHOLD_MINUTES = 2;

    public const DEFAULT_IN_FLIGHT_THRESHOLD_MINUTES = 15;

    public const MAX_ATTEMPTS = 3;

    public function __construct(
        protected WhatsAppNotificationService $notificationService,
        protected WhatsAppProviderManager $providerManager,
        protected SubscriptionEntitlementService $entitlementService,
        protected ReceiptService $receiptService,
        protected TenantManager $tenantManager
    ) {}

    /**
     * Recover stuck outbox messages.
     *
     * Scans for:
     * 1. Records with status 'pending' that were never picked up or enqueued (created_at <= threshold).
     * 2. Records with status 'failed' due to queue enqueue broker outages ('ENQUEUE_FAILED') with attempts < MAX_ATTEMPTS.
     * 3. Records with status 'sending' stuck in flight due to crashed/killed worker processes without provider confirmation.
     *
     * @param  int  $limit  Maximum number of messages to process per recovery pass.
     * @param  int  $stuckThresholdMinutes  Age in minutes to avoid racing active transactions.
     * @param  bool  $sync  Whether to execute the dispatch job synchronously (default: false, dispatches via queue).
     * @return array{scanned: int, recovered: int, skipped: int, failed: int}
     */
    public function recoverStuckMessages(
        int $limit = 50,
        int $stuckThresholdMinutes = self::DEFAULT_STUCK_THRESHOLD_MINUTES,
        bool $sync = false
    ): array {
        $stats = [
            'scanned' => 0,
            'recovered' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $pendingThreshold = now()->subMinutes($stuckThresholdMinutes);
        $inFlightThreshold = now()->subMinutes(self::DEFAULT_IN_FLIGHT_THRESHOLD_MINUTES);

        // Fetch candidate messages in platform context to satisfy PostgreSQL RLS
        $queryCandidates = function () use ($pendingThreshold, $inFlightThreshold, $limit) {
            return WhatsAppMessage::withoutGlobalScopes()
                ->where(function ($query) use ($pendingThreshold, $inFlightThreshold) {
                    // 1. Pending messages older than threshold
                    $query->where(function ($q) use ($pendingThreshold) {
                        $q->where('status', WhatsAppMessage::STATUS_PENDING)
                            ->where('created_at', '<=', $pendingThreshold);
                    })
                    // 2. Failed messages due to enqueue failure
                        ->orWhere(function ($q) {
                            $q->where('status', WhatsAppMessage::STATUS_FAILED)
                                ->where('error_code', 'ENQUEUE_FAILED');
                        })
                    // 3. Messages stuck in sending due to crashed worker before request
                        ->orWhere(function ($q) use ($inFlightThreshold) {
                            $q->where('status', WhatsAppMessage::STATUS_SENDING)
                                ->whereNull('provider_message_id')
                                ->where('last_attempt_at', '<=', $inFlightThreshold);
                        });
                })
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->whereNotIn('status', [
                    WhatsAppMessage::STATUS_SENT,
                    WhatsAppMessage::STATUS_DELIVERED,
                    WhatsAppMessage::STATUS_READ,
                    WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY,
                ])
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();
        };

        try {
            DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
            try {
                $candidates = $queryCandidates();
            } finally {
                DB::statement("SELECT set_config('app.is_platform_context', '', true)");
            }
        } catch (\Throwable) {
            $candidates = $queryCandidates();
        }

        $stats['scanned'] = $candidates->count();

        // Scan and observe ambiguous delivery messages without blind auto-redispatch
        $stats['ambiguous_observed'] = WhatsAppMessage::withoutGlobalScopes()
            ->where('status', WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY)
            ->count();

        foreach ($candidates as $message) {
            try {
                $recovered = $this->recoverMessage($message, $sync);
                if ($recovered) {
                    $stats['recovered']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error("WhatsApp outbox recovery failed for message {$message->id}: {$e->getMessage()}", [
                    'message_id' => $message->id,
                    'tenant_id' => $message->tenant_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // Domain reconciliation for unrecorded domain events
        $stats['reconciled_receipts'] = $this->reconcileUnrecordedReceipts();
        $stats['reconciled_orders'] = $this->reconcileUnrecordedOrders();
        $stats['reconciled_bookings'] = $this->reconcileUnrecordedBookings();
        $stats['reconciled_low_stock'] = $this->reconcileUnrecordedLowStock();
        $stats['reconciled_shift_anomalies'] = $this->reconcileUnrecordedShiftAnomalies();
        $stats['reconciled_settlements'] = $this->reconcileUnrecordedSettlements();

        return $stats;
    }

    /**
     * Recover an individual outbox message with strict idempotency and tenant isolation.
     */
    public function recoverMessage(WhatsAppMessage $message, bool $sync = false): bool
    {
        // 1. Terminal State Invariant: Never resend confirmed delivered or read messages!
        if (in_array($message->status, [
            WhatsAppMessage::STATUS_SENT,
            WhatsAppMessage::STATUS_DELIVERED,
            WhatsAppMessage::STATUS_READ,
        ], true)) {
            return false;
        }

        // 1b. Ambiguous Delivery Invariant: Never blindly retransmit! Await webhook reconciliation or manual audit.
        if ($message->isAmbiguous() || $message->status === WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY) {
            Log::info("Recovery skipped for ambiguous delivery message {$message->id}: awaiting provider webhook or manual operator resolution.");

            return false;
        }

        // 2. Resolve Tenant
        $tenant = Tenant::find($message->tenant_id);
        if (! $tenant) {
            $message->markFailed('TENANT_NOT_FOUND', 'Tenant no longer exists.');

            return false;
        }

        // 3. Execute inside Tenant Isolation Boundary
        return (bool) $this->tenantManager->runForTenant($tenant, function () use ($message, $tenant, $sync) {
            // Lock and acquire atomically to prevent concurrent race condition
            $affected = WhatsAppMessage::withoutGlobalScopes()
                ->where('id', $message->id)
                ->whereIn('status', [
                    WhatsAppMessage::STATUS_PENDING,
                    WhatsAppMessage::STATUS_FAILED,
                    WhatsAppMessage::STATUS_SENDING,
                ])
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->update([
                    'status' => WhatsAppMessage::STATUS_SENDING,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_attempt_at' => now(),
                ]);

            if ($affected === 0) {
                // Another concurrent worker already acquired or completed this message
                return false;
            }

            $message->refresh();

            // 4. Subscription Entitlement Check
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                $message->markFailed('FEATURE_UNENTITLED', 'Tenant subscription does not include WhatsApp module.');

                return false;
            }

            // 5. Positive Consent & Opt-Out Enforcement
            // Customer may have opted out between sale time and recovery time!
            $customer = $message->customer ?? ($message->customer_id ? Customer::find($message->customer_id) : null);
            if ($customer && ! $customer->isEligibleForWhatsApp($message->message_type, $tenant->id)) {
                $failureCode = $customer->whatsapp_opted_out ? 'CUSTOMER_OPTED_OUT' : 'CONSENT_REQUIRED';
                $message->markFailed($failureCode, 'Customer is not eligible for WhatsApp communication.');

                return false;
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
                $failureCode = $existingCustomer->whatsapp_opted_out ? 'RECIPIENT_OPTED_OUT' : 'CONSENT_REQUIRED';
                $message->markFailed($failureCode, 'Recipient phone has opted out or lacks positive consent.');

                return false;
            }

            // 5b. Merchant Notification Preference & Consent Re-check
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

                if ($prefKey && ! $this->notificationService->isMerchantNotificationEnabled($tenant, $prefKey)) {
                    $message->markFailed('MERCHANT_OPTED_OUT', "Merchant has disabled '{$prefKey}' notifications or revoked consent.");

                    return false;
                }
            }

            // 6. Resolve Active Provider Connection
            $resolved = $this->providerManager->resolveForTenant($tenant, $message->provider !== 'pending' ? $message->provider : null);
            $connection = $resolved['connection'];
            if (! $connection || ! $connection->isActive()) {
                $message->markFailed('NO_ACTIVE_CONNECTION', 'No active WhatsApp connection configured.');

                return false;
            }

            // 7. Re-enqueue or Dispatch Job based on Message Type
            if ($message->message_type === WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT) {
                $saleId = $message->payload['sale_id'] ?? null;
                if (! $saleId && $message->idempotency_key) {
                    $saleId = str_replace('whatsapp:receipt:', '', $message->idempotency_key);
                }

                if (! $saleId) {
                    $message->markFailed('MISSING_SALE_REFERENCE', 'Outbox record has no referenced sale ID.');

                    return false;
                }

                $sale = Sale::find($saleId);
                if (! $sale || ! $sale->isFinalized()) {
                    $message->markFailed('SALE_NOT_FINALIZED', 'Referenced sale is missing or not finalized.');

                    return false;
                }

                $overridePhone = $message->payload['override_phone'] ?? null;
                $job = new SendWhatsAppReceiptJob($sale->id, $overridePhone, $message->idempotency_key);
                $job->afterCommit = false; // Outside of transaction, enqueue directly

                if ($sync) {
                    $job->handle(
                        $this->notificationService,
                        $this->providerManager,
                        $this->entitlementService,
                        $this->receiptService,
                        $this->tenantManager
                    );
                    $message->refresh();

                    return $message->isSent();
                }

                try {
                    dispatch($job);

                    return true;
                } catch (\Throwable $e) {
                    Log::warning("Re-enqueue failed during recovery for message {$message->id}: {$e->getMessage()}");
                    $message->update([
                        'status' => WhatsAppMessage::STATUS_FAILED,
                        'error_code' => 'ENQUEUE_FAILED',
                        'error_message' => 'Failed to enqueue dispatch job: '.$e->getMessage(),
                        'failed_at' => now(),
                    ]);

                    return false;
                }
            }

            // Generic outbox message recovery (Merchant notifications, Order updates, Booking reminders, Promotions)
            $job = new SendWhatsAppMessageJob($message->id);
            $job->afterCommit = false;

            if ($sync) {
                $job->handle(
                    $this->notificationService,
                    $this->providerManager,
                    $this->entitlementService,
                    $this->tenantManager
                );
                $message->refresh();

                return $message->isSent();
            }

            try {
                dispatch($job);

                return true;
            } catch (\Throwable $e) {
                Log::warning("Re-enqueue failed during recovery for message {$message->id}: {$e->getMessage()}");
                $message->update([
                    'status' => WhatsAppMessage::STATUS_FAILED,
                    'error_code' => 'ENQUEUE_FAILED',
                    'error_message' => 'Failed to enqueue dispatch job: '.$e->getMessage(),
                    'failed_at' => now(),
                ]);

                return false;
            }
        });
    }

    public const MAX_RECEIPT_DELIVERY_AGE_HOURS = 24;

    /**
     * Independent Domain Reconciliation for unrecorded transactional receipts.
     * Closes crash window #1 using persistent watermark/cursor catch-up.
     * Enforces MAX_RECEIPT_DELIVERY_AGE_HOURS to prevent stale message spam to customers.
     *
     * @return array{
     *     scanned: int,
     *     reconciled: int,
     *     expired_stale: int,
     *     already_recorded: int,
     *     ineligible: int
     * }
     */
    public function reconcileUnrecordedReceipts(int $hours = 72, int $limit = 50, ?string $tenantId = null): array
    {
        $stats = [
            'scanned' => 0,
            'reconciled' => 0,
            'expired_stale' => 0,
            'already_recorded' => 0,
            'ineligible' => 0,
        ];

        // 1. Resolve Target Tenants
        $tenantsQuery = Tenant::withoutGlobalScopes()->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        } else {
            $activeTenantIds = TenantWhatsAppConnection::withoutGlobalScopes()
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->pluck('tenant_id')
                ->unique();
            $tenantsQuery->whereIn('id', $activeTenantIds);
        }
        $tenants = $tenantsQuery->get();

        $processTenant = function (Tenant $tenant) use ($hours, $limit, &$stats) {
            // Lock tenant row to ensure safe, serialized cursor updates under concurrent execution
            $lockedTenant = Tenant::withoutGlobalScopes()
                ->where('id', $tenant->id)
                ->lockForUpdate()
                ->first() ?? $tenant;

            $settings = $lockedTenant->settings ?? [];
            $cursor = $settings['whatsapp_receipt_reconciliation_cursor'] ?? null;

            $cursorFinalizedAt = null;
            $cursorId = null;

            if (is_array($cursor)) {
                $cursorFinalizedAt = isset($cursor['finalized_at']) ? Carbon::parse($cursor['finalized_at']) : null;
                $cursorId = $cursor['id'] ?? null;
            } elseif (is_string($cursor)) {
                if (str_contains($cursor, '|')) {
                    [$ts, $cId] = explode('|', $cursor, 2);
                    $cursorFinalizedAt = Carbon::parse($ts);
                    $cursorId = $cId;
                } else {
                    $cursorFinalizedAt = Carbon::parse($cursor);
                }
            }

            $salesQuery = Sale::withoutGlobalScopes()
                ->where('tenant_id', $lockedTenant->id)
                ->where('status', Sale::STATUS_FINALIZED)
                ->whereNotNull('customer_id');

            if ($cursorFinalizedAt) {
                if ($cursorId) {
                    $salesQuery->where(function ($q) use ($cursorFinalizedAt, $cursorId) {
                        $q->where('finalized_at', '>', $cursorFinalizedAt)
                            ->orWhere(function ($sub) use ($cursorFinalizedAt, $cursorId) {
                                $sub->where('finalized_at', '=', $cursorFinalizedAt)
                                    ->where('id', '>', $cursorId);
                            });
                    });
                } else {
                    $salesQuery->where('finalized_at', '>', $cursorFinalizedAt);
                }
            } else {
                $since = now()->subHours($hours);
                $salesQuery->where('finalized_at', '>=', $since);
            }

            // Deterministic ordering with (finalized_at asc, id asc) ensures batch boundaries never skip sales
            $finalizedSales = $salesQuery
                ->orderBy('finalized_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit($limit)
                ->get();

            if ($finalizedSales->isEmpty()) {
                return;
            }

            $lastProcessedSale = null;

            foreach ($finalizedSales as $sale) {
                try {
                    $stats['scanned']++;
                    $idempotencyKey = "whatsapp:receipt:{$sale->id}";

                    $exists = WhatsAppMessage::withoutGlobalScopes()
                        ->where('tenant_id', $sale->tenant_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->exists();

                    if ($exists) {
                        $stats['already_recorded']++;
                        $lastProcessedSale = $sale;

                        continue;
                    }

                    $customer = Customer::withoutGlobalScopes()->find($sale->customer_id);
                    if (! $customer || ! $customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $sale->tenant_id)) {
                        $stats['ineligible']++;
                        $lastProcessedSale = $sale;

                        continue;
                    }

                    $finalizedAt = $sale->finalized_at ?? now();
                    $ageHours = $finalizedAt->diffInHours(now());

                    // Stale Delivery Policy: If sale is older than max delivery age, record outbox terminal audit but DO NOT send to customer
                    if ($ageHours >= self::MAX_RECEIPT_DELIVERY_AGE_HOURS) {
                        WhatsAppMessage::create([
                            'tenant_id' => $lockedTenant->id,
                            'outlet_id' => $sale->outlet_id,
                            'customer_id' => $customer->id,
                            'recipient_phone' => $customer->phone,
                            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                            'status' => WhatsAppMessage::STATUS_FAILED,
                            'provider' => 'pending',
                            'idempotency_key' => $idempotencyKey,
                            'payload' => [
                                'sale_id' => $sale->id,
                                'stale_age_hours' => $ageHours,
                                'finalized_at' => $finalizedAt->toIso8601String(),
                            ],
                            'error_code' => 'EXPIRED_DELIVERY_WINDOW',
                            'error_message' => "Transaksi melewati batas usia pengiriman receipt ({$ageHours}h >= 24h). Tidak dikirim ke pelanggan untuk mencegah spam.",
                            'failed_at' => now(),
                            'attempts' => 0,
                        ]);

                        $stats['expired_stale']++;
                        Log::warning("Recovery reconciliation: Finalized sale {$sale->id} is {$ageHours}h old (> 24h). Created outbox record with EXPIRED_DELIVERY_WINDOW; skipped customer delivery.", [
                            'tenant_id' => $lockedTenant->id,
                            'sale_id' => $sale->id,
                        ]);
                    } else {
                        Log::info("Recovery reconciliation: Enqueuing missing receipt for finalized sale {$sale->id}.");
                        $this->notificationService->queueReceipt($sale);
                        $stats['reconciled']++;
                    }

                    $lastProcessedSale = $sale;
                } catch (\Throwable $e) {
                    Log::error("Recovery reconciliation failed on sale {$sale->id}: {$e->getMessage()}");
                    // Failed batch guard: Stop processing so cursor does NOT advance past unprocessed transactions
                    break;
                }
            }

            // Advance persistent watermark cursor ONLY up to the last successfully processed transaction
            if ($lastProcessedSale && $lastProcessedSale->finalized_at) {
                $settings['whatsapp_receipt_reconciliation_cursor'] = [
                    'finalized_at' => $lastProcessedSale->finalized_at->toIso8601String(),
                    'id' => (string) $lastProcessedSale->id,
                ];
                $lockedTenant->settings = $settings;
                $lockedTenant->saveQuietly();
            }
        };

        foreach ($tenants as $tenant) {
            // Subscription entitlement check
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            if (DB::transactionLevel() === 0) {
                DB::transaction(fn () => $processTenant($tenant));
            } else {
                $processTenant($tenant);
            }
        }

        return $stats;
    }

    /**
     * Domain reconciliation for unrecorded order confirmations.
     * Recovers committed orders whose outbox creation failed due to crash or fault.
     *
     * @return array{scanned: int, reconciled: int, already_recorded: int}
     */
    public function reconcileUnrecordedOrders(int $hours = 24, int $limit = 50, ?string $tenantId = null): array
    {
        $stats = ['scanned' => 0, 'reconciled' => 0, 'already_recorded' => 0];

        $tenantsQuery = Tenant::withoutGlobalScopes()->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        } else {
            $activeTenantIds = TenantWhatsAppConnection::withoutGlobalScopes()
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->pluck('tenant_id')
                ->unique();
            $tenantsQuery->whereIn('id', $activeTenantIds);
        }

        foreach ($tenantsQuery->get() as $tenant) {
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            $orders = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', [Order::STATUS_SUBMITTED, Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED])
                ->where('created_at', '>=', now()->subHours($hours))
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();

            foreach ($orders as $order) {
                $stats['scanned']++;
                $idempotencyKey = "whatsapp:order_confirmation:{$order->id}";

                $exists = WhatsAppMessage::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if ($exists) {
                    $stats['already_recorded']++;

                    continue;
                }

                if ($order->customer_id || ! empty($order->customer_contact_snapshot)) {
                    $this->notificationService->queueOrderConfirmation($order);
                    $stats['reconciled']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Domain reconciliation for unrecorded booking confirmations.
     *
     * @return array{scanned: int, reconciled: int, already_recorded: int}
     */
    public function reconcileUnrecordedBookings(int $hours = 24, int $limit = 50, ?string $tenantId = null): array
    {
        $stats = ['scanned' => 0, 'reconciled' => 0, 'already_recorded' => 0];

        $tenantsQuery = Tenant::withoutGlobalScopes()->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        } else {
            $activeTenantIds = TenantWhatsAppConnection::withoutGlobalScopes()
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->pluck('tenant_id')
                ->unique();
            $tenantsQuery->whereIn('id', $activeTenantIds);
        }

        foreach ($tenantsQuery->get() as $tenant) {
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            $bookings = Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_PENDING])
                ->where('created_at', '>=', now()->subHours($hours))
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();

            foreach ($bookings as $booking) {
                $stats['scanned']++;
                $idempotencyKey = "whatsapp:booking_confirmation:{$booking->id}";

                $exists = WhatsAppMessage::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if ($exists) {
                    $stats['already_recorded']++;

                    continue;
                }

                if ($booking->customer_id || ! empty($booking->customer_contact_snapshot)) {
                    $this->notificationService->queueBookingConfirmation($booking);
                    $stats['reconciled']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Domain reconciliation for unrecorded inventory low-stock alerts.
     *
     * @return array{scanned: int, reconciled: int, already_recorded: int}
     */
    public function reconcileUnrecordedLowStock(?string $tenantId = null): array
    {
        $stats = ['scanned' => 0, 'reconciled' => 0, 'already_recorded' => 0];

        $tenantsQuery = Tenant::withoutGlobalScopes()->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        } else {
            $activeTenantIds = TenantWhatsAppConnection::withoutGlobalScopes()
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->pluck('tenant_id')
                ->unique();
            $tenantsQuery->whereIn('id', $activeTenantIds);
        }

        $today = now()->toDateString();

        foreach ($tenantsQuery->get() as $tenant) {
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            $lowStockItems = InventoryItemLocation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('reorder_level')
                ->whereRaw('(on_hand - reserved) <= reorder_level')
                ->get();

            $today = Carbon::now($tenant->timezone ?? 'Asia/Jakarta')->toDateString();

            foreach ($lowStockItems as $itemLoc) {
                $stats['scanned']++;
                $idempotencyKey = "whatsapp:low_stock:{$tenant->id}:{$itemLoc->id}:{$today}";

                $exists = WhatsAppMessage::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if ($exists) {
                    $stats['already_recorded']++;

                    continue;
                }

                $this->notificationService->queueLowStockAlert($tenant, $itemLoc);
                $stats['reconciled']++;
            }
        }

        return $stats;
    }

    /**
     * Domain reconciliation for unrecorded shift closing anomalies.
     *
     * @return array{scanned: int, reconciled: int, already_recorded: int}
     */
    public function reconcileUnrecordedShiftAnomalies(int $hours = 24, ?string $tenantId = null): array
    {
        $stats = ['scanned' => 0, 'reconciled' => 0, 'already_recorded' => 0];

        $tenantsQuery = Tenant::withoutGlobalScopes()->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        } else {
            $activeTenantIds = TenantWhatsAppConnection::withoutGlobalScopes()
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->pluck('tenant_id')
                ->unique();
            $tenantsQuery->whereIn('id', $activeTenantIds);
        }

        foreach ($tenantsQuery->get() as $tenant) {
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            $shifts = Shift::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'closed')
                ->where('closed_at', '>=', now()->subHours($hours))
                ->where(function ($q) {
                    $q->where('variance_minor', '!=', 0)
                        ->orWhereColumn('closing_cash_minor', '!=', 'expected_cash_minor');
                })
                ->get();

            foreach ($shifts as $shift) {
                $stats['scanned']++;
                $idempotencyKey = "whatsapp:anomaly:{$tenant->id}:Selisih Kas Shift:shift_{$shift->id}";

                $exists = WhatsAppMessage::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if ($exists) {
                    $stats['already_recorded']++;

                    continue;
                }

                $variance = (int) ($shift->variance_minor ?? ($shift->closing_cash_minor - $shift->expected_cash_minor));
                $varianceFormatted = 'Rp '.number_format(abs($variance) / 100, 0, ',', '.');
                $desc = ($variance > 0 ? 'Kelebihan kas: ' : 'Kekurangan kas: ').$varianceFormatted." pada shift #{$shift->id}";

                $this->notificationService->queueAnomalyAlert($tenant, 'Selisih Kas Shift', $desc, [
                    'reference_id' => "shift_{$shift->id}",
                    'shift_id' => $shift->id,
                    'variance_minor' => $variance,
                ]);
                $stats['reconciled']++;
            }
        }

        return $stats;
    }

    /**
     * Domain reconciliation for unrecorded payment settlement alerts.
     *
     * @return array{scanned: int, reconciled: int, already_recorded: int}
     */
    public function reconcileUnrecordedSettlements(int $hours = 24, ?string $tenantId = null): array
    {
        $stats = ['scanned' => 0, 'reconciled' => 0, 'already_recorded' => 0];

        $tenantsQuery = Tenant::withoutGlobalScopes()->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        } else {
            $activeTenantIds = TenantWhatsAppConnection::withoutGlobalScopes()
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->pluck('tenant_id')
                ->unique();
            $tenantsQuery->whereIn('id', $activeTenantIds);
        }

        foreach ($tenantsQuery->get() as $tenant) {
            if (! $this->entitlementService->can($tenant, 'whatsapp')) {
                continue;
            }

            $reconciliations = PaymentReconciliation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('settlement_status', PaymentReconciliation::SETTLEMENT_SETTLED)
                ->where('created_at', '>=', now()->subHours($hours))
                ->get();

            foreach ($reconciliations as $recon) {
                $stats['scanned']++;
                $ref = $recon->provider_reference ?? (string) $recon->id;
                $idempotencyKey = "whatsapp:settlement:{$tenant->id}:{$ref}";

                $exists = WhatsAppMessage::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();

                if ($exists) {
                    $stats['already_recorded']++;

                    continue;
                }

                $this->notificationService->queuePaymentSettlementAlert($tenant, [
                    'reference' => $recon->provider_reference,
                    'provider' => $recon->provider,
                    'settled_amount_minor' => $recon->provider_amount_minor,
                    'transaction_count' => 1,
                ]);
                $stats['reconciled']++;
            }
        }

        return $stats;
    }
}
