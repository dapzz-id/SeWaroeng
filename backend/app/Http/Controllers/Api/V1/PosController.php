<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use App\Models\LoyaltyVoucher;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Loyalty\LoyaltyService;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PosController extends Controller
{
    public function __construct(
        protected PosService $posService,
    ) {}

    /**
     * List sales with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $filters = $request->only([
            'outlet_id',
            'status',
            'cashier_user_id',
            'customer_id',
            'date_from',
            'date_to',
            'search',
        ]);

        if (! empty($filters['outlet_id'])) {
            if (! $tenantContext->hasOutletAccess($filters['outlet_id'])) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $filters['assigned_outlet_ids'] = $tenantContext->membership()->assigned_outlet_ids ?? [];
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $this->posService->listSales($tenantId, $filters, $perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn ($s) => $this->formatSale($s))->all()
        );
    }

    /**
     * Create an open sale (cart).
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'outlet_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.product_variant_id' => ['required_with:items', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001'],
            'items.*.discount_minor' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_id' => ['required_with:items.*.modifiers', 'uuid'],
            'items.*.modifiers.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $cashierUserId = auth()->id();
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->hasOutletAccess($validated['outlet_id'])) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $sale = $this->posService->createSale(
            tenantId: $tenantId,
            outletId: $validated['outlet_id'],
            cashierUserId: $cashierUserId,
            items: $validated['items'] ?? [],
            notes: $validated['notes'] ?? null,
            customerId: $validated['customer_id'] ?? null,
        );

        return ApiResponse::success(
            data: $this->formatSale($sale),
            message: 'Sale created successfully.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Get sale details.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $sale = $this->posService->getSale($id, $tenantId);

        if ($error = $this->assertSaleOutletAccess($sale)) {
            return $error;
        }

        return ApiResponse::success(
            data: $this->formatSale($sale)
        );
    }

    /**
     * Update an open sale (edit cart items / notes).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $sale = $this->posService->getSale($id, $tenantId);

        if ($error = $this->assertSaleOutletAccess($sale)) {
            return $error;
        }

        $validated = $request->validate([
            'customer_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'discount_minor' => ['nullable', 'integer', 'min:0'],
            'tax_minor' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.product_variant_id' => ['required_with:items', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001'],
            'items.*.discount_minor' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_id' => ['required_with:items.*.modifiers', 'uuid'],
            'items.*.modifiers.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $userId = auth()->id();
        $updated = $this->posService->updateSale($id, $validated, userId: $userId, tenantId: $tenantId);

        return ApiResponse::success(
            data: $this->formatSale($updated),
            message: 'Sale updated successfully.'
        );
    }

    /**
     * Finalize a sale atomically.
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $sale = $this->posService->getSale($id, $tenantId);

        if ($error = $this->assertSaleOutletAccess($sale)) {
            return $error;
        }

        $validated = $request->validate([
            'discount_minor' => ['nullable', 'integer', 'min:0'],
            'tax_minor' => ['nullable', 'integer', 'min:0'],
            'location_id' => ['nullable', 'uuid'],
        ]);

        $userId = auth()->id();
        $finalized = $this->posService->finalizeSale(
            sale: $id,
            options: $validated,
            userId: $userId,
            tenantId: $tenantId,
        );

        return ApiResponse::success(
            data: $this->formatSale($finalized),
            message: 'Sale finalized successfully.'
        );
    }

    /**
     * Cancel a sale (void open cart or compensating reversal for finalized).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $sale = $this->posService->getSale($id, $tenantId);

        if ($error = $this->assertSaleOutletAccess($sale)) {
            return $error;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = auth()->id();
        $cancelled = $this->posService->cancelSale($id, reason: $validated['reason'] ?? null, userId: $userId, tenantId: $tenantId);

        return ApiResponse::success(
            data: $this->formatSale($cancelled),
            message: 'Sale cancelled successfully.'
        );
    }

    /**
     * Apply loyalty voucher to open sale.
     * Price authority remains strictly server-side.
     */
    public function applyVoucher(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $saleModel = $this->posService->getSale($id, $tenantId);

        if ($error = $this->assertSaleOutletAccess($saleModel)) {
            return $error;
        }

        $validated = $request->validate([
            'voucher_code' => ['required', 'string'],
        ]);

        if (! $saleModel->isOpen()) {
            throw new \InvalidArgumentException('Voucher hanya dapat diterapkan pada transaksi yang masih terbuka (open).');
        }

        $res = app(LoyaltyService::class)->validateAndCalculateVoucherDiscount(
            tenantId: $tenantId,
            voucherCode: $validated['voucher_code'],
            subtotalMinor: (int) $saleModel->subtotal_minor,
            customerId: $saleModel->customer_id,
            saleId: $saleModel->id
        );

        /** @var LoyaltyVoucher $voucher */
        $voucher = $res['voucher'];
        $discountMinor = $res['discount_minor'];

        // If sale has no customer attached yet, associate voucher owner
        if (! $saleModel->customer_id) {
            $saleModel->customer_id = $voucher->customer_id;
        }

        $saleModel->discount_minor = $discountMinor;
        $saleModel->total_minor = max(0, $saleModel->subtotal_minor - $discountMinor + $saleModel->tax_minor);
        $saleModel->save();

        // Reserve voucher for this sale (NOT marked used yet!)
        $voucher->status = LoyaltyVoucher::STATUS_RESERVED;
        $voucher->used_sale_id = $saleModel->id;
        $voucher->save();

        return ApiResponse::success(
            data: $this->formatSale($saleModel->fresh()),
            message: 'Voucher diskon berhasil diterapkan.'
        );
    }

    /**
     * Remove voucher from open sale and release its reservation.
     */
    public function removeVoucher(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $saleModel = $this->posService->getSale($id, $tenantId);

        if ($error = $this->assertSaleOutletAccess($saleModel)) {
            return $error;
        }

        if (! $saleModel->isOpen()) {
            throw new \InvalidArgumentException('Voucher hanya dapat dihapus pada transaksi yang masih terbuka (open).');
        }

        app(LoyaltyService::class)->releaseVoucherReservation($saleModel);

        $saleModel->discount_minor = 0;
        $saleModel->total_minor = $saleModel->subtotal_minor + $saleModel->tax_minor;
        $saleModel->save();

        return ApiResponse::success(
            data: $this->formatSale($saleModel->fresh()),
            message: 'Voucher berhasil dilepas dari transaksi.'
        );
    }

    /**
     * Read-only POS catalog endpoint tailored for cashier UI.
     * Returns products, variants with resolved prices, modifier groups, and categories.
     */
    public function catalog(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $outletId = $request->query('outlet_id');

        if ($outletId) {
            if (! $tenantContext->hasOutletAccess($outletId)) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            $outletId = $assigned[0] ?? null;
        }

        $categories = Category::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $productsQuery = Product::where('tenant_id', $tenantId)
            ->where('status', 'active');

        if ($outletId) {
            $productsQuery->whereDoesntHave('outletAvailabilities', function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->where('is_available', false);
            });
        }

        $products = $productsQuery
            ->with([
                'category:id,name',
                'variants' => function ($q) {
                    $q->where('status', 'active')->orderBy('sort_order');
                },
                'modifierGroups.modifiers' => function ($q) {
                    $q->where('status', 'active')->orderBy('sort_order');
                },
            ])
            ->orderBy('name')
            ->get();

        $catalogData = $products->map(function ($product) use ($outletId) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'category_id' => $product->category_id,
                'category_name' => $product->category?->name,
                'variants' => $product->variants->map(function ($variant) use ($outletId) {
                    $resolvedPrice = null;
                    if ($outletId) {
                        try {
                            $priceModel = $variant->resolvePrice($outletId);
                            $resolvedPrice = $priceModel ? [
                                'price_id' => $priceModel->id,
                                'amount' => (int) $priceModel->amount,
                                'currency' => $priceModel->currency,
                            ] : null;
                        } catch (\Throwable) {
                            $resolvedPrice = null;
                        }
                    }

                    return [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'is_default' => $variant->is_default,
                        'resolved_price' => $resolvedPrice,
                    ];
                }),
                'modifier_groups' => $product->modifierGroups->map(fn ($mg) => [
                    'id' => $mg->id,
                    'name' => $mg->name,
                    'selection_type' => $mg->selection_type,
                    'min_selection' => $mg->min_selection,
                    'max_selection' => $mg->max_selection,
                    'is_required' => $mg->is_required,
                    'modifiers' => $mg->modifiers->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'price_adjustment' => (int) $m->price_delta,
                    ]),
                ]),
            ];
        });

        return ApiResponse::success(data: [
            'categories' => $categories,
            'products' => $catalogData,
        ]);
    }

    /**
     * Format a Sale model into an API response structure.
     */
    protected function formatSale(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'tenant_id' => $sale->tenant_id,
            'outlet_id' => $sale->outlet_id,
            'reference_number' => $sale->reference_number,
            'status' => $sale->status,
            'opened_at' => $sale->opened_at?->toIso8601String(),
            'finalized_at' => $sale->finalized_at?->toIso8601String(),
            'cancelled_at' => $sale->cancelled_at?->toIso8601String(),
            'cashier_user_id' => $sale->cashier_user_id,
            'customer_id' => $sale->customer_id,
            'customer' => $sale->relationLoaded('customer') && $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'phone' => $sale->customer->phone,
                'email' => $sale->customer->email,
            ] : null,
            'subtotal_minor' => (int) $sale->subtotal_minor,
            'discount_minor' => (int) $sale->discount_minor,
            'tax_minor' => (int) $sale->tax_minor,
            'total_minor' => (int) $sale->total_minor,
            'notes' => $sale->notes,
            'created_at' => $sale->created_at?->toIso8601String(),
            'updated_at' => $sale->updated_at?->toIso8601String(),
            'items' => $sale->items->map(fn ($item) => [
                'id' => $item->id,
                'sale_id' => $item->sale_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name_snapshot' => $item->product_name_snapshot,
                'variant_name_snapshot' => $item->variant_name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price_minor' => (int) $item->unit_price_minor,
                'subtotal_minor' => (int) $item->subtotal_minor,
                'discount_minor' => (int) $item->discount_minor,
                'total_minor' => (int) $item->total_minor,
                'price_id' => $item->price_id,
                'recipe_version_id' => $item->recipe_version_id,
                'recipe_snapshot' => $item->recipe_snapshot,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->map(fn ($mod) => [
                    'id' => $mod->id,
                    'modifier_id' => $mod->modifier_id,
                    'modifier_name_snapshot' => $mod->modifier_name_snapshot,
                    'group_name_snapshot' => $mod->modifier_group_name_snapshot,
                    'quantity' => (float) $mod->quantity,
                    'unit_price_minor' => (int) $mod->unit_price_minor,
                    'unit_price_adjustment_minor' => (int) $mod->unit_price_minor,
                    'subtotal_minor' => (int) $mod->subtotal_minor,
                ]),
            ]),
            'consumptions' => $sale->relationLoaded('consumptions') ? $sale->consumptions->map(fn ($c) => [
                'id' => $c->id,
                'sale_item_id' => $c->sale_item_id,
                'inventory_item_id' => $c->inventory_item_id,
                'inventory_item_name' => $c->inventoryItem?->name,
                'location_id' => $c->location_id,
                'consumed_quantity' => (float) $c->consumed_quantity,
                'unit_cost_minor' => (int) $c->unit_cost_minor,
                'total_cost_minor' => (int) $c->total_cost_minor,
                'inventory_movement_id' => $c->inventory_movement_id,
                'recipe_component_snapshot' => $c->recipe_component_snapshot,
                'created_at' => $c->created_at?->toIso8601String(),
            ]) : [],
        ];
    }

    /**
     * Assert that active tenant context user has access to this sale's outlet.
     */
    protected function assertSaleOutletAccess(Sale $sale): ?JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->hasOutletAccess($sale->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }
}
