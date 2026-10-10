<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\StockOpname;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOpnameController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * List stock opnames.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = StockOpname::where('tenant_id', $tenantId)
            ->with(['location:id,name,code', 'counter:id,name', 'approver:id,name']);

        if ($locationId = $request->query('location_id')) {
            $query->where('location_id', $locationId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::success(data: [
            'items' => collect($paginated->items())->map(fn ($op) => [
                'id' => $op->id,
                'opname_number' => $op->opname_number,
                'location_id' => $op->location_id,
                'location_name' => $op->location?->name,
                'status' => $op->status,
                'counted_by' => $op->counter?->name,
                'approved_by' => $op->approver?->name,
                'approved_at' => $op->approved_at?->toIso8601String(),
                'created_at' => $op->created_at?->toIso8601String(),
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
     * Show single stock opname with counted items.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $opname = StockOpname::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with([
                'location:id,name,code',
                'counter:id,name',
                'approver:id,name',
                'items.item:id,name,sku,base_unit',
            ])
            ->firstOrFail();

        return ApiResponse::success(data: [
            'id' => $opname->id,
            'opname_number' => $opname->opname_number,
            'location_id' => $opname->location_id,
            'location_name' => $opname->location?->name,
            'status' => $opname->status,
            'notes' => $opname->notes,
            'counted_by' => $opname->counter?->name,
            'approved_by' => $opname->approver?->name,
            'approved_at' => $opname->approved_at?->toIso8601String(),
            'created_at' => $opname->created_at?->toIso8601String(),
            'items' => $opname->items->map(fn ($item) => [
                'id' => $item->id,
                'inventory_item_id' => $item->inventory_item_id,
                'item_name' => $item->item?->name,
                'sku' => $item->item?->sku,
                'unit' => $item->item?->base_unit,
                'system_quantity' => $item->system_quantity,
                'physical_quantity' => $item->physical_quantity,
                'difference' => $item->difference,
                'reason' => $item->reason,
                'notes' => $item->notes,
            ]),
        ]);
    }

    /**
     * Create a new stock opname counting session.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'location_id' => 'required|uuid',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|uuid',
            'items.*.physical_quantity' => 'required|numeric|min:0',
            'items.*.reason' => 'nullable|string|max:100',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $opname = $this->inventoryService->createOpname(
            tenantId: $tenantId,
            locationId: $validated['location_id'],
            items: $validated['items'],
            countedBy: $request->user()->id,
            notes: $validated['notes'] ?? null,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'stock_opname_created',
            'entity_type' => StockOpname::class,
            'entity_id' => $opname->id,
            'after' => $opname->toArray(),
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $opname->id,
                'opname_number' => $opname->opname_number,
                'location_id' => $opname->location_id,
                'status' => $opname->status,
                'items_count' => $opname->items->count(),
            ],
            message: 'Stock opname session created.',
            status: 201
        );
    }

    /**
     * Approve stock opname and generate reconciliation ledger movements.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $opname = $this->inventoryService->approveOpname(
            tenantId: $tenantId,
            opnameId: $id,
            approvedBy: $request->user()->id,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'stock_opname_approved',
            'entity_type' => StockOpname::class,
            'entity_id' => $opname->id,
            'after' => [
                'status' => $opname->status,
                'approved_by' => $opname->approved_by,
                'approved_at' => $opname->approved_at?->toIso8601String(),
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $opname->id,
                'opname_number' => $opname->opname_number,
                'status' => $opname->status,
                'approved_by' => $opname->approved_by,
                'approved_at' => $opname->approved_at?->toIso8601String(),
            ],
            message: 'Stock opname approved and movements posted successfully.'
        );
    }
}
