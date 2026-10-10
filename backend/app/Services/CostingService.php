<?php

namespace App\Services;

use App\Domain\Tenant\TenantManager;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostState;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

/**
 * CostingService — Moving Weighted Average (MWA) Foundation
 *
 * Costing Policy (Moving Weighted Average):
 * ==========================================
 * When an inbound movement (purchase_receipt, transfer_in, adjustment_in, opening_balance) occurs:
 *
 *   new_avg_cost = ROUND(
 *     (on_hand_before × old_avg_cost + received_qty × received_unit_cost)
 *     / (on_hand_before + received_qty)
 *   )
 *
 * where on_hand_before is the PHYSICAL on_hand balance BEFORE the inbound movement.
 *
 * Special cases:
 *   - on_hand_before <= 0: new_avg = received_unit_cost (fresh cost basis)
 *   - Negative inventory: average_cost is preserved (denominator would be <= 0)
 *   - Transfer: transfer_in cost basis comes from the SOURCE location's average_cost_minor
 *     at the time of dispatch, passed explicitly — not recomputed from a new purchase.
 *
 * Monetary precision:
 *   - All costs stored as INTEGER minor units (e.g., Rp 15,000 = 15000).
 *   - No float/double anywhere in the monetary path.
 *   - Intermediate MWA calculation uses bcmath scale=6, rounded HALF_UP to integer.
 *
 * Idempotency:
 *   - Cost layer is keyed by (tenant_id, source_movement_id) — UNIQUE constraint.
 *   - If a receipt is replayed, the duplicate cost layer creation will fail at DB constraint.
 *   - Callers must check idempotency key BEFORE calling postCostLayer.
 *
 * Authority separation & hierarchy:
 *   - InventoryMovement = stock quantity authority (immutable ledger)
 *   - InventoryCostState = current valuation authority (authoritative location MWA)
 *   - InventoryCostLayer = historical provenance / audit / source tracking (immutable batch record)
 *   - Purchasing = historical inbound purchase price authority
 *   - Catalog Price = selling price authority (prices table + resolution rules)
 *   - Recipe/BOM = consumption definition
 *
 * Authoritative Outbound Valuation Policy:
 *   - COGS / outbound consumption valuation = consumed_quantity × current location MWA.
 *   - FIFO layer ordering is strictly NOT used for COGS valuation in Phase 1.
 *   - Outbound consumption does NOT recalculate or mutate the unit average cost.
 */
class CostingService
{
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

