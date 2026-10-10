<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * List stock transfers.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $query = StockTransfer::where('tenant_id', $tenantId)
            ->with(['sourceLocation:id,name,code,outlet_id', 'destinationLocation:id,name,code,outlet_id', 'requester:id,name']);

        if (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            $query->where(function ($q) use ($assigned) {
                $q->whereHas('sourceLocation', fn ($sq) => $sq->whereIn('outlet_id', $assigned)->orWhereNull('outlet_id'))
                    ->orWhereHas('destinationLocation', fn ($dq) => $dq->whereIn('outlet_id', $assigned)->orWhereNull('outlet_id'));
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($locId = $request->query('location_id')) {
            $query->where(function ($q) use ($locId) {
                $q->where('source_location_id', $locId)
                    ->orWhere('destination_location_id', $locId);
            });
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::success(data: [
            'items' => collect($paginated->items())->map(fn ($tr) => [
                'id' => $tr->id,
                'transfer_number' => $tr->transfer_number,
                'source_location_id' => $tr->source_location_id,
                'source_location_name' => $tr->sourceLocation?->name,
                'destination_location_id' => $tr->destination_location_id,
                'destination_location_name' => $tr->destinationLocation?->name,
                'status' => $tr->status,
                'requested_by' => $tr->requester?->name,
                'created_at' => $tr->created_at?->toIso8601String(),
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
     * Show single transfer details.
     */
    public function show(string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $transfer = StockTransfer::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with([
                'sourceLocation:id,name,code,outlet_id',
                'destinationLocation:id,name,code,outlet_id',
                'requester:id,name',
                'approver:id,name',
                'receiver:id,name',
                'items.item:id,name,sku,base_unit',
            ])
            ->firstOrFail();

        $hasSourceAccess = ! $transfer->sourceLocation?->outlet_id || $tenantContext->hasOutletAccess($transfer->sourceLocation->outlet_id);
        $hasDestAccess = ! $transfer->destinationLocation?->outlet_id || $tenantContext->hasOutletAccess($transfer->destinationLocation->outlet_id);
        if (! $hasSourceAccess && ! $hasDestAccess) {
            return ApiResponse::error(
                message: 'User is not authorized to access this stock transfer.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        return ApiResponse::success(data: [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'source_location_id' => $transfer->source_location_id,
            'source_location_name' => $transfer->sourceLocation?->name,
            'destination_location_id' => $transfer->destination_location_id,
            'destination_location_name' => $transfer->destinationLocation?->name,
            'status' => $transfer->status,
            'notes' => $transfer->notes,
            'requested_by' => $transfer->requester?->name,
            'approved_by' => $transfer->approver?->name,
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_by' => $transfer->receiver?->name,
            'received_at' => $transfer->received_at?->toIso8601String(),
            'created_at' => $transfer->created_at?->toIso8601String(),
            'items' => $transfer->items->map(fn ($item) => [
                'id' => $item->id,
                'inventory_item_id' => $item->inventory_item_id,
                'item_name' => $item->item?->name,
                'sku' => $item->item?->sku,
                'unit' => $item->item?->base_unit,
                'quantity' => $item->quantity,
            ]),
        ]);
    }

    /**
     * Create a stock transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'source_location_id' => 'required|uuid',
            'destination_location_id' => 'required|uuid|different:source_location_id',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|uuid',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        $sourceLocation = InventoryLocation::where('tenant_id', $tenantId)->findOrFail($validated['source_location_id']);
        if ($sourceLocation->outlet_id && ! $tenantContext->hasOutletAccess($sourceLocation->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized for the source location outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        $transfer = $this->inventoryService->createTransfer(
            tenantId: $tenantId,
            sourceLocationId: $validated['source_location_id'],
            destinationLocationId: $validated['destination_location_id'],
            items: $validated['items'],
            requestedBy: $request->user()->id,
            notes: $validated['notes'] ?? null,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'inventory_transfer_created',
            'entity_type' => StockTransfer::class,
            'entity_id' => $transfer->id,
            'after' => $transfer->toArray(),
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'source_location_id' => $transfer->source_location_id,
                'destination_location_id' => $transfer->destination_location_id,
                'status' => $transfer->status,
                'items_count' => $transfer->items->count(),
            ],
            message: 'Stock transfer created successfully.',
            status: 201
        );
    }

    /**
     * Dispatch stock transfer (deducts stock from source location).
     */
    public function dispatch(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $transfer = StockTransfer::where('tenant_id', $tenantId)->with('sourceLocation')->findOrFail($id);
        if ($transfer->sourceLocation?->outlet_id && ! $tenantContext->hasOutletAccess($transfer->sourceLocation->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized for the source location outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        $transfer = $this->inventoryService->dispatchTransfer(
            tenantId: $tenantId,
            transferId: $id,
            approvedBy: $request->user()->id,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'inventory_transfer_approved',
            'entity_type' => StockTransfer::class,
            'entity_id' => $transfer->id,
            'after' => [
                'status' => $transfer->status,
                'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'status' => $transfer->status,
                'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            ],
            message: 'Stock transfer dispatched successfully.'
        );
    }

    /**
     * Receive stock transfer (adds stock to destination location).
     */
    public function receive(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $transfer = StockTransfer::where('tenant_id', $tenantId)->with('destinationLocation')->findOrFail($id);
        if ($transfer->destinationLocation?->outlet_id && ! $tenantContext->hasOutletAccess($transfer->destinationLocation->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized for the destination location outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        $transfer = $this->inventoryService->receiveTransfer(
            tenantId: $tenantId,
            transferId: $id,
            receivedBy: $request->user()->id,
        );

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'inventory_transfer_received',
            'entity_type' => StockTransfer::class,
            'entity_id' => $transfer->id,
            'after' => [
                'status' => $transfer->status,
                'received_at' => $transfer->received_at?->toIso8601String(),
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'status' => $transfer->status,
                'received_at' => $transfer->received_at?->toIso8601String(),
            ],
            message: 'Stock transfer received successfully.'
        );
    }
}
