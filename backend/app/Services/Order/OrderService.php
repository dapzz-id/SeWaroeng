<?php

namespace App\Services\Order;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\IdempotencyReplayUnavailableException;
use App\Exceptions\OrderInvalidStateException;
use App\Exceptions\TableUnavailableException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Modifier;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Services\Notification\NotificationService;
use App\Services\PosService;
use App\Services\Subscription\SubscriptionEntitlementService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected TenantManager $tenantManager,
        protected SubscriptionEntitlementService $entitlementService,
        protected NotificationService $notificationService,
        protected PosService $posService
    ) {}

    /**
     * List orders for staff POS / management with filters.
     */
    public function listOrders(string $tenantId, ?string $outletId = null, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $outletId, $filters, $perPage) {
            $query = Order::where('tenant_id', $tenantId)
                ->with(['items.variant.product', 'table', 'session', 'customer', 'sale']);

            if ($outletId) {
                $query->where('outlet_id', $outletId);
            } elseif (! empty($filters['assigned_outlet_ids'])) {
                $query->whereIn('outlet_id', $filters['assigned_outlet_ids']);
            }

            if (! empty($filters['table_id'])) {
                $query->where('table_id', $filters['table_id']);
            }

            if (! empty($filters['table_session_id'])) {
                $query->where('table_session_id', $filters['table_session_id']);
            }

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['source'])) {
                $query->where('source', $filters['source']);
            }

            return $query->orderBy('created_at', 'desc')->paginate($perPage);
        });
    }

    /**
     * Customer QR Order Submission (Public API).
     * Server calculates authoritative prices and taxes; client prices/totals are ignored.
     */
    public function submitOrderFromQr(
        string $qrToken,
        array $items,
        ?string $customerNotes = null,
        ?string $idempotencyKey = null,
        ?array $customerData = null
    ): Order {
        if (empty($items)) {
            throw new \InvalidArgumentException('Order items must not be empty.');
        }

        // 1. Resolve table by token without trusting client tenant/outlet input
        $table = $this->resolveTableByToken($qrToken);

        if (! $table || ! $table->qr_token_hash) {
            throw new TableUnavailableException('Invalid or expired QR token.');
        }

        if (! $table->is_active || $table->status === Table::STATUS_INACTIVE) {
            throw new TableUnavailableException("Table [{$table->code}] is inactive or unavailable.");
        }

        $tenantId = (string) $table->tenant_id;
        $tenant = Tenant::withoutGlobalScopes()->findOrFail($tenantId);

        // 2. Assert subscription entitlement for QR ordering
        $this->entitlementService->assertCan($tenant, 'qr_table_order');

        return $this->tenantManager->runForTenant($tenantId, function () use (
            $tenantId,
            $table,
            $items,
            $customerNotes,
            $idempotencyKey,
            $customerData
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $table,
                $items,
                $customerNotes,
                $idempotencyKey,
                $customerData
            ) {
                // 3. Idempotency assertion
                $payloadHash = sha1(json_encode([
                    'table_id' => $table->id,
                    'items' => $items,
                    'notes' => $customerNotes,
                ]));

                if ($idempotencyKey) {
                    $existingOrder = Order::where('tenant_id', $tenantId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->with(['items.variant.product', 'table'])
                        ->first();

                    if ($existingOrder) {
                        $storedHash = $existingOrder->metadata['payload_hash'] ?? null;
                        if ($storedHash && $storedHash !== $payloadHash) {
                            throw new \InvalidArgumentException("Idempotency key '{$idempotencyKey}' was previously used with different order payload.");
                        }

                        // Restore raw tracking token from dedicated encrypted column for deterministic retry replay
                        $ciphertext = $existingOrder->public_tracking_token_ciphertext
                            ?? $existingOrder->metadata['encrypted_tracking_token']
                            ?? null;

                        if (empty($ciphertext)) {
                            Log::warning('Tracking token ciphertext missing during idempotency replay.', [
                                'order_id' => $existingOrder->id,
                                'tenant_id' => $tenantId,
                            ]);
                            throw new IdempotencyReplayUnavailableException('Tracking credential replay is unavailable for this request.');
                        }

                        try {
                            $existingOrder->raw_tracking_token = Crypt::decryptString($ciphertext);
                        } catch (DecryptException $e) {
                            Log::warning('Failed to decrypt public tracking token ciphertext during idempotency replay: key rotation or corrupted ciphertext.', [
                                'order_id' => $existingOrder->id,
                                'tenant_id' => $tenantId,
                            ]);
                            throw new IdempotencyReplayUnavailableException('Tracking credential replay is unavailable for this request.');
                        } catch (\Throwable $e) {
                            Log::warning('Unexpected error decrypting public tracking token ciphertext during idempotency replay.', [
                                'order_id' => $existingOrder->id,
                                'tenant_id' => $tenantId,
                            ]);
                            throw new IdempotencyReplayUnavailableException('Tracking credential replay is unavailable for this request.');
                        }

                        return $existingOrder;
                    }
                }

                // 4. Resolve or auto-create active table session
                $session = TableSession::where('tenant_id', $tenantId)
                    ->where('table_id', $table->id)
                    ->where('status', TableSession::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    $sessionCode = 'SESS-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
                    $session = TableSession::create([
                        'tenant_id' => $tenantId,
                        'outlet_id' => $table->outlet_id,
                        'table_id' => $table->id,
                        'session_code' => $sessionCode,
                        'status' => TableSession::STATUS_ACTIVE,
                        'guest_count' => $table->capacity,
                        'started_at' => now(),
                    ]);

                    $table->status = Table::STATUS_OCCUPIED;
                    $table->save();
                }

                // 5. Customer resolution if identified
                $customerId = null;
                if (! empty($customerData['phone']) || ! empty($customerData['email'])) {
                    $contact = $customerData['phone'] ?? $customerData['email'];
                    $customer = Customer::where('tenant_id', $tenantId)
                        ->where(function ($q) use ($contact) {
                            $q->where('phone', $contact)->orWhere('email', $contact);
                        })
                        ->first();

                    if ($customer) {
                        $customerId = $customer->id;
                    }
                }

                // 6. Server-side authoritative calculation
                $orderNumber = 'ORD-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
                $orderSubtotalMinor = 0;
                $processedItems = [];

                foreach ($items as $itemData) {
                    $variantId = $itemData['product_variant_id'] ?? null;
                    if (! $variantId) {
                        throw new \InvalidArgumentException('product_variant_id is required for each order item.');
                    }

                    $variant = ProductVariant::where('tenant_id', $tenantId)
                        ->with('product')
                        ->findOrFail($variantId);

                    $quantity = (float) ($itemData['quantity'] ?? 1);
                    if ($quantity <= 0) {
                        throw new \InvalidArgumentException("Invalid quantity for product [{$variant->name}].");
                    }

                    $price = $variant->resolvePrice($table->outlet_id);
                    $unitPriceMinor = $price ? (int) $price->amount : 0;
                    $lineBaseMinor = (int) bcmul((string) $quantity, (string) $unitPriceMinor, 0);

                    // Resolve modifiers
                    $selectedModifiers = [];
                    $modifierTotalMinor = 0;
                    if (! empty($itemData['modifiers'])) {
                        foreach ($itemData['modifiers'] as $modInput) {
                            $modId = is_array($modInput) ? ($modInput['modifier_id'] ?? null) : $modInput;
                            if ($modId) {
                                $modifier = Modifier::where('tenant_id', $tenantId)->findOrFail($modId);
                                $modPrice = (int) $modifier->price_delta;
                                $modQty = is_array($modInput) ? (float) ($modInput['quantity'] ?? 1) : 1;
                                $totalModLine = (int) bcmul((string) ($quantity * $modQty), (string) $modPrice, 0);

                                $selectedModifiers[] = [
                                    'modifier_id' => $modifier->id,
                                    'name' => $modifier->name,
                                    'price_delta' => $modPrice,
                                    'quantity' => $modQty,
                                ];
                                $modifierTotalMinor += $totalModLine;
                            }
                        }
                    }

                    $lineSubtotalMinor = $lineBaseMinor + $modifierTotalMinor;
                    $orderSubtotalMinor += $lineSubtotalMinor;

                    $processedItems[] = [
                        'variant' => $variant,
                        'quantity' => $quantity,
                        'unit_price_minor' => $unitPriceMinor,
                        'subtotal_minor' => $lineSubtotalMinor,
                        'notes' => $itemData['notes'] ?? null,
                        'modifiers' => $selectedModifiers,
                    ];
                }

                $taxMinor = 0; // Tax calculated during final sale
                $totalMinor = $orderSubtotalMinor + $taxMinor;

                $rawTrackingToken = bin2hex(random_bytes(24));
                $trackingHash = Order::hashTrackingToken($rawTrackingToken);

                // 7. Persist Order
                $order = Order::create([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $table->outlet_id,
                    'table_id' => $table->id,
                    'table_session_id' => $session->id,
                    'customer_id' => $customerId,
                    'order_number' => $orderNumber,
                    'customer_name_snapshot' => $customerData['name'] ?? null,
                    'customer_contact_snapshot' => $customerData['phone'] ?? $customerData['email'] ?? null,
                    'status' => Order::STATUS_SUBMITTED,
                    'subtotal_minor' => $orderSubtotalMinor,
                    'discount_minor' => 0,
                    'tax_minor' => $taxMinor,
                    'total_minor' => $totalMinor,
                    'customer_notes' => $customerNotes,
                    'idempotency_key' => $idempotencyKey,
                    'public_tracking_token_hash' => $trackingHash,
                    'public_tracking_token_ciphertext' => Crypt::encryptString($rawTrackingToken),
                    'source' => 'qr_table',
                ]);
                $order->raw_tracking_token = $rawTrackingToken;

                // Store payload hash for idempotency integrity
                $order->metadata = ['payload_hash' => $payloadHash];
                $order->save();

                // 8. Persist Order Items
                foreach ($processedItems as $pi) {
                    OrderItem::create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_variant_id' => $pi['variant']->id,
                        'product_name_snapshot' => $pi['variant']->product->name,
                        'variant_name_snapshot' => $pi['variant']->name,
                        'quantity' => $pi['quantity'],
                        'unit_price_minor' => $pi['unit_price_minor'],
                        'subtotal_minor' => $pi['subtotal_minor'],
                        'notes' => $pi['notes'],
                        'selected_modifiers' => $pi['modifiers'],
                    ]);
                }

                // 9. Dispatch Staff Notification
                $this->notificationService->createNotification($tenantId, [
                    'type' => Notification::TYPE_ORDER_RECEIVED,
                    'title' => "Pesanan QR Baru: Meja {$table->code}",
                    'message' => "Pesanan {$order->order_number} ({$table->name}) telah diterima dan menunggu konfirmasi.",
                    'severity' => Notification::SEVERITY_INFO,
                    'dedup_key' => "order_received:{$order->id}",
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'table_id' => $table->id,
                        'table_code' => $table->code,
                        'outlet_id' => $table->outlet_id,
                    ],
                ]);

                // 10. Audit Log
                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'action' => 'order_submitted',
                    'entity_type' => Order::class,
                    'entity_id' => $order->id,
                    'after' => $order->toArray(),
                    'created_at' => now(),
                ]);

                return $order->load(['items.variant.product', 'table', 'session']);
            });
        });
    }

    /**
     * Update order operational lifecycle state.
     */
    public function updateOrderStatus(Order $order, string $newStatus, ?string $actorUserId = null): Order
    {
        return $this->tenantManager->runForTenant($order->tenant_id, function () use ($order, $newStatus, $actorUserId) {
            return DB::transaction(function () use ($order, $newStatus, $actorUserId) {
                $validTransitions = [
                    Order::STATUS_SUBMITTED => [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED, Order::STATUS_CONVERTED, Order::STATUS_CANCELLED],
                    Order::STATUS_ACCEPTED => [Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED, Order::STATUS_CONVERTED, Order::STATUS_CANCELLED],
                    Order::STATUS_PREPARING => [Order::STATUS_READY, Order::STATUS_SERVED, Order::STATUS_CONVERTED, Order::STATUS_CANCELLED],
                    Order::STATUS_READY => [Order::STATUS_SERVED, Order::STATUS_CONVERTED, Order::STATUS_CANCELLED],
                    Order::STATUS_SERVED => [Order::STATUS_CONVERTED, Order::STATUS_CANCELLED],
                    Order::STATUS_CONVERTED => [Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED, Order::STATUS_CANCELLED],
                ];

                $allowed = $validTransitions[$order->status] ?? [];
                if (! in_array($newStatus, $allowed)) {
                    throw new OrderInvalidStateException("Cannot transition order from '{$order->status}' to '{$newStatus}'.");
                }

                $before = $order->status;
                $order->status = $newStatus;
                $order->save();

                AuditLog::create([
                    'tenant_id' => $order->tenant_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'order_status_updated',
                    'entity_type' => Order::class,
                    'entity_id' => $order->id,
                    'before' => ['status' => $before],
                    'after' => ['status' => $newStatus],
                    'created_at' => now(),
                ]);

                return $order;
            });
        });
    }

    /**
     * Converts one or multiple accepted/served orders into an authoritative Sale.
     * Uses existing PosService without duplicating POS finalization, pricing, or payment logic.
     */
    public function convertOrdersToSale(
        array|string $orderIdsOrSessionId,
        string $cashierUserId,
        ?string $notes = null
    ): Sale {
        // Collect orders
        if (is_string($orderIdsOrSessionId) && str_starts_with($orderIdsOrSessionId, 'SESS-')) {
            $session = TableSession::where('session_code', $orderIdsOrSessionId)->firstOrFail();
            $orders = Order::where('table_session_id', $session->id)
                ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED, Order::STATUS_SUBMITTED])
                ->with('items')
                ->get();
        } elseif (is_string($orderIdsOrSessionId)) {
            $order = Order::with('items')->findOrFail($orderIdsOrSessionId);
            $orders = collect([$order]);
        } else {
            $orders = Order::whereIn('id', $orderIdsOrSessionId)
                ->with('items')
                ->get();
        }

        if ($orders->isEmpty()) {
            throw new \InvalidArgumentException('No valid orders found to convert to Sale.');
        }

        $tenantId = $orders->first()->tenant_id;
        $outletId = $orders->first()->outlet_id;
        $customerId = $orders->first()->customer_id;

        return $this->tenantManager->runForTenant($tenantId, function () use (
            $tenantId,
            $outletId,
            $orders,
            $cashierUserId,
            $notes,
            $customerId
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $outletId,
                $orders,
                $cashierUserId,
                $notes,
                $customerId
            ) {
                // Bundle items for PosService::createSale
                $saleItems = [];
                foreach ($orders as $ord) {
                    foreach ($ord->items as $item) {
                        $saleItems[] = [
                            'product_variant_id' => $item->product_variant_id,
                            'quantity' => (float) $item->quantity,
                            'notes' => $item->notes,
                            'modifiers' => $item->selected_modifiers ?? [],
                        ];
                    }
                }

                // Call existing PosService::createSale
                $sale = $this->posService->createSale(
                    $tenantId,
                    $outletId,
                    $cashierUserId,
                    $saleItems,
                    $notes ?? ('Converted from Order '.implode(', ', $orders->pluck('order_number')->toArray())),
                    $customerId
                );

                // Mark orders as converted
                foreach ($orders as $ord) {
                    $ord->sale_id = $sale->id;
                    $ord->status = Order::STATUS_CONVERTED;
                    $ord->save();

                    AuditLog::create([
                        'tenant_id' => $tenantId,
                        'actor_user_id' => $cashierUserId,
                        'action' => 'order_converted_to_sale',
                        'entity_type' => Order::class,
                        'entity_id' => $ord->id,
                        'after' => ['sale_id' => $sale->id],
                        'created_at' => now(),
                    ]);
                }

                return $sale;
            });
        });
    }

    /**
     * Resolves table by public QR token safely across PostgreSQL RLS barrier.
     */
    public function resolveTableByToken(string $qrToken): ?Table
    {
        if (trim($qrToken) === '') {
            return null;
        }

        $tokenHash = Table::hashToken($qrToken);

        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.is_platform_context', 'true', false)");
            }

            return Table::withoutGlobalScopes()
                ->with(['outlet' => fn ($q) => $q->withoutGlobalScopes()])
                ->where('qr_token_hash', $tokenHash)
                ->first();
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.is_platform_context', '', false)");
            }
        }
    }
}
