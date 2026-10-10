<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
    ) {}

    /**
     * Retrieve aggregated, role-aware dashboard metrics for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        $tenantId = $context->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';
        $user = $request->user();

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'in:today,yesterday,7_days,last_7_days,30_days,last_30_days,custom'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'outlet_id' => ['nullable', 'uuid'],
        ]);

        $period = $validated['period'] ?? 'today';
        $outletId = $validated['outlet_id'] ?? null;
        $customStart = $validated['start_date'] ?? null;
        $customEnd = $validated['end_date'] ?? null;

        if ($outletId && ! $context->hasOutletAccess($outletId)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        // Determine user permissions and authorized outlet scope
        $isOwner = $context->isOwner();
        $canViewFinance = $isOwner || $context->hasPermission('dashboard.view.finance');

        $authorizedOutletIds = null;
        if (! $isOwner) {
            $membership = $context->membership();
            $authorizedOutletIds = $membership?->assigned_outlet_ids ?? [];
        }

        $data = $this->dashboardService->getDashboardData(
            tenantId: $tenantId,
            period: $period,
            outletId: $outletId,
            customStartDate: $customStart,
            customEndDate: $customEnd,
            authorizedOutletIds: $authorizedOutletIds,
            canViewFinance: $canViewFinance,
            timezone: $timezone
        );

        return ApiResponse::success(
            data: $data,
            message: 'Dashboard data retrieved successfully.'
        );
    }
}
