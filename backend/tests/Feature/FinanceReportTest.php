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

class FinanceReportTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $accountant;

    protected User $cashier;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected FinanceService $financeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Resto Enak Jaya');
        $tenant = $this->tenantData['tenant'];
        $this->outlet1 = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->outlet2 = $this->runForTenant(function () use ($tenant) {
            $out = Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => 'Resto Enak Jaya - Cabang 2',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);

            return $out;
        });

        $accountantData = $this->addMemberToTenant($tenant, 'accountant', 'Akuntan Maya');
        $this->accountant = $accountantData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Rudi', [$this->outlet1->id]);
        $this->cashier = $cashierData['user'];

        $this->financeService = app(FinanceService::class);

        $this->runForTenant(function () use ($tenant) {
            $loc1 = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'name' => 'Kitchen 1',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $this->outlet1->update(['default_pos_location_id' => $loc1->id]);

            $loc2 = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet2->id,
                'name' => 'Kitchen 2',
                'code' => 'KIT-02',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $this->outlet2->update(['default_pos_location_id' => $loc2->id]);

            $this->financeService->ensureDefaultAccounts($tenant->id);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_profit_and_loss_report_computes_correct_gross_and_net_profit(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // 1. Sale Journal: Rp 10.000.000 Revenue, Rp 4.000.000 COGS
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale batch',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_COGS]->id, 'debit_minor' => 4000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 10000000],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 0, 'credit_minor' => 4000000],
            ]);

            // 2. Expense Journal: Rp 2.500.000 Operating Expense
            $this->financeService->createExpense($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'category' => Expense::CATEGORY_UTILITIES,
                'amount_minor' => 2500000,
                'expense_date' => now()->toDateString(),
                'payment_method' => Expense::PAYMENT_METHOD_CASH,
                'status' => Expense::STATUS_POSTED,
            ]);
        });

        // Query P&L via API as Accountant
        $res = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/profit-loss?period=today');

        $res->assertStatus(200)
            ->assertJsonPath('data.revenue_minor', 10000000)
            ->assertJsonPath('data.cogs_minor', 4000000)
            ->assertJsonPath('data.gross_profit_minor', 6000000)
            ->assertJsonPath('data.expense_minor', 2500000)
            ->assertJsonPath('data.net_profit_minor', 3500000)
            ->assertJsonPath('data.breakdown.categories.utilities', 2500000);
    }

    public function test_cash_flow_report_calculates_cash_in_cash_out_and_net_cash_movement(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // 1. Cash Sale of Rp 7.000.000 (Dr Cash 7m, Cr Revenue 7m)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_SALE,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 7000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 7000000],
            ]);

            // 2. Non-Cash QRIS Sale of Rp 5.000.000 (Dr Bank Clearing 5m, Cr Revenue 5m)
            // MUST NOT count as physical cash in!
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_SALE,
            ], [
                ['account_id' => $accounts[Account::CODE_BANK_CLEARING]->id, 'debit_minor' => 5000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 5000000],
            ]);

            // 3. Cash Expense of Rp 3.000.000 (Dr Operating Expense 3m, Cr Cash 3m)
            $this->financeService->createExpense($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'category' => Expense::CATEGORY_SUPPLIES,
                'amount_minor' => 3000000,
                'expense_date' => now()->toDateString(),
                'payment_method' => Expense::PAYMENT_METHOD_CASH,
                'status' => Expense::STATUS_POSTED,
            ]);
        });

        // Query Cash Flow via API
        $res = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/cash-flow?period=today');

        $res->assertStatus(200)
            ->assertJsonPath('data.cash_in_minor', 7000000)
            ->assertJsonPath('data.cash_out_minor', 3000000)
            ->assertJsonPath('data.net_cashflow_minor', 4000000)
            ->assertJsonPath('data.breakdown.cash_sales_minor', 7000000)
            ->assertJsonPath('data.breakdown.cash_expenses_minor', 3000000);
    }

    public function test_outlet_filter_isolates_outlet_reports(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Outlet 1 Sale: Rp 2.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_SALE,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 2000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 2000000],
            ]);

            // Outlet 2 Sale: Rp 5.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet2->id,
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_SALE,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 5000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 5000000],
            ]);
        });

        // Filter Outlet 1 only
        $resOutlet1 = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson("/api/v1/finance/profit-loss?outlet_id={$this->outlet1->id}&period=today");

        $resOutlet1->assertStatus(200)
            ->assertJsonPath('data.revenue_minor', 2000000);

        // Filter Outlet 2 only
        $resOutlet2 = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson("/api/v1/finance/profit-loss?outlet_id={$this->outlet2->id}&period=today");

        $resOutlet2->assertStatus(200)
            ->assertJsonPath('data.revenue_minor', 5000000);

        // Company-wide (no outlet filter) aggregates both: Rp 7.000.000
        $resAll = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/profit-loss?period=today');

        $resAll->assertStatus(200)
            ->assertJsonPath('data.revenue_minor', 7000000);
    }

    public function test_tenant_isolation_on_finance_reports(): void
    {
        $tenantA = $this->tenantData['tenant'];

        // Post revenue in Tenant A
        $this->runForTenant(function () use ($tenantA) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenantA->id);
            $this->financeService->postJournal($tenantA->id, [
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_SALE,
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 15000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 15000000],
            ]);
        });

        // Tenant B
        $tenantBData = $this->createTenant('Tenant B Cafe');
        $tenantB = $tenantBData['tenant'];
        $accountantBData = $this->addMemberToTenant($tenantB, 'accountant', 'Akuntan B');
        $accountantB = $accountantBData['user'];

        // Tenant B reports should show 0 revenue, strictly isolated from Tenant A
        $resB = $this->authenticateTenantUser($accountantB, $tenantB)
            ->getJson('/api/v1/finance/profit-loss?period=today');

        $resB->assertStatus(200)
            ->assertJsonPath('data.revenue_minor', 0)
            ->assertJsonPath('data.net_profit_minor', 0);
    }

    public function test_rbac_cashier_cannot_view_finance_reports(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Cashier has no report viewing authority
        $pnlRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/profit-loss');
        $pnlRes->assertStatus(403);

        $cashflowRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/cash-flow');
        $cashflowRes->assertStatus(403);

        $journalRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/journal-entries');
        $journalRes->assertStatus(403);
    }
}
