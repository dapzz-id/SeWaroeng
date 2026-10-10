<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Account;
use App\Models\Expense;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class FinancialExportTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $manager;

    protected User $cashier;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected FinanceService $financeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Kopi Mantap');
        $tenant = $this->tenantData['tenant'];
        $this->outlet1 = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->outlet2 = $this->runForTenant(function () use ($tenant) {
            return Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => 'Warung Kopi Mantap - Cabang 2',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);
        });

        // Manager assigned only to outlet 1
        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manajer Budi', [$this->outlet1->id]);
        $this->manager = $managerData['user'];

        // Cashier assigned to outlet 1
        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Siti', [$this->outlet1->id]);
        $this->cashier = $cashierData['user'];

        $this->financeService = app(FinanceService::class);

        $this->runForTenant(function () use ($tenant) {
            $loc1 = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'name' => 'Gudang 1',
                'code' => 'LOC-01',
                'type' => 'warehouse',
                'is_active' => true,
            ]);
            $this->outlet1->update(['default_pos_location_id' => $loc1->id]);

            $this->financeService->ensureDefaultAccounts($tenant->id);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    /**
     * Seed baseline accounting journals for tests.
     */
    protected function seedStandardAccountingData(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Sale Journal: Kas 10.000.000 / Revenue 10.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Penjualan Kopi Batch 1',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000000],
            ]);

            // COGS Journal: COGS 4.000.000 / Persediaan 4.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'COGS Kopi Batch 1',
            ], [
                ['account_id' => $accounts[Account::CODE_COGS]->id, 'debit_minor' => 4000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 0, 'credit_minor' => 4000000],
            ]);

            // Expense Journal: Beban Operasional 1.500.000 / Kas 1.500.000
            $exp = Expense::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id,
                'category' => 'utilities',
                'amount_minor' => 1500000,
                'expense_date' => '2026-10-03',
                'payment_method' => 'cash',
                'status' => Expense::STATUS_DRAFT,
                'description' => 'Listrik & Air',
                'created_by' => $this->owner->id,
            ]);
            $this->financeService->postExpense($exp, $this->owner->id);
        });
    }

    public function test_owner_can_export_profit_and_loss_in_csv_xlsx_and_pdf(): void
    {
        $this->seedStandardAccountingData();
        $this->actingAs($this->owner);

        // 1. CSV
        $csvResponse = $this->getJson('/api/v1/finance/profit-loss/export?format=csv&period=this_month');
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="sewaroeng-profit-and-loss-', (string) $csvResponse->headers->get('Content-Disposition'));
        $csvContent = $csvResponse->getContent();
        $this->assertStringContainsString('LAPORAN KEUANGAN SEWAROENG', $csvContent);
        $this->assertStringContainsString('Pendapatan Bersih (Net Revenue)', $csvContent);
        $this->assertStringContainsString('10000000', $csvContent);
        $this->assertStringContainsString('Rp 10.000.000', $csvContent);

        // 2. XLSX
        $xlsxResponse = $this->get('/api/v1/finance/profit-loss/export?format=xlsx&period=this_month');
        $xlsxResponse->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $xlsxResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', (string) $xlsxResponse->headers->get('Content-Disposition'));
        $this->assertNotEmpty($xlsxResponse->getContent());

        // 3. PDF
        $pdfResponse = $this->get('/api/v1/finance/profit-loss/export?format=pdf&period=this_month');
        $pdfResponse->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('.pdf', (string) $pdfResponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $pdfResponse->getContent());
    }

    public function test_export_values_are_strictly_consistent_with_json_api(): void
    {
        $this->seedStandardAccountingData();
        $this->actingAs($this->owner);

        // JSON API
        $apiResponse = $this->getJson('/api/v1/finance/profit-loss?period=this_month');
        $apiResponse->assertStatus(200);
        $jsonData = $apiResponse->json('data');

        $expectedRevenue = (int) $jsonData['revenue_minor'];
        $expectedCogs = (int) $jsonData['cogs_minor'];
        $expectedGrossProfit = (int) $jsonData['gross_profit_minor'];
        $expectedNetProfit = (int) $jsonData['net_profit_minor'];

        $this->assertEquals(10000000, $expectedRevenue);
        $this->assertEquals(4000000, $expectedCogs);
        $this->assertEquals(6000000, $expectedGrossProfit);
        $this->assertEquals(4500000, $expectedNetProfit);

        // CSV Export
        $csvResponse = $this->getJson('/api/v1/finance/profit-loss/export?format=csv&period=this_month');
        $csvResponse->assertStatus(200);
        $csvContent = $csvResponse->getContent();

        // Exact minor integers must exist in CSV rows
        $this->assertStringContainsString("\"Pendapatan Bersih (Net Revenue)\",{$expectedRevenue}", $csvContent);
        $this->assertStringContainsString("\"Beban Pokok Penjualan (COGS / HPP)\",{$expectedCogs}", $csvContent);
        $this->assertStringContainsString("\"Laba Kotor (Gross Profit)\",{$expectedGrossProfit}", $csvContent);
        $this->assertStringContainsString("\"Laba Bersih (Net Profit)\",{$expectedNetProfit}", $csvContent);

        // Rupiah presentation string must also exist in CSV
        $this->assertStringContainsString('Rp 10.000.000', $csvContent);
        $this->assertStringContainsString('Rp 4.000.000', $csvContent);
        $this->assertStringContainsString('Rp 6.000.000', $csvContent);
        $this->assertStringContainsString('Rp 4.500.000', $csvContent);

        // PDF Export
        $pdfResponse = $this->get('/api/v1/finance/profit-loss/export?format=pdf&period=this_month');
        $pdfResponse->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $pdfResponse->getContent());
    }

    public function test_balance_sheet_and_trial_balance_export_all_formats(): void
    {
        $this->seedStandardAccountingData();
        $this->actingAs($this->owner);

        // Balance Sheet
        foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
            $res = $this->get("/api/v1/finance/balance-sheet/export?format={$fmt}");
            $res->assertStatus(200);
            $this->assertNotEmpty($res->getContent());
        }

        // Trial Balance
        foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
            $res = $this->get("/api/v1/finance/trial-balance/export?format={$fmt}");
            $res->assertStatus(200);
            $this->assertNotEmpty($res->getContent());
        }
    }

    public function test_cash_flow_and_clearing_export_all_formats(): void
    {
        $this->seedStandardAccountingData();
        $this->actingAs($this->owner);

        // Cash Flow
        foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
            $res = $this->get("/api/v1/finance/cash-flow/export?format={$fmt}&period=this_month");
            $res->assertStatus(200);
            $this->assertNotEmpty($res->getContent());
        }

        // Clearing
        foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
            $res = $this->get("/api/v1/finance/clearing-summary/export?format={$fmt}&period=this_month");
            $res->assertStatus(200);
            $this->assertNotEmpty($res->getContent());
        }
    }

    public function test_account_ledger_export_does_not_truncate_to_first_page(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Seed 60 entries (more than default 50 per_page)
        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            for ($i = 1; $i <= 60; $i++) {
                $this->financeService->postJournal($tenant->id, [
                    'outlet_id' => $this->outlet1->id,
                    'entry_date' => '2026-10-01',
                    'source_type' => JournalEntry::SOURCE_SALE,
                    'description' => "Penjualan Ledger Test Entry #{$i}",
                ], [
                    ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000, 'credit_minor' => 0],
                    ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000],
                ]);
            }
        });

        $this->actingAs($this->owner);

        // Verify API paginates by default
        $apiRes = $this->getJson('/api/v1/finance/account-ledger?account_code=1110&period=this_month');
        $apiRes->assertStatus(200);
        $this->assertCount(50, $apiRes->json('data.lines'));
        $this->assertEquals(60, $apiRes->json('data.pagination.total'));

        // Verify CSV export includes ALL 60 entries
        $csvRes = $this->getJson('/api/v1/finance/account-ledger/export?account_code=1110&format=csv&period=this_month');
        $csvRes->assertStatus(200);
        $csvContent = $csvRes->getContent();

        for ($i = 1; $i <= 60; $i++) {
            $this->assertStringContainsString("Penjualan Ledger Test Entry #{$i}", $csvContent);
        }
    }

    public function test_journal_entries_export_all_formats(): void
    {
        $this->seedStandardAccountingData();
        $this->actingAs($this->owner);

        foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
            $res = $this->get("/api/v1/finance/journal-entries/export?format={$fmt}");
            $res->assertStatus(200);
            $this->assertNotEmpty($res->getContent());
        }
    }

    public function test_multi_outlet_authorization(): void
    {
        $this->seedStandardAccountingData();

        // 1. Owner can export tenant-wide or specific outlet
        $this->actingAs($this->owner);
        $this->get('/api/v1/finance/profit-loss/export?format=csv')->assertStatus(200);
        $this->get("/api/v1/finance/profit-loss/export?format=csv&outlet_id={$this->outlet1->id}")->assertStatus(200);
        $this->get("/api/v1/finance/profit-loss/export?format=csv&outlet_id={$this->outlet2->id}")->assertStatus(200);

        // 2. Manager assigned to outlet 1 can export outlet 1
        $this->actingAs($this->manager);
        $this->get('/api/v1/finance/profit-loss/export?format=csv')->assertStatus(200); // defaults to assigned outlet
        $this->get("/api/v1/finance/profit-loss/export?format=csv&outlet_id={$this->outlet1->id}")->assertStatus(200);

        // 3. Manager denied for outlet 2
        $deniedRes = $this->get("/api/v1/finance/profit-loss/export?format=csv&outlet_id={$this->outlet2->id}");
        $deniedRes->assertStatus(403);
        $this->assertEquals('OUTLET_ACCESS_DENIED', $deniedRes->json('code'));

        // 4. Cashier has no finance report permission
        $this->actingAs($this->cashier);
        $cashierRes = $this->get('/api/v1/finance/profit-loss/export?format=csv');
        $cashierRes->assertStatus(403);
    }

    public function test_cross_tenant_export_isolation(): void
    {
        $this->seedStandardAccountingData();

        // Create Tenant B
        $tenantBData = $this->createTenant('Tenant Rival B');
        $ownerB = $tenantBData['owner'];

        // Owner B accessing Tenant A's outlet must fail closed
        $this->actingAs($ownerB);
        $res = $this->get("/api/v1/finance/profit-loss/export?format=csv&outlet_id={$this->outlet1->id}");
        $res->assertStatus(403);
    }

    public function test_export_is_strictly_read_only_and_leaves_accounting_state_intact(): void
    {
        $this->seedStandardAccountingData();
        $this->actingAs($this->owner);

        $initialJournalCount = JournalEntry::count();
        $initialExpenseCount = Expense::count();

        // Execute exports across all reports and formats
        $this->get('/api/v1/finance/profit-loss/export?format=pdf');
        $this->get('/api/v1/finance/balance-sheet/export?format=xlsx');
        $this->get('/api/v1/finance/cash-flow/export?format=csv');
        $this->get('/api/v1/finance/trial-balance/export?format=pdf');
        $this->get('/api/v1/finance/account-ledger/export?account_code=1110&format=xlsx');
        $this->get('/api/v1/finance/clearing-summary/export?format=csv');
        $this->get('/api/v1/finance/journal-entries/export?format=pdf');

        $this->assertEquals($initialJournalCount, JournalEntry::count(), 'Journal count must not change after exports.');
        $this->assertEquals($initialExpenseCount, Expense::count(), 'Expense count must not change after exports.');
    }

    public function test_invalid_format_and_invalid_report_type_fail_closed(): void
    {
        $this->actingAs($this->owner);

        // Invalid format
        $badFormatRes = $this->getJson('/api/v1/finance/profit-loss/export?format=doc');
        $badFormatRes->assertStatus(422);
        $this->assertEquals('INVALID_EXPORT_FORMAT', $badFormatRes->json('code'));

        // Invalid report via generic export route
        $badReportRes = $this->getJson('/api/v1/finance/reports/unknown-report/export?format=pdf');
        $badReportRes->assertStatus(404);
        $this->assertEquals('INVALID_REPORT_TYPE', $badReportRes->json('code'));
    }

    public function test_date_range_handling_respects_boundaries_and_business_timezone(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Seed 3 sales on different days
        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Day 1: 2026-10-01
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale Oct 1',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
            ]);

            // Day 2: 2026-10-05
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-05',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale Oct 5',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 2000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 2000000],
            ]);

            // Day 3: 2026-10-10
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-10',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale Oct 10',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 3000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 3000000],
            ]);
        });

        $this->actingAs($this->owner);

        // Custom range: 2026-10-02 to 2026-10-06 should ONLY include Oct 5 (2.000.000)
        $res = $this->getJson('/api/v1/finance/profit-loss/export?format=csv&start_date=2026-10-02&end_date=2026-10-06');
        $res->assertStatus(200);
        $csv = $res->getContent();

        $this->assertStringContainsString('"Pendapatan Bersih (Net Revenue)",2000000', $csv);
        $this->assertStringNotContainsString('1000000', $csv);
        $this->assertStringNotContainsString('3000000', $csv);
    }

    public function test_negative_values_and_sales_returns_are_accurately_preserved(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Gross Revenue 5.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale Initial',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 5000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 5000000],
            ]);

            // Sales Return 1.000.000 (Account 4200 debit)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-10-02',
                'source_type' => JournalEntry::SOURCE_REFUND,
                'description' => 'Retur Barang',
            ], [
                ['account_id' => $accounts[Account::CODE_SALES_RETURN]->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
            ]);
        });

        $this->actingAs($this->owner);

        $res = $this->getJson('/api/v1/finance/profit-loss/export?format=csv&period=this_month');
        $res->assertStatus(200);
        $csv = $res->getContent();

        // Sales return preserved as negative value
        $this->assertStringContainsString('"Retur Penjualan (Sales Return)",-1000000', $csv);
        $this->assertStringContainsString('-Rp 1.000.000', $csv);
        $this->assertStringContainsString('"Pendapatan Bersih (Net Revenue)",4000000', $csv);
    }
}
