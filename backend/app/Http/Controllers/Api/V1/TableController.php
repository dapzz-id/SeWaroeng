<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Table;
use App\Services\Table\TableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableController extends Controller
{
    public function __construct(
        protected TableService $tableService
    ) {}

    /**
     * List tables for current tenant and optional outlet.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $outletId = $request->query('outlet_id');
        $perPage = min((int) $request->query('per_page', 25), 100);

        $filters = $request->only(['status', 'is_active', 'search']);
        if ($outletId) {
            $this->assertOutletAccess($outletId);
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $filters['assigned_outlet_ids'] = $tenantContext->membership()->assigned_outlet_ids ?? [];
        }

        $paginator = $this->tableService->listTables($tenantId, $outletId, $filters, $perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (Table $t) => $this->formatTable($t))->all()
        );
    }

    /**
     * Create a new table.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'outlet_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:available,occupied,reserved,inactive'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $this->assertOutletAccess($validated['outlet_id']);

        $table = $this->tableService->createTable(
            tenant: $tenantId,
            outletId: $validated['outlet_id'],
            data: $validated,
            actorUserId: $request->user()?->id
        );

        $responseData = $this->formatTable($table);
        $responseData['raw_token'] = $table->raw_token;
        $responseData['qr_token'] = $table->raw_token;
        $responseData['qr_url'] = $table->raw_token ? url("/order/{$table->raw_token}") : null;

        return ApiResponse::success(
            data: $responseData,
            message: 'Table created successfully.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Show table details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $table = Table::where('tenant_id', $tenantId)
            ->with(['outlet', 'activeSession', 'bookings'])
            ->findOrFail($id);

        $this->assertOutletAccess($table->outlet_id);

        return ApiResponse::success(data: $this->formatTable($table, true));
    }

    /**
     * Update table details.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $table = Table::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($table->outlet_id);

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:available,occupied,reserved,inactive'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $updated = $this->tableService->updateTable($table, $validated, $request->user()?->id);

        return ApiResponse::success(
            data: $this->formatTable($updated),
            message: 'Table updated successfully.'
        );
    }

    /**
     * Rotate table QR token.
     */
    public function rotateQr(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $table = Table::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($table->outlet_id);

        $newToken = $this->tableService->rotateQrToken($table, $request->user()?->id);

        return ApiResponse::success(
            data: [
                'raw_token' => $newToken,
                'qr_token' => $newToken,
                'qr_url' => url("/order/{$newToken}"),
            ],
            message: 'QR token rotated successfully.'
        );
    }

    /**
     * Revoke table QR token.
     */
    public function revokeQr(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $table = Table::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($table->outlet_id);

        $this->tableService->revokeQrToken($table, $request->user()?->id);

        return ApiResponse::success(
            message: 'QR token revoked successfully.'
        );
    }

    /**
     * Release table and complete active session.
     */
    public function release(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $table = Table::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($table->outlet_id);

        $this->tableService->releaseTable($table, $request->user()?->id);

        return ApiResponse::success(
            data: $this->formatTable($table->fresh()),
            message: 'Table released successfully.'
        );
    }

    /**
     * Check in / start session for table directly.
     */
    public function checkIn(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $table = Table::where('tenant_id', $tenantId)->findOrFail($id);

        $this->assertOutletAccess($table->outlet_id);

        $validated = $request->validate([
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'booking_id' => ['nullable', 'uuid'],
        ]);

        $session = $this->tableService->startSession(
            $table,
            $validated['guest_count'] ?? null,
            $validated['booking_id'] ?? null,
            $request->user()?->id
        );

        return ApiResponse::success(
            data: [
                'session' => $session,
                'table' => $this->formatTable($table->fresh()),
            ],
            message: 'Table check-in successful.'
        );
    }

    /**
     * Assert outlet access for multi-outlet users.
     */
    protected function assertOutletAccess(string $outletId): void
    {
        $context = app(TenantContext::class);
        if (! $context->hasOutletAccess($outletId)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have access to this outlet.');
        }
    }

    protected function formatTable(Table $table, bool $detailed = false): array
    {
        $data = [
            'id' => $table->id,
            'tenant_id' => $table->tenant_id,
            'outlet_id' => $table->outlet_id,
            'code' => $table->code,
            'name' => $table->name,
            'capacity' => $table->capacity,
            'status' => $table->status,
            'qr_token' => $table->qr_token,
            'is_active' => $table->is_active,
            'active_session' => $table->activeSession,
            'created_at' => $table->created_at?->toIso8601String(),
            'updated_at' => $table->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['outlet'] = $table->outlet ? [
                'id' => $table->outlet->id,
                'name' => $table->outlet->name,
            ] : null;
            $data['bookings'] = $table->bookings;
        }

        return $data;
    }
}
