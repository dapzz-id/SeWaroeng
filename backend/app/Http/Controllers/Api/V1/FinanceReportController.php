<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FinanceReportController extends Controller
{
    public function __construct(
        protected FinanceService $financeService,
    ) {}

    /**
     * Get Profit & Loss report.
     */
    public function profitAndLoss(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);
        $compare = $request->boolean('compare');

        $report = $this->financeService->getProfitAndLoss(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone,
            compare: $compare
        );

        return ApiResponse::success(
            data: $report,
            message: 'Profit & Loss report generated successfully.'
        );
    }

    /**
     * Get Cash Flow report.
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);

        $report = $this->financeService->getCashFlow(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone
        );

        return ApiResponse::success(
            data: $report,
            message: 'Cash Flow report generated successfully.'
        );
    }

    /**
     * Get Balance Sheet report.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $dateStr = $request->query('as_of_date') ?: $request->query('date');
        $asOfDate = $dateStr
            ? Carbon::parse($dateStr, $timezone)->endOfDay()
            : now($timezone)->endOfDay();

        $report = $this->financeService->getBalanceSheet(
            tenantId: $tenantId,
            outletId: $outletId,
            asOfDate: $asOfDate,
            timezone: $timezone
        );

        return ApiResponse::success(
            data: $report,
            message: 'Balance Sheet report generated successfully.'
        );
    }

    /**
     * Get Trial Balance report.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $dateStr = $request->query('as_of_date') ?: $request->query('date');
        $asOfDate = $dateStr
            ? Carbon::parse($dateStr, $timezone)->endOfDay()
            : now($timezone)->endOfDay();

        $report = $this->financeService->getTrialBalance(
            tenantId: $tenantId,
            outletId: $outletId,
            asOfDate: $asOfDate,
            timezone: $timezone
        );

        return ApiResponse::success(
            data: $report,
            message: 'Trial Balance report generated successfully.'
        );
    }

    /**
     * Get Account Ledger report.
     */
    public function accountLedger(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $accountCode = $request->query('account_code');
        if (empty($accountCode)) {
            return ApiResponse::error(
                message: 'account_code is required.',
                code: 'VALIDATION_ERROR',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min((int) $request->query('per_page', 50), 100);

        try {
            $report = $this->financeService->getAccountLedger(
                tenantId: $tenantId,
                accountCode: $accountCode,
                outletId: $outletId,
                startDate: $startDate,
                endDate: $endDate,
                timezone: $timezone,
                page: $page,
                perPage: $perPage
            );
        } catch (\Throwable $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                code: 'ACCOUNT_NOT_FOUND',
                status: Response::HTTP_NOT_FOUND
            );
        }

        return ApiResponse::success(
            data: $report,
            message: 'Account Ledger generated successfully.'
        );
    }

    /**
     * Get Payment Clearing & Reconciliation summary report.
     */
    public function clearingSummary(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);

        $report = $this->financeService->getClearingReport(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone
        );

        return ApiResponse::success(
            data: $report,
            message: 'Payment clearing summary generated successfully.'
        );
    }

    /**
     * List posted or all journal entries with lines.
     */
    public function journalEntries(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $query = JournalEntry::with(['lines.account', 'outlet', 'poster'])
            ->where('tenant_id', $tenantId);

        if ($request->filled('outlet_id')) {
            $outletId = $request->query('outlet_id');
            $outletExists = Outlet::where('tenant_id', $tenantContext->id())->where('id', $outletId)->exists();
            if (! $outletExists || ! $tenantContext->hasOutletAccess($outletId)) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
            $query->where('outlet_id', $outletId);
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            if (empty($assigned)) {
                return ApiResponse::error(
                    message: 'User is not authorized to access tenant-wide journal entries.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
            $query->whereIn('outlet_id', $assigned);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('source_type')) {
            $query->where('source_type', $request->query('source_type'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('entry_date', '>=', $request->query('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('entry_date', '<=', $request->query('end_date'));
        }

        $query->orderBy('entry_date', 'desc')->orderBy('created_at', 'desc');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (JournalEntry $je) => [
                'id' => $je->id,
                'entry_number' => $je->entry_number,
                'entry_date' => $je->entry_date instanceof Carbon ? $je->entry_date->toDateString() : (string) $je->entry_date,
                'status' => $je->status,
                'source_type' => $je->source_type,
                'source_id' => $je->source_id,
                'description' => $je->description,
                'outlet_id' => $je->outlet_id,
                'outlet' => $je->outlet ? [
                    'id' => $je->outlet->id,
                    'name' => $je->outlet->name,
                ] : null,
                'total_debit_minor' => (int) $je->lines->sum('debit_minor'),
                'total_credit_minor' => (int) $je->lines->sum('credit_minor'),
                'is_balanced' => $je->isBalanced(),
                'lines' => $je->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'account_id' => $l->account_id,
                    'account_code' => $l->account?->code,
                    'account_name' => $l->account?->name,
                    'debit_minor' => (int) $l->debit_minor,
                    'credit_minor' => (int) $l->credit_minor,
                    'description' => $l->description,
                ])->all(),
                'posted_at' => $je->posted_at?->toIso8601String(),
                'reversed_at' => $je->reversed_at?->toIso8601String(),
                'created_at' => $je->created_at?->toIso8601String(),
            ])->all()
        );
    }

    /**
     * List Chart of Accounts.
     */
    public function accounts(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $this->financeService->ensureDefaultAccounts($tenantId);

        $accounts = Account::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        return ApiResponse::success(
            data: $accounts->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'is_system' => $a->is_system,
                'description' => $a->description,
            ])->all()
        );
    }

    /**
     * Resolve date range respecting tenant timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Request $request, string $timezone): array
    {
        $now = now($timezone);

        $period = $request->query('period');

        if ($period === 'today') {
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        }

        if ($period === 'this_week') {
            return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
        }

        if ($period === 'this_month') {
            return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        }

        // Custom or default to this month
        $startDateStr = $request->query('start_date');
        $endDateStr = $request->query('end_date');

        if ($startDateStr && $endDateStr) {
            $start = Carbon::parse($startDateStr, $timezone)->startOfDay();
            $end = Carbon::parse($endDateStr, $timezone)->endOfDay();

            return [$start, $end];
        }

        return [$now->copy()->startOfMonth(), $now->copy()->endOfDay()];
    }

    /**
     * Resolve outlet scope respecting user role and membership.
     *
     * @return array{0: ?string, 1: ?JsonResponse}
     */
    protected function resolveOutletScope(Request $request, TenantContext $tenantContext): array
    {
        $outletId = $request->query('outlet_id');
        if (! empty($outletId)) {
            $outletExists = Outlet::where('tenant_id', $tenantContext->id())->where('id', $outletId)->exists();
            if (! $outletExists || ! $tenantContext->hasOutletAccess($outletId)) {
                return [null, ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                )];
            }

            return [$outletId, null];
        }

        if (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            if (empty($assigned)) {
                return [null, ApiResponse::error(
                    message: 'User is not authorized to access tenant-wide financial reports.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                )];
            }

            return [$assigned[0], null];
        }

        return [null, null];
    }
}
