<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductOutletAvailability;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * List products with pagination, filtering, search, and outlet pricing resolution.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $outletId = $request->query('outlet_id') ?: $request->header('X-Outlet-ID');

        if ($outletId && ! $tenantContext->hasOutletAccess($outletId)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $query = Product::where('tenant_id', $tenantId)
            ->with([
                'category:id,name,slug',
                'defaultVariant.prices',
                'variants.prices',
                'variants.barcodes',
            ]);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($productType = $request->query('product_type')) {
            $query->where('product_type', $productType);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhereHas('variants', function ($vq) use ($search) {
                        $vq->where('name', 'ilike', "%{$search}%")
                            ->orWhere('sku', 'ilike', "%{$search}%")
                            ->orWhereHas('barcodes', function ($bq) use ($search) {
                                $bq->where('barcode', 'ilike', "%{$search}%");
                            });
                    });
            });
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);

        $items = collect($paginated->items())->map(function (Product $product) use ($outletId) {
            $defaultVariant = $product->defaultVariant ?: $product->variants->first();
            $resolvedPrice = $defaultVariant?->resolvePrice($outletId);

            return [
                'id' => $product->id,
                'tenant_id' => $product->tenant_id,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'description' => $product->description,
                'product_type' => $product->product_type,
                'status' => $product->status,
                'sort_order' => $product->sort_order,
                'is_available' => $outletId ? $product->isAvailableAtOutlet($outletId) : true,
                'price' => $resolvedPrice ? [
                    'id' => $resolvedPrice->id,
                    'amount' => $resolvedPrice->amount,
                    'currency' => $resolvedPrice->currency,
                    'outlet_id' => $resolvedPrice->outlet_id,
                ] : null,
                'variants_count' => $product->variants->count(),
                'variants' => $product->variants->map(function (ProductVariant $v) use ($outletId) {
                    $vPrice = $v->resolvePrice($outletId);

                    return [
                        'id' => $v->id,
                        'name' => $v->name,
                        'sku' => $v->sku,
                        'is_default' => $v->is_default,
                        'status' => $v->status,
                        'barcodes' => $v->barcodes->pluck('barcode'),
                        'price' => $vPrice ? [
                            'id' => $vPrice->id,
                            'amount' => $vPrice->amount,
                            'currency' => $vPrice->currency,
                            'outlet_id' => $vPrice->outlet_id,
                        ] : null,
                    ];
                }),
                'created_at' => $product->created_at?->toIso8601String(),
                'updated_at' => $product->updated_at?->toIso8601String(),
            ];
        });

        return ApiResponse::success(data: [
            'items' => $items,
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Display a specific product with full details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $outletId = $request->query('outlet_id') ?: $request->header('X-Outlet-ID');

        $product = Product::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with([
                'category',
                'variants.barcodes',
                'variants.prices',
                'modifierGroups.modifiers',
                'outletAvailabilities',
            ])
            ->firstOrFail();

        return ApiResponse::success(data: [
            'id' => $product->id,
            'tenant_id' => $product->tenant_id,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'description' => $product->description,
            'product_type' => $product->product_type,
            'status' => $product->status,
            'sort_order' => $product->sort_order,
            'metadata' => $product->metadata,
            'is_available' => $outletId ? $product->isAvailableAtOutlet($outletId) : true,
            'variants' => $product->variants->map(function (ProductVariant $v) use ($outletId) {
                $vPrice = $v->resolvePrice($outletId);

                return [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'is_default' => $v->is_default,
                    'status' => $v->status,
                    'sort_order' => $v->sort_order,
                    'barcodes' => $v->barcodes->map(fn ($b) => [
                        'id' => $b->id,
                        'barcode' => $b->barcode,
                        'type' => $b->type,
                    ]),
                    'price' => $vPrice ? [
                        'id' => $vPrice->id,
                        'amount' => $vPrice->amount,
                        'currency' => $vPrice->currency,
                        'outlet_id' => $vPrice->outlet_id,
                    ] : null,
                    'prices' => $v->prices->map(fn ($p) => [
                        'id' => $p->id,
                        'amount' => $p->amount,
                        'currency' => $p->currency,
                        'outlet_id' => $p->outlet_id,
                        'is_active' => $p->is_active,
                        'effective_from' => $p->effective_from?->toIso8601String(),
                        'effective_until' => $p->effective_until?->toIso8601String(),
                    ]),
                ];
            }),
            'modifier_groups' => $product->modifierGroups->map(fn ($mg) => [
                'id' => $mg->id,
                'name' => $mg->name,
                'selection_mode' => $mg->selection_mode,
                'min_selection' => $mg->min_selection,
                'max_selection' => $mg->max_selection,
                'is_required' => $mg->is_required,
                'modifiers' => $mg->modifiers->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price_delta' => $m->price_delta,
                    'status' => $m->status,
                ]),
            ]),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Atomically create a new product, its default variant, price, barcodes, and associations.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $tenantId = (string) $tenant->id;
        $currency = $tenant->currency ?: 'IDR';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:100',
            'category_id' => 'nullable|uuid',
            'product_type' => 'nullable|in:product,service,bundle',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'metadata' => 'nullable|array',
            'status' => 'nullable|in:active,inactive',

            // Simple product pricing & barcode
            'price' => 'nullable|integer|min:0',
            'barcode' => 'nullable|string|max:100',

            // Multi-variant creation support
            'variants' => 'nullable|array',
            'variants.*.name' => 'required_with:variants|string|max:255',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.price' => 'nullable|integer|min:0',
            'variants.*.is_default' => 'nullable|boolean',
            'variants.*.barcodes' => 'nullable|array',
            'variants.*.barcodes.*' => 'string|max:100',

            // Associations
            'modifier_group_ids' => 'nullable|array',
            'modifier_group_ids.*' => 'uuid',
        ]);

        // 1. Verify category ownership if provided
        if (! empty($validated['category_id'])) {
            $catExists = Category::where('id', $validated['category_id'])
                ->where('tenant_id', $tenantId)
                ->exists();

            if (! $catExists) {
                return ApiResponse::error(
                    message: 'Category not found or belongs to another business.',
                    code: 'CATEGORY_CROSS_TENANT',
                    status: 422
                );
            }
        }

        // 2. Validate product slug uniqueness within tenant
        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        $slugTaken = Product::where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists();

        if ($slugTaken) {
            return ApiResponse::error(
                message: 'A product with this slug already exists in this business.',
                code: 'PRODUCT_SLUG_TAKEN',
                status: 422
            );
        }

        // 3. Validate product SKU if provided
        if (! empty($validated['sku'])) {
            $skuTaken = Product::where('tenant_id', $tenantId)
                ->where('sku', trim($validated['sku']))
                ->exists();

            if ($skuTaken) {
                return ApiResponse::error(
                    message: 'A product with this SKU already exists in this business.',
                    code: 'PRODUCT_SKU_TAKEN',
                    status: 422
                );
            }
        }

        // 4. Validate modifier group ownership
        if (! empty($validated['modifier_group_ids'])) {
            $mgCount = ModifierGroup::where('tenant_id', $tenantId)
                ->whereIn('id', $validated['modifier_group_ids'])
                ->count();

            if ($mgCount !== count($validated['modifier_group_ids'])) {
                return ApiResponse::error(
                    message: 'One or more modifier groups belong to another business or do not exist.',
                    code: 'MODIFIER_GROUP_CROSS_TENANT',
                    status: 422
                );
            }
        }

        // 5. Execute Atomic Creation
        $product = DB::transaction(function () use ($validated, $tenantId, $slug, $currency, $request) {
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->first();
            if ($tenant) {
                app(SubscriptionEntitlementService::class)->assertQuotaAvailable($tenant, 'max_products', 1);
            }

            $product = Product::create([
                'tenant_id' => $tenantId,
                'category_id' => $validated['category_id'] ?? null,
                'name' => trim($validated['name']),
                'slug' => $slug,
                'sku' => ! empty($validated['sku']) ? trim($validated['sku']) : null,
                'description' => $validated['description'] ?? null,
                'product_type' => $validated['product_type'] ?? 'product',
                'status' => $validated['status'] ?? 'active',
                'sort_order' => $validated['sort_order'] ?? 0,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            // Handle variants
            if (! empty($validated['variants']) && is_array($validated['variants'])) {
                $hasExplicitDefault = collect($validated['variants'])->contains('is_default', true);

                foreach ($validated['variants'] as $idx => $vData) {
                    $isDefault = $hasExplicitDefault
                        ? ($vData['is_default'] ?? false)
                        : ($idx === 0);

                    $vSku = ! empty($vData['sku']) ? trim($vData['sku']) : null;
                    if ($vSku) {
                        $skuExists = ProductVariant::where('tenant_id', $tenantId)->where('sku', $vSku)->exists();
                        if ($skuExists) {
                            throw new \InvalidArgumentException("Variant SKU '{$vSku}' is already registered in this business.");
                        }
                    }

                    $variant = ProductVariant::create([
                        'tenant_id' => $tenantId,
                        'product_id' => $product->id,
                        'name' => trim($vData['name']),
                        'sku' => $vSku,
                        'status' => 'active',
                        'is_default' => $isDefault,
                        'sort_order' => $idx,
                    ]);

                    // Add price if provided
                    if (isset($vData['price'])) {
                        Price::create([
                            'tenant_id' => $tenantId,
                            'variant_id' => $variant->id,
                            'outlet_id' => null,
                            'amount' => (int) $vData['price'],
                            'currency' => $currency,
                            'effective_from' => now(),
                            'is_active' => true,
                        ]);
                    }

                    // Add barcodes if provided
                    if (! empty($vData['barcodes'])) {
                        foreach ($vData['barcodes'] as $bc) {
                            $bcClean = trim($bc);
                            $bcExists = ProductBarcode::where('tenant_id', $tenantId)->where('barcode', $bcClean)->exists();
                            if ($bcExists) {
                                throw new \InvalidArgumentException("Barcode '{$bcClean}' is already registered in this business.");
                            }
                            ProductBarcode::create([
                                'tenant_id' => $tenantId,
                                'variant_id' => $variant->id,
                                'barcode' => $bcClean,
                            ]);
                        }
                    }
                }
            } else {
                // Canonical default variant for standard products
                $variant = ProductVariant::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'name' => 'Standard',
                    'sku' => ! empty($validated['sku']) ? trim($validated['sku']) : null,
                    'status' => 'active',
                    'is_default' => true,
                    'sort_order' => 0,
                ]);

                if (isset($validated['price'])) {
                    Price::create([
                        'tenant_id' => $tenantId,
                        'variant_id' => $variant->id,
                        'outlet_id' => null,
                        'amount' => (int) $validated['price'],
                        'currency' => $currency,
                        'effective_from' => now(),
                        'is_active' => true,
                    ]);
                }

                if (! empty($validated['barcode'])) {
                    $bcClean = trim($validated['barcode']);
                    $bcExists = ProductBarcode::where('tenant_id', $tenantId)->where('barcode', $bcClean)->exists();
                    if ($bcExists) {
                        throw new \InvalidArgumentException("Barcode '{$bcClean}' is already registered in this business.");
                    }
                    ProductBarcode::create([
                        'tenant_id' => $tenantId,
                        'variant_id' => $variant->id,
                        'barcode' => $bcClean,
                    ]);
                }
            }

            // Attach modifier groups
            if (! empty($validated['modifier_group_ids'])) {
                $product->modifierGroups()->sync($validated['modifier_group_ids']);
            }

            // Audit Log
            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_user_id' => $request->user()->id,
                'action' => 'product_created',
                'entity_type' => Product::class,
                'entity_id' => $product->id,
                'before' => null,
                'after' => [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category_id' => $product->category_id,
                    'product_type' => $product->product_type,
                    'status' => $product->status,
                ],
                'request_id' => $request->header('X-Request-ID'),
            ]);

            return $product;
        });

        return ApiResponse::success(
            data: [
                'id' => $product->id,
                'tenant_id' => $product->tenant_id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'status' => $product->status,
                'product_type' => $product->product_type,
            ],
            message: 'Product created successfully.',
            status: 201
        );
    }

    /**
     * Update an existing product.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $product = Product::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:100',
            'category_id' => 'nullable|uuid',
            'description' => 'nullable|string',
            'product_type' => 'nullable|in:product,service,bundle',
            'status' => 'nullable|in:active,inactive,archived',
            'sort_order' => 'nullable|integer',
            'metadata' => 'nullable|array',
            'modifier_group_ids' => 'nullable|array',
            'modifier_group_ids.*' => 'uuid',
        ]);

        if (array_key_exists('category_id', $validated) && $validated['category_id']) {
            $catExists = Category::where('id', $validated['category_id'])
                ->where('tenant_id', $tenantId)
                ->exists();

            if (! $catExists) {
                return ApiResponse::error(
                    message: 'Category not found or belongs to another business.',
                    code: 'CATEGORY_CROSS_TENANT',
                    status: 422
                );
            }
            $product->category_id = $validated['category_id'];
        } elseif (array_key_exists('category_id', $validated)) {
            $product->category_id = null;
        }

        if (isset($validated['slug'])) {
            $slug = Str::slug($validated['slug']);
            $slugExists = Product::where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->where('id', '!=', $product->id)
                ->exists();

            if ($slugExists) {
                return ApiResponse::error(
                    message: 'A product with this slug already exists in this business.',
                    code: 'PRODUCT_SLUG_TAKEN',
                    status: 422
                );
            }
            $product->slug = $slug;
        }

        if (isset($validated['sku'])) {
            $sku = trim($validated['sku']);
            $skuExists = Product::where('tenant_id', $tenantId)
                ->where('sku', $sku)
                ->where('id', '!=', $product->id)
                ->exists();

            if ($skuExists) {
                return ApiResponse::error(
                    message: 'A product with this SKU already exists in this business.',
                    code: 'PRODUCT_SKU_TAKEN',
                    status: 422
                );
            }
            $product->sku = $sku;
        }

        if (isset($validated['name'])) {
            $product->name = trim($validated['name']);
        }
        if (isset($validated['description'])) {
            $product->description = $validated['description'];
        }
        if (isset($validated['product_type'])) {
            $product->product_type = $validated['product_type'];
        }
        if (isset($validated['sort_order'])) {
            $product->sort_order = $validated['sort_order'];
        }
        if (isset($validated['metadata'])) {
            $product->metadata = $validated['metadata'];
        }

        $statusChanged = false;
        if (isset($validated['status']) && $validated['status'] !== $product->status) {
            $product->status = $validated['status'];
            $statusChanged = true;
        }

        if (isset($validated['modifier_group_ids'])) {
            $product->modifierGroups()->sync($validated['modifier_group_ids']);
        }

        $before = $product->getOriginal();
        $product->save();

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => $statusChanged ? 'product_status_changed' : 'product_updated',
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'before' => [
                'name' => $before['name'] ?? null,
                'sku' => $before['sku'] ?? null,
                'status' => $before['status'] ?? null,
            ],
            'after' => [
                'name' => $product->name,
                'sku' => $product->sku,
                'status' => $product->status,
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $product->id,
                'tenant_id' => $product->tenant_id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'status' => $product->status,
            ],
            message: 'Product updated successfully.'
        );
    }

    /**
     * Configure outlet-specific availability for this product.
     */
    public function setOutletAvailability(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $product = Product::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'outlet_id' => 'required|uuid',
            'is_available' => 'required|boolean',
        ]);

        $outletExists = Outlet::where('id', $validated['outlet_id'])
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $outletExists) {
            return ApiResponse::error(
                message: 'Outlet not found or belongs to another business.',
                code: 'OUTLET_CROSS_TENANT',
                status: 422
            );
        }

        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->hasOutletAccess($validated['outlet_id'])) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $availability = ProductOutletAvailability::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'outlet_id' => $validated['outlet_id'],
            ],
            [
                'is_available' => $validated['is_available'],
            ]
        );

        return ApiResponse::success(
            data: [
                'product_id' => $product->id,
                'outlet_id' => $availability->outlet_id,
                'is_available' => $availability->is_available,
            ],
            message: 'Product availability updated.'
        );
    }
}
