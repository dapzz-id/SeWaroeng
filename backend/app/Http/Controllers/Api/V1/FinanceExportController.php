<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Services\Finance\Export\FinancialExportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FinanceExportController extends Controller
{
    public function __construct(
        protected FinancialExportService $exportService
    ) {}

    /**
     * Export Profit & Loss report.
     */
    public function profitAndLoss(Request $request): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);
        $compare = $request->boolean('compare');

        $result = $this->exportService->exportProfitAndLoss(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone,
            compare: $compare,
            format: $format
        );

        return $result->toResponse();
    }

    /**
     * Export Cash Flow report.
     */
    public function cashFlow(Request $request): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);

        $result = $this->exportService->exportCashFlow(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone,
            format: $format
        );

        return $result->toResponse();
    }

    /**
     * Export Balance Sheet report.
     */
    public function balanceSheet(Request $request): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        $dateStr = $request->query('as_of_date') ?: $request->query('date');
        $asOfDate = $dateStr
            ? Carbon::parse($dateStr, $timezone)->endOfDay()
            : now($timezone)->endOfDay();

        $result = $this->exportService->exportBalanceSheet(
            tenantId: $tenantId,
            outletId: $outletId,
            asOfDate: $asOfDate,
            timezone: $timezone,
            format: $format
        );

        return $result->toResponse();
    }

    /**
     * Export Trial Balance report.
     */
    public function trialBalance(Request $request): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        $dateStr = $request->query('as_of_date') ?: $request->query('date');
        $asOfDate = $dateStr
            ? Carbon::parse($dateStr, $timezone)->endOfDay()
            : now($timezone)->endOfDay();

        $result = $this->exportService->exportTrialBalance(
            tenantId: $tenantId,
            outletId: $outletId,
            asOfDate: $asOfDate,
            timezone: $timezone,
            format: $format
        );

        return $result->toResponse();
    }

    /**
     * Export Account Ledger report.
     */
    public function accountLedger(Request $request): Response
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
                message: 'account_code is required for ledger export.',
                code: 'VALIDATION_ERROR',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);

        try {
            $result = $this->exportService->exportAccountLedger(
                tenantId: $tenantId,
                accountCode: $accountCode,
                outletId: $outletId,
                startDate: $startDate,
                endDate: $endDate,
                timezone: $timezone,
                format: $format
            );
        } catch (\Throwable $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                code: 'ACCOUNT_NOT_FOUND',
                status: Response::HTTP_NOT_FOUND
            );
        }

        return $result->toResponse();
    }

    /**
     * Export Payment Clearing & Reconciliation summary report.
     */
    public function clearingSummary(Request $request): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        [$startDate, $endDate] = $this->resolveDateRange($request, $timezone);

        $result = $this->exportService->exportClearingSummary(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone,
            format: $format
        );

        return $result->toResponse();
    }

    /**
     * Export Journal Entries.
     */
    public function journalEntries(Request $request): Response
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $tenant = Tenant::findOrFail($tenantId);
        $timezone = $tenant->timezone ?: 'Asia/Jakarta';

        [$outletId, $errorResponse] = $this->resolveOutletScope($request, $tenantContext);
        if ($errorResponse) {
            return $errorResponse;
        }

        $format = $this->resolveFormat($request);
        if ($format instanceof JsonResponse) {
            return $format;
        }

        $result = $this->exportService->exportJournalEntries(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $request->query('start_date'),
            endDate: $request->query('end_date'),
            status: $request->query('status'),
            sourceType: $request->query('source_type'),
            timezone: $timezone,
            format: $format
        );

        return $result->toResponse();
    }

    /**
     * Generic report export router matching /finance/reports/{report}/export.
     */
    public function export(Request $request, string $report): Response
    {
        return match ($report) {
            'profit-and-loss', 'profit-loss' => $this->profitAndLoss($request),
            'cash-flow' => $this->cashFlow($request),
            'balance-sheet' => $this->balanceSheet($request),
            'trial-balance' => $this->trialBalance($request),
            'account-ledger' => $this->accountLedger($request),
            'clearing-summary', 'clearing' => $this->clearingSummary($request),
            'journal-entries' => $this->journalEntries($request),
            default => ApiResponse::error(
                message: "Laporan '{$report}' tidak dikenali.",
                code: 'INVALID_REPORT_TYPE',
                status: Response::HTTP_NOT_FOUND
            ),
        };
    }

    /**
     * Resolve and validate export format.
     */
    protected function resolveFormat(Request $request): string|JsonResponse
    {
        $format = strtolower((string) $request->query('format', 'pdf'));

        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            return ApiResponse::error(
                message: "Format export '{$format}' tidak valid. Pilihan yang tersedia: csv, xlsx, pdf.",
                code: 'INVALID_EXPORT_FORMAT',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $format;
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
