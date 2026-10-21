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
     * Get visual floor plan and table layouts for an outlet.
     */
    public function getFloorPlan(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $outletId = $request->query('outlet_id');

        if (! $outletId) {
            return ApiResponse::error(message: 'Outlet ID is required.', status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->assertOutletAccess($outletId);

        $outlet = \App\Models\Outlet::where('tenant_id', $tenantId)->findOrFail($outletId);

        $defaultFloorPlan = [
            'canvas_width' => 1000,
            'canvas_height' => 700,
            'elements' => [
                [
                    'id' => 'zone-indoor',
                    'type' => 'zone',
                    'x' => 40,
                    'y' => 40,
                    'width' => 440,
                    'height' => 620,
                    'label' => 'Area Indoor (AC & Bebas Asap)',
                    'color' => '#1e293b',
                ],
                [
                    'id' => 'zone-outdoor',
                    'type' => 'zone',
                    'x' => 520,
                    'y' => 40,
                    'width' => 440,
                    'height' => 620,
                    'label' => 'Area Outdoor (Smoking & Taman)',
                    'color' => '#0f291e',
                ],
                [
                    'id' => 'wall-divider',
                    'type' => 'wall',
                    'x1' => 500,
                    'y1' => 40,
                    'x2' => 500,
                    'y2' => 660,
                    'thickness' => 6,
                    'color' => '#475569',
                ],
                [
                    'id' => 'text-entrance',
                    'type' => 'text',
                    'x' => 220,
                    'y' => 640,
                    'label' => '🚪 PINTU MASUK UTAMA',
                    'font_size' => 14,
                    'color' => '#94a3b8',
                ],
                [
                    'id' => 'text-cashier',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 80,
                    'label' => '☕ KASIR & BAR',
                    'font_size' => 14,
                    'color' => '#38bdf8',
                ],
            ],
        ];

        $floorPlan = $outlet->settings['floor_plan'] ?? $defaultFloorPlan;

        $tables = Table::where('tenant_id', $tenantId)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->with(['activeSession', 'bookings'])
            ->orderBy('code')
            ->get()
            ->map(fn (Table $t) => $this->formatTable($t, true))
            ->values();

        return ApiResponse::success(data: [
            'outlet_id' => $outlet->id,
            'outlet_name' => $outlet->name,
            'floor_plan' => $floorPlan,
            'tables' => $tables,
        ]);
    }

    /**
     * Save floor plan canvas and table layout coordinates.
     */
    public function saveFloorPlan(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'outlet_id' => ['required', 'uuid'],
            'floor_plan' => ['required', 'array'],
            'tables' => ['nullable', 'array'],
            'tables.*.id' => ['required', 'uuid'],
            'tables.*.x' => ['nullable', 'numeric'],
            'tables.*.y' => ['nullable', 'numeric'],
            'tables.*.width' => ['nullable', 'numeric'],
            'tables.*.height' => ['nullable', 'numeric'],
            'tables.*.shape' => ['nullable', 'string', 'in:rect,circle,square'],
            'tables.*.zone' => ['nullable', 'string'],
            'tables.*.name' => ['nullable', 'string', 'max:255'],
            'tables.*.capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->assertOutletAccess($validated['outlet_id']);

        $outlet = \App\Models\Outlet::where('tenant_id', $tenantId)->findOrFail($validated['outlet_id']);

        $settings = $outlet->settings ?? [];
        $settings['floor_plan'] = $validated['floor_plan'];
        $outlet->settings = $settings;
        $outlet->save();

        if (! empty($validated['tables'])) {
            foreach ($validated['tables'] as $tData) {
                $table = Table::where('tenant_id', $tenantId)
                    ->where('outlet_id', $outlet->id)
                    ->find($tData['id']);

                if ($table) {
                    $meta = $table->metadata ?? [];
                    if (isset($tData['x'])) $meta['x'] = (float) $tData['x'];
                    if (isset($tData['y'])) $meta['y'] = (float) $tData['y'];
                    if (isset($tData['width'])) $meta['width'] = (float) $tData['width'];
                    if (isset($tData['height'])) $meta['height'] = (float) $tData['height'];
                    if (isset($tData['shape'])) $meta['shape'] = $tData['shape'];
                    if (isset($tData['zone'])) $meta['zone'] = $tData['zone'];

                    $table->metadata = $meta;
                    if (isset($tData['name'])) $table->name = trim($tData['name']);
                    if (isset($tData['capacity'])) $table->capacity = (int) $tData['capacity'];
                    $table->save();
                }
            }
        }

        return $this->getFloorPlan($request->merge(['outlet_id' => $outlet->id]));
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
            'metadata' => $table->metadata ?? [],
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
