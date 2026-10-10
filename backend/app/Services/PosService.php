<?php

namespace App\Services;

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\TenantManager;
use App\Events\SaleCancelled;
use App\Events\SaleFinalized;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PriceNotConfiguredException;
use App\Exceptions\ProductNotAvailableAtOutletException;
use App\Exceptions\RecipeNotConfiguredException;
use App\Exceptions\SaleInvalidStateException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\Modifier;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\SaleConsumption;
use App\Models\SaleItem;
use App\Models\SaleItemModifier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PosService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected CostingService $costingService,
        protected RecipeService $recipeService,
    ) {}

    protected function ensureTenantRls(string $tenantId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);
        } catch (\Throwable) {
            // Ignored if non-pgsql connection or testing mock
        }
    }

    /**
     * Resolve active tenant id from parameter or tenant context.
     */
    protected function resolveTenantId(?string $explicitTenantId = null): string
    {
        if ($explicitTenantId) {
            return $explicitTenantId;
        }

        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext()) {
            return $tenantManager->currentTenantId();
        }

        if (app()->bound(TenantContext::class)) {
            $context = app(TenantContext::class);
            if ($context->hasTenant()) {
                return $context->id();
            }
        }

        throw new \RuntimeException('Tenant context could not be resolved for POS operation.');
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

    /**
     * List sales for a tenant / outlet with optional filters.
     */
    public function listSales(
        string|array|null $tenantOrFilters = null,
        string|array|null $outletOrFilters = null,
        ?string $status = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        if (is_array($tenantOrFilters)) {
            $tenantId = $this->resolveTenantId();
            $filters = $tenantOrFilters;
            $perPage = (int) ($outletOrFilters ?? 20);
        } else {
            $tenantId = $this->resolveTenantId($tenantOrFilters);
            $filters = is_array($outletOrFilters) ? $outletOrFilters : [
                'outlet_id' => $outletOrFilters,
                'status' => $status,
            ];
        }

        return $this->executeForTenant($tenantId, function () use ($tenantId, $filters, $perPage) {
            $query = Sale::where('tenant_id', $tenantId)
                ->with(['items.variant.product', 'items.modifiers', 'outlet', 'cashier', 'customer'])
                ->orderByDesc('opened_at');

            if (! empty($filters['outlet_id'])) {
                $query->where('outlet_id', $filters['outlet_id']);
            } elseif (! empty($filters['assigned_outlet_ids'])) {
                $query->whereIn('outlet_id', $filters['assigned_outlet_ids']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['cashier_user_id'])) {
                $query->where('cashier_user_id', $filters['cashier_user_id']);
            }
            if (! empty($filters['customer_id'])) {
                $query->where('customer_id', $filters['customer_id']);
            }
            if (! empty($filters['date_from'])) {
                $query->where('opened_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->where('opened_at', '<=', $filters['date_to']);
            }
            if (! empty($filters['search'])) {
                $query->where('reference_number', 'ilike', "%{$filters['search']}%");
            }

            return $query->paginate($perPage);
        });
    }

    /**
     * Get a sale with full relations.
     */
    public function getSale(string|Sale $sale, ?string $tenantId = null): Sale
    {
        if ($sale instanceof Sale) {
            return $sale->loadMissing([
                'items.variant.product',
                'items.modifiers',
                'consumptions.inventoryItem',
                'consumptions.location',
                'outlet',
                'cashier',
                'customer',
            ]);
        }

        $resolvedTenantId = $this->resolveTenantId($tenantId);

        return $this->executeForTenant($resolvedTenantId, function () use ($resolvedTenantId, $sale) {
            return Sale::where('tenant_id', $resolvedTenantId)
                ->with([
                    'items.variant.product',
                    'items.modifiers',
                    'consumptions.inventoryItem',
                    'consumptions.location',
                    'outlet',
                    'cashier',
                    'customer',
                ])
                ->findOrFail($sale);
        });
    }

    /**
     * Create an open sale (cart/order).
     */
    public function createSale(
        string|array $tenantId,
        ?string $outletId = null,
        ?string $cashierUserId = null,
        array $items = [],
        ?string $notes = null,
        ?string $customerId = null,
        ?string $id = null,
    ): Sale {
        if (is_array($tenantId)) {
            $data = $tenantId;
            $secondArg = $outletId;
            $tenantId = $data['tenant_id'] ?? $this->resolveTenantId();
            $outletId = $data['outlet_id'] ?? null;
            $cashierUserId = $data['cashier_user_id'] ?? $cashierUserId ?? $secondArg ?? auth()->id() ?? '00000000-0000-0000-0000-000000000000';
            $items = $data['items'] ?? $items;
            $notes = $data['notes'] ?? $notes;
            $customerId = $data['customer_id'] ?? $customerId;
            $id = $data['id'] ?? $id;
        }

        return $this->executeForTenant($tenantId, function () use (
            $tenantId,
            $outletId,
            $cashierUserId,
            $items,
            $notes,
            $customerId,
            $id
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $outletId,
                $cashierUserId,
                $items,
                $notes,
                $customerId,
                $id
            ) {
                $this->ensureTenantRls($tenantId);

                // Verify outlet belongs to tenant
                $outlet = Outlet::where('tenant_id', $tenantId)->findOrFail($outletId);

                // Verify customer belongs to tenant if provided
                if ($customerId) {
                    Customer::where('tenant_id', $tenantId)->findOrFail($customerId);
                }

                // Generate Reference: SALE-YYYYMMDD-XXXXXX
                $dateStr = now()->format('Ymd');
                $randomSuffix = strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 6));
                $ref = "SALE-{$dateStr}-{$randomSuffix}";

                $saleAttributes = [
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outlet->id,
                    'reference_number' => $ref,
                    'status' => Sale::STATUS_OPEN,
                    'opened_at' => now(),
                    'cashier_user_id' => $cashierUserId,
                    'customer_id' => $customerId,
                    'subtotal_minor' => 0,
                    'discount_minor' => 0,
                    'tax_minor' => 0,
                    'total_minor' => 0,
                    'notes' => $notes,
                ];

                if (! empty($id)) {
                    $saleAttributes['id'] = $id;
                }

                $sale = Sale::create($saleAttributes);

                if (! empty($items)) {
                    $this->syncSaleItems($tenantId, $sale, $items);
                }

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $cashierUserId,
                    'action' => 'sale_created',
                    'entity_type' => Sale::class,
                    'entity_id' => $sale->id,
                    'after' => ['reference_number' => $ref, 'outlet_id' => $outletId],
                    'created_at' => now(),
                ]);

                return $this->getSale($sale, $tenantId);
            });
        });
    }

    /**
     * Update an open sale (cart modifications).
     */
    public function updateSale(
        Sale|string $sale,
        array $data,
        ?string $userId = null,
        ?string $tenantId = null,
    ): Sale {
        if ($sale instanceof Sale) {
            $saleId = $sale->id;
            $tenantId = $tenantId ?? $sale->tenant_id;
        } else {
            $saleId = $sale;
            $tenantId = $this->resolveTenantId($tenantId);
        }
        $userId = $userId ?? auth()->id() ?? '00000000-0000-0000-0000-000000000000';

        return $this->executeForTenant($tenantId, function () use ($tenantId, $saleId, $data) {
            return DB::transaction(function () use ($tenantId, $saleId, $data) {
                $this->ensureTenantRls($tenantId);

                $sale = Sale::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($saleId);

                if (! $sale->isOpen()) {
                    throw new SaleInvalidStateException("Cannot update sale in status [{$sale->status}]. Only 'open' sales can be modified.");
                }

                if (array_key_exists('notes', $data)) {
                    $sale->notes = $data['notes'];
                }
                if (array_key_exists('customer_id', $data)) {
                    if (! empty($data['customer_id'])) {
                        Customer::where('tenant_id', $tenantId)->findOrFail($data['customer_id']);
                    }
                    $sale->customer_id = $data['customer_id'];
                }
                if (array_key_exists('discount_minor', $data)) {
                    $sale->discount_minor = max(0, (int) $data['discount_minor']);
                }
                if (array_key_exists('tax_minor', $data)) {
                    $sale->tax_minor = max(0, (int) $data['tax_minor']);
                }

                $sale->save();

                if (array_key_exists('items', $data)) {
                    // Replace items
                    SaleItemModifier::where('tenant_id', $tenantId)->where('sale_id', $sale->id)->delete();
                    SaleItem::where('tenant_id', $tenantId)->where('sale_id', $sale->id)->delete();

                    if (! empty($data['items'])) {
                        $this->syncSaleItems($tenantId, $sale, $data['items']);
                    }
                }

                return $this->getSale($sale, $tenantId);
            });
        });
    }

    /**
     * Synchronize items into an open sale, calculating provisional prices.
     */
    protected function syncSaleItems(string $tenantId, Sale $sale, array $items): void
    {
        $subtotal = 0;

        foreach ($items as $itemData) {
            $variant = ProductVariant::where('tenant_id', $tenantId)
                ->with('product')
                ->findOrFail($itemData['product_variant_id']);

            if (! $variant->product || ! $variant->product->isAvailableAtOutlet($sale->outlet_id)) {
                throw new ProductNotAvailableAtOutletException("Product [{$variant->product?->name}] is not available at this outlet.");
            }

            $qtyStr = sprintf('%.4f', (float) $itemData['quantity']);
            if (bccomp($qtyStr, '0.0000', 4) <= 0) {
                throw new \InvalidArgumentException("Sale item quantity must be greater than zero for [{$variant->name}].");
            }

            // Tentative price resolution for open cart
            $price = $variant->resolvePrice($sale->outlet_id);
            $unitPriceMinor = $price?->amount ?? 0;
            $lineSubtotal = (int) bcmul($qtyStr, (string) $unitPriceMinor, 0);
            $lineDiscount = max(0, (int) ($itemData['discount_minor'] ?? 0));
            $lineTotal = max(0, $lineSubtotal - $lineDiscount);

            $itemAttributes = [
                'tenant_id' => $tenantId,
                'sale_id' => $sale->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $variant->product->name,
                'variant_name_snapshot' => $variant->name,
                'quantity' => $qtyStr,
                'unit_price_minor' => $unitPriceMinor,
                'subtotal_minor' => $lineSubtotal,
                'discount_minor' => $lineDiscount,
                'total_minor' => $lineTotal,
                'price_id' => $price?->id,
                'notes' => $itemData['notes'] ?? null,
            ];

            if (! empty($itemData['id'])) {
                $itemAttributes['id'] = $itemData['id'];
            }

            $saleItem = SaleItem::create($itemAttributes);

            $modTotal = 0;
            if (! empty($itemData['modifiers'])) {
                foreach ($itemData['modifiers'] as $modData) {
                    $modifier = Modifier::where('tenant_id', $tenantId)
                        ->with('group')
                        ->findOrFail($modData['modifier_id']);

                    $perUnitModQty = sprintf('%.4f', (float) ($modData['quantity'] ?? 1));
                    $totalModQty = bcmul($qtyStr, $perUnitModQty, 4);
                    $modPrice = (int) $modifier->price_delta;
                    $modSubtotal = (int) bcmul($totalModQty, (string) $modPrice, 0);

                    SaleItemModifier::create([
                        'tenant_id' => $tenantId,
                        'sale_id' => $sale->id,
                        'sale_item_id' => $saleItem->id,
                        'modifier_id' => $modifier->id,
                        'modifier_name_snapshot' => $modifier->name,
                        'modifier_group_name_snapshot' => $modifier->group?->name ?? 'Modifiers',
                        'quantity' => $totalModQty,
                        'unit_price_minor' => $modPrice,
                        'subtotal_minor' => $modSubtotal,
                    ]);

                    $modTotal += $modSubtotal;
                }
            }

            $saleItem->subtotal_minor += $modTotal;
            $saleItem->total_minor = max(0, $saleItem->subtotal_minor - $saleItem->discount_minor);
            $saleItem->save();

            $subtotal += $saleItem->total_minor;
        }

        $sale->subtotal_minor = $subtotal;
        $sale->total_minor = max(0, $subtotal - $sale->discount_minor + $sale->tax_minor);
        $sale->save();
    }

    /**
     * Atomically finalize a sale.
     */
    public function finalizeSale(
        Sale|string $sale,
        array $options = [],
        ?string $userId = null,
        ?string $tenantId = null,
    ): Sale {
        if ($sale instanceof Sale) {
            $saleId = $sale->id;
            $tenantId = $tenantId ?? $sale->tenant_id;
        } else {
            $saleId = $sale;
            $tenantId = $this->resolveTenantId($tenantId);
        }

        $userId = $userId ?? auth()->id() ?? '00000000-0000-0000-0000-000000000000';
        $discountMinor = $options['discount_minor'] ?? null;
        $taxMinor = $options['tax_minor'] ?? null;
        $locationId = $options['location_id'] ?? null;

        return $this->executeForTenant($tenantId, function () use (
            $tenantId,
            $saleId,
            $userId,
            $discountMinor,
            $taxMinor,
            $locationId
        ) {
            return DB::transaction(function () use (
                $tenantId,
                $saleId,
                $userId,
                $discountMinor,
                $taxMinor,
                $locationId
            ) {
                $this->ensureTenantRls($tenantId);

                // 1. Lock Sale header
                $sale = Sale::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($saleId);

                if (! $sale->isOpen()) {
                    throw new SaleInvalidStateException("Cannot finalize sale [{$sale->reference_number}] in status [{$sale->status}]. Expected 'open'.");
                }

                $saleItems = SaleItem::where('tenant_id', $tenantId)->where('sale_id', $sale->id)->get();
                if ($saleItems->isEmpty()) {
                    throw new SaleInvalidStateException('Cannot finalize sale with no items.');
                }

                // 2. Resolve Server-Authoritative Inventory Location for Outlet
                $outlet = Outlet::where('tenant_id', $tenantId)->findOrFail($sale->outlet_id);
                $targetLocation = $outlet->resolvePosInventoryLocation($locationId);

                $now = now();
                $saleSubtotal = 0;
                $allConsumptionRequirements = [];

                // 3. Process each SaleItem: resolve selling price, snapshot modifiers, explode recipe & determine consumption plan
                foreach ($saleItems as $item) {
                    $variant = ProductVariant::where('tenant_id', $tenantId)
                        ->with('product')
                        ->findOrFail($item->product_variant_id);

                    if (! $variant->product || ! $variant->product->isAvailableAtOutlet($sale->outlet_id)) {
                        throw new ProductNotAvailableAtOutletException("Product [{$variant->product?->name}] is not available at outlet [{$outlet->name}].");
                    }

                    // A. Price Domain Resolution (Precedence: Outlet -> Tenant default)
                    $resolvedPrice = $variant->resolvePrice($sale->outlet_id);
                    if (! $resolvedPrice) {
                        throw new PriceNotConfiguredException("No active selling price configured for variant [{$variant->name}] (outlet: {$sale->outlet_id}). Finalization rejected.");
                    }

                    $item->price_id = $resolvedPrice->id;
                    $item->unit_price_minor = (int) $resolvedPrice->amount;
                    $itemBaseSubtotal = (int) bcmul((string) $item->quantity, (string) $item->unit_price_minor, 0);

                    // Snapshot variant and product names
                    $item->product_name_snapshot = $variant->product->name;
                    $item->variant_name_snapshot = $variant->name;

                    // B. Modifiers Snapshot
                    $itemMods = SaleItemModifier::where('tenant_id', $tenantId)
                        ->where('sale_item_id', $item->id)
                        ->get();

                    $modTotal = 0;
                    foreach ($itemMods as $mod) {
                        if ($mod->modifier_id) {
                            $modModel = Modifier::where('tenant_id', $tenantId)->with('group')->find($mod->modifier_id);
                            if ($modModel) {
                                $mod->modifier_name_snapshot = $modModel->name;
                                $mod->modifier_group_name_snapshot = $modModel->group?->name ?? 'Modifiers';
                                $mod->unit_price_minor = (int) $modModel->price_delta;
                            }
                        }
                        $mod->subtotal_minor = (int) bcmul((string) $mod->quantity, (string) $mod->unit_price_minor, 0);
                        $mod->save();
                        $modTotal += $mod->subtotal_minor;
                    }

                    $item->subtotal_minor = $itemBaseSubtotal + $modTotal;
                    $item->total_minor = max(0, $item->subtotal_minor - $item->discount_minor);

                    // C. Recipe Resolution, BOM Explosion & Physical Consumption Plan
                    try {
                        $resolved = $this->recipeService->resolveRecipe(
                            tenantId: $tenantId,
                            productVariantId: $variant->id,
                            at: $now,
                        );

                        // Pure theoretical BOM explosion
                        $exploded = $this->recipeService->explodeRecipe(
                            tenantId: $tenantId,
                            recipeId: $resolved['recipe']->id,
                            versionId: $resolved['version']->id,
                            multiplier: (string) $item->quantity,
                            effectiveAt: $now,
                        );

                        // Physical consumption plan adhering to production_mode / consumption_mode
                        $consumptionRes = $this->resolvePhysicalConsumptionRequirements(
                            tenantId: $tenantId,
                            version: $resolved['version'],
                            multiplier: (string) $item->quantity,
                            effectiveAt: $now,
                        );

                        // Snapshot recipe, theoretical explosion, and actual consumption plan
                        $item->recipe_version_id = $resolved['version']->id;
                        $item->recipe_snapshot = [
                            'recipe_id' => $resolved['recipe']->id,
                            'recipe_name' => $resolved['recipe']->name,
                            'version_id' => $resolved['version']->id,
                            'version_number' => $resolved['version']->version_number,
                            'yield_quantity' => (float) $resolved['version']->yield_quantity,
                            'yield_unit' => $resolved['version']->yield_unit,
                            'theoretical_leaves' => $exploded['leaf_components'],
                            'consumption_plan' => $consumptionRes['consumption_tree'],
                        ];

                        foreach ($consumptionRes['requirements'] as $req) {
                            $allConsumptionRequirements[] = [
                                'sale_item_id' => $item->id,
                                'inventory_item_id' => $req['inventory_item_id'],
                                'required_quantity' => $req['quantity'],
                                'unit' => $req['unit'],
                                'consumption_mode' => $req['consumption_mode'],
                                'recipe_component_snapshot' => $req['component_snapshot'],
                            ];
                        }
                    } catch (RecipeNotConfiguredException $e) {
                        // Direct retail item (no recipe): check if inventory item matches variant
                        $directInventoryItem = InventoryItem::where('tenant_id', $tenantId)
                            ->where('product_variant_id', $variant->id)
                            ->first();

                        if ($directInventoryItem) {
                            $allConsumptionRequirements[] = [
                                'sale_item_id' => $item->id,
                                'inventory_item_id' => $directInventoryItem->id,
                                'required_quantity' => (float) $item->quantity,
                                'unit' => $directInventoryItem->unit,
                                'recipe_component_snapshot' => [
                                    'inventory_item_id' => $directInventoryItem->id,
                                    'name' => $directInventoryItem->name,
                                    'required_quantity' => (float) $item->quantity,
                                    'unit' => $directInventoryItem->unit,
                                    'type' => 'direct_variant',
                                ],
                            ];
                        }
                        // Non-inventory service items require no physical stock
                    }

                    $item->save();
                    $saleSubtotal += $item->total_minor;
                }

                // 4. Aggregate required inventory by inventory_item_id
                $aggregatedRequirements = [];
                foreach ($allConsumptionRequirements as $req) {
                    $itemId = $req['inventory_item_id'];
                    if (! isset($aggregatedRequirements[$itemId])) {
                        $aggregatedRequirements[$itemId] = 0.0;
                    }
                    $aggregatedRequirements[$itemId] += $req['required_quantity'];
                }

                // 5. Deterministic Deadlock-Free Locking: Sort inventory item IDs ascending
                $sortedItemIds = array_keys($aggregatedRequirements);
                sort($sortedItemIds);

                $inventoryItemModels = [];
                $inventoryLocationRows = [];
                $costStates = [];

                foreach ($sortedItemIds as $itemId) {
                    // Lock InventoryItem
                    $itemModel = InventoryItem::where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->findOrFail($itemId);
                    $inventoryItemModels[$itemId] = $itemModel;

                    // Lock InventoryItemLocation
                    $locRow = InventoryItemLocation::where('tenant_id', $tenantId)
                        ->where('inventory_item_id', $itemId)
                        ->where('location_id', $targetLocation->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $locRow) {
                        $locRow = InventoryItemLocation::create([
                            'tenant_id' => $tenantId,
                            'inventory_item_id' => $itemId,
                            'location_id' => $targetLocation->id,
                            'on_hand' => 0.0,
                            'reserved' => 0.0,
                        ]);
                    }
                    $inventoryLocationRows[$itemId] = $locRow;

                    // Lock InventoryCostState for MWA snapshot
                    $costState = InventoryCostState::where('tenant_id', $tenantId)
                        ->where('inventory_item_id', $itemId)
                        ->where('location_id', $targetLocation->id)
                        ->lockForUpdate()
                        ->first();
                    $costStates[$itemId] = $costState;
                }

                // 6. Validate Availability Atomically across all items
                foreach ($sortedItemIds as $itemId) {
                    $reqQty = $aggregatedRequirements[$itemId];
                    $locRow = $inventoryLocationRows[$itemId];
                    $itemModel = $inventoryItemModels[$itemId];

                    $available = (float) $locRow->on_hand - (float) $locRow->reserved;

                    if ($available < $reqQty && ! $itemModel->allow_negative_stock) {
                        throw new InsufficientStockException(
                            "Insufficient stock for inventory item [{$itemModel->name}]. Required: {$reqQty} {$itemModel->unit}, available: {$available} {$itemModel->unit} at location [{$targetLocation->name}]."
                        );
                    }
                }

                // 7. Debit Inventory & Record Immutable Sale Consumptions with Captured MWA
                foreach ($allConsumptionRequirements as $req) {
                    $itemId = $req['inventory_item_id'];
                    $qty = $req['required_quantity'];
                    $costState = $costStates[$itemId];
                    $mwa = $costState ? (int) $costState->average_cost_minor : 0;
                    $totalCogsMinor = (int) bcmul(sprintf('%.4f', $qty), (string) $mwa, 0);

                    // Record physical inventory movement
                    $negDelta = (float) bcmul(sprintf('%.4f', $qty), '-1', 4);

                    $movement = $this->inventoryService->recordMovement(
                        tenantId: $tenantId,
                        inventoryItemId: $itemId,
                        locationId: $targetLocation->id,
                        movementType: 'sales_consumption',
                        delta: $negDelta,
                        unit: $req['unit'],
                        referenceType: Sale::class,
                        referenceId: $sale->id,
                        reason: 'POS Sale Consumption',
                        note: "Finalized {$sale->reference_number}",
                        userId: $userId,
                    );

                    // Immutable COGS / Consumption snapshot
                    SaleConsumption::create([
                        'tenant_id' => $tenantId,
                        'sale_id' => $sale->id,
                        'sale_item_id' => $req['sale_item_id'],
                        'inventory_item_id' => $itemId,
                        'location_id' => $targetLocation->id,
                        'consumed_quantity' => $qty,
                        'unit_cost_minor' => $mwa,
                        'total_cost_minor' => $totalCogsMinor,
                        'inventory_movement_id' => $movement->id,
                        'recipe_component_snapshot' => $req['recipe_component_snapshot'],
                    ]);
                }

                // 8. Update Sale totals and mark finalized
                if ($discountMinor !== null) {
                    $sale->discount_minor = max(0, $discountMinor);
                }
                if ($taxMinor !== null) {
                    $sale->tax_minor = max(0, $taxMinor);
                }

                $sale->subtotal_minor = $saleSubtotal;
                $sale->total_minor = max(0, $saleSubtotal - $sale->discount_minor + $sale->tax_minor);
                $sale->status = Sale::STATUS_FINALIZED;
                $sale->finalized_at = $now;
                $sale->save();

                // 9. Write audit log
                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'sale_finalized',
                    'entity_type' => Sale::class,
                    'entity_id' => $sale->id,
                    'after' => [
                        'status' => 'finalized',
                        'total_minor' => $sale->total_minor,
                        'reference_number' => $sale->reference_number,
                    ],
                    'created_at' => now(),
                ]);

                SaleFinalized::dispatch($sale);

                return $this->getSale($sale, $tenantId);
            });
        });
    }

    /**
     * Cancel a sale.
     *
     * If sale is open: marks cancelled; no stock mutation.
     * If sale is finalized: records compensating physical movements at original MWA cost basis.
     */
    public function cancelSale(
        Sale|string $sale,
        ?string $reason = null,
        ?string $userId = null,
        ?string $tenantId = null,
    ): Sale {
        if ($sale instanceof Sale) {
            $saleId = $sale->id;
            $tenantId = $tenantId ?? $sale->tenant_id;
        } else {
            $saleId = $sale;
            $tenantId = $this->resolveTenantId($tenantId);
        }
        $userId = $userId ?? auth()->id() ?? '00000000-0000-0000-0000-000000000000';

        return $this->executeForTenant($tenantId, function () use ($tenantId, $saleId, $userId, $reason) {
            return DB::transaction(function () use ($tenantId, $saleId, $userId, $reason) {
                $this->ensureTenantRls($tenantId);

                $sale = Sale::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($saleId);

                if ($sale->isCancelled()) {
                    throw new SaleInvalidStateException("Sale [{$sale->reference_number}] is already cancelled.");
                }

                if ($sale->isFinalized()) {
                    // Compensating inventory movements for all consumed items
                    $consumptions = SaleConsumption::where('tenant_id', $tenantId)
                        ->where('sale_id', $sale->id)
                        ->get();

                    foreach ($consumptions as $consump) {
                        $this->inventoryService->recordMovement(
                            tenantId: $tenantId,
                            inventoryItemId: $consump->inventory_item_id,
                            locationId: $consump->location_id,
                            movementType: 'sales_reversal',
                            delta: $consump->consumed_quantity,
                            referenceType: Sale::class,
                            referenceId: $sale->id,
                            reason: 'Sale Cancellation Reversal',
                            note: 'Compensating movement for cancelled sale '.$sale->reference_number.($reason ? ": {$reason}" : ''),
                            userId: $userId,
                            unitCostMinor: $consump->unit_cost_minor,
                        );
                    }
                }

                $sale->status = Sale::STATUS_CANCELLED;
                $sale->cancelled_at = now();
                $sale->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'sale_cancelled',
                    'entity_type' => Sale::class,
                    'entity_id' => $sale->id,
                    'after' => [
                        'status' => 'cancelled',
                        'reason' => $reason,
                    ],
                    'created_at' => now(),
                ]);

                SaleCancelled::dispatch($sale);

                return $this->getSale($sale, $tenantId);
            });
        });
    }

    /**
     * Deterministically resolves the physical inventory consumption requirements for a recipe version.
     * Enforces the production mode / consumption policy:
     *  - Mode A (stocked): consumes the semi-finished item itself from stock; does NOT explode into child components.
     *  - Mode B (make-to-order): explodes child recipe; consumes child components; does NOT consume the semi-finished item.
     * Guarantees zero double consumption.
     *
     * @return array{
     *   requirements: array<int, array{inventory_item_id: string, name: string, quantity: float, unit: string, consumption_mode: string, component_snapshot: array}>,
     *   consumption_tree: array
     * }
     */
    public function resolvePhysicalConsumptionRequirements(
        string $tenantId,
        RecipeVersion $version,
        string $multiplier,
        \DateTimeInterface $effectiveAt,
        int $depth = 0
    ): array {
        if ($depth > 10) {
            throw new \DomainException('Maximum recipe consumption resolution depth (10) exceeded.');
        }

        $version->loadMissing('components.inventoryItem');
        $yieldQty = (string) $version->yield_quantity;
        $scale = 8;

        $requirements = [];
        $consumptionTree = [];

        foreach ($version->components as $comp) {
            $item = $comp->inventoryItem;
            if (! $item) {
                continue;
            }

            // gross_quantity = quantity * (1 + waste_factor)
            $wasteMultiplier = bcadd('1', (string) $comp->waste_factor, $scale);
            $grossQty = bcmul((string) $comp->quantity, $wasteMultiplier, $scale);

            // quantity_per_yield = gross_quantity / yield_quantity
            $qtyPerYield = bcdiv($grossQty, $yieldQty, $scale);

            // effective_quantity = qty_per_yield * multiplier
            $effectiveQty = bcmul($qtyPerYield, $multiplier, $scale);
            $effectiveQtyFloat = (float) sprintf('%.4f', (float) $effectiveQty);

            // Check if this component has a published child recipe (semi-finished good)
            $childRecipe = Recipe::where('tenant_id', $tenantId)
                ->where('inventory_item_id', $item->id)
                ->where('status', Recipe::STATUS_PUBLISHED)
                ->first();

            $mode = $comp->effectiveConsumptionMode(); // 'stocked' or 'make_to_order'

            if ($childRecipe && $mode === RecipeComponent::CONSUMPTION_MODE_MAKE_TO_ORDER) {
                // Mode B: MAKE-TO-ORDER -> Explode child recipe, consume child components, do NOT consume item itself
                $childVersion = RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $childRecipe->id)
                    ->where('status', RecipeVersion::STATUS_PUBLISHED)
                    ->where('effective_from', '<=', $effectiveAt)
                    ->where(function ($q) use ($effectiveAt) {
                        $q->whereNull('effective_until')->orWhere('effective_until', '>', $effectiveAt);
                    })
                    ->first();

                if ($childVersion) {
                    $childRes = $this->resolvePhysicalConsumptionRequirements(
                        $tenantId,
                        $childVersion,
                        (string) $effectiveQty,
                        $effectiveAt,
                        $depth + 1
                    );

                    $consumptionTree[] = [
                        'inventory_item_id' => $item->id,
                        'name' => $item->name,
                        'component_type' => $comp->component_type,
                        'consumption_mode' => 'make_to_order',
                        'required_quantity' => $effectiveQtyFloat,
                        'unit' => $comp->unit,
                        'child_recipe_id' => $childRecipe->id,
                        'child_version_id' => $childVersion->id,
                        'child_requirements' => $childRes['consumption_tree'],
                    ];

                    foreach ($childRes['requirements'] as $childReq) {
                        $requirements[] = $childReq;
                    }

                    continue;
                }
            }

            // Mode A (stocked) OR standard raw material / packaging:
            // Consume THIS inventory item directly! Child recipe components are NOT consumed.
            $consumptionMode = $childRecipe ? 'stocked' : 'direct';

            $requirements[] = [
                'inventory_item_id' => $item->id,
                'name' => $item->name,
                'quantity' => $effectiveQtyFloat,
                'unit' => $comp->unit,
                'consumption_mode' => $consumptionMode,
                'component_snapshot' => [
                    'inventory_item_id' => $item->id,
                    'name' => $item->name,
                    'item_type' => $item->item_type,
                    'quantity' => $effectiveQtyFloat,
                    'unit' => $comp->unit,
                    'consumption_mode' => $consumptionMode,
                ],
            ];

            $consumptionTree[] = [
                'inventory_item_id' => $item->id,
                'name' => $item->name,
                'component_type' => $comp->component_type,
                'consumption_mode' => $consumptionMode,
                'required_quantity' => $effectiveQtyFloat,
                'unit' => $comp->unit,
            ];
        }

        return [
            'requirements' => $requirements,
            'consumption_tree' => $consumptionTree,
        ];
    }
}