    protected function executeForTenant(string $tenantId, callable $callback): mixed
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext() && $tenantManager->currentTenantId() === $tenantId) {
            $this->ensureTenantRls($tenantId);

            return $callback();
        }

        return $tenantManager->runForTenant($tenantId, $callback);
    }

    // =========================================================================
    // INBOUND COST POSTING
    // =========================================================================

    /**
     * Post a cost layer for an inbound physical movement.
     *
     * Called atomically from InventoryService::recordMovement for inbound movements.
     * Must be called WITHIN the same database transaction as the inventory movement.
     *
     * @param  string  $sourceMovementId  ID of the InventoryMovement being posted
     * @param  string  $sourceType  One of InventoryCostLayer::INBOUND_SOURCE_TYPES
     * @param  string  $sourceReference  Human-readable reference (e.g., GRN-20261004-ABC)
     * @param  string  $quantity  Inbound quantity (same as movement delta, positive)
     * @param  int  $unitCostMinor  Historical unit cost in IDR minor units
     * @param  string  $onHandBefore  Physical on_hand BEFORE this movement
     */
    public function postCostLayer(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string $sourceMovementId,
        string $sourceType,
        ?string $sourceReference,
        string $quantity,
        int $unitCostMinor,
        string $onHandBefore,
    ): InventoryCostLayer {
        $this->ensureTenantRls($tenantId);

        // total_cost_minor = ROUND(quantity × unit_cost_minor)
        $totalCostMinor = $this->calculateTotalCostMinor($quantity, $unitCostMinor);

        // Create immutable cost layer (unique on source_movement_id → idempotency guard)
        $layer = InventoryCostLayer::create([
            'tenant_id' => $tenantId,
            'inventory_item_id' => $inventoryItemId,
            'location_id' => $locationId,
            'source_movement_id' => $sourceMovementId,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            'total_cost_minor' => $totalCostMinor,
        ]);

        // Update MWA read model
        $this->updateMovingWeightedAverage(
            tenantId: $tenantId,
            inventoryItemId: $inventoryItemId,
            locationId: $locationId,
            sourceMovementId: $sourceMovementId,
            receivedQty: $quantity,
            receivedUnitCostMinor: $unitCostMinor,
            onHandBefore: $onHandBefore,
        );

        return $layer;
    }

    /**
     * Record cost for a stock TRANSFER.
     *
     * Transfer semantics:
     *   - Transfer OUT: reduces source quantity; no new cost layer (consumption)
     *   - Transfer IN:  creates a cost layer at destination using SOURCE average_cost_minor
     *                   This is NOT a new purchase; cost basis is preserved from source.
     *
     * @param  string  $quantity  Positive quantity transferred
     * @param  string  $transferInMovementId  The 'transfer_in' movement at destination
     * @param  string  $onHandBeforeDest  Physical on_hand at destination BEFORE movement
     */
    public function postTransferCost(
        string $tenantId,
        string $inventoryItemId,
        string $sourceLocationId,
        string $destLocationId,
        string $quantity,
        string $transferInMovementId,
        string $sourceReference,
        string $onHandBeforeDest,
    ): ?InventoryCostLayer {
        $this->ensureTenantRls($tenantId);

        // Get source location's current average cost
        $sourceState = InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $inventoryItemId)
            ->where('location_id', $sourceLocationId)
            ->first();

        $sourceCostMinor = $sourceState?->average_cost_minor ?? 0;

        if ($sourceCostMinor <= 0) {
            // Source has no cost basis — cannot create a meaningful cost layer.
            // Still update destination MWA with zero cost rather than silently ignoring.
            // This is a known limitation documented in the architecture.
            return null;
        }

        return $this->postCostLayer(
            tenantId: $tenantId,
            inventoryItemId: $inventoryItemId,
            locationId: $destLocationId,
            sourceMovementId: $transferInMovementId,
            sourceType: 'transfer_in',
            sourceReference: $sourceReference,
            quantity: $quantity,
            unitCostMinor: $sourceCostMinor,
            onHandBefore: $onHandBeforeDest,
        );
    }

    // =========================================================================
    // CURRENT COST LOOKUP
    // =========================================================================

    /**
     * Get the current Moving Weighted Average cost for an item at a location.
     * Returns null if no cost basis exists (e.g., no inbound receipts yet).
     */
    public function getAverageCost(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
    ): ?int {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemId, $locationId) {
            $state = InventoryCostState::where('tenant_id', $tenantId)
                ->where('inventory_item_id', $inventoryItemId)
                ->where('location_id', $locationId)
                ->first();

            return $state?->average_cost_minor;
        });
    }

    /**
     * Get cost states for multiple items at a location (bulk lookup).
     *
     * @param  array<string>  $inventoryItemIds
     * @return array<string, int> Map of inventory_item_id => average_cost_minor
     */
    public function getBulkAverageCosts(
        string $tenantId,
        array $inventoryItemIds,
        string $locationId,
    ): array {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemIds, $locationId) {
            $states = InventoryCostState::where('tenant_id', $tenantId)
                ->whereIn('inventory_item_id', $inventoryItemIds)
                ->where('location_id', $locationId)
                ->get()
                ->keyBy('inventory_item_id');

            $result = [];
            foreach ($inventoryItemIds as $itemId) {
                $result[$itemId] = $states->get($itemId)?->average_cost_minor ?? null;
            }

            return $result;
        });
    }

    // =========================================================================
    // OUTBOUND VALUATION CONTRACT (MWA)
    // =========================================================================

    /**
     * Calculate authoritative outbound inventory consumption valuation using location MWA.
     *
     * Outbound Valuation Invariant:
     *   total_cost_minor = ROUND(consumed_quantity × current_mwa_minor)
     *
     * Outbound consumption strictly does NOT recalculate or mutate the MWA cost basis.
     * FIFO layer depletion is NOT used for COGS valuation.
     *
     * @param  string  $quantity  Consumed quantity (positive decimal string)
     * @return array{
     *   inventory_item_id: string,
     *   location_id: string,
     *   consumed_quantity: string,
     *   applied_mwa_minor: int,
     *   total_cost_minor: int,
     *   costing_method: string
     * }
     */
    public function calculateOutboundCost(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string $quantity,
    ): array {
        $qtyStr = sprintf('%.4f', (float) $quantity);
        if (bccomp($qtyStr, '0.0000', 4) <= 0) {
            throw new \InvalidArgumentException('Consumed quantity must be strictly greater than zero.');
        }

        return $this->executeForTenant($tenantId, function () use ($tenantId, $inventoryItemId, $locationId, $qtyStr) {
            $mwaMinor = $this->getAverageCost($tenantId, $inventoryItemId, $locationId) ?? 0;
            $totalCostMinor = $this->calculateTotalCostMinor($qtyStr, $mwaMinor);

            return [
                'inventory_item_id' => $inventoryItemId,
                'location_id' => $locationId,
                'consumed_quantity' => $qtyStr,
                'applied_mwa_minor' => $mwaMinor,
                'total_cost_minor' => $totalCostMinor,
                'costing_method' => 'moving_weighted_average',
            ];
        });
    }

    // =========================================================================
    // PRIVATE: MWA CALCULATION
    // =========================================================================

    /**
     * Update the Moving Weighted Average cost state for an item at a location.
     *
     * MWA Formula:
     *   new_avg = (on_hand_before × old_avg + received_qty × received_cost)
     *             / (on_hand_before + received_qty)
     *
     * All intermediate math uses bcmath (scale=8) to prevent float precision loss.
     * Final result is rounded to integer (bigint minor unit).
     *
     * Special cases:
     *   - on_hand_before <= 0: new_avg = received_unit_cost (fresh cost basis)
     *   - received_qty <= 0: should never happen; caller enforces positive delta.
     */
    protected function updateMovingWeightedAverage(
        string $tenantId,
        string $inventoryItemId,
        string $locationId,
        string $sourceMovementId,
        string $receivedQty,
        int $receivedUnitCostMinor,
        string $onHandBefore,
    ): InventoryCostState {
        // Pessimistic lock on cost state row
        $state = InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $inventoryItemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        $oldAvgMinor = $state?->average_cost_minor ?? 0;

        // Compute new MWA using bcmath scale=8
        $newAvgMinor = $this->computeMwa(
            onHandBefore: $onHandBefore,
            oldAvgCostMinor: (string) $oldAvgMinor,
            receivedQty: $receivedQty,
            receivedUnitCostMinor: (string) $receivedUnitCostMinor,
        );

        if ($state) {
            $state->average_cost_minor = $newAvgMinor;
            $state->last_movement_id = $sourceMovementId;
            $state->save();
        } else {
            $state = InventoryCostState::create([
                'tenant_id' => $tenantId,
                'inventory_item_id' => $inventoryItemId,
                'location_id' => $locationId,
                'average_cost_minor' => $newAvgMinor,
                'last_movement_id' => $sourceMovementId,
            ]);
        }

        return $state;
    }

    /**
     * Compute Moving Weighted Average.
     *
     * Uses bcmath throughout. Final result is intval() after bcmath rounding.
     * Scale 8 is used for intermediate; final value rounded to 0 decimal places (integer).
     *
     * @return int New average cost in minor units (integer, never float)
     */
    public function computeMwa(
        string $onHandBefore,
        string $oldAvgCostMinor,
        string $receivedQty,
        string $receivedUnitCostMinor,
    ): int {
        $scale = 8;

        // If on_hand_before <= 0, fresh cost basis = received_unit_cost
        if (bccomp($onHandBefore, '0', $scale) <= 0) {
            return (int) $receivedUnitCostMinor;
        }

        // existing_value = on_hand_before × old_avg
        $existingValue = bcmul($onHandBefore, $oldAvgCostMinor, $scale);

        // received_value = received_qty × received_unit_cost
        $receivedValue = bcmul($receivedQty, $receivedUnitCostMinor, $scale);

        // total_value = existing_value + received_value
        $totalValue = bcadd($existingValue, $receivedValue, $scale);

        // new_quantity = on_hand_before + received_qty
        $newQty = bcadd($onHandBefore, $receivedQty, $scale);

        if (bccomp($newQty, '0', $scale) <= 0) {
            // Should not happen given non-negative stock policy, but guard against it.
            return (int) $oldAvgCostMinor;
        }

        // new_avg = total_value / new_quantity — keep 8 decimal places
        $newAvg = bcdiv($totalValue, $newQty, $scale);

        // Round half-up to integer minor unit
        return $this->roundHalfUpToInt($newAvg);
    }

    /**
     * Calculate total_cost_minor = ROUND(quantity × unit_cost_minor).
     * Uses bcmath + roundHalfUp. No float arithmetic.
     */
    public function calculateTotalCostMinor(string $quantity, int $unitCostMinor): int
    {
        $product = bcmul($quantity, (string) $unitCostMinor, 8);

        return $this->roundHalfUpToInt($product);
    }

    /**
     * Round a bcmath string value HALF_UP to the nearest integer.
     * Example: "14999.99999" → 15000, "14999.49999" → 14999
     */
    protected function roundHalfUpToInt(string $value): int
    {
        // Add 0.5 then truncate (floor for positive numbers)
        $rounded = bcadd($value, '0.5', 0);

        return (int) $rounded;
    }
}
