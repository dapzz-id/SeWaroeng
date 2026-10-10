<?php

namespace App\Services\Finance\Export;

use App\Domain\Finance\Export\FinancialExportData;
use App\Domain\Finance\Export\FinancialReportNormalizer;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use InvalidArgumentException;

class FinancialExportService
{
    public function __construct(
        protected FinanceService $financeService,
        protected CsvFinancialExporter $csvExporter,
        protected XlsxFinancialExporter $xlsxExporter,
        protected PdfFinancialExporter $pdfExporter
    ) {}

    /**
     * Export Profit & Loss report.
     */
    public function exportProfitAndLoss(
        string $tenantId,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta',
        bool $compare = false,
        string $format = 'pdf'
    ): FinancialExportResult {
        $canonicalData = $this->financeService->getProfitAndLoss(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone,
            compare: $compare
        );

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $exportData = FinancialReportNormalizer::normalizeProfitAndLoss(
            data: $canonicalData,
            tenantName: $tenantName,
            outletName: $outletName,
            generatedAt: $generatedAt
        );

        $dateSlug = $startDate->copy()->setTimezone($timezone)->toDateString().'-'.$endDate->copy()->setTimezone($timezone)->toDateString();

        return $this->formatExport($exportData, 'profit-and-loss', $dateSlug, $format);
    }

    /**
     * Export Balance Sheet report.
     */
    public function exportBalanceSheet(
        string $tenantId,
        ?string $outletId,
        Carbon $asOfDate,
        string $timezone = 'Asia/Jakarta',
        string $format = 'pdf'
    ): FinancialExportResult {
        $canonicalData = $this->financeService->getBalanceSheet(
            tenantId: $tenantId,
            outletId: $outletId,
            asOfDate: $asOfDate,
            timezone: $timezone
        );

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $exportData = FinancialReportNormalizer::normalizeBalanceSheet(
            data: $canonicalData,
            tenantName: $tenantName,
            outletName: $outletName,
            generatedAt: $generatedAt
        );

        $dateSlug = $asOfDate->copy()->setTimezone($timezone)->toDateString();

        return $this->formatExport($exportData, 'balance-sheet', $dateSlug, $format);
    }

    /**
     * Export Trial Balance report.
     */
    public function exportTrialBalance(
        string $tenantId,
        ?string $outletId,
        Carbon $asOfDate,
        string $timezone = 'Asia/Jakarta',
        string $format = 'pdf'
    ): FinancialExportResult {
        $canonicalData = $this->financeService->getTrialBalance(
            tenantId: $tenantId,
            outletId: $outletId,
            asOfDate: $asOfDate,
            timezone: $timezone
        );

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $exportData = FinancialReportNormalizer::normalizeTrialBalance(
            data: $canonicalData,
            tenantName: $tenantName,
            outletName: $outletName,
            generatedAt: $generatedAt
        );

        $dateSlug = $asOfDate->copy()->setTimezone($timezone)->toDateString();

        return $this->formatExport($exportData, 'trial-balance', $dateSlug, $format);
    }

    /**
     * Export Account Ledger report without API pagination limits.
     */
    public function exportAccountLedger(
        string $tenantId,
        string $accountCode,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta',
        string $format = 'pdf'
    ): FinancialExportResult {
        // perPage: 0 signals to return all ledger movements without pagination truncation
        $canonicalData = $this->financeService->getAccountLedger(
            tenantId: $tenantId,
            accountCode: $accountCode,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone,
            page: 1,
            perPage: 0
        );

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $exportData = FinancialReportNormalizer::normalizeAccountLedger(
            data: $canonicalData,
            tenantName: $tenantName,
            outletName: $outletName,
            generatedAt: $generatedAt
        );

        $dateSlug = $accountCode.'-'.$startDate->copy()->setTimezone($timezone)->toDateString().'-'.$endDate->copy()->setTimezone($timezone)->toDateString();

        return $this->formatExport($exportData, 'account-ledger', $dateSlug, $format);
    }

    /**
     * Export Cash Flow report.
     */
    public function exportCashFlow(
        string $tenantId,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta',
        string $format = 'pdf'
    ): FinancialExportResult {
        $canonicalData = $this->financeService->getCashFlow(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone
        );

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $exportData = FinancialReportNormalizer::normalizeCashFlow(
            data: $canonicalData,
            tenantName: $tenantName,
            outletName: $outletName,
            generatedAt: $generatedAt
        );

        $dateSlug = $startDate->copy()->setTimezone($timezone)->toDateString().'-'.$endDate->copy()->setTimezone($timezone)->toDateString();

        return $this->formatExport($exportData, 'cash-flow', $dateSlug, $format);
    }

