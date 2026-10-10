<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PurchaseReceipt;
use App\Services\PurchasingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReceiptController extends Controller
{
    public function __construct(
        protected PurchasingService $purchasingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = PurchaseReceipt::where('tenant_id', $tenantId)
            ->with(['purchaseOrder:id,reference_number,status', 'destinationLocation:id,name,code', 'items.item:id,name,base_unit']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($poId = $request->query('purchase_order_id')) {
            $query->where('purchase_order_id', $poId);
        }

        if ($locationId = $request->query('location_id')) {
            $query->where('destination_location_id', $locationId);
        }

        if ($search = $request->query('search')) {
            $query->where('reference_number', 'ilike', "%{$search}%");
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $receipts = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $receipts,
            data: $receipts->map(fn ($r) => $this->formatReceipt($r))->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'purchase_order_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost_minor' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $receipt = $this->purchasingService->createReceipt(
            tenantId: $tenantId,
            orderId: $validated['purchase_order_id'],
            itemsData: $validated['items'],
            receivedBy: $request->user()->id,
            notes: $validated['notes'] ?? null
        );

        return ApiResponse::success(
            data: $this->formatReceipt($receipt),
            message: 'Draft goods receipt created successfully.',
            status: 201
        );
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $receipt = PurchaseReceipt::where('tenant_id', $tenantId)
            ->with(['purchaseOrder', 'destinationLocation', 'items.item', 'receiver:id,name'])
            ->findOrFail($id);

        return ApiResponse::success(data: $this->formatReceipt($receipt));
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $receipt = $this->purchasingService->postReceipt(
            tenantId: $tenantId,
            receiptId: $id,
            postedBy: $request->user()->id
        );

        return ApiResponse::success(
            data: $this->formatReceipt($receipt),
            message: 'Goods receipt posted successfully. Inventory updated.'
        );
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $receipt = $this->purchasingService->cancelReceipt(
            tenantId: $tenantId,
            receiptId: $id,
            cancelledBy: $request->user()->id,
            reason: $validated['reason'] ?? null
        );

        return ApiResponse::success(
            data: $this->formatReceipt($receipt),
            message: 'Draft goods receipt cancelled successfully.'
        );
    }

    protected function formatReceipt(PurchaseReceipt $r): array
    {
        return [
            'id' => $r->id,
            'tenant_id' => $r->tenant_id,
            'purchase_order_id' => $r->purchase_order_id,
            'purchase_order_reference' => $r->purchaseOrder?->reference_number,
            'destination_location_id' => $r->destination_location_id,
            'destination_location_name' => $r->destinationLocation?->name,
            'reference_number' => $r->reference_number,
            'status' => $r->status,
            'received_at' => $r->received_at?->toIso8601String(),
            'received_by' => $r->received_by,
            'notes' => $r->notes,
            'created_at' => $r->created_at?->toIso8601String(),
            'items' => $r->items->map(fn ($item) => [
                'id' => $item->id,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'inventory_item_id' => $item->inventory_item_id,
                'item_name' => $item->item?->name,
                'base_unit' => $item->item?->base_unit,
                'quantity' => (string) $item->quantity,
                'unit_cost_minor' => $item->unit_cost_minor,
                'notes' => $item->notes,
            ])->all(),
        ];
    }
}
