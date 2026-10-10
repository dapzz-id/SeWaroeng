<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Table;
use App\Models\Tenant;
use App\Services\Order\OrderService;
use App\Services\Subscription\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicTableController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected TenantManager $tenantManager,
        protected SubscriptionEntitlementService $entitlementService
    ) {}

    /**
     * Resolves table context from public QR token without leaking internal tenant IDs.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $table = $this->orderService->resolveTableByToken($token);

        if (! $table || ! $table->qr_token_hash) {
            return ApiResponse::error(
                message: 'Meja tidak ditemukan atau kode QR telah kadaluarsa.',
                status: Response::HTTP_NOT_FOUND
            );
        }

        if (! $table->is_active || $table->status === Table::STATUS_INACTIVE) {
            return ApiResponse::error(
                message: 'Meja saat ini tidak aktif.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $tenant = Tenant::withoutGlobalScopes()->find($table->tenant_id);
        if (! $tenant || ! $this->entitlementService->can($tenant, 'qr_table_order')) {
            return ApiResponse::error(
                message: 'Layanan pemesanan QR meja tidak aktif untuk outlet ini.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        return $this->tenantManager->runForTenant((string) $table->tenant_id, function () use ($table) {
            $table->load('outlet');

            return ApiResponse::success([
                'table' => [
                    'code' => $table->code,
                    'name' => $table->name,
                    'capacity' => $table->capacity,
                    'status' => $table->status,
                ],
                'outlet' => [
                    'name' => $table->outlet?->name,
                ],
                'table_code' => $table->code,
                'table_name' => $table->name,
                'capacity' => $table->capacity,
                'status' => $table->status,
                'outlet_name' => $table->outlet?->name,
            ]);
        });
    }

    /**
     * Public menu endpoint for customer ordering.
     */
    public function menu(Request $request, string $token): JsonResponse
    {
        $table = $this->orderService->resolveTableByToken($token);

        if (! $table || ! $table->qr_token_hash || ! $table->is_active || $table->status === Table::STATUS_INACTIVE) {
            return ApiResponse::error(
                message: 'Meja tidak tersedia.',
                status: Response::HTTP_NOT_FOUND
            );
        }

        $tenant = Tenant::withoutGlobalScopes()->find($table->tenant_id);
        if (! $tenant || ! $this->entitlementService->can($tenant, 'qr_table_order')) {
            return ApiResponse::error(
                message: 'Fitur pemesanan meja belum diaktifkan.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $menuData = $this->tenantManager->runForTenant((string) $table->tenant_id, function () use ($table) {
            $table->load('outlet');
            $products = Product::where('tenant_id', $table->tenant_id)
                ->where('status', 'active')
                ->with([
                    'category:id,name,slug',
                    'variants.prices',
                ])
                ->orderBy('sort_order')
                ->get();

            $modifierGroups = ModifierGroup::where('tenant_id', $table->tenant_id)
                ->with(['modifiers' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
                ->get();

            $formattedProducts = $products->filter(function (Product $product) use ($table) {
                return $product->isAvailableAtOutlet($table->outlet_id);
            })->map(function (Product $product) use ($table) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category' => $product->category?->name,
                    'variants' => $product->variants->map(function (ProductVariant $variant) use ($table) {
                        $price = $variant->resolvePrice($table->outlet_id);

                        return [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'price_minor' => $price ? (int) $price->amount : 0,
                            'is_default' => $variant->is_default,
                        ];
                    })->values(),
                ];
            })->values();

            $formattedModifiers = $modifierGroups->map(function ($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'min_selection' => $group->min_selection,
                    'max_selection' => $group->max_selection,
                    'modifiers' => $group->modifiers->map(function ($m) {
                        return [
                            'id' => $m->id,
                            'name' => $m->name,
                            'price_delta' => $m->price_delta,
                        ];
                    })->values(),
                ];
            })->values();

            return [
                'outlet_name' => $table->outlet?->name,
                'table_name' => $table->name,
                'table_code' => $table->code,
                'categories' => $products->pluck('category.name')->filter()->unique()->values(),
                'products' => $formattedProducts,
                'modifier_groups' => $formattedModifiers,
            ];
        });

        return ApiResponse::success($menuData);
    }

    /**
     * Submit an order from customer QR page.
     * Price and total amounts are authoritative and calculated strictly by server.
     */
    public function submitOrder(Request $request, string $token): JsonResponse
    {
        $table = $this->orderService->resolveTableByToken($token);

        if (! $table || ! $table->qr_token_hash) {
            return ApiResponse::error(
                message: 'Meja tidak ditemukan atau kode QR telah kadaluarsa.',
                status: Response::HTTP_NOT_FOUND
            );
        }

        if (! $table->is_active || $table->status === Table::STATUS_INACTIVE) {
            return ApiResponse::error(
                message: 'Meja saat ini tidak aktif.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $tenant = Tenant::withoutGlobalScopes()->find($table->tenant_id);
        if (! $tenant || ! $this->entitlementService->can($tenant, 'qr_table_order')) {
            return ApiResponse::error(
                message: 'Layanan pemesanan QR meja tidak aktif untuk outlet ini.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array'],
            'customer_notes' => ['nullable', 'string', 'max:500'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:100'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');

        $customerData = null;
        if (! empty($validated['customer_phone']) || ! empty($validated['customer_email']) || ! empty($validated['customer_name'])) {
            $customerData = [
                'name' => $validated['customer_name'] ?? null,
                'phone' => $validated['customer_phone'] ?? null,
                'email' => $validated['customer_email'] ?? null,
            ];
        }

        $order = $this->orderService->submitOrderFromQr(
            qrToken: $token,
            items: $validated['items'],
            customerNotes: $validated['customer_notes'] ?? null,
            idempotencyKey: $idempotencyKey,
            customerData: $customerData
        );

        return ApiResponse::success(
            data: [
                'order_number' => $order->order_number,
                'tracking_token' => $order->raw_tracking_token,
                'status' => $order->status,
                'subtotal_minor' => $order->subtotal_minor,
                'total_minor' => $order->total_minor,
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at->toIso8601String(),
            ],
            message: 'Pesanan Anda telah diterima!',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Customer order status tracking with cryptographically secure tracking token.
     */
    public function orderStatus(Request $request, string $token, string $trackingToken): JsonResponse
    {
        $table = $this->orderService->resolveTableByToken($token);

        if (! $table || ! $table->qr_token_hash || ! $table->is_active) {
            return ApiResponse::error(
                message: 'Meja tidak ditemukan atau kode QR telah kadaluarsa.',
                status: Response::HTTP_NOT_FOUND
            );
        }

        $trackingHash = Order::hashTrackingToken($trackingToken);

        return $this->tenantManager->runForTenant((string) $table->tenant_id, function () use ($table, $trackingHash) {
            $order = Order::where('tenant_id', $table->tenant_id)
                ->where('table_id', $table->id)
                ->where('public_tracking_token_hash', $trackingHash)
                ->with(['items', 'sale'])
                ->first();

            if (! $order) {
                return ApiResponse::error(
                    message: 'Pesanan tidak ditemukan atau token pelacakan tidak valid.',
                    status: Response::HTTP_NOT_FOUND
                );
            }

            // Public customer status projection: received -> preparing -> ready -> served (terminal) / cancelled (terminal)
            // When an order is converted into an open Sale, tracking progress continues seamlessly until finalized or cancelled
            $publicStatus = match ($order->status) {
                Order::STATUS_SUBMITTED, Order::STATUS_ACCEPTED => 'received',
                Order::STATUS_PREPARING => 'preparing',
                Order::STATUS_READY => 'ready',
                Order::STATUS_SERVED => 'served',
                Order::STATUS_CANCELLED => 'cancelled',
                Order::STATUS_CONVERTED => match ($order->sale?->status) {
                    Sale::STATUS_CANCELLED => 'cancelled',
                    Sale::STATUS_FINALIZED => 'served',
                    default => 'received', // Converted to open Sale at cashier: still in progress for customer
                },
                default => $order->status,
            };

            $statusLabels = [
                'received' => 'Diterima',
                'preparing' => 'Sedang Disiapkan',
                'ready' => 'Siap Disajikan',
                'served' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                // Backward-compatibility keys
                Order::STATUS_SUBMITTED => 'Diterima',
                Order::STATUS_ACCEPTED => 'Diterima',
                Order::STATUS_PREPARING => 'Sedang Disiapkan',
                Order::STATUS_READY => 'Siap Disajikan',
                Order::STATUS_SERVED => 'Selesai',
                Order::STATUS_CONVERTED => 'Diterima di Kasir',
                Order::STATUS_CANCELLED => 'Dibatalkan',
            ];

            $isTerminal = in_array($publicStatus, ['served', 'cancelled'], true);

            return ApiResponse::success([
                'order_number' => $order->order_number,
                'status' => $order->status,
                'public_status' => $publicStatus,
                'status_label' => $statusLabels[$publicStatus] ?? $statusLabels[$order->status] ?? $order->status,
                'is_terminal' => $isTerminal,
                'customer_name' => $order->customer_name_snapshot,
                'item_count' => $order->items->count(),
                'total_minor' => $order->total_minor,
                'created_at' => $order->created_at?->toIso8601String(),
            ]);
        });
    }

    /**
     * Serves the customer-facing mobile-first public QR ordering web page with hardened security headers.
     */
    public function landingPage(Request $request, string $token)
    {
        $table = $this->orderService->resolveTableByToken($token);

        $hasError = (! $table || ! $table->qr_token_hash || ! $table->is_active);

        $response = response()->view('order', [
            'token' => $token,
            'initialError' => $hasError ? 'Meja tidak ditemukan atau kode QR telah kadaluarsa.' : null,
        ], $hasError ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Content-Security-Policy', "default-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");

        return $response;
    }
}
