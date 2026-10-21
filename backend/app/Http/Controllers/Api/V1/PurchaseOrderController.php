<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PurchaseOrder;
use App\Services\PurchasingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchasingService $purchasingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = PurchaseOrder::where('tenant_id', $tenantId)
            ->with(['supplier:id,name,code', 'destinationLocation:id,name,code', 'items.item:id,name,sku,base_unit']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        if ($locationId = $request->query('location_id')) {
            $query->where('destination_location_id', $locationId);
        }

        if ($search = $request->query('search')) {
            $query->where('reference_number', 'ilike', "%{$search}%");
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $orders = $query->orderByDesc('ordered_at')->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $orders,
            data: $orders->map(fn ($po) => $this->formatOrder($po))->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'destination_location_id' => ['required', 'uuid'],
            'ordered_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'uuid'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost_minor' => ['required', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $order = $this->purchasingService->createPurchaseOrder(
            tenantId: $tenantId,
            data: $validated,
            userId: $request->user()->id
        );

        return ApiResponse::success(
            data: $this->formatOrder($order),
            message: 'Purchase order created successfully.',
            status: 201
        );
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $po = PurchaseOrder::where('tenant_id', $tenantId)
            ->with(['supplier', 'destinationLocation', 'items.item', 'receipts.items', 'creator:id,name', 'approver:id,name'])
            ->findOrFail($id);

        return ApiResponse::success(data: $this->formatOrder($po));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $order = $this->purchasingService->submitPurchaseOrder(
            tenantId: $tenantId,
            orderId: $id,
            userId: $request->user()->id
        );

        return ApiResponse::success(
            data: $this->formatOrder($order),
            message: 'Purchase order submitted successfully.'
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $order = $this->purchasingService->approvePurchaseOrder(
            tenantId: $tenantId,
            orderId: $id,
            approvedBy: $request->user()->id
        );

        return ApiResponse::success(
            data: $this->formatOrder($order),
            message: 'Purchase order approved successfully.'
        );
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->purchasingService->cancelPurchaseOrder(
            tenantId: $tenantId,
            orderId: $id,
            cancelledBy: $request->user()->id,
            reason: $validated['reason'] ?? null
        );

        return ApiResponse::success(
            data: $this->formatOrder($order),
            message: 'Purchase order cancelled successfully.'
        );
    }

    public function smartReorder(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $locationId = $request->query('location_id');

        $recommendations = $this->purchasingService->calculateSmartReorder(
            tenantId: $tenantId,
            locationId: $locationId
        );

        return ApiResponse::success(
            data: $recommendations,
            message: 'Smart reorder recommendations calculated successfully.'
        );
    }

    protected function formatOrder(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'tenant_id' => $po->tenant_id,
            'supplier_id' => $po->supplier_id,
            'supplier_name' => $po->supplier?->name,
            'supplier_code' => $po->supplier?->code,
            'destination_location_id' => $po->destination_location_id,
            'destination_location_name' => $po->destinationLocation?->name,
            'reference_number' => $po->reference_number,
            'status' => $po->status,
            'ordered_at' => $po->ordered_at?->toIso8601String(),
            'expected_at' => $po->expected_at?->toIso8601String(),
            'notes' => $po->notes,
            'created_by' => $po->created_by,
            'approved_by' => $po->approved_by,
            'approved_at' => $po->approved_at?->toIso8601String(),
            'created_at' => $po->created_at?->toIso8601String(),
            'items' => $po->items->map(fn ($item) => [
                'id' => $item->id,
                'inventory_item_id' => $item->inventory_item_id,
                'item_name' => $item->item?->name,
                'item_sku' => $item->item?->sku,
                'base_unit' => $item->item?->base_unit,
                'ordered_quantity' => (string) $item->ordered_quantity,
                'received_quantity' => (string) $item->received_quantity,
                'remaining_quantity' => $item->remaining_quantity,
                'unit_cost_minor' => $item->unit_cost_minor,
                'notes' => $item->notes,
            ])->all(),
        ];
    }
}
