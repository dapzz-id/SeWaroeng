<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Exceptions\RecipeCycleDetectedException;
use App\Exceptions\RecipeNotConfiguredException;
use App\Exceptions\RecipeVersionInvalidStateException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\CostingService;
use App\Services\RecipeService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function __construct(
        protected RecipeService $recipeService,
        protected CostingService $costingService,
    ) {}

    // =========================================================================
    // RECIPES
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = Recipe::where('tenant_id', $tenantId)
            ->with(['variant.product:id,name,tenant_id']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($variantId = $request->query('product_variant_id')) {
            $query->where('product_variant_id', $variantId);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $recipes = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $recipes,
            data: $recipes->map(fn ($r) => $this->formatRecipe($r))->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        // Auto-provision product & variant if recipe is created standalone
        if (! $request->filled('product_variant_id') && ! $request->filled('inventory_item_id') && $request->filled('name')) {
            $slug = \Illuminate\Support\Str::slug($request->input('name'));
            if (empty($slug)) {
                $slug = 'recipe-'.substr(md5(uniqid()), 0, 8);
            }
            $product = \App\Models\Product::firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $slug],
                ['name' => $request->input('name'), 'status' => 'active']
            );
            $variant = \App\Models\ProductVariant::where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->whereDoesntHave('recipe')
                ->first();
            if (! $variant) {
                $variant = \App\Models\ProductVariant::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'name' => $request->filled('code') ? 'Porsi '.$request->input('code') : 'Porsi Standar',
                    'is_default' => ! \App\Models\ProductVariant::where('product_id', $product->id)->exists(),
                ]);
            }
            $request->merge(['product_variant_id' => $variant->id]);
        }

        $validated = $request->validate([
            'product_variant_id' => [
                'nullable', 'uuid', 'required_without:inventory_item_id', 'prohibited_unless:inventory_item_id,null',
                Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId),
                Rule::unique('recipes', 'product_variant_id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'inventory_item_id' => [
                'nullable', 'uuid', 'required_without:product_variant_id', 'prohibited_unless:product_variant_id,null',
                Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId),
                Rule::unique('recipes', 'inventory_item_id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:100',
                Rule::unique('recipes', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
        ]);

        $recipe = $this->recipeService->createRecipe(
            tenantId: $tenantId,
            data: $validated,
            userId: $request->user()->id,
        );

        return ApiResponse::success(
            data: $this->formatRecipe($recipe),
            message: 'Recipe created successfully.',
            status: 201
        );
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $recipe = Recipe::where('tenant_id', $tenantId)
            ->with(['variant.product', 'versions.components.inventoryItem', 'creator'])
            ->findOrFail($id);

        return ApiResponse::success(data: $this->formatRecipe($recipe, withVersions: true));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:100',
                Rule::unique('recipes', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($id),
            ],
        ]);

        $recipe = $this->recipeService->updateRecipe(
            tenantId: $tenantId,
            recipeId: $id,
            data: $validated,
            userId: $request->user()->id,
        );

        return ApiResponse::success(
            data: $this->formatRecipe($recipe),
            message: 'Recipe updated successfully.'
        );
    }

    // =========================================================================
    // VERSIONS
    // =========================================================================

    public function indexVersions(string $recipeId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        // Validate recipe belongs to tenant
        $recipe = Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);

        $versions = RecipeVersion::where('tenant_id', $tenantId)
            ->where('recipe_id', $recipe->id)
            ->with(['components.inventoryItem', 'creator', 'publisher'])
            ->orderByDesc('version_number')
            ->get();

        return ApiResponse::success(data: $versions->map(fn ($v) => $this->formatVersion($v))->all());
    }

    public function storeVersion(Request $request, string $recipeId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'yield_quantity' => ['required', 'numeric', 'min:0.0001'],
            'yield_unit' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $version = $this->recipeService->createVersion(
            tenantId: $tenantId,
            recipeId: $recipeId,
            data: $validated,
            userId: $request->user()->id,
        );

        return ApiResponse::success(
            data: $this->formatVersion($version),
            message: 'Recipe version created successfully.',
            status: 201
        );
    }

    public function showVersion(string $recipeId, string $versionId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);

        $version = RecipeVersion::where('tenant_id', $tenantId)
            ->where('recipe_id', $recipeId)
            ->with(['components.inventoryItem', 'creator', 'publisher', 'recipe'])
            ->findOrFail($versionId);

        return ApiResponse::success(data: $this->formatVersion($version));
    }

    public function publishVersion(Request $request, string $recipeId, string $versionId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        try {
            $version = $this->recipeService->publishVersion(
                tenantId: $tenantId,
                recipeId: $recipeId,
                versionId: $versionId,
                userId: $request->user()->id,
            );
        } catch (RecipeCycleDetectedException $e) {
            return ApiResponse::error(message: $e->getMessage(), code: 'RECIPE_CYCLE_DETECTED', status: 422);
        } catch (UniqueConstraintViolationException $e) {
            return ApiResponse::error(
                message: 'The effective period for this recipe version overlaps with an existing published version.',
                code: 'RECIPE_VERSION_OVERLAP',
                status: 422
            );
        }

        return ApiResponse::success(
            data: $this->formatVersion($version),
            message: 'Recipe version published successfully.'
        );
    }

    public function archiveVersion(Request $request, string $recipeId, string $versionId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $version = $this->recipeService->archiveVersion(
            tenantId: $tenantId,
            recipeId: $recipeId,
            versionId: $versionId,
            userId: $request->user()->id,
        );

        return ApiResponse::success(
            data: $this->formatVersion($version),
            message: 'Recipe version archived successfully.'
        );
    }

    /**
     * Set recipe version components (replace-all semantics for draft versions).
     */
    public function setVersionComponents(Request $request, string $recipeId, string $versionId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.inventory_item_id' => ['required', 'uuid'],
            'components.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'components.*.unit' => ['nullable', 'string', 'max:30'],
            'components.*.component_type' => ['nullable', 'string', 'in:raw_material,packaging,semi_finished,finished_good'],
            'components.*.waste_factor' => ['nullable', 'numeric', 'min:0', 'max:0.9999'],
            'components.*.sequence' => ['nullable', 'integer', 'min:0'],
            'components.*.notes' => ['nullable', 'string'],
        ]);

        $version = $this->recipeService->setComponents(
            tenantId: $tenantId,
            recipeId: $recipeId,
            versionId: $versionId,
            components: $validated['components'],
            userId: $request->user()->id,
        );

        return ApiResponse::success(
            data: $this->formatVersion($version),
            message: 'Recipe components updated successfully.'
        );
    }

    // =========================================================================
    // RECIPE RESOLUTION & COST
    // =========================================================================

    /**
     * GET /api/v1/recipes/{recipe}/resolve
     * Resolve the effective recipe version at an optional timestamp.
     */
    public function resolve(Request $request, string $recipeId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $recipe = Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);

        $at = $request->query('at') ? Carbon::parse($request->query('at')) : null;

        try {
            $resolved = $this->recipeService->resolveRecipe(
                tenantId: $tenantId,
                productVariantId: $recipe->product_variant_id,
                at: $at,
                locationId: $request->query('location_id'),
            );
        } catch (RecipeNotConfiguredException $e) {
            return ApiResponse::error(message: $e->getMessage(), code: 'RECIPE_NOT_CONFIGURED', status: 404);
        } catch (RecipeVersionInvalidStateException $e) {
            return ApiResponse::error(message: $e->getMessage(), code: 'RECIPE_VERSION_NOT_ACTIVE', status: 404);
        }

        return ApiResponse::success(data: [
            'recipe' => $this->formatRecipe($resolved['recipe']),
            'version' => $this->formatVersion($resolved['version']),
            'resolved_at' => ($at ?? now())->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/recipes/{recipe}/cost
     * Calculate estimated recipe cost from current inventory MWA.
     * Requires recipe.cost.view permission.
     */
    public function cost(Request $request, string $recipeId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $recipe = Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);

        $at = $request->query('at') ? Carbon::parse($request->query('at')) : null;
        $locationId = $request->query('location_id');

        try {
            $resolved = $this->recipeService->resolveRecipe(
                tenantId: $tenantId,
                productVariantId: $recipe->product_variant_id,
                at: $at,
                locationId: $locationId,
                inventoryItemId: $recipe->inventory_item_id,
                recipeId: $recipe->id,
            );
        } catch (RecipeNotConfiguredException $e) {
            return ApiResponse::error(message: $e->getMessage(), code: 'RECIPE_NOT_CONFIGURED', status: 404);
        } catch (RecipeVersionInvalidStateException $e) {
            return ApiResponse::error(message: $e->getMessage(), code: 'RECIPE_VERSION_NOT_ACTIVE', status: 404);
        }

        $estimate = $this->recipeService->estimateCost(
            tenantId: $tenantId,
            recipeId: $recipeId,
            versionId: $resolved['version']->id,
            locationId: $locationId,
        );

        return ApiResponse::success(data: $estimate);
    }

    /**
     * GET /api/v1/recipes/{recipe}/explode
     * Deterministic read calculation of recipe BOM explosion into leaf components.
     */
    public function explode(Request $request, string $recipeId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $recipe = Recipe::where('tenant_id', $tenantId)->findOrFail($recipeId);

        $at = $request->query('at') ? Carbon::parse($request->query('at')) : null;
        $multiplier = (string) $request->query('multiplier', '1.0000');
        $versionId = $request->query('version_id');

        $explosion = $this->recipeService->explodeRecipe(
            tenantId: $tenantId,
            recipeId: $recipe->id,
            versionId: $versionId,
            multiplier: $multiplier,
            effectiveAt: $at,
        );

        return ApiResponse::success(data: $explosion);
    }

    // =========================================================================
    // FORMATTERS
    // =========================================================================

    protected function formatRecipe(Recipe $recipe, bool $withVersions = false): array
    {
        $data = [
            'id' => $recipe->id,
            'tenant_id' => $recipe->tenant_id,
            'target_type' => $recipe->isVariantRecipe() ? 'product_variant' : 'inventory_item',
            'product_variant_id' => $recipe->product_variant_id,
            'variant_name' => $recipe->variant?->name,
            'product_name' => $recipe->variant?->product?->name,
            'inventory_item_id' => $recipe->inventory_item_id,
            'inventory_item_name' => $recipe->inventoryItem?->name,
            'inventory_item_type' => $recipe->inventoryItem?->item_type,
            'name' => $recipe->name,
            'code' => $recipe->code,
            'status' => $recipe->status,
            'created_by' => $recipe->created_by,
            'created_at' => $recipe->created_at?->toIso8601String(),
            'updated_at' => $recipe->updated_at?->toIso8601String(),
        ];

        if ($withVersions && $recipe->relationLoaded('versions')) {
            $data['versions'] = $recipe->versions->map(fn ($v) => $this->formatVersion($v))->values()->all();
        }

        return $data;
    }

    protected function formatVersion(RecipeVersion $version): array
    {
        $data = [
            'id' => $version->id,
            'tenant_id' => $version->tenant_id,
            'recipe_id' => $version->recipe_id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_until' => $version->effective_until?->toIso8601String(),
            'yield_quantity' => (string) $version->yield_quantity,
            'yield_unit' => $version->yield_unit,
            'notes' => $version->notes,
            'created_by' => $version->created_by,
            'published_by' => $version->published_by,
            'published_at' => $version->published_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
        ];

        if ($version->relationLoaded('components')) {
            $data['components'] = $version->components
                ->sortBy('sequence')
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'inventory_item_id' => $c->inventory_item_id,
                    'item_name' => $c->inventoryItem?->name,
                    'item_sku' => $c->inventoryItem?->sku,
                    'base_unit' => $c->inventoryItem?->base_unit,
                    'quantity' => (string) $c->quantity,
                    'unit' => $c->unit,
                    'component_type' => $c->component_type,
                    'waste_factor' => (string) $c->waste_factor,
                    'sequence' => $c->sequence,
                    'notes' => $c->notes,
                ])->values()->all();
        }

        return $data;
    }
}
