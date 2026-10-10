<?php

namespace App\Services;

use App\Domain\Tenant\TenantManager;
use App\Events\InventoryAdjusted;
use App\Events\InventoryOpnameApproved;
use App\Events\InventoryTransferred;
use App\Events\InventoryWrittenOff;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OpnameAlreadyApprovedException;
use App\Exceptions\TransferInvalidStateException;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Notification;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(
        protected ?CostingService $costingService = null,
    ) {
        $this->costingService ??= app(CostingService::class);
    }

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
     * Executes an operation ensuring both in-memory TenantContext (Layer 1) and PostgreSQL RLS (Layer 2) are active.
     */
    public function executeForTenant(string $tenantId, callable $callback): mixed
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext() && $tenantManager->currentTenantId() === $tenantId) {
            $this->ensureTenantRls($tenantId);

            return $callback();
        }

        return $tenantManager->runForTenant($tenantId, $callback);
    }

    /**
     * Atomically record an inventory movement in the immutable ledger
     * and update the materialized location balance read model.
     * Uses pessimistic row locking to prevent race conditions and lost updates.
     */
    public function recordMovement(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string $movementType,
        string|float|int $delta,
        ?string $unit = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $reason = null,
        ?string $note = null,
        ?string $userId = null,
        ?Carbon $occurredAt = null,
        ?int $unitCostMinor = null,
    ): InventoryMovement {
        $deltaStr = sprintf('%.4f', (float) $delta);

        return $this->executeForTenant($tenantId, function () use (
            $tenantId,
            $inventoryItemId,
            $locationId,
            $movementType,
            $deltaStr,
            $unit,
            $referenceType,
            $referenceId,
            $reason,
            $note,
            $userId,
            $occurredAt,
            $unitCostMinor,
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $inventoryItemId,
                $locationId,
                $movementType,
                $deltaStr,
                $unit,
                $referenceType,
                $referenceId,
                $reason,
                $note,
                $userId,
                $occurredAt,
                $unitCostMinor,
            ) {
                $this->ensureTenantRls($tenantId);

                // 1. Fetch item and lock location balance row
                $item = InventoryItem::where('tenant_id', $tenantId)->findOrFail($inventoryItemId);
                $location = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($locationId);

                // Ensure read model record exists first
                $itemLoc = InventoryItemLocation::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $item->id)
                    ->where('location_id', $location->id)
                    ->lockForUpdate()
                    ->first();

                if (! $itemLoc) {
                    $itemLoc = InventoryItemLocation::create([
                        'tenant_id' => $tenantId,
                        'inventory_item_id' => $item->id,
                        'location_id' => $location->id,
                        'on_hand' => 0,
                        'reserved' => 0,
                        'reorder_level' => 0,
                    ]);

                    // Re-lock
                    $itemLoc = InventoryItemLocation::where('id', $itemLoc->id)->lockForUpdate()->first();
                }

                $beforeOnHand = sprintf('%.4f', (float) $itemLoc->on_hand);
                $afterOnHand = bcadd($beforeOnHand, $deltaStr, 4);

                // 2. Negative stock policy check
                if (bccomp($afterOnHand, '0.0000', 4) < 0 && ! $item->allow_negative_stock) {
                    throw new InsufficientStockException(
                        "Insufficient stock for item [{$item->name}] at location [{$location->name}]. Requested delta [{$deltaStr}], current on hand [{$beforeOnHand}]."
                    );
                }

                // 3. Generate tenant-scoped human-readable movement number
                $datePrefix = ($occurredAt ?? now())->format('Ymd');
                $randomSuffix = strtoupper(Str::random(6));
                $movementNumber = "INV-{$datePrefix}-{$randomSuffix}";

                // 4. Create immutable movement ledger entry
                $movement = InventoryMovement::create([
                    'tenant_id' => $tenantId,
                    'movement_number' => $movementNumber,
                    'inventory_item_id' => $item->id,
                    'location_id' => $location->id,
                    'movement_type' => $movementType,
                    'quantity_delta' => $deltaStr,
                    'unit' => $unit ?: $item->base_unit,
                    'before_on_hand' => $beforeOnHand,
                    'after_on_hand' => $afterOnHand,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => $reason,
                    'note' => $note,
                    'occurred_at' => $occurredAt ?? now(),
                    'created_by' => $userId,
                ]);

                // 5. Update materialized read model
                $itemLoc->on_hand = $afterOnHand;
                $itemLoc->save();

                if (bccomp((string) $itemLoc->reorder_level, '0.0000', 4) > 0) {
                    if ($itemLoc->isLowStock()) {
                        app(NotificationService::class)->createNotification($tenantId, [
                            'type' => Notification::TYPE_LOW_STOCK,
                            'title' => 'Stok Menipis',
                            'message' => "Stok item {$item->name} di {$location->name} tersisa {$itemLoc->available} {$item->base_unit} (ambang batas: {$itemLoc->reorder_level}).",
                            'severity' => Notification::SEVERITY_WARNING,
                            'data' => [
                                'inventory_item_id' => $item->id,
                                'location_id' => $location->id,
                                'outlet_id' => $location->outlet_id,
                                'item_name' => $item->name,
                                'location_name' => $location->name,
                                'available' => $itemLoc->available,
                                'reorder_level' => $itemLoc->reorder_level,
                            ],
                            'dedup_key' => "low_stock:item_{$item->id}:loc_{$location->id}",
                        ]);
                    } else {
                        app(NotificationService::class)->resolveNotification($tenantId, "low_stock:item_{$item->id}:loc_{$location->id}");
                    }
                }

                // 6. Inbound cost layer posting for adjustment_in / opening_balance
                if (in_array($movementType, ['adjustment_in', 'opening_balance'], true) && bccomp($deltaStr, '0.0000', 4) > 0) {
                    $costBasis = $unitCostMinor;
                    if ($costBasis === null && $this->costingService) {
                        $costBasis = $this->costingService->getAverageCost($tenantId, $item->id, $location->id);
                    }

                    if ($costBasis !== null && $costBasis > 0) {
                        $this->costingService->postCostLayer(
                            tenantId: $tenantId,
                            inventoryItemId: $item->id,
                            locationId: $location->id,
                            sourceMovementId: $movement->id,
                            sourceType: $movementType,
                            sourceReference: $movement->movement_number,
                            quantity: $deltaStr,
                            unitCostMinor: $costBasis,
                            onHandBefore: $beforeOnHand,
                        );
                    }
                }

                return $movement;
            });
        });
    }

    /**
     * Set initial opening balance for an item at a location.
     */
    public function setOpeningBalance(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string|float|int $quantity,
        ?string $note = null,
        ?string $userId = null,
        ?int $unitCostMinor = null,
    ): InventoryMovement {
        $qtyStr = sprintf('%.4f', (float) $quantity);

        if (bccomp($qtyStr, '0.0000', 4) < 0) {
            throw new \InvalidArgumentException('Opening balance quantity cannot be negative.');
        }

        return $this->recordMovement(
            tenantId: $tenantId,
            inventoryItemId: $inventoryItemId,
            locationId: $locationId,
            movementType: 'opening_balance',
            delta: $qtyStr,
            reason: 'Opening Balance',
            note: $note ?: 'Initial stock opening balance',
            userId: $userId,
            unitCostMinor: $unitCostMinor,
        );
    }

    /**
     * Command-based stock adjustment (delta).
     */
    public function adjustStock(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string|float|int $quantityDelta,
        string $reason,
        ?string $note = null,
        ?string $userId = null,
        ?int $unitCostMinor = null,
    ): InventoryMovement {
        $deltaStr = sprintf('%.4f', (float) $quantityDelta);
        $cmp = bccomp($deltaStr, '0.0000', 4);

        if ($cmp === 0) {
            throw new \InvalidArgumentException('Adjustment delta cannot be zero.');
        }

        $movementType = $cmp > 0 ? 'adjustment_in' : 'adjustment_out';
        if (in_array(strtolower($reason), ['waste', 'expired'])) {
            $movementType = strtolower($reason);
        }

        $movement = $this->recordMovement(
            tenantId: $tenantId,
            inventoryItemId: $inventoryItemId,
            locationId: $locationId,
            movementType: $movementType,
            delta: $deltaStr,
            reason: $reason,
            note: $note,
            userId: $userId,
            unitCostMinor: $unitCostMinor,
        );

        if ($movementType === 'waste' || $movementType === 'expired') {
            InventoryWrittenOff::dispatch($movement);
        } else {
            InventoryAdjusted::dispatch($movement);
        }

        return $movement;
    }

    /**
     * Reserve available stock for future consumption (e.g. pending orders).
     * Physical on_hand is NEVER mutated by reservation.
     */
    public function reserveStock(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string|float|int $quantity,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $userId = null,
    ): InventoryItemLocation {
        $qtyStr = sprintf('%.4f', (float) $quantity);

        if (bccomp($qtyStr, '0.0000', 4) <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be strictly greater than zero.');
        }

        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemId, $locationId, $qtyStr, $referenceType, $referenceId, $userId) {
            return DB::transaction(function () use ($tenantId, $inventoryItemId, $locationId, $qtyStr, $referenceType, $referenceId, $userId) {
                $this->ensureTenantRls($tenantId);

                $item = InventoryItem::where('tenant_id', $tenantId)->findOrFail($inventoryItemId);
                $location = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($locationId);

                $itemLoc = InventoryItemLocation::firstOrCreate(
                    ['tenant_id' => $tenantId, 'inventory_item_id' => $item->id, 'location_id' => $location->id],
                    ['on_hand' => '0.0000', 'reserved' => '0.0000', 'reorder_level' => '0.0000']
                );

                $itemLoc = InventoryItemLocation::where('id', $itemLoc->id)->lockForUpdate()->first();

                $available = bcsub((string) $itemLoc->on_hand, (string) $itemLoc->reserved, 4);

                if (bccomp($available, $qtyStr, 4) < 0 && ! $item->allow_negative_stock) {
                    throw new InsufficientStockException("Insufficient available stock to reserve. Available: [{$available}], Requested: [{$qtyStr}].");
                }

                $itemLoc->reserved = bcadd((string) $itemLoc->reserved, $qtyStr, 4);
                $itemLoc->save();

                // Record reservation movement with delta 0 (physical on_hand unchanged)
                InventoryMovement::create([
                    'tenant_id' => $tenantId,
                    'movement_number' => 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'inventory_item_id' => $item->id,
                    'location_id' => $location->id,
                    'movement_type' => 'reserved',
                    'quantity_delta' => '0.0000',
                    'unit' => $item->base_unit,
                    'before_on_hand' => $itemLoc->on_hand,
                    'after_on_hand' => $itemLoc->on_hand,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => 'Stock reservation',
                    'note' => "Reserved {$qtyStr} {$item->base_unit}",
                    'occurred_at' => now(),
                    'created_by' => $userId,
                ]);

                return $itemLoc;
            });
        });
    }

    /**
     * Release reserved stock back to available balance.
     * Physical on_hand is NEVER mutated by releasing.
     * Reserved cannot be negative.
     */
    public function releaseStock(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string|float|int $quantity,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $userId = null,
    ): InventoryItemLocation {
        $qtyStr = sprintf('%.4f', (float) $quantity);

        if (bccomp($qtyStr, '0.0000', 4) <= 0) {
            throw new \InvalidArgumentException('Release quantity must be strictly greater than zero.');
        }

        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemId, $locationId, $qtyStr, $referenceType, $referenceId, $userId) {
            return DB::transaction(function () use ($tenantId, $inventoryItemId, $locationId, $qtyStr, $referenceType, $referenceId, $userId) {
                $this->ensureTenantRls($tenantId);

                $item = InventoryItem::where('tenant_id', $tenantId)->findOrFail($inventoryItemId);
                $location = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($locationId);

                $itemLoc = InventoryItemLocation::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $item->id)
                    ->where('location_id', $location->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (bccomp((string) $itemLoc->reserved, $qtyStr, 4) < 0) {
                    throw new \InvalidArgumentException("Cannot release more stock than currently reserved. Reserved: [{$itemLoc->reserved}], Requested: [{$qtyStr}].");
                }

                $newReserved = bcsub((string) $itemLoc->reserved, $qtyStr, 4);

                $itemLoc->reserved = $newReserved;
                $itemLoc->save();

                // Record release movement with delta 0 (physical on_hand unchanged)
                InventoryMovement::create([
                    'tenant_id' => $tenantId,
                    'movement_number' => 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'inventory_item_id' => $item->id,
                    'location_id' => $location->id,
                    'movement_type' => 'released',
                    'quantity_delta' => '0.0000',
                    'unit' => $item->base_unit,
                    'before_on_hand' => $itemLoc->on_hand,
                    'after_on_hand' => $itemLoc->on_hand,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => 'Stock reservation released',
                    'note' => "Released {$qtyStr} {$item->base_unit}",
                    'occurred_at' => now(),
                    'created_by' => $userId,
                ]);

                return $itemLoc;
            });
        });
    }

    /**
     * Create Stock Opname count session.
     * Note: Counting physically DOES NOT mutate stock.
     */
    public function createOpname(
        string $tenantId,
        string $locationId,
        array $items,
        string $countedBy,
        ?string $notes = null,
    ): StockOpname {
        return DB::transaction(function () use ($tenantId, $locationId, $items, $countedBy, $notes) {
            $this->ensureTenantRls($tenantId);

            $location = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($locationId);

            $opnameNumber = 'OPN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

            $opname = StockOpname::create([
                'tenant_id' => $tenantId,
                'opname_number' => $opnameNumber,
                'location_id' => $location->id,
                'status' => 'draft',
                'notes' => $notes,
                'counted_by' => $countedBy,
            ]);

            foreach ($items as $itemData) {
                $invItem = InventoryItem::where('tenant_id', $tenantId)->findOrFail($itemData['inventory_item_id']);

                $itemLoc = InventoryItemLocation::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $invItem->id)
                    ->where('location_id', $location->id)
                    ->first();

                $systemQty = $itemLoc ? sprintf('%.4f', (float) $itemLoc->on_hand) : '0.0000';
                $physicalQty = sprintf('%.4f', (float) $itemData['physical_quantity']);
                $diff = bcsub($physicalQty, $systemQty, 4);

                StockOpnameItem::create([
                    'tenant_id' => $tenantId,
                    'opname_id' => $opname->id,
                    'inventory_item_id' => $invItem->id,
                    'system_quantity' => $systemQty,
                    'physical_quantity' => $physicalQty,
                    'difference' => $diff,
                    'reason' => $itemData['reason'] ?? null,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            return $opname->load(['items.item', 'location', 'counter']);
        });
    }

    /**
     * Review and approve Stock Opname.
     * Generates immutable movement adjustments for all non-zero discrepancies.
     */
    public function approveOpname(
        string $tenantId,
        string $opnameId,
        string $approvedBy,
    ): StockOpname {
        return DB::transaction(function () use ($tenantId, $opnameId, $approvedBy) {
            $this->ensureTenantRls($tenantId);

            $opname = StockOpname::where('tenant_id', $tenantId)
                ->where('id', $opnameId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($opname->status === 'approved') {
                throw new OpnameAlreadyApprovedException("Stock Opname [{$opname->opname_number}] has already been approved.");
            }

            $items = StockOpnameItem::where('tenant_id', $tenantId)
                ->where('opname_id', $opname->id)
                ->get();

            foreach ($items as $item) {
                // If there is a difference between physical and system count, record stock_opname movement
                if (bccomp((string) $item->difference, '0.0000', 4) !== 0) {
                    $this->recordMovement(
                        tenantId: $tenantId,
                        inventoryItemId: $item->inventory_item_id,
                        locationId: $opname->location_id,
                        movementType: 'stock_opname',
                        delta: $item->difference,
                        referenceType: StockOpname::class,
                        referenceId: $opname->id,
                        reason: $item->reason ?: 'Stock Opname Adjustment',
                        note: "Discrepancy adjustment from Opname {$opname->opname_number}",
                        userId: $approvedBy,
                    );
                }
            }

            $opname->status = 'approved';
            $opname->approved_by = $approvedBy;
            $opname->approved_at = now();
            $opname->save();

            InventoryOpnameApproved::dispatch($opname);

            return $opname->load(['items.item', 'location', 'counter', 'approver']);
        });
    }

    /**
     * Create Stock Transfer request.
     */
    public function createTransfer(
        string $tenantId,
        string $sourceLocationId,
        string $destinationLocationId,
        array $items,
        string $requestedBy,
        ?string $notes = null,
    ): StockTransfer {
        if ($sourceLocationId === $destinationLocationId) {
            throw new \InvalidArgumentException('Source and destination locations must be different.');
        }

        return $this->executeForTenant($tenantId, function () use ($tenantId, $sourceLocationId, $destinationLocationId, $items, $requestedBy, $notes) {
            return DB::transaction(function () use ($tenantId, $sourceLocationId, $destinationLocationId, $items, $requestedBy, $notes) {
                $this->ensureTenantRls($tenantId);

                $src = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($sourceLocationId);
                $dst = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($destinationLocationId);

                $transferNumber = 'TRF-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $transfer = StockTransfer::create([
                    'tenant_id' => $tenantId,
                    'transfer_number' => $transferNumber,
                    'source_location_id' => $src->id,
                    'destination_location_id' => $dst->id,
                    'status' => 'pending',
                    'notes' => $notes,
                    'requested_by' => $requestedBy,
                ]);

                foreach ($items as $itemData) {
                    $invItem = InventoryItem::where('tenant_id', $tenantId)->findOrFail($itemData['inventory_item_id']);
                    $qtyStr = sprintf('%.4f', (float) $itemData['quantity']);

                    if (bccomp($qtyStr, '0.0000', 4) <= 0) {
                        throw new \InvalidArgumentException("Transfer quantity must be greater than zero for item [{$invItem->name}].");
                    }

                    StockTransferItem::create([
                        'tenant_id' => $tenantId,
                        'transfer_id' => $transfer->id,
                        'inventory_item_id' => $invItem->id,
                        'quantity' => $qtyStr,
                    ]);
                }

                return $transfer->load(['items.item', 'sourceLocation', 'destinationLocation', 'requester']);
            });
        });
    }

    /**
     * Dispatch stock transfer: deducts stock from source location (transfer_out).
     */
    public function dispatchTransfer(
        string $tenantId,
        string $transferId,
        string $approvedBy,
    ): StockTransfer {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $transferId, $approvedBy) {
            return DB::transaction(function () use ($tenantId, $transferId, $approvedBy) {
                $this->ensureTenantRls($tenantId);

                $transfer = StockTransfer::where('tenant_id', $tenantId)
                    ->where('id', $transferId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($transfer->status, ['draft', 'pending', 'approved'])) {
                    throw new TransferInvalidStateException("Cannot dispatch transfer in status [{$transfer->status}].");
                }

                $items = StockTransferItem::where('tenant_id', $tenantId)->where('transfer_id', $transfer->id)->get();

                foreach ($items as $item) {
                    $negativeDelta = bcmul((string) $item->quantity, '-1', 4);

                    $this->recordMovement(
                        tenantId: $tenantId,
                        inventoryItemId: $item->inventory_item_id,
                        locationId: $transfer->source_location_id,
                        movementType: 'transfer_out',
                        delta: $negativeDelta,
                        referenceType: StockTransfer::class,
                        referenceId: $transfer->id,
                        reason: 'Transfer Dispatch',
                        note: "Dispatched transfer {$transfer->transfer_number}",
                        userId: $approvedBy,
                    );
                }

                $transfer->status = 'in_transit';
                $transfer->approved_by = $approvedBy;
                $transfer->dispatched_at = now();
                $transfer->save();

                return $transfer->load(['items.item', 'sourceLocation', 'destinationLocation']);
            });
        });
    }

    /**
     * Receive stock transfer: adds stock to destination location (transfer_in).
     * Also creates cost layer at destination using source location's MWA — cost basis is preserved.
     */
    public function receiveTransfer(
        string $tenantId,
        string $transferId,
        string $receivedBy,
    ): StockTransfer {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $transferId, $receivedBy) {
            return DB::transaction(function () use ($tenantId, $transferId, $receivedBy) {
                $this->ensureTenantRls($tenantId);

                $transfer = StockTransfer::where('tenant_id', $tenantId)
                    ->where('id', $transferId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($transfer->status !== 'in_transit') {
                    throw new TransferInvalidStateException("Cannot receive transfer in status [{$transfer->status}]. Expected 'in_transit'.");
                }

                $items = StockTransferItem::where('tenant_id', $tenantId)->where('transfer_id', $transfer->id)->get();

                foreach ($items as $item) {
                    // Capture on_hand at destination BEFORE movement for MWA
                    $destLocBefore = InventoryItemLocation::where('tenant_id', $tenantId)
                        ->where('inventory_item_id', $item->inventory_item_id)
                        ->where('location_id', $transfer->destination_location_id)
                        ->first();
                    $onHandBeforeDest = $destLocBefore ? sprintf('%.4f', (float) $destLocBefore->on_hand) : '0.0000';

                    $movement = $this->recordMovement(
                        tenantId: $tenantId,
                        inventoryItemId: $item->inventory_item_id,
                        locationId: $transfer->destination_location_id,
                        movementType: 'transfer_in',
                        delta: $item->quantity,
                        referenceType: StockTransfer::class,
                        referenceId: $transfer->id,
                        reason: 'Transfer Receive',
                        note: "Received transfer {$transfer->transfer_number}",
                        userId: $receivedBy,
                    );

                    // Post transfer cost layer — cost basis from source location MWA
                    if ($this->costingService) {
                        $this->costingService->postTransferCost(
                            tenantId: $tenantId,
                            inventoryItemId: $item->inventory_item_id,
                            sourceLocationId: $transfer->source_location_id,
                            destLocationId: $transfer->destination_location_id,
                            quantity: sprintf('%.4f', (float) $item->quantity),
                            transferInMovementId: $movement->id,
                            sourceReference: $transfer->transfer_number,
                            onHandBeforeDest: $onHandBeforeDest,
                        );
                    }
                }

                $transfer->status = 'received';
                $transfer->received_by = $receivedBy;
                $transfer->received_at = now();
                $transfer->save();

                InventoryTransferred::dispatch($transfer);

                return $transfer->load(['items.item', 'sourceLocation', 'destinationLocation']);
            });
        });
    }

    /**
     * Reconcile balance between authoritative ledger sum and materialized read model.
     */
    public function reconcile(string $tenantId, string $inventoryItemId, string $locationId): array
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemId, $locationId) {
            $ledgerSum = InventoryMovement::where('tenant_id', $tenantId)
                ->where('inventory_item_id', $inventoryItemId)
                ->where('location_id', $locationId)
                ->whereIn('movement_type', InventoryMovement::PHYSICAL_MOVEMENT_TYPES)
                ->sum('quantity_delta');

            $ledgerSumStr = sprintf('%.4f', (float) $ledgerSum);

            $readModel = InventoryItemLocation::where('tenant_id', $tenantId)
                ->where('inventory_item_id', $inventoryItemId)
                ->where('location_id', $locationId)
                ->first();

            $materializedOnHand = $readModel ? sprintf('%.4f', (float) $readModel->on_hand) : '0.0000';
            $reserved = $readModel ? sprintf('%.4f', (float) $readModel->reserved) : '0.0000';
            $available = bcsub($materializedOnHand, $reserved, 4);

            return [
                'tenant_id' => $tenantId,
                'inventory_item_id' => $inventoryItemId,
                'location_id' => $locationId,
                'ledger_sum' => $ledgerSumStr,
                'materialized_on_hand' => $materializedOnHand,
                'reserved' => $reserved,
                'available' => $available,
                'is_consistent' => bccomp($ledgerSumStr, $materializedOnHand, 4) === 0,
            ];
        });
    }

    /**
     * Rebuild materialized balance projection from the authoritative ledger.
     * Posted movements are strictly untouched; produces an audit log with provenance.
     */
    public function repairProjection(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string $repairedBy,
        string $reason,
    ): array {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemId, $locationId, $repairedBy, $reason) {
            return DB::transaction(function () use ($tenantId, $inventoryItemId, $locationId, $repairedBy, $reason) {
                $this->ensureTenantRls($tenantId);

                $itemLoc = InventoryItemLocation::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $inventoryItemId)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->first();

                $ledgerSum = InventoryMovement::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $inventoryItemId)
                    ->where('location_id', $locationId)
                    ->whereIn('movement_type', InventoryMovement::PHYSICAL_MOVEMENT_TYPES)
                    ->sum('quantity_delta');

                $ledgerSumStr = sprintf('%.4f', (float) $ledgerSum);

                if (! $itemLoc) {
                    $itemLoc = InventoryItemLocation::create([
                        'tenant_id' => $tenantId,
                        'inventory_item_id' => $inventoryItemId,
                        'location_id' => $locationId,
                        'on_hand' => $ledgerSumStr,
                        'reserved' => '0.0000',
                        'reorder_level' => '0.0000',
                    ]);
                    $beforeOnHand = '0.0000';
                } else {
                    $beforeOnHand = sprintf('%.4f', (float) $itemLoc->on_hand);
                    $itemLoc->on_hand = $ledgerSumStr;
                    $itemLoc->save();
                }

                // Audit log with full provenance
                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $repairedBy,
                    'action' => 'inventory_projection_rebuilt',
                    'entity_type' => InventoryItemLocation::class,
                    'entity_id' => $itemLoc->id,
                    'before' => ['on_hand' => $beforeOnHand],
                    'after' => [
                        'on_hand' => $ledgerSumStr,
                        'reason' => $reason,
                        'ledger_sum' => $ledgerSumStr,
                    ],
                    'created_at' => now(),
                ]);

                return [
                    'repaired' => true,
                    'before_on_hand' => $beforeOnHand,
                    'after_on_hand' => $ledgerSumStr,
                    'ledger_sum' => $ledgerSumStr,
                    'reason' => $reason,
                ];
            });
        });
    }
}
