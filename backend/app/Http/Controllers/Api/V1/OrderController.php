<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\TableSession;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * List operational orders for POS and kitchen display.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $outletId = $request->query('outlet_id');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $filters = $request->only(['table_id', 'table_session_id', 'status', 'source']);
        if ($outletId) {
            $this->assertOutletAccess($outletId);
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $filters['assigned_outlet_ids'] = $tenantContext->membership()->assigned_outlet_ids ?? [];
        }

        $paginator = $this->orderService->listOrders($tenantId, $outletId, $filters, $perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (Order $o) => $this->formatOrder($o))->all()
        );
    }

    /**
     * Show single order details with items and modifiers.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $order = Order::where('tenant_id', $tenantId)
            ->with(['items.variant.product', 'table', 'session', 'customer', 'sale'])
            ->findOrFail($id);

        $this->assertOutletAccess($order->outlet_id);

        return ApiResponse::success(data: $this->formatOrder($order, true));
    }

    /**
     * Update order operational lifecycle status.
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $order = Order::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($order->outlet_id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,preparing,ready,served,cancelled'],
        ]);

        $updated = $this->orderService->updateOrderStatus(
            order: $order,
            newStatus: $validated['status'],
            actorUserId: $request->user()?->id
        );

        return ApiResponse::success(
            data: $this->formatOrder($updated),
            message: "Order status updated to {$updated->status}."
        );
    }

    /**
     * Convert operational orders into an authoritative POS Sale.
     */
    public function convertToSale(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['uuid'],
            'table_session_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $target = ! empty($validated['order_ids'])
            ? $validated['order_ids']
            : ($validated['table_session_id'] ?? null);

        if (! $target) {
            return ApiResponse::error(
                message: 'Either order_ids or table_session_id must be provided.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (! empty($validated['order_ids'])) {
            $orders = Order::where('tenant_id', $tenantId)->whereIn('id', $validated['order_ids'])->get();
            foreach ($orders as $order) {
                $this->assertOutletAccess($order->outlet_id);
            }
        } elseif (! empty($validated['table_session_id'])) {
            $sessionTarget = $validated['table_session_id'];
            $sessionQuery = TableSession::where('tenant_id', $tenantId);
            $session = Str::isUuid($sessionTarget)
                ? $sessionQuery->where('id', $sessionTarget)->firstOrFail()
                : $sessionQuery->where('session_code', $sessionTarget)->firstOrFail();
            $this->assertOutletAccess($session->outlet_id);
        }

        $sale = $this->orderService->convertOrdersToSale(
            orderIdsOrSessionId: $target,
            cashierUserId: $request->user()?->id ?? '00000000-0000-0000-0000-000000000000',
            notes: $validated['notes'] ?? null
        );

        return ApiResponse::success(
            data: [
                'sale' => $sale,
            ],
            message: 'Orders converted to Sale successfully.',
            status: Response::HTTP_CREATED
        );
    }

    protected function assertOutletAccess(string $outletId): void
    {
        $context = app(TenantContext::class);
        if (! $context->hasOutletAccess($outletId)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this outlet.');
        }
    }

    protected function formatOrder(Order $order, bool $detailed = false): array
    {
        $data = [
            'id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'outlet_id' => $order->outlet_id,
            'table_id' => $order->table_id,
            'table_session_id' => $order->table_session_id,
            'customer_id' => $order->customer_id,
            'sale_id' => $order->sale_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'subtotal_minor' => $order->subtotal_minor,
            'discount_minor' => $order->discount_minor,
            'tax_minor' => $order->tax_minor,
            'total_minor' => $order->total_minor,
            'customer_notes' => $order->customer_notes,
            'source' => $order->source,
            'table' => $order->table ? [
                'id' => $order->table->id,
                'code' => $order->table->code,
                'name' => $order->table->name,
            ] : null,
            'session' => $order->session ? [
                'id' => $order->session->id,
                'session_code' => $order->session->session_code,
            ] : null,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];

        if ($detailed || $order->relationLoaded('items')) {
            $data['items'] = $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product_name_snapshot,
                    'variant_name' => $item->variant_name_snapshot,
                    'quantity' => (float) $item->quantity,
                    'unit_price_minor' => $item->unit_price_minor,
                    'subtotal_minor' => $item->subtotal_minor,
                    'notes' => $item->notes,
                    'modifiers' => $item->selected_modifiers,
                ];
            });
        }

        return $data;
    }
}
