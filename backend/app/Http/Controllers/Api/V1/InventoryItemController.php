<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Notification;
use App\Models\ProductVariant;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    /**
     * List inventory items with pagination, search, balances, and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $locationId = $request->query('location_id');

        $query = InventoryItem::where('tenant_id', $tenantId)
            ->with([
                'variant.product:id,name',
                'itemLocations.location:id,name,code,type',
            ]);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('item_type')) {
            $query->where('item_type', $request->query('item_type'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhereHas('variant', function ($vq) use ($search) {
                        $vq->where('name', 'ilike', "%{$search}%")
                            ->orWhere('sku', 'ilike', "%{$search}%");
                    });
            });
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->orderBy('name')->paginate($perPage);

        $items = collect($paginated->items())->map(function (InventoryItem $item) use ($locationId) {
            $locationBalance = null;
            if ($locationId) {
                $locBal = $item->itemLocations->firstWhere('location_id', $locationId);
                if ($locBal) {
                    $locationBalance = [
                        'location_id' => $locBal->location_id,
                        'location_name' => $locBal->location?->name,
                        'on_hand' => $locBal->on_hand,
                        'reserved' => $locBal->reserved,
                        'available' => $locBal->available,
                        'reorder_level' => $locBal->reorder_level,
                        'is_low_stock' => $locBal->isLowStock(),
                    ];
                }
            }

            // Total aggregated balance across all locations
            $totalOnHand = sprintf('%.4f', (float) $item->itemLocations->sum('on_hand'));
            $totalReserved = sprintf('%.4f', (float) $item->itemLocations->sum('reserved'));
            $totalAvailable = bcsub($totalOnHand, $totalReserved, 4);

            return [
                'id' => $item->id,
                'tenant_id' => $item->tenant_id,
                'product_variant_id' => $item->product_variant_id,
                'variant_name' => $item->variant?->name,
                'product_name' => $item->variant?->product?->name,
                'sku' => $item->sku,
                'name' => $item->name,
                'item_type' => $item->item_type,
                'base_unit' => $item->base_unit,
                'status' => $item->status,
                'allow_negative_stock' => $item->allow_negative_stock,
                'total_on_hand' => $totalOnHand,
                'total_reserved' => $totalReserved,
                'total_available' => $totalAvailable,
                'location_balance' => $locationBalance,
                'locations_count' => $item->itemLocations->count(),
            ];
        });

        // Filter low_stock if requested
        if ($request->boolean('low_stock') && $locationId) {
            $items = $items->filter(fn ($i) => ! empty($i['location_balance']['is_low_stock']))->values();
        }

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
     * Show detailed inventory item with all location balances.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $item = InventoryItem::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with([
                'variant.product',
                'itemLocations.location:id,name,code,type,is_active',
            ])
            ->firstOrFail();

        $locationBalances = $item->itemLocations->map(fn ($locBal) => [
            'location_id' => $locBal->location_id,
            'location_name' => $locBal->location?->name,
            'location_code' => $locBal->location?->code,
            'location_type' => $locBal->location?->type,
            'on_hand' => $locBal->on_hand,
            'reserved' => $locBal->reserved,
            'available' => $locBal->available,
            'reorder_level' => $locBal->reorder_level,
            'is_low_stock' => $locBal->isLowStock(),
        ]);

        return ApiResponse::success(data: [
            'id' => $item->id,
            'tenant_id' => $item->tenant_id,
            'product_variant_id' => $item->product_variant_id,
            'variant' => $item->variant ? [
                'id' => $item->variant->id,
                'name' => $item->variant->name,
                'sku' => $item->variant->sku,
                'product_name' => $item->variant->product?->name,
            ] : null,
            'sku' => $item->sku,
            'name' => $item->name,
            'item_type' => $item->item_type,
            'base_unit' => $item->base_unit,
            'status' => $item->status,
            'allow_negative_stock' => $item->allow_negative_stock,
            'location_balances' => $locationBalances,
        ]);
    }

    /**
     * Create an inventory item.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'product_variant_id' => 'nullable|uuid',
            'sku' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
            'item_type' => 'nullable|string|in:sellable,raw_material,packaging,finished_good,semi_finished,asset',
            'base_unit' => 'required|string|max:30',
            'status' => 'nullable|string|in:active,inactive',
            'allow_negative_stock' => 'nullable|boolean',
        ]);

        // Verify variant belongs to tenant
        if (! empty($validated['product_variant_id'])) {
            $variant = ProductVariant::where('id', $validated['product_variant_id'])
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $variant) {
                return ApiResponse::error(
                    message: 'Variant not found or belongs to another business.',
                    code: 'VARIANT_CROSS_TENANT',
                    status: 422
                );
            }

            // Check if inventory item already exists for this variant
            $exists = InventoryItem::where('tenant_id', $tenantId)
                ->where('product_variant_id', $variant->id)
                ->exists();

            if ($exists) {
                return ApiResponse::error(
                    message: 'An inventory item already exists for this variant.',
                    code: 'INVENTORY_ITEM_EXISTS',
                    status: 422
                );
            }
        }

        $item = InventoryItem::create([
            'tenant_id' => $tenantId,
            'product_variant_id' => $validated['product_variant_id'] ?? null,
            'sku' => $validated['sku'] ?? null,
            'name' => $validated['name'],
            'item_type' => $validated['item_type'] ?? 'sellable',
            'base_unit' => strtolower($validated['base_unit']),
            'status' => $validated['status'] ?? 'active',
            'allow_negative_stock' => $validated['allow_negative_stock'] ?? false,
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'inventory_item_created',
            'entity_type' => InventoryItem::class,
            'entity_id' => $item->id,
            'after' => $item->toArray(),
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'base_unit' => $item->base_unit,
                'item_type' => $item->item_type,
                'status' => $item->status,
                'allow_negative_stock' => $item->allow_negative_stock,
            ],
            message: 'Inventory item created successfully.',
            status: 201
        );
    }

    /**
     * Get paginated movements for a specific inventory item.
     */
    public function movements(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $item = InventoryItem::where('tenant_id', $tenantId)->findOrFail($id);

        $query = InventoryMovement::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->with(['location:id,name,code', 'creator:id,name']);

        if ($locationId = $request->query('location_id')) {
            $query->where('location_id', $locationId);
        }

        if ($movementType = $request->query('movement_type')) {
            $query->where('movement_type', $movementType);
        }

        if ($from = $request->query('from')) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('occurred_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginated = $query->orderByDesc('occurred_at')->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::success(data: [
            'items' => collect($paginated->items())->map(fn ($m) => [
                'id' => $m->id,
                'movement_number' => $m->movement_number,
                'location_id' => $m->location_id,
                'location_name' => $m->location?->name,
                'movement_type' => $m->movement_type,
                'quantity_delta' => $m->quantity_delta,
                'unit' => $m->unit,
                'before_on_hand' => $m->before_on_hand,
                'after_on_hand' => $m->after_on_hand,
                'reason' => $m->reason,
                'note' => $m->note,
                'occurred_at' => $m->occurred_at?->toIso8601String(),
                'creator_name' => $m->creator?->name,
            ]),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Bulk or single availability query: on_hand, reserved, available.
     */
    public function availability(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $locationId = $request->query('location_id');

        $query = InventoryItemLocation::where('tenant_id', $tenantId)
            ->with(['item:id,name,sku,base_unit', 'location:id,name,code']);

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        if ($itemId = $request->query('inventory_item_id')) {
            $query->where('inventory_item_id', $itemId);
        }

        $balances = $query->get();

        return ApiResponse::success(data: $balances->map(fn ($b) => [
            'inventory_item_id' => $b->inventory_item_id,
            'item_name' => $b->item?->name,
            'sku' => $b->item?->sku,
            'unit' => $b->item?->base_unit,
            'location_id' => $b->location_id,
            'location_name' => $b->location?->name,
            'on_hand' => $b->on_hand,
            'reserved' => $b->reserved,
            'available' => $b->available,
            'reorder_level' => $b->reorder_level,
            'is_low_stock' => $b->isLowStock(),
        ]));
    }

    /**
     * Update reorder level threshold for an item at a location.
     */
    public function updateThreshold(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'location_id' => 'required|uuid',
            'reorder_level' => 'required|numeric|min:0',
        ]);

        $item = InventoryItem::where('tenant_id', $tenantId)->findOrFail($id);
        $location = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($validated['location_id']);

        $itemLoc = InventoryItemLocation::firstOrCreate(
            ['tenant_id' => $tenantId, 'inventory_item_id' => $item->id, 'location_id' => $location->id],
            ['on_hand' => 0, 'reserved' => 0, 'reorder_level' => 0]
        );

        $before = $itemLoc->reorder_level;
        $itemLoc->reorder_level = sprintf('%.4f', (float) $validated['reorder_level']);
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

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'reorder_level_changed',
            'entity_type' => InventoryItemLocation::class,
            'entity_id' => $itemLoc->id,
            'before' => ['reorder_level' => $before],
            'after' => ['reorder_level' => $itemLoc->reorder_level],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(data: [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'reorder_level' => $itemLoc->reorder_level,
        ], message: 'Reorder level updated successfully.');
    }
}
