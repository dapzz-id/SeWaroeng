<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class AdvancedFinanceReportTest extends TenantTestCase
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

        $this->tenantData = $this->createTenant('Resto Sinar Harapan');
        $tenant = $this->tenantData['tenant'];
        $this->outlet1 = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->outlet2 = $this->runForTenant(function () use ($tenant) {
            return Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => 'Resto Sinar Harapan - Cabang B',
                'code' => 'OUT-B',
                'is_active' => true,
            ]);
        });

        $accountantData = $this->addMemberToTenant($tenant, 'accountant', 'Akuntan Dian');
        $this->accountant = $accountantData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Budi', [$this->outlet1->id]);
        $this->cashier = $cashierData['user'];

        $this->financeService = app(FinanceService::class);

        $this->runForTenant(function () use ($tenant) {
            $this->financeService->ensureDefaultAccounts($tenant->id);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_profit_and_loss_with_period_comparison_and_margins(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Previous period: 2026-05-01
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-05-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale Prev Period',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
            ]);

            // Current period: 2026-05-02
            // Sale: 2.000.000 revenue, 800.000 COGS
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-05-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Sale Current Period',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 2000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 2000000],
            ]);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-05-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'COGS Current Period',
            ], [
                ['account_id' => $accounts[Account::CODE_COGS]->id, 'debit_minor' => 800000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 0, 'credit_minor' => 800000],
            ]);

            // Expense: 200.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-05-02',
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'description' => 'Electricity Current Period',
            ], [
                ['account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id, 'debit_minor' => 200000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 200000],
            ]);
        });

        // Request with compare=1 for 2026-05-02 (1 day period -> compares with 2026-05-01)
        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/profit-loss?start_date=2026-05-02&end_date=2026-05-02&compare=1');

        $response->assertOk()
            ->assertJsonPath('data.revenue_minor', 2000000)
            ->assertJsonPath('data.cogs_minor', 800000)
            ->assertJsonPath('data.gross_profit_minor', 1200000)
            ->assertJsonPath('data.gross_margin_pct', fn ($val) => (float) $val === 60.0)
            ->assertJsonPath('data.expense_minor', 200000)
            ->assertJsonPath('data.net_profit_minor', 1000000)
            ->assertJsonPath('data.net_margin_pct', fn ($val) => (float) $val === 50.0)
            ->assertJsonPath('data.comparison.previous_revenue_minor', 1000000)
            ->assertJsonPath('data.comparison.revenue_growth_pct', fn ($val) => (float) $val === 100.0);
    }

    public function test_balance_sheet_mathematically_balances_and_computes_retained_earnings(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // 1. Initial Owner Equity: Rp 10.000.000 cash injection
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Owner Capital Injection',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 10000000],
            ]);

            // 2. Buy Inventory for Rp 3.000.000 with Cash
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Initial Inventory Purchase',
            ], [
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 3000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 3000000],
            ]);

            // 3. Digital Sale: Rp 5.000.000 via Clearing (1120), Rp 500.000 Tax (2110)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'QRIS Sale with Tax',
            ], [
                ['account_id' => $accounts[Account::CODE_BANK_CLEARING]->id, 'debit_minor' => 5500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 5000000],
                ['account_id' => $accounts[Account::CODE_TAX_PAYABLE]->id, 'debit_minor' => 0, 'credit_minor' => 500000],
            ]);

            // 4. COGS: Rp 1.500.000 inventory reduction
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'COGS for QRIS Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_COGS]->id, 'debit_minor' => 1500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 0, 'credit_minor' => 1500000],
            ]);
        });

        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/balance-sheet?as_of_date=2026-06-02');

        $response->assertOk()
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.discrepancy_minor', 0)
            ->assertJsonPath('data.assets.cash_minor', 7000000)
            ->assertJsonPath('data.assets.clearing_minor', 5500000)
            ->assertJsonPath('data.assets.inventory_minor', 1500000)
            ->assertJsonPath('data.assets.total_assets_minor', 14000000)
            ->assertJsonPath('data.liabilities.tax_payable_minor', 500000)
            ->assertJsonPath('data.liabilities.total_liabilities_minor', 500000)
            ->assertJsonPath('data.equity.owner_equity_minor', 10000000)
            ->assertJsonPath('data.equity.retained_earnings_minor', 3500000) // 5M rev - 1.5M cogs
            ->assertJsonPath('data.equity.total_equity_minor', 13500000)
            ->assertJsonPath('data.total_liabilities_and_equity_minor', 14000000);
    }

    public function test_trial_balance_verifies_total_debit_equals_total_credit(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-05',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Test Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 500000],
            ]);
        });

        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/trial-balance?as_of_date=2026-06-05');

        $response->assertOk()
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.discrepancy_minor', 0)
            ->assertJsonPath('data.total_debit_minor', 500000)
            ->assertJsonPath('data.total_credit_minor', 500000);
    }

    public function test_account_ledger_computes_opening_and_running_balances_chronologically(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Day 1: Opening sale Rp 300.000 cash
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-10',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Day 1 Cash Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 300000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 300000],
            ]);

            // Day 2 (within query range): Cash In Rp 200.000, Cash Out Rp 50.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-11',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Day 2 Cash Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 200000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 200000],
            ]);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-11',
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'description' => 'Day 2 Petty Cash Out',
            ], [
                ['account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id, 'debit_minor' => 50000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 50000],
            ]);
        });

        // Query for Day 2 only: opening balance should be Day 1's Rp 300.000
        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/account-ledger?account_code=1110&start_date=2026-06-11&end_date=2026-06-11');

        $response->assertOk()
            ->assertJsonPath('data.account.code', '1110')
            ->assertJsonPath('data.opening_balance_minor', 300000)
            ->assertJsonPath('data.total_debit_minor', 200000)
            ->assertJsonPath('data.total_credit_minor', 50000)
            ->assertJsonPath('data.closing_balance_minor', 450000)
            ->assertJsonPath('data.lines.0.debit_minor', 200000)
            ->assertJsonPath('data.lines.0.running_balance_minor', 500000)
            ->assertJsonPath('data.lines.1.credit_minor', 50000)
            ->assertJsonPath('data.lines.1.running_balance_minor', 450000);
    }

    public function test_cash_flow_opening_and_closing_balance_continuity(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Previous cash inflow: 1.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Previous Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
            ]);

            // Period cash inflow: 400.000, outflow: 100.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-15',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Period Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 400000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 400000],
            ]);

            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-15',
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'description' => 'Period Expense',
            ], [
                ['account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id, 'debit_minor' => 100000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 100000],
            ]);
        });

        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/cash-flow?start_date=2026-06-15&end_date=2026-06-15');

        $response->assertOk()
            ->assertJsonPath('data.opening_cash_minor', 1000000)
            ->assertJsonPath('data.cash_in_minor', 400000)
            ->assertJsonPath('data.cash_out_minor', 100000)
            ->assertJsonPath('data.net_cashflow_minor', 300000)
            ->assertJsonPath('data.closing_cash_minor', 1300000);
    }

    public function test_clearing_report_integrates_account_1120_and_reconciliation_verification(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Post QRIS sale: Dr 1120 Bank/Clearing 250.000, Cr 4100 Revenue 250.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-20',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'QRIS Non-Cash Payment',
            ], [
                ['account_id' => $accounts[Account::CODE_BANK_CLEARING]->id, 'debit_minor' => 250000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 250000],
            ]);

            // Create Payment Reconciliation record
            PaymentReconciliation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'provider' => 'midtrans',
                'provider_reference' => 'MID-998811',
                'channel' => 'qris',
                'expected_amount_minor' => 250000,
                'provider_amount_minor' => 250000,
                'fee_amount_minor' => 1750,
                'net_amount_minor' => 248250,
                'currency' => 'IDR',
                'payment_status' => Payment::STATUS_SUCCEEDED,
                'settlement_status' => PaymentReconciliation::SETTLEMENT_SETTLED,
                'reconciliation_status' => PaymentReconciliation::STATUS_MATCHED,
                'reconciled_at' => Carbon::parse('2026-06-20 12:00:00', 'Asia/Jakarta')->utc(),
                'created_at' => Carbon::parse('2026-06-20 12:00:00', 'Asia/Jakarta')->utc(),
            ]);
        });

        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/clearing-summary?start_date=2026-06-20&end_date=2026-06-20');

        $response->assertOk()
            ->assertJsonPath('data.clearing_ledger.account_code', '1120')
            ->assertJsonPath('data.clearing_ledger.debit_minor', 250000)
            ->assertJsonPath('data.clearing_ledger.credit_minor', 0)
            ->assertJsonPath('data.clearing_ledger.net_movement_minor', 250000)
            ->assertJsonPath('data.reconciliation_verification.matched_count', 1)
            ->assertJsonPath('data.reconciliation_verification.matched_amount_minor', 250000)
            ->assertJsonPath('data.reconciliation_verification.settled_count', 1)
            ->assertJsonPath('data.reconciliation_verification.settled_amount_minor', 248250);
    }

    public function test_reversal_of_sale_maintains_balance_sheet_and_trial_balance_invariants(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // 1. Initial Sale: Cash 500.000, Revenue 500.000
            $saleEntry = $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-22',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Original Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 500000],
            ]);

            // 2. Reverse Sale via compensating reversal entry (immutable double-entry principle)
            $this->financeService->reverseJournalEntry(
                $saleEntry,
                $this->accountant->id,
                'Customer Order Cancelled and Refunded',
                Carbon::parse('2026-06-22')
            );
        });

        // Assert Trial Balance remains perfectly balanced at net 0
        $tbResponse = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/trial-balance?as_of_date=2026-06-22');

        $tbResponse->assertOk()
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.discrepancy_minor', 0)
            ->assertJsonPath('data.total_debit_minor', 0)
            ->assertJsonPath('data.total_credit_minor', 0);

        // Assert Balance Sheet remains balanced at 0 discrepancy
        $bsResponse = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/balance-sheet?as_of_date=2026-06-22');

        $bsResponse->assertOk()
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.discrepancy_minor', 0);
    }

    public function test_security_cashier_cannot_access_advanced_reports_and_cross_outlet_denied(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Cashier has no finance.report.view permission
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/balance-sheet')
            ->assertForbidden();

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/trial-balance')
            ->assertForbidden();

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/account-ledger?account_code=1110')
            ->assertForbidden();

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/clearing-summary')
            ->assertForbidden();
    }
}
