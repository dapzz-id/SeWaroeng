<?php

namespace App\Services;

use App\Domain\Tenant\TenantManager;
use App\Events\PurchaseOrderApproved;
use App\Events\PurchaseReceiptPosted;
use App\Exceptions\PurchaseOrderInvalidStateException;
use App\Exceptions\PurchaseReceiptOverQuantityException;
use App\Exceptions\SupplierInactiveException;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchasingService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected CostingService $costingService,
    ) {}

    /**
     * Ensure PostgreSQL RLS session setting is bound for transaction context.
     */
    protected function ensureTenantRls(string $tenantId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);
        } catch (\Throwable) {
            // Ignored if non-pgsql connection or testing mock
        }
    }

    /**
     * Execute callback ensuring tenant context is active for both Eloquent TenantScope and PostgreSQL RLS.
     */
    protected function executeForTenant(string $tenantId, callable $callback): mixed
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext() && $tenantManager->currentTenantId() === $tenantId) {
            $this->ensureTenantRls($tenantId);

            return $callback();
        }

        return $tenantManager->runForTenant($tenantId, $callback);
    }

    // ==========================================
    // SUPPLIER DOMAIN METHODS
    // ==========================================

    public function createSupplier(string $tenantId, array $data, string $userId): Supplier
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $data, $userId) {
            $supplier = Supplier::create([
                'tenant_id' => $tenantId,
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'contact_name' => $data['contact_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'tax_identifier' => $data['tax_identifier'] ?? null,
                'payment_terms_days' => $data['payment_terms_days'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]);

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId,
                'action' => 'supplier_created',
                'entity_type' => Supplier::class,
                'entity_id' => $supplier->id,
                'after' => $supplier->only(['code', 'name', 'status']),
                'created_at' => now(),
            ]);

            return $supplier;
        });
    }

    public function updateSupplier(string $tenantId, string $supplierId, array $data, string $userId): Supplier
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $supplierId, $data, $userId) {
            $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($supplierId);
            $before = $supplier->only(['code', 'name', 'status', 'contact_name', 'phone']);

            if (isset($data['code'])) {
                $supplier->code = strtoupper(trim($data['code']));
            }
            if (isset($data['name'])) {
                $supplier->name = trim($data['name']);
            }
            if (array_key_exists('contact_name', $data)) {
                $supplier->contact_name = $data['contact_name'];
            }
            if (array_key_exists('phone', $data)) {
                $supplier->phone = $data['phone'];
            }
            if (array_key_exists('email', $data)) {
                $supplier->email = $data['email'];
            }
            if (array_key_exists('address', $data)) {
                $supplier->address = $data['address'];
            }
            if (array_key_exists('tax_identifier', $data)) {
                $supplier->tax_identifier = $data['tax_identifier'];
            }
            if (array_key_exists('payment_terms_days', $data)) {
                $supplier->payment_terms_days = $data['payment_terms_days'];
            }
            if (array_key_exists('notes', $data)) {
                $supplier->notes = $data['notes'];
            }
            if (isset($data['status'])) {
                $supplier->status = $data['status'];
            }

            $supplier->save();

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId,
                'action' => 'supplier_updated',
                'entity_type' => Supplier::class,
                'entity_id' => $supplier->id,
                'before' => $before,
                'after' => $supplier->only(['code', 'name', 'status', 'contact_name', 'phone']),
                'created_at' => now(),
            ]);

            return $supplier;
        });
    }

    // ==========================================
    // PURCHASE ORDER DOMAIN METHODS
    // ==========================================

    /**
     * Create a Purchase Order.
     * Note: PO creation DOES NOT mutate inventory stock.
     */
    public function createPurchaseOrder(string $tenantId, array $data, string $userId): PurchaseOrder
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $data, $userId) {
            return DB::transaction(function () use ($tenantId, $data, $userId) {
                $this->ensureTenantRls($tenantId);

                // 1. Validate supplier
                $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($data['supplier_id']);
                if (! $supplier->isActive()) {
                    throw new SupplierInactiveException("Supplier [{$supplier->name}] is inactive and cannot accept new purchase orders.");
                }

                // 2. Validate destination location
                $location = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($data['destination_location_id']);

                // 3. Generate reference number PO-YYYYMMDD-XXXXXX
                $datePrefix = now()->format('Ymd');
                $randomSuffix = strtoupper(Str::random(6));
                $referenceNumber = "PO-{$datePrefix}-{$randomSuffix}";

                // 4. Create PO aggregate root
                $po = PurchaseOrder::create([
                    'tenant_id' => $tenantId,
                    'supplier_id' => $supplier->id,
                    'destination_location_id' => $location->id,
                    'reference_number' => $referenceNumber,
                    'status' => 'draft',
                    'ordered_at' => $data['ordered_at'] ?? now(),
                    'expected_at' => $data['expected_at'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                // 5. Create PO Items
                foreach ($data['items'] as $itemData) {
                    $invItem = InventoryItem::where('tenant_id', $tenantId)->findOrFail($itemData['inventory_item_id']);
                    $orderedQty = sprintf('%.4f', (float) $itemData['ordered_quantity']);

                    if (bccomp($orderedQty, '0.0000', 4) <= 0) {
                        throw new \InvalidArgumentException("Ordered quantity must be greater than zero for item [{$invItem->name}].");
                    }

                    PurchaseOrderItem::create([
                        'tenant_id' => $tenantId,
                        'purchase_order_id' => $po->id,
                        'inventory_item_id' => $invItem->id,
                        'ordered_quantity' => $orderedQty,
                        'unit_cost_minor' => (int) $itemData['unit_cost_minor'],
                        'received_quantity' => '0.0000',
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'purchase_order_created',
                    'entity_type' => PurchaseOrder::class,
                    'entity_id' => $po->id,
                    'after' => [
                        'reference_number' => $po->reference_number,
                        'supplier_id' => $supplier->id,
                        'status' => 'draft',
                        'items_count' => count($data['items']),
                    ],
                    'created_at' => now(),
                ]);

                return $po->load(['items.item', 'supplier', 'destinationLocation']);
            });
        });
    }

    /**
     * Submit draft purchase order.
     */
    public function submitPurchaseOrder(string $tenantId, string $orderId, string $userId): PurchaseOrder
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $orderId, $userId) {
            return DB::transaction(function () use ($tenantId, $orderId, $userId) {
                $this->ensureTenantRls($tenantId);

                $po = PurchaseOrder::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($orderId);

                if ($po->status !== 'draft') {
                    throw new PurchaseOrderInvalidStateException("Cannot submit purchase order in status [{$po->status}]. Expected 'draft'.");
                }

                $po->status = 'submitted';
                $po->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'purchase_order_submitted',
                    'entity_type' => PurchaseOrder::class,
                    'entity_id' => $po->id,
                    'after' => ['status' => 'submitted'],
                    'created_at' => now(),
                ]);

                return $po->load(['items.item', 'supplier', 'destinationLocation']);
            });
        });
    }

    /**
     * Approve purchase order.
     * Note: PO approval DOES NOT mutate inventory stock.
     */
    public function approvePurchaseOrder(string $tenantId, string $orderId, string $approvedBy): PurchaseOrder
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $orderId, $approvedBy) {
            return DB::transaction(function () use ($tenantId, $orderId, $approvedBy) {
                $this->ensureTenantRls($tenantId);

                $po = PurchaseOrder::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($orderId);

                if (! in_array($po->status, ['draft', 'submitted'])) {
                    throw new PurchaseOrderInvalidStateException("Cannot approve purchase order in status [{$po->status}]. Expected 'draft' or 'submitted'.");
                }

                $po->status = 'approved';
                $po->approved_by = $approvedBy;
                $po->approved_at = now();
                $po->save();

                PurchaseOrderApproved::dispatch($po);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $approvedBy,
                    'action' => 'purchase_order_approved',
                    'entity_type' => PurchaseOrder::class,
                    'entity_id' => $po->id,
                    'after' => ['status' => 'approved', 'approved_by' => $approvedBy],
                    'created_at' => now(),
                ]);

                return $po->load(['items.item', 'supplier', 'destinationLocation']);
            });
        });
    }

    /**
     * Cancel purchase order.
     */
    public function cancelPurchaseOrder(string $tenantId, string $orderId, string $cancelledBy, ?string $reason = null): PurchaseOrder
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $orderId, $cancelledBy, $reason) {
            return DB::transaction(function () use ($tenantId, $orderId, $cancelledBy, $reason) {
                $this->ensureTenantRls($tenantId);

                $po = PurchaseOrder::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($orderId);

                if (in_array($po->status, ['received', 'cancelled'])) {
                    throw new PurchaseOrderInvalidStateException("Cannot cancel purchase order in status [{$po->status}].");
                }

                // If partially received, verify if cancellation of remaining items is acceptable
                $po->status = 'cancelled';
                if ($reason) {
                    $po->notes = trim(($po->notes ? $po->notes."\n" : '')."Cancellation reason: {$reason}");
                }
                $po->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $cancelledBy,
                    'action' => 'purchase_order_cancelled',
                    'entity_type' => PurchaseOrder::class,
                    'entity_id' => $po->id,
                    'after' => ['status' => 'cancelled', 'reason' => $reason],
                    'created_at' => now(),
                ]);

                return $po->load(['items.item', 'supplier', 'destinationLocation']);
            });
        });
    }

    // ==========================================
    // GOODS RECEIPT DOMAIN METHODS
    // ==========================================

    /**
     * Create a draft goods receipt note (GRN).
     * Note: Receipt creation DOES NOT mutate stock.
     */
    public function createReceipt(
        string $tenantId,
        string $orderId,
        array $itemsData,
        string $receivedBy,
        ?string $notes = null,
    ): PurchaseReceipt {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $orderId, $itemsData, $receivedBy, $notes) {
            return DB::transaction(function () use ($tenantId, $orderId, $itemsData, $receivedBy, $notes) {
                $this->ensureTenantRls($tenantId);

                $po = PurchaseOrder::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($orderId);

                if (! in_array($po->status, ['approved', 'partially_received'])) {
                    throw new PurchaseOrderInvalidStateException("Cannot receive purchase order in status [{$po->status}]. Expected 'approved' or 'partially_received'.");
                }

                $referenceNumber = 'GRN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $receipt = PurchaseReceipt::create([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $po->id,
                    'destination_location_id' => $po->destination_location_id,
                    'reference_number' => $referenceNumber,
                    'status' => 'draft',
                    'received_at' => now(),
                    'received_by' => $receivedBy,
                    'notes' => $notes,
                ]);

                foreach ($itemsData as $itemInput) {
                    $poItem = PurchaseOrderItem::where('tenant_id', $tenantId)
                        ->where('purchase_order_id', $po->id)
                        ->findOrFail($itemInput['purchase_order_item_id']);

                    $qty = sprintf('%.4f', (float) $itemInput['quantity']);
                    if (bccomp($qty, '0.0000', 4) <= 0) {
                        throw new \InvalidArgumentException('Receipt quantity must be strictly greater than zero.');
                    }

                    // Over-receiving validation
                    $remaining = bcsub((string) $poItem->ordered_quantity, (string) $poItem->received_quantity, 4);
                    if (bccomp($qty, $remaining, 4) > 0) {
                        throw new PurchaseReceiptOverQuantityException(
                            "Cannot receive [{$qty}] for item. Remaining ordered quantity is [{$remaining}]."
                        );
                    }

                    PurchaseReceiptItem::create([
                        'tenant_id' => $tenantId,
                        'purchase_receipt_id' => $receipt->id,
                        'purchase_order_item_id' => $poItem->id,
                        'inventory_item_id' => $poItem->inventory_item_id,
                        'quantity' => $qty,
                        'unit_cost_minor' => $itemInput['unit_cost_minor'] ?? $poItem->unit_cost_minor,
                        'notes' => $itemInput['notes'] ?? null,
                    ]);
                }

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $receivedBy,
                    'action' => 'purchase_receipt_created',
                    'entity_type' => PurchaseReceipt::class,
                    'entity_id' => $receipt->id,
                    'after' => [
                        'reference_number' => $receipt->reference_number,
                        'purchase_order_id' => $po->id,
                        'status' => 'draft',
                    ],
                    'created_at' => now(),
                ]);

                return $receipt->load(['items.item', 'purchaseOrder', 'destinationLocation']);
            });
        });
    }

    /**
     * Post Goods Receipt:
     * - Atomically creates inventory physical movements ('purchase_receipt')
     * - Increases inventory on_hand
     * - Updates received_quantity on PO items
     * - Updates PO status (partially_received or received)
     * - Posted receipt becomes immutable.
     */
    public function postReceipt(string $tenantId, string $receiptId, string $postedBy): PurchaseReceipt
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $receiptId, $postedBy) {
            return DB::transaction(function () use ($tenantId, $receiptId, $postedBy) {
                $this->ensureTenantRls($tenantId);

                // 1. Lock receipt and PO
                $receipt = PurchaseReceipt::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($receiptId);

                if ($receipt->status !== 'draft') {
                    throw new PurchaseOrderInvalidStateException("Cannot post goods receipt in status [{$receipt->status}]. Expected 'draft'.");
                }

                $po = PurchaseOrder::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($receipt->purchase_order_id);

                if (! in_array($po->status, ['approved', 'partially_received'])) {
                    throw new PurchaseOrderInvalidStateException("Cannot post receipt against purchase order in status [{$po->status}].");
                }

                $receiptItems = PurchaseReceiptItem::where('tenant_id', $tenantId)
                    ->where('purchase_receipt_id', $receipt->id)
                    ->get();

                if ($receiptItems->isEmpty()) {
                    throw new \InvalidArgumentException('Cannot post empty goods receipt note.');
                }

                // 2. Validate all quantities against PO items under lock
                foreach ($receiptItems as $rItem) {
                    $poItem = PurchaseOrderItem::where('tenant_id', $tenantId)
                        ->where('id', $rItem->purchase_order_item_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $remaining = bcsub((string) $poItem->ordered_quantity, (string) $poItem->received_quantity, 4);

                    if (bccomp((string) $rItem->quantity, $remaining, 4) > 0) {
                        throw new PurchaseReceiptOverQuantityException(
                            "Cannot receive [{$rItem->quantity}]. Remaining ordered quantity is [{$remaining}]."
                        );
                    }
                }

                // 3. Atomically record physical inventory movements, cost layers, and update received quantities
                foreach ($receiptItems as $rItem) {
                    $poItem = PurchaseOrderItem::where('tenant_id', $tenantId)->findOrFail($rItem->purchase_order_item_id);

                    // Capture on_hand BEFORE movement for MWA calculation
                    $itemLocBefore = InventoryItemLocation::where('tenant_id', $tenantId)
                        ->where('inventory_item_id', $rItem->inventory_item_id)
                        ->where('location_id', $receipt->destination_location_id)
                        ->first();
                    $onHandBefore = $itemLocBefore ? sprintf('%.4f', (float) $itemLocBefore->on_hand) : '0.0000';

                    // Physical inventory movement creation: movement_type = 'purchase_receipt'
                    $movement = $this->inventoryService->recordMovement(
                        tenantId: $tenantId,
                        inventoryItemId: $rItem->inventory_item_id,
                        locationId: $receipt->destination_location_id,
                        movementType: 'purchase_receipt',
                        delta: $rItem->quantity,
                        referenceType: PurchaseReceipt::class,
                        referenceId: $receipt->id,
                        reason: "Goods Receipt {$receipt->reference_number}",
                        note: "PO: {$po->reference_number}, Unit Cost: Rp ".number_format($rItem->unit_cost_minor, 0, ',', '.'),
                        userId: $postedBy,
                    );

                    // Atomically post cost layer — SAME transaction as inventory movement.
                    // Idempotency: unique constraint on (tenant_id, source_movement_id) prevents duplicate layers.
                    $this->costingService->postCostLayer(
                        tenantId: $tenantId,
                        inventoryItemId: $rItem->inventory_item_id,
                        locationId: $receipt->destination_location_id,
                        sourceMovementId: $movement->id,
                        sourceType: 'purchase_receipt',
                        sourceReference: $receipt->reference_number,
                        quantity: sprintf('%.4f', (float) $rItem->quantity),
                        unitCostMinor: (int) $rItem->unit_cost_minor,
                        onHandBefore: $onHandBefore,
                    );

                    // Update PO item received quantity
                    $poItem->received_quantity = bcadd((string) $poItem->received_quantity, (string) $rItem->quantity, 4);
                    $poItem->save();
                }

                // 4. Update PO Status
                $allPoItems = PurchaseOrderItem::where('tenant_id', $tenantId)
                    ->where('purchase_order_id', $po->id)
                    ->get();

                $isFullyReceived = true;
                foreach ($allPoItems as $item) {
                    if (bccomp((string) $item->received_quantity, (string) $item->ordered_quantity, 4) < 0) {
                        $isFullyReceived = false;
                        break;
                    }
                }

                $po->status = $isFullyReceived ? 'received' : 'partially_received';
                $po->save();

                // 5. Transition receipt status to posted
                $receipt->status = 'posted';
                $receipt->received_at = now();
                $receipt->save();

                PurchaseReceiptPosted::dispatch($receipt);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $postedBy,
                    'action' => 'purchase_receipt_posted',
                    'entity_type' => PurchaseReceipt::class,
                    'entity_id' => $receipt->id,
                    'after' => [
                        'status' => 'posted',
                        'po_status' => $po->status,
                        'reference_number' => $receipt->reference_number,
                    ],
                    'created_at' => now(),
                ]);

                return $receipt->load(['items.item', 'purchaseOrder', 'destinationLocation']);
            });
        });
    }

    /**
     * Cancel a draft goods receipt note.
     */
    public function cancelReceipt(string $tenantId, string $receiptId, string $cancelledBy, ?string $reason = null): PurchaseReceipt
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $receiptId, $cancelledBy, $reason) {
            return DB::transaction(function () use ($tenantId, $receiptId, $cancelledBy, $reason) {
                $this->ensureTenantRls($tenantId);

                $receipt = PurchaseReceipt::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($receiptId);

                if ($receipt->status !== 'draft') {
                    throw new PurchaseOrderInvalidStateException("Cannot cancel receipt in status [{$receipt->status}]. Only 'draft' receipts can be cancelled.");
                }

                $receipt->status = 'cancelled';
                if ($reason) {
                    $receipt->notes = trim(($receipt->notes ? $receipt->notes."\n" : '')."Cancellation: {$reason}");
                }
                $receipt->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $cancelledBy,
                    'action' => 'purchase_receipt_cancelled',
                    'entity_type' => PurchaseReceipt::class,
                    'entity_id' => $receipt->id,
                    'after' => ['status' => 'cancelled', 'reason' => $reason],
                    'created_at' => now(),
                ]);

                return $receipt->load(['items.item', 'purchaseOrder', 'destinationLocation']);
            });
        });
    }

    /**
     * Calculate smart reorder recommendations based on PRD Section 13 formula:
     * (Average daily usage × lead time) + safety stock - current available
     *
     * @return array<int, array<string, mixed>>
     */
    public function calculateSmartReorder(string $tenantId, ?string $locationId = null): array
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $locationId) {
            $this->ensureTenantRls($tenantId);

            $locationQuery = InventoryItemLocation::where('tenant_id', $tenantId)
                ->with(['item', 'location']);

            if ($locationId) {
                $locationQuery->where('location_id', $locationId);
            }

            $itemLocations = $locationQuery->get();
            $recommendations = [];

            // 30 days analysis window
            $thirtyDaysAgo = now()->subDays(30);

            // Group recent consumption (sale, production, waste, manual_issue, adjustment_out) by inventory_item_id
            $recentUsages = InventoryMovement::where('tenant_id', $tenantId)
                ->whereIn('movement_type', ['sale', 'production', 'waste', 'manual_issue', 'adjustment_out'])
                ->where('occurred_at', '>=', $thirtyDaysAgo)
                ->selectRaw('inventory_item_id, SUM(ABS(quantity_delta)) as total_consumed')
                ->groupBy('inventory_item_id')
                ->pluck('total_consumed', 'inventory_item_id')
                ->all();

            // Recent supplier and latest unit cost mapping from historical purchase orders
            $latestPoItems = PurchaseOrderItem::where('purchase_order_items.tenant_id', $tenantId)
                ->whereHas('purchaseOrder', function ($q) use ($tenantId) {
                    $q->where('purchase_orders.tenant_id', $tenantId)
                        ->whereIn('status', ['submitted', 'approved', 'partially_received', 'received']);
                })
                ->with('purchaseOrder')
                ->latest()
                ->get()
                ->unique('inventory_item_id')
                ->keyBy('inventory_item_id');

            $suppliers = Supplier::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->get()
                ->keyBy('id');

            foreach ($itemLocations as $itemLoc) {
                $item = $itemLoc->item;
                $location = $itemLoc->location;

                if (! $item || ! $location || $item->status !== 'active') {
                    continue;
                }

                $onHand = (float) $itemLoc->on_hand;
                $reserved = (float) $itemLoc->reserved;
                $currentAvailable = max(0.0, $onHand - $reserved);
                $safetyStock = (float) ($itemLoc->reorder_level ?? 0);

                // Average daily usage over 30 days
                $totalConsumed30d = (float) ($recentUsages[$item->id] ?? 0);
                $averageDailyUsage = $totalConsumed30d > 0 ? round($totalConsumed30d / 30, 4) : 0.0;

                // Lead time in days: default to 3 days if not specified
                $leadTimeDays = 3;

                // Latest PO metadata for supplier recommendation
                $latestPoItem = $latestPoItems->get($item->id);
                $suggestedSupplier = null;
                $latestUnitCostMinor = 0;

                if ($latestPoItem) {
                    $latestUnitCostMinor = (int) $latestPoItem->unit_cost_minor;
                    $supplierId = $latestPoItem->purchaseOrder?->supplier_id;
                    if ($supplierId && isset($suppliers[$supplierId])) {
                        $suggestedSupplier = $suppliers[$supplierId];
                    }
                }

                if (! $suggestedSupplier && $suppliers->isNotEmpty()) {
                    $suggestedSupplier = $suppliers->first();
                }

                // Formula: (Average daily usage × lead time) + safety stock - current available
                $grossNeed = ($averageDailyUsage * $leadTimeDays) + $safetyStock;
                $recommendedQuantity = max(0.0, $grossNeed - $currentAvailable);

                // Item requires reorder if current available <= safety stock or if gross need exceeds available
                $needsReorder = ($currentAvailable <= $safetyStock && $safetyStock > 0) || ($recommendedQuantity > 0 && $averageDailyUsage > 0);

                // If safety stock is set and stock is below safety stock, ensure recommended quantity brings it back above safety stock
                if ($needsReorder && $recommendedQuantity <= 0 && $currentAvailable <= $safetyStock) {
                    $recommendedQuantity = max(1.0, $safetyStock - $currentAvailable);
                }

                $roundedRecommended = ceil($recommendedQuantity * 100) / 100;

                if ($needsReorder && $roundedRecommended > 0) {
                    $recommendations[] = [
                        'inventory_item_id' => $item->id,
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'base_unit' => $item->base_unit,
                        'location_id' => $location->id,
                        'location_name' => $location->name,
                        'current_available' => sprintf('%.4f', $currentAvailable),
                        'safety_stock' => sprintf('%.4f', $safetyStock),
                        'average_daily_usage' => sprintf('%.4f', $averageDailyUsage),
                        'lead_time_days' => $leadTimeDays,
                        'recommended_order_quantity' => sprintf('%.4f', $roundedRecommended),
                        'supplier_id' => $suggestedSupplier?->id,
                        'supplier_name' => $suggestedSupplier?->name,
                        'unit_cost_minor' => $latestUnitCostMinor,
                        'estimated_total_cost_minor' => (int) round($roundedRecommended * $latestUnitCostMinor),
                    ];
                }
            }

            return $recommendations;
        });
    }
}
