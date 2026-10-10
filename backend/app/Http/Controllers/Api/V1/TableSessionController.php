<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\Order\OrderService;
use App\Services\Table\TableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableSessionController extends Controller
{
    public function __construct(
        protected TableService $tableService,
        protected OrderService $orderService
    ) {}

    /**
     * List table sessions.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $outletId = $request->query('outlet_id');
        $status = $request->query('status');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $query = TableSession::where('tenant_id', $tenantId)
            ->with(['table', 'openedBy', 'orders']);

        if ($outletId) {
            $this->assertOutletAccess($outletId);
            $query->where('outlet_id', $outletId);
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            $query->whereIn('outlet_id', $assigned);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->orderBy('started_at', 'desc')->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (TableSession $s) => $this->formatSession($s))->all()
        );
    }

    /**
     * Start a new table session explicitly.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'table_id' => ['required', 'uuid'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'booking_id' => ['nullable', 'uuid'],
        ]);

        $table = Table::where('tenant_id', $tenantId)->findOrFail($validated['table_id']);
        $this->assertOutletAccess($table->outlet_id);

        $session = $this->tableService->startSession(
            table: $table,
            guestCount: $validated['guest_count'] ?? null,
            bookingId: $validated['booking_id'] ?? null,
            actorUserId: $request->user()?->id
        );

        return ApiResponse::success(
            data: $this->formatSession($session),
            message: 'Table session started.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Checkout session: convert all active orders to a Sale and release table.
     */
    public function checkout(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $session = TableSession::where('tenant_id', $tenantId)->with('table')->findOrFail($id);
        $this->assertOutletAccess($session->outlet_id);

        // Convert orders to Sale
        $sale = $this->orderService->convertOrdersToSale(
            orderIdsOrSessionId: $session->session_code,
            cashierUserId: $request->user()?->id ?? '00000000-0000-0000-0000-000000000000'
        );

        return ApiResponse::success(
            data: [
                'session' => $this->formatSession($session->fresh()),
                'sale' => $sale,
            ],
            message: 'Session checked out and converted to Sale.'
        );
    }

    protected function assertOutletAccess(string $outletId): void
    {
        $context = app(TenantContext::class);
        if (! $context->hasOutletAccess($outletId)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this outlet.');
        }
    }

    protected function formatSession(TableSession $session): array
    {
        return [
            'id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'outlet_id' => $session->outlet_id,
            'table_id' => $session->table_id,
            'booking_id' => $session->booking_id,
            'session_code' => $session->session_code,
            'status' => $session->status,
            'guest_count' => $session->guest_count,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'table' => $session->table ? [
                'id' => $session->table->id,
                'code' => $session->table->code,
                'name' => $session->table->name,
            ] : null,
        ];
    }
}
