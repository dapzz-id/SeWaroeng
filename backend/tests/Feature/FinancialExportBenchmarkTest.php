<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Finance\Export\FinancialExportService;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class FinancialExportBenchmarkTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected Outlet $outlet;

    protected FinanceService $financeService;

    protected FinancialExportService $exportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Benchmark Waroeng');
        $this->owner = $this->tenantData['owner'];
        $this->outlet = $this->tenantData['outlet'];

        $this->financeService = app(FinanceService::class);
        $this->exportService = app(FinancialExportService::class);
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_benchmark_1000_journal_entries_and_export_scalability(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $cashAccId = $accounts[Account::CODE_CASH]->id;
            $revAccId = $accounts[Account::CODE_SALES_REVENUE]->id;

            // Bulk seed 1,000 journal entries with 2,000 lines
            $entryBatch = [];
            $lineBatch = [];
            $now = Carbon::parse('2026-10-01 10:00:00');

            for ($i = 1; $i <= 1000; $i++) {
                $entryId = (string) Str::uuid();
                $entryNumber = sprintf('JE-BENCH-%05d', $i);
                $entryDate = $now->copy()->addMinutes($i * 5)->toDateString();

                $entryBatch[] = [
                    'id' => $entryId,
                    'tenant_id' => $tenant->id,
                    'outlet_id' => $this->outlet->id,
                    'entry_number' => $entryNumber,
                    'entry_date' => $entryDate,
                    'status' => JournalEntry::STATUS_POSTED,
                    'source_type' => JournalEntry::SOURCE_SALE,
                    'source_id' => (string) Str::uuid(),
                    'description' => "Penjualan Benchmark #{$i}",
                    'posted_at' => now(),
                    'posted_by' => $this->owner->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $lineBatch[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'journal_entry_id' => $entryId,
                    'account_id' => $cashAccId,
                    'debit_minor' => 50000,
                    'credit_minor' => 0,
                    'description' => "Kas Penerimaan #{$i}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $lineBatch[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'journal_entry_id' => $entryId,
                    'account_id' => $revAccId,
                    'debit_minor' => 0,
                    'credit_minor' => 50000,
                    'description' => "Pendapatan #{$i}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($entryBatch, 250) as $chunk) {
                JournalEntry::insert($chunk);
            }
            foreach (array_chunk($lineBatch, 500) as $chunk) {
                JournalLine::insert($chunk);
            }
        });

        $this->actingAs($this->owner);

        // 1. Benchmark CSV Export for 1,000 Journal Entries
        $memBeforeCsv = memory_get_peak_usage(true);
        $startCsv = microtime(true);

        $csvResponse = $this->get('/api/v1/finance/reports/journal-entries/export?format=csv&start_date=2026-10-01&end_date=2026-10-08');
        $csvResponse->assertStatus(200);

        $durationCsv = microtime(true) - $startCsv;
        $memAfterCsv = memory_get_peak_usage(true);
        $csvContent = (string) $csvResponse->getContent();
        $csvSize = strlen($csvContent);

        $this->assertGreaterThan(50000, $csvSize, 'CSV export should contain substantial bytes');
        $this->assertStringContainsString('JE-BENCH-00001', $csvContent);
        $this->assertStringContainsString('JE-BENCH-01000', $csvContent);

        // 2. Benchmark XLSX Export for 1,000 Journal Entries
        $memBeforeXlsx = memory_get_peak_usage(true);
        $startXlsx = microtime(true);

        $xlsxResponse = $this->get('/api/v1/finance/reports/journal-entries/export?format=xlsx&start_date=2026-10-01&end_date=2026-10-08');
        $xlsxResponse->assertStatus(200);

        $durationXlsx = microtime(true) - $startXlsx;
        $memAfterXlsx = memory_get_peak_usage(true);
        $xlsxContent = (string) $xlsxResponse->getContent();
        $xlsxSize = strlen($xlsxContent);

        $this->assertGreaterThan(10000, $xlsxSize, 'XLSX export should produce valid zip bytes');
        $this->assertSame('PK', substr($xlsxContent, 0, 2), 'XLSX must be a valid zip archive');

        // 3. Benchmark Account Ledger Unpaginated Export
        $startLedger = microtime(true);
        $ledgerResponse = $this->get('/api/v1/finance/reports/account-ledger/export?account_code='.Account::CODE_CASH.'&format=csv&start_date=2026-10-01&end_date=2026-10-08');
        $ledgerResponse->assertStatus(200);
        $durationLedger = microtime(true) - $startLedger;
        $ledgerContent = (string) $ledgerResponse->getContent();

        // Verify ledger contains full 1,000 rows
        $this->assertStringContainsString('JE-BENCH-00001', $ledgerContent);
        $this->assertStringContainsString('JE-BENCH-01000', $ledgerContent);

        // Output benchmark summary
        fwrite(STDERR, sprintf(
            "\n[BENCHMARK] 1,000 Journal Entries / 2,000 Lines:\n"
            ."- CSV Generation: %.3fs, Size: %d KB, Peak Memory Delta: %.2f MB\n"
            ."- XLSX Generation: %.3fs, Size: %d KB, Peak Memory Delta: %.2f MB\n"
            ."- Unpaginated Ledger CSV: %.3fs, Size: %d KB\n",
            $durationCsv,
            round($csvSize / 1024),
            ($memAfterCsv - $memBeforeCsv) / 1048576,
            $durationXlsx,
            round($xlsxSize / 1024),
            ($memAfterXlsx - $memBeforeXlsx) / 1048576,
            $durationLedger,
            round(strlen($ledgerContent) / 1024)
        ));

        $this->assertLessThan(10.0, $durationCsv, 'CSV export of 1000 items should complete in < 10s');
        $this->assertLessThan(15.0, $durationXlsx, 'XLSX export of 1000 items should complete in < 15s');
    }
}