    /**
     * Export Payment Clearing & Reconciliation summary report.
     */
    public function exportClearingSummary(
        string $tenantId,
        ?string $outletId,
        Carbon $startDate,
        Carbon $endDate,
        string $timezone = 'Asia/Jakarta',
        string $format = 'pdf'
    ): FinancialExportResult {
        $canonicalData = $this->financeService->getClearingReport(
            tenantId: $tenantId,
            outletId: $outletId,
            startDate: $startDate,
            endDate: $endDate,
            timezone: $timezone
        );

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $exportData = FinancialReportNormalizer::normalizeClearingSummary(
            data: $canonicalData,
            tenantName: $tenantName,
            outletName: $outletName,
            generatedAt: $generatedAt
        );

        $dateSlug = $startDate->copy()->setTimezone($timezone)->toDateString().'-'.$endDate->copy()->setTimezone($timezone)->toDateString();

        return $this->formatExport($exportData, 'clearing-summary', $dateSlug, $format);
    }

    /**
     * Export Journal Entries.
     */
    public function exportJournalEntries(
        string $tenantId,
        ?string $outletId,
        ?string $startDate,
        ?string $endDate,
        ?string $status,
        ?string $sourceType,
        string $timezone = 'Asia/Jakarta',
        string $format = 'pdf'
    ): FinancialExportResult {
        $query = JournalEntry::with(['lines.account', 'outlet'])
            ->where('tenant_id', $tenantId);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($startDate) {
            $query->whereDate('entry_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('entry_date', '<=', $endDate);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($sourceType) {
            $query->where('source_type', $sourceType);
        }

        $query->orderBy('entry_date', 'asc')->orderBy('created_at', 'asc');

        $entries = $query->get();

        [$tenantName, $outletName, $generatedAt] = $this->resolveMetadata($tenantId, $outletId, $timezone);

        $periodText = ($startDate && $endDate) ? "{$startDate} s/d {$endDate}" : ($startDate ? "Mulai {$startDate}" : ($endDate ? "Sampai {$endDate}" : 'Seluruh Riwayat'));

        $exportData = FinancialReportNormalizer::normalizeJournalEntries(
            entries: $entries,
            tenantName: $tenantName,
            outletName: $outletName,
            periodText: $periodText,
            generatedAt: $generatedAt
        );

        $dateSlug = ($startDate && $endDate) ? "{$startDate}-{$endDate}" : 'all-time';

        return $this->formatExport($exportData, 'journal-entries', $dateSlug, $format);
    }

    /**
     * Format normalized data to the requested output format.
     */
    protected function formatExport(
        FinancialExportData $exportData,
        string $reportSlug,
        string $dateSlug,
        string $format
    ): FinancialExportResult {
        $format = strtolower(trim($format));

        // Sanitize filename components
        $safeReportSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $reportSlug);
        $safeDateSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $dateSlug);

        return match ($format) {
            'csv' => new FinancialExportResult(
                content: $this->csvExporter->exportToString($exportData),
                filename: "sewaroeng-{$safeReportSlug}-{$safeDateSlug}.csv",
                contentType: 'text/csv; charset=UTF-8',
                format: 'csv'
            ),
            'xlsx' => new FinancialExportResult(
                content: $this->xlsxExporter->exportToString($exportData),
                filename: "sewaroeng-{$safeReportSlug}-{$safeDateSlug}.xlsx",
                contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                format: 'xlsx'
            ),
            'pdf' => new FinancialExportResult(
                content: $this->pdfExporter->exportToString($exportData),
                filename: "sewaroeng-{$safeReportSlug}-{$safeDateSlug}.pdf",
                contentType: 'application/pdf',
                format: 'pdf'
            ),
            default => throw new InvalidArgumentException("Format export '{$format}' tidak didukung. Gunakan csv, xlsx, atau pdf."),
        };
    }

    /**
     * Resolve business metadata for report headers.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function resolveMetadata(string $tenantId, ?string $outletId, string $timezone): array
    {
        $tenant = Tenant::find($tenantId);
        $tenantName = $tenant?->name ?: 'SeWaroeng Business';

        if ($outletId) {
            $outlet = Outlet::find($outletId);
            $outletName = $outlet?->name ?: 'Outlet Tertentu';
        } else {
            $outletName = 'Semua Outlet (Konsolidasi)';
        }

        $generatedAt = now($timezone)->format('Y-m-d H:i:s').' ('.$timezone.')';

        return [$tenantName, $outletName, $generatedAt];
    }
}
