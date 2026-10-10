<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Outlet;
use App\Models\Shift;
use App\Services\Shift\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShiftController extends Controller
{
    public function __construct(
        protected ShiftService $shiftService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * List shifts for an outlet.
     */
    public function index(Request $request, string $outletId): JsonResponse
    {
        if ($error = $this->assertOutletAccess($outletId)) {
            return $error;
        }

        $outlet = Outlet::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($outletId);

        $shifts = Shift::where('outlet_id', $outlet->id)
            ->with(['openedBy', 'closedBy'])
            ->orderBy('opened_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(data: $shifts);
    }

    /**
     * Open a new shift for the outlet.
     */
    public function open(Request $request, string $outletId): JsonResponse
    {
        if ($error = $this->assertOutletAccess($outletId)) {
            return $error;
        }

        $outlet = Outlet::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($outletId);

        $validated = $request->validate([
            'opening_cash_minor' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $shift = $this->shiftService->openShift(
            outlet: $outlet,
            openingCashMinor: $validated['opening_cash_minor'],
            user: $request->user(),
            notes: $validated['notes'] ?? null
        );

        return ApiResponse::success(
            data: $shift->load(['openedBy', 'movements.performedBy']),
            message: 'Shift opened successfully.',
            status: 201
        );
    }

    /**
     * Get the current active shift for an outlet.
     */
    public function current(string $outletId): JsonResponse
    {
        if ($error = $this->assertOutletAccess($outletId)) {
            return $error;
        }

        $outlet = Outlet::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($outletId);

        $shift = $this->shiftService->getCurrentShift($outlet);

        if (! $shift) {
            return ApiResponse::success(data: null, message: 'No active shift found for this outlet.');
        }

        $summary = $this->shiftService->getCashSummary($shift);

        return ApiResponse::success(data: [
            'shift' => $shift,
            'summary' => $summary,
        ]);
    }

    /**
     * Get authoritative cash summary and movements for a shift.
     */
    public function summary(string $shiftId): JsonResponse
    {
        $shift = Shift::where('tenant_id', $this->tenantContext->id())
            ->with(['outlet', 'openedBy', 'closedBy', 'movements.performedBy'])
            ->findOrFail($shiftId);

        if ($error = $this->assertShiftOutletAccess($shift)) {
            return $error;
        }

        $summary = $this->shiftService->getCashSummary($shift);

        return ApiResponse::success(data: [
            'shift' => $shift,
            'summary' => $summary,
        ]);
    }

    /**
     * Record cash in for a shift.
     */
    public function cashIn(Request $request, string $shiftId): JsonResponse
    {
        $shift = Shift::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($shiftId);

        if ($error = $this->assertShiftOutletAccess($shift)) {
            return $error;
        }

        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $movement = $this->shiftService->cashIn(
            shift: $shift,
            amountMinor: $validated['amount_minor'],
            reason: $validated['reason'],
            user: $request->user()
        );

        return ApiResponse::success(
            data: $movement->load('performedBy'),
            message: 'Cash in recorded successfully.',
            status: 201
        );
    }

    /**
     * Record cash out for a shift.
     */
    public function cashOut(Request $request, string $shiftId): JsonResponse
    {
        $shift = Shift::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($shiftId);

        if ($error = $this->assertShiftOutletAccess($shift)) {
            return $error;
        }

        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $movement = $this->shiftService->cashOut(
            shift: $shift,
            amountMinor: $validated['amount_minor'],
            reason: $validated['reason'],
            user: $request->user()
        );

        return ApiResponse::success(
            data: $movement->load('performedBy'),
            message: 'Cash out recorded successfully.',
            status: 201
        );
    }

    /**
     * Record cash adjustment for a shift (requires cash.adjust).
     */
    public function adjust(Request $request, string $shiftId): JsonResponse
    {
        $shift = Shift::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($shiftId);

        if ($error = $this->assertShiftOutletAccess($shift)) {
            return $error;
        }

        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'direction' => 'required|in:in,out',
            'reason' => 'required|string|max:500',
        ]);

        $movement = $this->shiftService->cashAdjust(
            shift: $shift,
            amountMinor: $validated['amount_minor'],
            direction: $validated['direction'],
            reason: $validated['reason'],
            user: $request->user()
        );

        return ApiResponse::success(
            data: $movement->load('performedBy'),
            message: 'Cash adjustment recorded successfully.',
            status: 201
        );
    }

    /**
     * Close a shift with actual cash count.
     */
    public function close(Request $request, string $shiftId): JsonResponse
    {
        $shift = Shift::where('tenant_id', $this->tenantContext->id())
            ->findOrFail($shiftId);

        if ($error = $this->assertShiftOutletAccess($shift)) {
            return $error;
        }

        $validated = $request->validate([
            'actual_closing_cash_minor' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $closedShift = $this->shiftService->closeShift(
            shift: $shift,
            actualClosingCashMinor: $validated['actual_closing_cash_minor'],
            user: $request->user(),
            notes: $validated['notes'] ?? null
        );

        $summary = $this->shiftService->getCashSummary($closedShift);

        return ApiResponse::success(
            data: [
                'shift' => $closedShift->load(['openedBy', 'closedBy', 'movements.performedBy']),
                'summary' => $summary,
            ],
            message: 'Shift closed successfully.'
        );
    }

    /**
     * Assert that active user has access to this outlet.
     */
    protected function assertOutletAccess(string $outletId): ?JsonResponse
    {
        if (! $this->tenantContext->hasOutletAccess($outletId)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }

    /**
     * Assert that active user has access to this shift's outlet.
     */
    protected function assertShiftOutletAccess(Shift $shift): ?JsonResponse
    {
        return $this->assertOutletAccess($shift->outlet_id);
    }
}
