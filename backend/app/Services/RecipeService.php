<?php

namespace App\Services;

use App\Domain\Tenant\TenantManager;
use App\Events\RecipeArchived;
use App\Events\RecipePublished;
use App\Exceptions\RecipeCycleDetectedException;
use App\Exceptions\RecipeNotConfiguredException;
use App\Exceptions\RecipeVersionInvalidStateException;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RecipeService — Recipe/BOM Domain Logic
 *
 * Responsibilities:
 *   - Recipe lifecycle: create, update, archive
 *   - Version management: create, publish, archive
 *   - Component management: add, update, remove
 *   - Cycle detection in BOM graph
 *   - Deterministic recipe resolution by (tenant, variant, timestamp)
 *   - Estimated recipe cost calculation (read-only)
 *
 * Invariants:
 *   - A recipe belongs to exactly one ProductVariant.
 *   - Published recipe versions are immutable in their formulation.
 *   - Only one published version may be effective at any given timestamp.
 *   - Cycle detection runs at publish time.
 *   - Published version requires at least one component.
 *   - Costs are estimated from location-scoped MWA; never authoritative HPP.
 */
class RecipeService
{
    public function __construct(
        protected CostingService $costingService,
    ) {}

    protected function ensureTenantRls(string $tenantId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);
        } catch (\Throwable) {
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
    // RECIPE CRUD
    // =========================================================================

    /**
     * Create a new recipe for a product variant.
     */
    public function createRecipe(string $tenantId, array $data, string $userId): Recipe
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $data, $userId) {
            return DB::transaction(function () use ($tenantId, $data, $userId) {
                $this->ensureTenantRls($tenantId);

                $variantId = $data['product_variant_id'] ?? null;
                $itemId = $data['inventory_item_id'] ?? null;

                if (($variantId && $itemId) || (! $variantId && ! $itemId)) {
                    throw new \InvalidArgumentException('Recipe must be assigned to either a product_variant_id or an inventory_item_id, exactly one.');
                }

                if ($variantId) {
                    $existing = Recipe::where('tenant_id', $tenantId)
                        ->where('product_variant_id', $variantId)
                        ->first();
                    if ($existing) {
                        throw new DomainException("A recipe already exists for product variant [{$variantId}].");
                    }
                } else {
                    $existing = Recipe::where('tenant_id', $tenantId)
                        ->where('inventory_item_id', $itemId)
                        ->first();
                    if ($existing) {
                        throw new DomainException("A recipe already exists for inventory item [{$itemId}].");
                    }
                }

                $recipe = Recipe::create([
                    'tenant_id' => $tenantId,
                    'product_variant_id' => $variantId,
                    'inventory_item_id' => $itemId,
                    'name' => trim($data['name']),
                    'code' => isset($data['code']) ? strtoupper(trim($data['code'])) : null,
                    'status' => Recipe::STATUS_DRAFT,
                    'created_by' => $userId,
                ]);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'recipe_created',
                    'entity_type' => Recipe::class,
                    'entity_id' => $recipe->id,
                    'after' => [
                        'name' => $recipe->name,
                        'product_variant_id' => $recipe->product_variant_id,
                        'inventory_item_id' => $recipe->inventory_item_id,
                        'status' => $recipe->status,
                    ],
                    'created_at' => now(),
                ]);

                return $recipe->load(['variant.product', 'inventoryItem', 'creator']);
            });
        });
    }

    /**
     * Update draft recipe metadata.
     * Published/archived recipes may only change status via explicit transitions.
     */
    public function updateRecipe(string $tenantId, string $recipeId, array $data, string $userId): Recipe
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $data, $userId) {
            return DB::transaction(function () use ($tenantId, $recipeId, $data, $userId) {
                $this->ensureTenantRls($tenantId);

                $recipe = Recipe::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($recipeId);
                $before = $recipe->only(['name', 'code', 'status']);

                if (! $recipe->isDraft()) {
                    throw new RecipeVersionInvalidStateException(
                        "Only draft recipes can have their metadata updated. Recipe [{$recipeId}] is [{$recipe->status}]."
                    );
                }

                if (isset($data['name'])) {
                    $recipe->name = trim($data['name']);
                }
                if (array_key_exists('code', $data)) {
                    $recipe->code = $data['code'] ? strtoupper(trim($data['code'])) : null;
                }
                $recipe->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'recipe_updated',
                    'entity_type' => Recipe::class,
                    'entity_id' => $recipe->id,
                    'before' => $before,
                    'after' => $recipe->only(['name', 'code', 'status']),
                    'created_at' => now(),
                ]);

                return $recipe->load(['variant.product', 'creator']);
            });
        });
    }

    /**
     * Archive a recipe. All published versions are also archived.
     */
    public function archiveRecipe(string $tenantId, string $recipeId, string $userId): Recipe
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $userId) {
            return DB::transaction(function () use ($tenantId, $recipeId, $userId) {
                $this->ensureTenantRls($tenantId);

                $recipe = Recipe::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($recipeId);

                if ($recipe->isArchived()) {
                    throw new RecipeVersionInvalidStateException("Recipe [{$recipeId}] is already archived.");
                }

                $recipe->status = Recipe::STATUS_ARCHIVED;
                $recipe->save();

                // Archive published versions
                RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipe->id)
                    ->where('status', RecipeVersion::STATUS_PUBLISHED)
                    ->update(['status' => RecipeVersion::STATUS_ARCHIVED]);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'recipe_archived',
                    'entity_type' => Recipe::class,
                    'entity_id' => $recipe->id,
                    'after' => ['status' => 'archived'],
                    'created_at' => now(),
                ]);

                RecipeArchived::dispatch($recipe);

                return $recipe->load(['variant.product']);
            });
        });
    }

    // =========================================================================
    // VERSION MANAGEMENT
    // =========================================================================

    /**
     * Create a new recipe version (always draft).
     * Copies are NOT made from existing versions — each version is explicitly defined.
     */
    public function createVersion(string $tenantId, string $recipeId, array $data, string $userId): RecipeVersion
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $data, $userId) {
            return DB::transaction(function () use ($tenantId, $recipeId, $data, $userId) {
                $this->ensureTenantRls($tenantId);

                $recipe = Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);

                if ($recipe->isArchived()) {
                    throw new RecipeVersionInvalidStateException('Cannot add versions to an archived recipe.');
                }

                // Auto-increment version number within tenant scope
                $nextVersionNumber = (RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipe->id)
                    ->max('version_number') ?? 0) + 1;

                $version = RecipeVersion::create([
                    'tenant_id' => $tenantId,
                    'recipe_id' => $recipe->id,
                    'version_number' => $nextVersionNumber,
                    'status' => RecipeVersion::STATUS_DRAFT,
                    'effective_from' => $data['effective_from'],
                    'effective_until' => $data['effective_until'] ?? null,
                    'yield_quantity' => $data['yield_quantity'],
                    'yield_unit' => $data['yield_unit'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'recipe_version_created',
                    'entity_type' => RecipeVersion::class,
                    'entity_id' => $version->id,
                    'after' => [
                        'recipe_id' => $recipe->id,
                        'version_number' => $nextVersionNumber,
                        'effective_from' => (string) $data['effective_from'],
                    ],
                    'created_at' => now(),
                ]);

                return $version->load(['recipe', 'components.inventoryItem', 'creator']);
            });
        });
    }

    /**
     * Publish a recipe version.
     *
     * Pre-conditions:
     *   1. Version must be in draft status.
     *   2. Version must have at least one component.
     *   3. Cycle detection must pass.
     *   4. Effective period must not overlap any existing published version for the same recipe.
     *      (Enforced at DB level via exclusion constraint — exception will propagate from DB.)
     *   5. Recipe is transitioned to 'published' if it was 'draft'.
     */
    public function publishVersion(string $tenantId, string $recipeId, string $versionId, string $userId): RecipeVersion
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $versionId, $userId) {
            return DB::transaction(function () use ($tenantId, $recipeId, $versionId, $userId) {
                $this->ensureTenantRls($tenantId);

                $recipe = Recipe::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($recipeId);
                $version = RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipeId)
                    ->lockForUpdate()
                    ->findOrFail($versionId);

                if (! $version->isDraft()) {
                    throw new RecipeVersionInvalidStateException(
                        "Only draft versions can be published. Version [{$versionId}] is [{$version->status}]."
                    );
                }

                $components = RecipeComponent::where('tenant_id', $tenantId)
                    ->where('recipe_version_id', $version->id)
                    ->with('inventoryItem')
                    ->get();

                if ($components->isEmpty()) {
                    throw new \InvalidArgumentException(
                        'A recipe version must have at least one component before it can be published.'
                    );
                }

                // Validate unit consistency: component unit must match InventoryItem base_unit
                foreach ($components as $comp) {
                    if ($comp->unit !== $comp->inventoryItem->base_unit) {
                        throw new \InvalidArgumentException(
                            "Component unit [{$comp->unit}] does not match InventoryItem base_unit [{$comp->inventoryItem->base_unit}] for item [{$comp->inventoryItem->name}]. Recipe component quantities must use the item's declared base unit."
                        );
                    }
                }

                // Cycle detection
                $this->detectCycles($tenantId, $recipeId, $components->pluck('inventory_item_id')->all());

                // Publish version
                $version->status = RecipeVersion::STATUS_PUBLISHED;
                $version->published_by = $userId;
                $version->published_at = now();

                try {
                    $version->save(); // DB exclusion constraint will enforce non-overlap
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'excl_recipe_versions_no_overlap') || $e->getCode() === '23P01') {
                        throw new RecipeVersionInvalidStateException(
                            "Cannot publish recipe version [{$versionId}]: effective period overlaps with an already published version for this recipe."
                        );
                    }
                    throw $e;
                }

                // Promote recipe to published if it was still draft
                if ($recipe->isDraft()) {
                    $recipe->status = Recipe::STATUS_PUBLISHED;
                    $recipe->save();
                }

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'recipe_published',
                    'entity_type' => RecipeVersion::class,
                    'entity_id' => $version->id,
                    'after' => [
                        'recipe_id' => $recipeId,
                        'version_number' => $version->version_number,
                        'published_at' => $version->published_at->toIso8601String(),
                    ],
                    'created_at' => now(),
                ]);

                RecipePublished::dispatch($recipe, $version->id);

                return $version->load(['recipe', 'components.inventoryItem', 'creator', 'publisher']);
            });
        });
    }

    /**
     * Archive a recipe version.
     */
    public function archiveVersion(string $tenantId, string $recipeId, string $versionId, string $userId): RecipeVersion
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $versionId, $userId) {
            return DB::transaction(function () use ($tenantId, $recipeId, $versionId, $userId) {
                $this->ensureTenantRls($tenantId);

                $version = RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipeId)
                    ->lockForUpdate()
                    ->findOrFail($versionId);

                if ($version->isArchived()) {
                    throw new RecipeVersionInvalidStateException("Version [{$versionId}] is already archived.");
                }

                $version->status = RecipeVersion::STATUS_ARCHIVED;
                $version->save();

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $userId,
                    'action' => 'recipe_archived',
                    'entity_type' => RecipeVersion::class,
                    'entity_id' => $version->id,
                    'after' => ['status' => 'archived', 'version_number' => $version->version_number],
                    'created_at' => now(),
                ]);

                return $version->load(['recipe', 'components.inventoryItem']);
            });
        });
    }

    // =========================================================================
    // COMPONENT MANAGEMENT
    // =========================================================================

    /**
     * Set components for a draft recipe version (replace-all semantics).
     * Components may only be set on draft versions.
     *
     * Each component item:
     *   - inventory_item_id (uuid)
     *   - quantity (numeric, positive)
     *   - unit (string — must match InventoryItem.base_unit)
     *   - component_type (raw_material | packaging | semi_finished | finished_good)
     *   - waste_factor (0..0.9999, default 0)
     *   - sequence (optional int)
     *   - notes (optional string)
     */
    public function setComponents(string $tenantId, string $recipeId, string $versionId, array $components, string $userId): RecipeVersion
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $versionId, $components) {
            return DB::transaction(function () use ($tenantId, $recipeId, $versionId, $components) {
                $this->ensureTenantRls($tenantId);

                $version = RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipeId)
                    ->lockForUpdate()
                    ->findOrFail($versionId);

                if (! $version->isDraft()) {
                    throw new RecipeVersionInvalidStateException(
                        "Components can only be modified on draft recipe versions. Version [{$versionId}] is [{$version->status}]."
                    );
                }

                // Validate all inventory items exist within tenant and have allowed types
                $itemIds = array_column($components, 'inventory_item_id');
                $inventoryItems = InventoryItem::where('tenant_id', $tenantId)
                    ->whereIn('id', $itemIds)
                    ->get()
                    ->keyBy('id');

                foreach ($components as $comp) {
                    $itemId = $comp['inventory_item_id'];

                    if (! $inventoryItems->has($itemId)) {
                        throw new \InvalidArgumentException("Inventory item [{$itemId}] does not exist in this tenant.");
                    }

                    $item = $inventoryItems->get($itemId);

                    // Unit consistency check
                    $unit = $comp['unit'] ?? $item->base_unit;
                    if ($unit !== $item->base_unit) {
                        throw new \InvalidArgumentException(
                            "Component unit [{$unit}] does not match InventoryItem [{$item->name}] base_unit [{$item->base_unit}]. No implicit unit conversion is supported in Phase 1B.4."
                        );
                    }

                    // Component type must be allowed
                    $compType = $comp['component_type'] ?? 'raw_material';
                    if (! in_array($compType, RecipeComponent::ALLOWED_TYPES)) {
                        throw new \InvalidArgumentException(
                            "Component type [{$compType}] is not allowed. Allowed: ".implode(', ', RecipeComponent::ALLOWED_TYPES)
                        );
                    }
                }

                // Replace all components (delete + recreate within transaction)
                RecipeComponent::where('tenant_id', $tenantId)
                    ->where('recipe_version_id', $version->id)
                    ->delete();

                foreach ($components as $comp) {
                    $item = $inventoryItems->get($comp['inventory_item_id']);
                    RecipeComponent::create([
                        'tenant_id' => $tenantId,
                        'recipe_version_id' => $version->id,
                        'inventory_item_id' => $comp['inventory_item_id'],
                        'quantity' => sprintf('%.4f', (float) $comp['quantity']),
                        'unit' => $item->base_unit,
                        'component_type' => $comp['component_type'] ?? 'raw_material',
                        'waste_factor' => sprintf('%.4f', (float) ($comp['waste_factor'] ?? 0)),
                        'sequence' => $comp['sequence'] ?? null,
                        'notes' => $comp['notes'] ?? null,
                    ]);
                }

                return $version->load(['recipe', 'components.inventoryItem', 'creator']);
            });
        });
    }

    // =========================================================================
    // RECIPE RESOLUTION
    // =========================================================================

    /**
     * Resolve the effective recipe version for a given variant at a given timestamp.
     *
     * Algorithm:
     *   1. Find the single published recipe for the variant within tenant.
     *   2. Find the single published version where:
     *      effective_from <= $at AND (effective_until IS NULL OR effective_until > $at)
     *   3. If zero or multiple versions match, throw deterministic error.
     *
     * @param  Carbon|null  $at  Defaults to now()
     * @return array{ recipe: Recipe, version: RecipeVersion, components: Collection }
     *
     * @throws RecipeNotConfiguredException if no published recipe exists
     * @throws RecipeVersionInvalidStateException if no effective version found at $at
     */
    public function resolveRecipe(
        string $tenantId,
        ?string $productVariantId = null,
        ?Carbon $at = null,
        ?string $locationId = null,
        ?string $inventoryItemId = null,
        ?string $recipeId = null,
    ): array {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $productVariantId, $at, $locationId, $inventoryItemId, $recipeId) {
            $at = $at ?? now();

            $query = Recipe::where('tenant_id', $tenantId)
                ->where('status', Recipe::STATUS_PUBLISHED);

            if ($recipeId) {
                $query->where('id', $recipeId);
            } elseif ($productVariantId) {
                $query->where('product_variant_id', $productVariantId);
            } elseif ($inventoryItemId) {
                $query->where('inventory_item_id', $inventoryItemId);
            } else {
                throw new \InvalidArgumentException('Either recipeId, productVariantId, or inventoryItemId must be provided.');
            }

            $recipe = $query->first();

            if (! $recipe) {
                $targetDesc = $productVariantId ? "variant [{$productVariantId}]" : ($inventoryItemId ? "inventory item [{$inventoryItemId}]" : "recipe [{$recipeId}]");
                throw new RecipeNotConfiguredException(
                    "No published recipe configured for {$targetDesc}."
                );
            }

            $versions = RecipeVersion::where('tenant_id', $tenantId)
                ->where('recipe_id', $recipe->id)
                ->where('status', RecipeVersion::STATUS_PUBLISHED)
                ->where('effective_from', '<=', $at)
                ->where(function ($q) use ($at) {
                    $q->whereNull('effective_until')
                        ->orWhere('effective_until', '>', $at);
                })
                ->with(['components.inventoryItem'])
                ->get();

            if ($versions->isEmpty()) {
                throw new RecipeVersionInvalidStateException(
                    "No active recipe version found for recipe [{$recipe->id}] at [{$at->toIso8601String()}]."
                );
            }

            if ($versions->count() > 1) {
                throw new RecipeVersionInvalidStateException(
                    "Ambiguous: multiple active recipe versions found for recipe [{$recipe->id}] at [{$at->toIso8601String()}]. RECIPE_INVALID."
                );
            }

            $version = $versions->first();
            $components = $version->components;

            return compact('recipe', 'version', 'components', 'locationId');
        });
    }

    // =========================================================================
    // ESTIMATED COST
    // =========================================================================

    /**
     * Calculate estimated recipe cost from current MWA inventory valuations.
     *
     * This is an ESTIMATED cost (Estimasi Biaya), NOT authoritative HPP.
     * Cost is derived from the current location-scoped Moving Weighted Average.
     *
     * Formula per component:
     *   component_cost = ROUND(gross_quantity_per_portion × average_cost_minor)
     *   gross_quantity_per_portion = (quantity × (1 + waste_factor)) / yield_quantity
     *
     * Total estimated cost = SUM(component_cost)
     *
     * If a component has no cost basis (no inbound receipts), its cost is null.
     * Estimated cost with missing components is marked as incomplete.
     *
     * @return array{
     *   recipe_id: string,
     *   version_id: string,
     *   version_number: int,
     *   location_id: string|null,
     *   estimated_at: string,
     *   yield_quantity: string,
     *   yield_unit: string,
     *   estimated_total_cost_minor: int|null,
     *   is_complete: bool,
     *   components: array
     * }
     */
    public function estimateCost(
        string $tenantId,
        string $recipeId,
        string $versionId,
        ?string $locationId = null,
        int $depth = 0,
    ): array {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $recipeId, $versionId, $locationId, $depth) {
            if ($depth > 10) {
                throw new \DomainException('Maximum BOM cost calculation depth (10) exceeded.');
            }

            $version = RecipeVersion::where('tenant_id', $tenantId)
                ->where('recipe_id', $recipeId)
                ->with(['components.inventoryItem', 'recipe'])
                ->findOrFail($versionId);

            $components = $version->components;
            $itemIds = $components->pluck('inventory_item_id')->all();

            // Bulk cost lookup for leaf items at this location
            $avgCosts = $locationId
                ? $this->costingService->getBulkAverageCosts($tenantId, $itemIds, $locationId)
                : [];

            $componentCosts = [];
            $totalBatchMinor = 0;
            $isComplete = true;
            $yieldQty = (string) $version->yield_quantity;
            $scale = 8;

            foreach ($components as $comp) {
                $item = $comp->inventoryItem;

                // gross_quantity_per_portion = (quantity × (1 + waste_factor)) / yield_quantity
                $wasteMultiplier = bcadd('1', (string) $comp->waste_factor, $scale);
                $grossBatchQty = bcmul((string) $comp->quantity, $wasteMultiplier, $scale);
                $grossPortionQty = bcdiv($grossBatchQty, $yieldQty, $scale);

                // Check if this component has an active published recipe (semi-finished good)
                $childRecipe = Recipe::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $item->id)
                    ->where('status', Recipe::STATUS_PUBLISHED)
                    ->first();

                $childUnitCostMinor = null;
                $isNested = false;

                if ($childRecipe) {
                    $childVersion = RecipeVersion::where('tenant_id', $tenantId)
                        ->where('recipe_id', $childRecipe->id)
                        ->where('status', RecipeVersion::STATUS_PUBLISHED)
                        ->where('effective_from', '<=', now())
                        ->where(function ($q) {
                            $q->whereNull('effective_until')->orWhere('effective_until', '>', now());
                        })
                        ->first();

                    if ($childVersion) {
                        $isNested = true;
                        $childEstimate = $this->estimateCost(
                            tenantId: $tenantId,
                            recipeId: $childRecipe->id,
                            versionId: $childVersion->id,
                            locationId: $locationId,
                            depth: $depth + 1,
                        );

                        if ($childEstimate['is_complete']) {
                            $childUnitCostMinor = $childEstimate['estimated_unit_cost_minor']
                                ?? $childEstimate['estimated_total_cost_minor'];
                        }
                    }
                }

                // If nested calculation is complete, use it; otherwise fallback to location MWA
                $unitCostMinor = $childUnitCostMinor ?? ($locationId ? ($avgCosts[$item->id] ?? null) : null);

                if ($unitCostMinor === null) {
                    $isComplete = false;
                    $batchComponentCost = null;
                    $portionComponentCost = null;
                } else {
                    $batchCostDecimal = bcmul($grossBatchQty, (string) $unitCostMinor, $scale);
                    $batchComponentCost = (int) bcadd($batchCostDecimal, '0.5', 0);
                    $totalBatchMinor += $batchComponentCost;

                    $portionCostDecimal = bcmul($grossPortionQty, (string) $unitCostMinor, $scale);
                    $portionComponentCost = (int) bcadd($portionCostDecimal, '0.5', 0);
                }

                $componentCosts[] = [
                    'inventory_item_id' => $item->id,
                    'item_name' => $item->name,
                    'item_sku' => $item->sku,
                    'quantity_in_recipe' => (string) $comp->quantity,
                    'unit' => $comp->unit,
                    'waste_factor' => (string) $comp->waste_factor,
                    'gross_quantity_per_portion' => rtrim(sprintf('%.6f', (float) $grossPortionQty), '0'),
                    'component_type' => $comp->component_type,
                    'is_nested_recipe' => $isNested,
                    'average_cost_minor' => $unitCostMinor,
                    'estimated_component_cost_minor' => $portionComponentCost,
                    'estimated_batch_component_cost_minor' => $batchComponentCost,
                    'cost_note' => $unitCostMinor === null ? 'Tidak ada data biaya' : null,
                ];
            }

            $unitCost = $isComplete
                ? (int) bcadd(bcdiv((string) $totalBatchMinor, $yieldQty, $scale), '0.5', 0)
                : null;

            return [
                'recipe_id' => $recipeId,
                'version_id' => $versionId,
                'version_number' => $version->version_number,
                'location_id' => $locationId,
                'estimated_at' => now()->toIso8601String(),
                'yield_quantity' => (string) $version->yield_quantity,
                'yield_unit' => $version->yield_unit,
                'estimated_batch_cost_minor' => $isComplete ? $totalBatchMinor : null,
                'estimated_unit_cost_minor' => $unitCost,
                'estimated_total_cost_minor' => $unitCost,
                'is_complete' => $isComplete,
                'cost_label' => 'Estimasi Biaya',
                'disclaimer' => 'Nilai ini adalah estimasi berdasarkan rata-rata biaya bergerak saat ini dan nested BOM. Bukan HPP resmi.',
                'components' => $componentCosts,
            ];
        });
    }

    /**
     * Explode a recipe into its complete hierarchical tree and aggregated leaf components.
     * Pure read-only domain calculation.
     *
     * @param  string  $multiplier  Quantity of the parent item being produced/sold (default 1.0000)
     */
    public function explodeRecipe(
        string $tenantId,
        string $recipeId,
        ?string $versionId = null,
        string $multiplier = '1.0000',
        ?\DateTimeInterface $effectiveAt = null,
        int $depth = 0,
        array $visitedRecipes = [],
    ): array {
        return $this->executeForTenant($tenantId, function () use (
            $tenantId,
            $recipeId,
            $versionId,
            $multiplier,
            $effectiveAt,
            $depth,
            $visitedRecipes,
        ) {
            if ($depth > 10) {
                throw new \DomainException('Maximum BOM explosion depth (10) exceeded. Possible loop or hierarchy too deep.');
            }

            if (in_array($recipeId, $visitedRecipes, true)) {
                throw new RecipeCycleDetectedException("Cyclic dependency encountered during recipe explosion for recipe [{$recipeId}]. RECIPE_CYCLE_DETECTED.");
            }
            $visitedRecipes[] = $recipeId;

            $recipe = Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);
            $at = $effectiveAt ?? now();

            if ($versionId) {
                $version = RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipe->id)
                    ->with('components.inventoryItem')
                    ->findOrFail($versionId);
            } else {
                $versions = RecipeVersion::where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipe->id)
                    ->where('status', RecipeVersion::STATUS_PUBLISHED)
                    ->where('effective_from', '<=', $at)
                    ->where(function ($q) use ($at) {
                        $q->whereNull('effective_until')->orWhere('effective_until', '>', $at);
                    })
                    ->with('components.inventoryItem')
                    ->get();

                if ($versions->isEmpty()) {
                    throw new RecipeVersionInvalidStateException("No active published recipe version found for recipe [{$recipeId}].");
                }
                $version = $versions->first();
            }

            $yieldQty = (string) $version->yield_quantity;
            $scale = 8;

            $treeComponents = [];
            $aggregatedLeaves = []; // inventory_item_id => [...]

            foreach ($version->components as $comp) {
                $item = $comp->inventoryItem;

                // gross_quantity_per_batch = quantity * (1 + waste_factor)
                $wasteMultiplier = bcadd('1', (string) $comp->waste_factor, $scale);
                $grossQty = bcmul((string) $comp->quantity, $wasteMultiplier, $scale);

                // quantity_per_yield_unit = gross_quantity / yield_quantity
                $qtyPerYieldUnit = bcdiv($grossQty, $yieldQty, $scale);

                // effective_quantity = qty_per_yield_unit * multiplier
                $effectiveQty = bcmul($qtyPerYieldUnit, $multiplier, $scale);
                $effectiveQty4 = bcadd($effectiveQty, '0', 4);

                // Check if this component has its own published recipe (semi-finished)
                $childRecipe = Recipe::where('tenant_id', $tenantId)
                    ->where('inventory_item_id', $item->id)
                    ->where('status', Recipe::STATUS_PUBLISHED)
                    ->first();

                $childExplosion = null;
                if ($childRecipe) {
                    try {
                        $childExplosion = $this->explodeRecipe(
                            tenantId: $tenantId,
                            recipeId: $childRecipe->id,
                            versionId: null,
                            multiplier: $effectiveQty,
                            effectiveAt: $at,
                            depth: $depth + 1,
                            visitedRecipes: $visitedRecipes,
                        );
                    } catch (RecipeVersionInvalidStateException) {
                        $childExplosion = null;
                    }
                }

                if ($childExplosion) {
                    $treeComponents[] = [
                        'inventory_item_id' => $item->id,
                        'name' => $item->name,
                        'item_type' => $item->item_type,
                        'unit' => $comp->unit,
                        'required_quantity' => $effectiveQty4,
                        'waste_factor' => (string) $comp->waste_factor,
                        'is_semi_finished' => true,
                        'child_recipe' => $childExplosion,
                    ];

                    // Merge leaf components from child
                    foreach ($childExplosion['leaf_components'] as $leaf) {
                        $leafId = $leaf['inventory_item_id'];
                        if (! isset($aggregatedLeaves[$leafId])) {
                            $aggregatedLeaves[$leafId] = [
                                'inventory_item_id' => $leafId,
                                'name' => $leaf['name'],
                                'item_type' => $leaf['item_type'],
                                'unit' => $leaf['unit'],
                                'quantity' => '0.0000',
                            ];
                        }
                        $aggregatedLeaves[$leafId]['quantity'] = bcadd(
                            $aggregatedLeaves[$leafId]['quantity'],
                            $leaf['quantity'],
                            4
                        );
                    }
                } else {
                    $treeComponents[] = [
                        'inventory_item_id' => $item->id,
                        'name' => $item->name,
                        'item_type' => $item->item_type,
                        'unit' => $comp->unit,
                        'required_quantity' => $effectiveQty4,
                        'waste_factor' => (string) $comp->waste_factor,
                        'is_semi_finished' => false,
                        'child_recipe' => null,
                    ];

                    if (! isset($aggregatedLeaves[$item->id])) {
                        $aggregatedLeaves[$item->id] = [
                            'inventory_item_id' => $item->id,
                            'name' => $item->name,
                            'item_type' => $item->item_type,
                            'unit' => $comp->unit,
                            'quantity' => '0.0000',
                        ];
                    }
                    $aggregatedLeaves[$item->id]['quantity'] = bcadd(
                        $aggregatedLeaves[$item->id]['quantity'],
                        $effectiveQty4,
                        4
                    );
                }
            }

            return [
                'recipe_id' => $recipe->id,
                'recipe_name' => $recipe->name,
                'target_type' => $recipe->isVariantRecipe() ? 'product_variant' : 'inventory_item',
                'target_id' => $recipe->product_variant_id ?? $recipe->inventory_item_id,
                'version_id' => $version->id,
                'version_number' => $version->version_number,
                'multiplier' => $multiplier,
                'yield_quantity' => (string) $version->yield_quantity,
                'yield_unit' => $version->yield_unit,
                'tree' => $treeComponents,
                'leaf_components' => array_values($aggregatedLeaves),
            ];
        });
    }

    // =========================================================================
    // CYCLE DETECTION
    // =========================================================================

    /**
     * Detect cycles in the BOM graph using DFS.
     *
     * A cycle exists when a recipe component references an item that
     * itself (directly or indirectly) includes the starting target.
     *
     * Graph:
     *   - Vertices: Node IDs prefixed with "item:" or "variant:"
     *   - Edges: recipe_components dependencies
     *
     * Algorithm: DFS with 'in-stack' tracking (detects back-edges).
     * Correctly handles DAGs (diamond dependencies) without false positives.
     *
     * @param  string  $rootRecipeId  The recipe being published
     * @param  string[]  $componentItemIds  The direct component items for the new version
     *
     * @throws RecipeCycleDetectedException
     */
    protected function detectCycles(string $tenantId, string $rootRecipeId, array $componentItemIds): void
    {
        $rootRecipe = Recipe::where('tenant_id', $tenantId)->findOrFail($rootRecipeId);

        $rootNode = $rootRecipe->inventory_item_id
            ? "item:{$rootRecipe->inventory_item_id}"
            : "variant:{$rootRecipe->product_variant_id}";

        // Direct self-cycle check
        if ($rootRecipe->inventory_item_id && in_array($rootRecipe->inventory_item_id, $componentItemIds, true)) {
            throw new RecipeCycleDetectedException(
                "Cyclic BOM detected. Item [{$rootRecipe->inventory_item_id}] directly references itself. RECIPE_CYCLE_DETECTED."
            );
        }

        // Build adjacency: node_id => [array of child node_ids]
        $adjacency = [];
        $adjacency[$rootNode] = array_map(fn ($id) => "item:{$id}", $componentItemIds);

        $this->buildAdjacency($tenantId, $componentItemIds, $adjacency, $rootRecipeId);

        $visited = [];
        $inStack = [];

        $this->dfs($rootNode, $adjacency, $visited, $inStack);
    }

    /**
     * Recursively build the adjacency list by following published recipes.
     */
    protected function buildAdjacency(string $tenantId, array $itemIds, array &$adjacency, ?string $skipRecipeId = null): void
    {
        foreach ($itemIds as $itemId) {
            $nodeKey = "item:{$itemId}";
            if (array_key_exists($nodeKey, $adjacency)) {
                continue; // Already processed
            }

            $adjacency[$nodeKey] = [];

            // 1. Direct recipe on inventory item
            $recipe = Recipe::where('tenant_id', $tenantId)
                ->where('inventory_item_id', $itemId)
                ->where('status', Recipe::STATUS_PUBLISHED)
                ->when($skipRecipeId, fn ($q) => $q->where('id', '!=', $skipRecipeId))
                ->first();

            $item = InventoryItem::where('tenant_id', $tenantId)->find($itemId);
            if ($item?->product_variant_id) {
                $adjacency[$nodeKey][] = "variant:{$item->product_variant_id}";
            }

            // 2. Or recipe on linked product variant
            if (! $recipe && $item?->product_variant_id) {
                $recipe = Recipe::where('tenant_id', $tenantId)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->where('status', Recipe::STATUS_PUBLISHED)
                    ->when($skipRecipeId, fn ($q) => $q->where('id', '!=', $skipRecipeId))
                    ->first();
            }

            if (! $recipe) {
                continue;
            }

            // Find active version
            $now = now();
            $version = RecipeVersion::where('tenant_id', $tenantId)
                ->where('recipe_id', $recipe->id)
                ->where('status', RecipeVersion::STATUS_PUBLISHED)
                ->where('effective_from', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->whereNull('effective_until')->orWhere('effective_until', '>', $now);
                })
                ->first();

            if (! $version) {
                continue;
            }

            $subComponentIds = RecipeComponent::where('tenant_id', $tenantId)
                ->where('recipe_version_id', $version->id)
                ->pluck('inventory_item_id')
                ->all();

            $adjacency[$nodeKey] = array_map(fn ($id) => "item:{$id}", $subComponentIds);

            // Recurse
            $this->buildAdjacency($tenantId, $subComponentIds, $adjacency, $skipRecipeId);
        }
    }

    /**
     * DFS to detect cycles (back-edges in directed graph).
     *
     * @throws RecipeCycleDetectedException
     */
    protected function dfs(string $node, array $adjacency, array &$visited, array &$inStack): void
    {
        $visited[$node] = true;
        $inStack[$node] = true;

        $neighbors = $adjacency[$node] ?? [];
        foreach ($neighbors as $neighbor) {
            if (! empty($inStack[$neighbor])) {
                $cleanId = str_replace(['item:', 'variant:'], '', $neighbor);
                throw new RecipeCycleDetectedException(
                    "Cyclic BOM detected. Item [{$cleanId}] creates a circular dependency. RECIPE_CYCLE_DETECTED."
                );
            }

            if (empty($visited[$neighbor])) {
                $this->dfs($neighbor, $adjacency, $visited, $inStack);
            }
        }

        $inStack[$node] = false;
    }
}
