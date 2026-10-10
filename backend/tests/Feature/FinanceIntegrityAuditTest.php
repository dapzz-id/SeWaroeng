<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\Sale;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class FinanceIntegrityAuditTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $manager;

    protected User $accountant;

    protected User $cashier;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected FinanceService $financeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Audit Waroeng Canonical');
        $tenant = $this->tenantData['tenant'];
        $this->outlet1 = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->outlet2 = $this->runForTenant(function () use ($tenant) {
            return Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => 'Audit Waroeng Cabang 2',
                'code' => 'OUT-AUDIT-2',
                'is_active' => true,
            ]);
        });

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manajer Cabang 1', [$this->outlet1->id]);
        $this->manager = $managerData['user'];

        $accountantData = $this->addMemberToTenant($tenant, 'accountant', 'Akuntan Utama');
        $this->accountant = $accountantData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Outlet 1', [$this->outlet1->id]);
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

    /**
     * OBJECTIVE 1: Balance Sheet Independent Correctness
     *
     * Invariants:
     * - Retained earnings is computed independently as (Cumulative Revenue - Cumulative Expenses).
     * - Retained earnings is NOT an algebraic balancing plug (Assets - Liabilities - Equity).
     * - Independent verification of exact expected accounting values.
     * - Supports custom accounts (e.g. 2100 Accounts Payable) in canonical type aggregation.
     */
    public function test_balance_sheet_calculates_independent_retained_earnings_not_balancing_plug(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Create custom liability account: 2100 Accounts Payable (Utang Usaha)
            $apAccount = Account::create([
                'tenant_id' => $tenant->id,
                'code' => '2100',
                'name' => 'Utang Usaha (Accounts Payable)',
                'type' => Account::TYPE_LIABILITY,
                'is_system' => false,
                'is_active' => true,
                'description' => 'Kewajiban kepada pemasok',
            ]);

            // Deterministic dataset per user prompt:
            // Cash:                   10.000.000 (Dr 1110)
            // Inventory:               2.000.000 (Dr 1200)
            // Accounts Payable:        3.000.000 (Cr 2100)
            // Owner Equity:            5.000.000 (Cr 3100)
            // Revenue:                 8.000.000 (Cr 4100)
            // COGS:                    3.000.000 (Dr 5100)
            // Expense:                 1.000.000 (Dr 6100)

            // Balanced Entry 1: Capital injection and supplier inventory purchase on credit
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Initial Capital and Credit Inventory Purchase',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 10000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 5000000, 'credit_minor' => 0],
                ['account_id' => $apAccount->id, 'debit_minor' => 0, 'credit_minor' => 3000000],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 12000000],
            ]);

            // Adjust Owner Equity down to exactly 5.000.000 via drawing or owner capital reallocation
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-01',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Equity Adjustment',
            ], [
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 7000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 7000000],
            ]);

            // Balanced Entry 2: Sales Revenue Rp 8.000.000 received in Cash
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Cash Sales Revenue',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 8000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 8000000],
            ]);

            // Balanced Entry 3: COGS Rp 3.000.000 reducing Inventory from 5.000.000 to 2.000.000
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'COGS Recognition',
            ], [
                ['account_id' => $accounts[Account::CODE_COGS]->id, 'debit_minor' => 3000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_INVENTORY]->id, 'debit_minor' => 0, 'credit_minor' => 3000000],
            ]);

            // Balanced Entry 4: Operating Expense Rp 1.000.000 paid in Cash
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-03',
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'description' => 'Operating Expense Payment',
            ], [
                ['account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
            ]);
        });

        // Query Balance Sheet via API
        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/reports/balance-sheet?as_of_date=2026-07-05');

        $response->assertOk();

        // Independent Mathematical Oracle Constants:
        // Cash: 10.000.000
        // Inventory: 2.000.000 (5M initial - 3M COGS)
        // Expected Total Assets: 10M + 2M = 12.000.000
        // Accounts Payable: 3.000.000
        // Expected Total Liabilities: 3.000.000
        // Owner Equity: 12M initial - 7M drawing = 5.000.000
        // Independent Retained Earnings: Revenue (8.000.000) - COGS (3.000.000) - Expense (1.000.000) = 4.000.000
        // Expected Total Equity: 5.000.000 + 4.000.000 = 9.000.000
        // Total Liabilities & Equity: 3.000.000 + 9.000.000 = 12.000.000
        $data = $response->json('data');

        $this->assertSame(10000000, $data['assets']['cash_minor'], 'Cash must strictly equal 10.000.000');
        $this->assertSame(2000000, $data['assets']['inventory_minor'], 'Inventory must strictly equal 2.000.000');
        $this->assertSame(12000000, $data['assets']['total_assets_minor'], 'Total Assets must strictly equal 12.000.000');
        $this->assertSame(3000000, $data['liabilities']['total_liabilities_minor'], 'Total Liabilities must include Accounts Payable = 3.000.000');
        $this->assertSame(5000000, $data['equity']['owner_equity_minor'], 'Owner Equity must strictly equal 5.000.000');
        $this->assertSame(4000000, $data['equity']['retained_earnings_minor'], 'Retained Earnings must independently equal 4.000.000');
        $this->assertSame(9000000, $data['equity']['total_equity_minor'], 'Total Equity must strictly equal 9.000.000');
        $this->assertSame(12000000, $data['total_liabilities_and_equity_minor'], 'Total Liabilities & Equity must strictly equal 12.000.000');
        $this->assertTrue($data['is_balanced'], 'Balance sheet must balance');
        $this->assertSame(0, $data['discrepancy_minor'], 'Discrepancy must be 0');
    }

    /**
     * OBJECTIVE 2: Trial Balance Independent Correctness
     */
    public function test_trial_balance_verifies_exact_account_balances_and_excludes_draft_entries(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // 1. Posted Sale 1: Rp 1.500.000 Cash
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-10',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Posted Sale 1',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 1500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 1500000],
            ]);

            // 2. Posted Expense 1: Rp 300.000 Cash
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-10',
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'description' => 'Posted Expense 1',
            ], [
                ['account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id, 'debit_minor' => 300000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 300000],
            ]);

            // 3. Unposted DRAFT Entry: Rp 99.999.000 (MUST BE COMPLETELY EXCLUDED)
            $draftEntry = JournalEntry::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'entry_number' => 'JE-DRAFT-999',
                'entry_date' => '2026-07-10',
                'status' => JournalEntry::STATUS_DRAFT,
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Unposted Draft Entry',
            ]);

            JournalLine::create([
                'tenant_id' => $tenant->id,
                'journal_entry_id' => $draftEntry->id,
                'account_id' => $accounts[Account::CODE_CASH]->id,
                'debit_minor' => 99999000,
                'credit_minor' => 0,
            ]);

            JournalLine::create([
                'tenant_id' => $tenant->id,
                'journal_entry_id' => $draftEntry->id,
                'account_id' => $accounts[Account::CODE_SALES_REVENUE]->id,
                'debit_minor' => 0,
                'credit_minor' => 99999000,
            ]);
        });

        $response = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/reports/trial-balance?as_of_date=2026-07-10');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertTrue($data['is_balanced']);
        $this->assertSame(0, $data['discrepancy_minor']);
        $this->assertSame(1500000, $data['total_debit_minor'], 'Draft entries must not inflate total debits');
        $this->assertSame(1500000, $data['total_credit_minor'], 'Draft entries must not inflate total credits');

        $accountsMap = collect($data['accounts'])->keyBy('account_code');
        $this->assertSame(1200000, $accountsMap['1110']['ending_debit_minor']);
        $this->assertSame(0, $accountsMap['1110']['ending_credit_minor']);
        $this->assertSame(1500000, $accountsMap['4100']['ending_credit_minor']);
        $this->assertSame(300000, $accountsMap['6100']['ending_debit_minor']);
    }

    /**
     * OBJECTIVE 3: Journal and Line Immutability Enforcement
     */
    public function test_posted_journal_and_lines_are_strictly_immutable(): void
    {
        $tenant = $this->tenantData['tenant'];

        /** @var JournalEntry $postedEntry */
        $postedEntry = $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            return $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-15',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Immutable Entry Test',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 500000],
            ]);
        });

        $this->expectException(DomainException::class);
        $this->runForTenant(function () use ($postedEntry) {
            $postedEntry->update(['description' => 'Malicious Description Change']);
        });
    }

    public function test_posted_journal_cannot_be_reverted_to_draft(): void
    {
        $tenant = $this->tenantData['tenant'];

        /** @var JournalEntry $postedEntry */
        $postedEntry = $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            return $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-15',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Status Mutation Test',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 100000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 100000],
            ]);
        });

        $this->expectException(DomainException::class);
        $this->runForTenant(function () use ($postedEntry) {
            $postedEntry->status = JournalEntry::STATUS_DRAFT;
            $postedEntry->save();
        });
    }

    public function test_posted_journal_cannot_be_deleted(): void
    {
        $tenant = $this->tenantData['tenant'];

        /** @var JournalEntry $postedEntry */
        $postedEntry = $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            return $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-15',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Deletion Test',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 200000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 200000],
            ]);
        });

        $this->expectException(DomainException::class);
        $this->runForTenant(function () use ($postedEntry) {
            $postedEntry->delete();
        });
    }

    public function test_lines_of_posted_journal_cannot_be_modified_or_deleted(): void
    {
        $tenant = $this->tenantData['tenant'];

        /** @var JournalEntry $postedEntry */
        $postedEntry = $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            return $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-15',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Line Immutability Test',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 300000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 300000],
            ]);
        });

        $line = $this->runForTenant(fn () => $postedEntry->lines()->first());

        // 1. Attempt line modification
        try {
            $this->runForTenant(function () use ($line) {
                $line->update(['debit_minor' => 999999]);
            });
            $this->fail('Expected DomainException when modifying line of posted entry');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot be modified', $e->getMessage());
        }

        // 2. Attempt line deletion
        try {
            $this->runForTenant(function () use ($line) {
                $line->delete();
            });
            $this->fail('Expected DomainException when deleting line of posted entry');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }

    /**
     * OBJECTIVE 4: Reversal Semantics and Duplicate Protection
     */
    public function test_reversal_creates_compensating_entry_and_prevents_duplicate_or_ambiguous_reversal(): void
    {
        $tenant = $this->tenantData['tenant'];

        /** @var JournalEntry $original */
        $original = $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            return $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-18',
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Original Entry for Reversal',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 750000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 750000],
            ]);
        });

        // Execute reversal
        $reversal = $this->runForTenant(function () use ($original) {
            return $this->financeService->reverseJournalEntry($original, $this->accountant->id, 'Mistaken manual posting');
        });

        // Verify status & linkage
        $this->runForTenant(function () use ($original, $reversal) {
            $original->refresh();
            $this->assertSame(JournalEntry::STATUS_REVERSED, $original->status);
            $this->assertSame($reversal->id, $original->reversal_of_id, 'Original links to reversal entry');
            $this->assertSame($original->id, $reversal->reversal_of_id, 'Reversal links to original entry');
            $this->assertTrue($reversal->isPosted());
            $this->assertTrue($reversal->isBalanced());

            // Verify line inversion: Dr Revenue 750.000, Cr Cash 750.000
            $revLines = $reversal->lines()->get();
            $this->assertCount(2, $revLines);
            $cashReversalLine = $revLines->firstWhere('debit_minor', 0);
            $this->assertSame(750000, $cashReversalLine->credit_minor, 'Cash credited by reversal');
        });

        // Verify duplicate reversal is rejected
        try {
            $this->runForTenant(function () use ($original) {
                $this->financeService->reverseJournalEntry($original, $this->accountant->id, 'Duplicate Reversal Attempt');
            });
            $this->fail('Expected DomainException on duplicate reversal');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Only posted journal entries can be reversed', $e->getMessage());
        }

        // Verify reversing a reversal entry is rejected (unambiguous reversal trail)
        try {
            $this->runForTenant(function () use ($reversal) {
                $this->financeService->reverseJournalEntry($reversal, $this->accountant->id, 'Reversing a reversal');
            });
            $this->fail('Expected DomainException on reversing a compensating reversal');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cannot reverse a compensating reversal entry', $e->getMessage());
        }

        // Verify trial balance net ending balances are 0
        $trial = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getTrialBalance($tenant->id, $this->outlet1->id, Carbon::parse('2026-07-18'));
        });
        $this->assertTrue($trial['is_balanced']);
        $accountsMap = collect($trial['accounts'])->keyBy('account_code');
        $this->assertSame(0, $accountsMap['1110']['ending_debit_minor'], 'Net cash balance must be zero after reversal');
        $this->assertSame(0, $accountsMap['4100']['ending_credit_minor'], 'Net revenue balance must be zero after reversal');
    }

    /**
     * OBJECTIVE 5: P&L Independent Correctness & Zero/Contra Edge Cases
     */
    public function test_profit_and_loss_independent_formulas_and_zero_division_safety(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Period 1: Empty ledger (Zero revenue, zero COGS, zero expense)
        $emptyReport = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getProfitAndLoss(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-08-01'),
                Carbon::parse('2026-08-05'),
                'Asia/Jakarta',
                true
            );
        });

        $this->assertSame(0, $emptyReport['revenue_minor']);
        $this->assertSame(0, $emptyReport['cogs_minor']);
        $this->assertSame(0, $emptyReport['gross_profit_minor']);
        $this->assertSame(0.0, $emptyReport['gross_margin_pct']);
        $this->assertSame(0, $emptyReport['expense_minor']);
        $this->assertSame(0, $emptyReport['net_profit_minor']);
        $this->assertSame(0.0, $emptyReport['net_margin_pct']);
        $this->assertSame(0.0, $emptyReport['comparison']['revenue_growth_pct']);
        $this->assertSame(0.0, $emptyReport['comparison']['net_profit_growth_pct']);

        // Period 2: Contra returns exceed revenue (Sales return 500k, Gross revenue 0)
        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Post Rp 500.000 return / refund contra revenue
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-08-10',
                'source_type' => JournalEntry::SOURCE_REFUND,
                'description' => 'Stand-alone Return',
            ], [
                ['account_id' => $accounts[Account::CODE_SALES_RETURN]->id, 'debit_minor' => 500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 500000],
            ]);
        });

        $contraReport = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getProfitAndLoss(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-08-09'),
                Carbon::parse('2026-08-11'),
                'Asia/Jakarta',
                false
            );
        });

        // Net Revenue = Gross (0) - Return (500.000) = -500.000
        $this->assertSame(-500000, $contraReport['revenue_minor']);
        $this->assertSame(-500000, $contraReport['gross_profit_minor']);
        $this->assertSame(0.0, $contraReport['gross_margin_pct'], 'Negative revenue safely sets gross margin to 0.0% without division error');
        $this->assertSame(-500000, $contraReport['net_profit_minor']);
        $this->assertSame(0.0, $contraReport['net_margin_pct']);
    }

    /**
     * OBJECTIVE 6: Timezone Boundary Semantics
     */
    public function test_timezone_and_month_boundaries_strictly_isolate_financial_events(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // June 30 (Last day of June)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-06-30',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'June 30 End of Month Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
            ]);

            // July 01 (First day of July)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-07-01',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'July 01 New Month Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 2000000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 2000000],
            ]);
        });

        // 1. Balance sheet as of June 30 includes only June 30 entry (Rp 1.000.000)
        $juneSheet = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getBalanceSheet(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-06-30', 'Asia/Jakarta')->endOfDay(),
                'Asia/Jakarta'
            );
        });
        $this->assertSame(1000000, $juneSheet['assets']['cash_minor']);
        $this->assertSame(1000000, $juneSheet['equity']['retained_earnings_minor']);

        // 2. Balance sheet as of July 01 includes both (Rp 3.000.000)
        $julySheet = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getBalanceSheet(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-07-01', 'Asia/Jakarta')->endOfDay(),
                'Asia/Jakarta'
            );
        });
        $this->assertSame(3000000, $julySheet['assets']['cash_minor']);
        $this->assertSame(3000000, $julySheet['equity']['retained_earnings_minor']);

        // 3. P&L strictly for July 01 - July 31 contains only Rp 2.000.000
        $julyPnl = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getProfitAndLoss(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-07-01', 'Asia/Jakarta')->startOfDay(),
                Carbon::parse('2026-07-31', 'Asia/Jakarta')->endOfDay(),
                'Asia/Jakarta',
                false
            );
        });
        $this->assertSame(2000000, $julyPnl['revenue_minor']);
    }

    /**
     * OBJECTIVE 7: Cash Flow Semantics
     */
    public function test_cash_flow_strictly_evaluates_account_1110_and_preserves_continuity(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Day 1: Opening physical cash Rp 500.000 via drawer float
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-08-01',
                'source_type' => JournalEntry::SOURCE_CASH_MOVEMENT,
                'description' => 'Initial Drawer Cash Float',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 500000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_OWNER_EQUITY]->id, 'debit_minor' => 0, 'credit_minor' => 500000],
            ]);

            // Day 2: Cash Sale Rp 300.000 (Physical Cash Inflow)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-08-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Physical Cash Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 300000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 300000],
            ]);

            // Day 2: QRIS Digital Sale Rp 700.000 (Account 1120 Kliring, MUST BE EXCLUDED from Cash Flow)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-08-02',
                'source_type' => JournalEntry::SOURCE_SALE,
                'description' => 'Digital QRIS Sale',
            ], [
                ['account_id' => $accounts[Account::CODE_BANK_CLEARING]->id, 'debit_minor' => 700000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 700000],
            ]);

            // Day 2: Cash Expense Rp 100.000 (Physical Cash Outflow)
            $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-08-02',
                'source_type' => JournalEntry::SOURCE_EXPENSE,
                'description' => 'Physical Cash Outflow for Supplies',
            ], [
                ['account_id' => $accounts[Account::CODE_OPERATING_EXPENSE]->id, 'debit_minor' => 100000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_CASH]->id, 'debit_minor' => 0, 'credit_minor' => 100000],
            ]);
        });

        // Query Cash Flow for Day 2 (2026-08-02)
        $cf = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getCashFlow(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-08-02'),
                Carbon::parse('2026-08-02'),
                'Asia/Jakarta'
            );
        });

        $this->assertSame(500000, $cf['opening_cash_minor']);
        $this->assertSame(300000, $cf['cash_in_minor']);
        $this->assertSame(100000, $cf['cash_out_minor']);
        $this->assertSame(200000, $cf['net_cashflow_minor']);
        $this->assertSame(700000, $cf['closing_cash_minor']);
        $this->assertSame(300000, $cf['breakdown']['cash_sales_minor']);
        $this->assertSame(100000, $cf['breakdown']['cash_expenses_minor']);
    }

    /**
     * OBJECTIVE 8: Payment Clearing / Reconciliation Boundary
     */
    public function test_payment_reconciliation_boundary_prevents_accounting_duplication_or_corruption(): void
    {
        $tenant = $this->tenantData['tenant'];

        /** @var array{sale: Sale, payment: Payment, journal: JournalEntry} $records */
        $records = $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $sale = Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'reference_number' => 'SALE-RECON-AUDIT-001',
                'status' => Sale::STATUS_FINALIZED,
                'opened_at' => now(),
                'finalized_at' => now(),
                'subtotal_minor' => 150000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 150000,
                'cashier_user_id' => $this->cashier->id,
            ]);

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-RECON-AUDIT-001',
                'status' => Payment::STATUS_SUCCEEDED,
                'amount_minor' => 150000,
                'currency' => 'IDR',
                'method' => 'qris',
                'provider' => 'midtrans',
                'provider_payment_id' => 'MID-TEST-AUDIT-001',
                'succeeded_at' => now(),
            ]);

            $journal = $this->financeService->postJournal($tenant->id, [
                'outlet_id' => $this->outlet1->id,
                'entry_date' => '2026-08-15',
                'source_type' => JournalEntry::SOURCE_SALE,
                'source_id' => $sale->id,
                'description' => 'QRIS Sale Authoritative Posting',
            ], [
                ['account_id' => $accounts[Account::CODE_BANK_CLEARING]->id, 'debit_minor' => 150000, 'credit_minor' => 0],
                ['account_id' => $accounts[Account::CODE_SALES_REVENUE]->id, 'debit_minor' => 0, 'credit_minor' => 150000],
            ]);

            return ['sale' => $sale, 'payment' => $payment, 'journal' => $journal];
        });

        $saleJournal = $records['journal'];
        $payment = $records['payment'];

        $journalCountBefore = $this->runForTenant(fn () => JournalEntry::where('tenant_id', $tenant->id)->count());

        // Simulate Reconciliation Mismatch record
        $this->runForTenant(function () use ($tenant, $payment) {
            PaymentReconciliation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'payment_id' => $payment->id,
                'provider' => 'midtrans',
                'provider_reference' => 'MID-TEST-001',
                'channel' => 'qris',
                'expected_amount_minor' => 150000,
                'provider_amount_minor' => 140000,
                'fee_amount_minor' => 1000,
                'net_amount_minor' => 139000,
                'currency' => 'IDR',
                'payment_status' => 'paid',
                'settlement_status' => PaymentReconciliation::SETTLEMENT_SETTLED,
                'reconciliation_status' => PaymentReconciliation::STATUS_MISMATCHED,
                'mismatch_reason' => PaymentReconciliation::MISMATCH_AMOUNT,
                'reconciled_at' => Carbon::parse('2026-08-15 10:00:00', 'Asia/Jakarta')->utc(),
            ]);
        });

        $journalCountAfter = $this->runForTenant(fn () => JournalEntry::where('tenant_id', $tenant->id)->count());

        // 1. Reconciliation mismatch MUST NOT create duplicate or compensating entries automatically
        $this->assertSame($journalCountBefore, $journalCountAfter, 'Reconciliation record must not duplicate or corrupt journal entries');

        // 2. Original journal remains intact and posted
        $this->runForTenant(function () use ($saleJournal) {
            $saleJournal->refresh();
            $this->assertTrue($saleJournal->isPosted());
            $this->assertSame(150000, $saleJournal->lines()->firstWhere('debit_minor', '>', 0)->debit_minor);
        });

        // 3. Clearing Summary report correctly isolates ledger reality from verification discrepancy
        $clearingReport = $this->runForTenant(function () use ($tenant) {
            return $this->financeService->getClearingReport(
                $tenant->id,
                $this->outlet1->id,
                Carbon::parse('2026-08-15'),
                Carbon::parse('2026-08-15')
            );
        });

        $this->assertSame(150000, $clearingReport['clearing_ledger']['debit_minor']);
        $this->assertSame(1, $clearingReport['reconciliation_verification']['mismatched_count']);
        $this->assertSame(150000, $clearingReport['reconciliation_verification']['mismatched_amount_minor']);
    }

    /**
     * OBJECTIVE 9: Multi-Outlet & Security Authorization Consistency
     */
    public function test_multi_outlet_and_rbac_authorization_matrix(): void
    {
        $tenant = $this->tenantData['tenant'];

        // 1. Cashier is forbidden from financial reports
        $cashierResponse = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/reports/profit-and-loss');
        $cashierResponse->assertForbidden();

        // 2. Manager with assigned Outlet 1 passes valid query
        $mgrResponseValid = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson("/api/v1/finance/reports/profit-and-loss?outlet_id={$this->outlet1->id}");
        $mgrResponseValid->assertOk();

        // 3. Manager queries Outlet 2 (which manager is NOT assigned to) -> 403 Forbidden
        $mgrResponseUnassigned = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson("/api/v1/finance/reports/profit-and-loss?outlet_id={$this->outlet2->id}");
        $mgrResponseUnassigned->assertStatus(403);
        $this->assertSame('OUTLET_ACCESS_DENIED', $mgrResponseUnassigned->json('code'));

        // 4. Create Tenant B and Outlet B
        $tenantBData = $this->createTenant('Tenant B Security Boundary');
        $outletB = $tenantBData['outlet'];

        // Owner of Tenant A attempts to pass Outlet B (belonging to Tenant B)
        $ownerForgedOutletResponse = $this->authenticateTenantUser($this->owner, $tenant)
            ->getJson("/api/v1/finance/reports/profit-and-loss?outlet_id={$outletB->id}");
        $ownerForgedOutletResponse->assertStatus(403);
        $this->assertSame('OUTLET_ACCESS_DENIED', $ownerForgedOutletResponse->json('code'));

        // Owner of Tenant A attempts to list journal entries with forged Outlet B
        $ownerForgedJournalResponse = $this->authenticateTenantUser($this->owner, $tenant)
            ->getJson("/api/v1/finance/journal-entries?outlet_id={$outletB->id}");
        $ownerForgedJournalResponse->assertStatus(403);
        $this->assertSame('OUTLET_ACCESS_DENIED', $ownerForgedJournalResponse->json('code'));
    }

    /**
     * OBJECTIVES 10 & 11: Performance Benchmark & Query Plan Audit
     */
    public function test_performance_benchmark_and_explain_query_plan_audit(): void
    {
        $tenant = $this->tenantData['tenant'];

        // 1. Seed representative dataset (100 journal entries with 2 lines each = 200 lines)
        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            DB::transaction(function () use ($tenant, $accounts) {
                for ($i = 1; $i <= 100; $i++) {
                    $day = str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT);
                    $entry = JournalEntry::create([
                        'tenant_id' => $tenant->id,
                        'outlet_id' => ($i % 2 === 0) ? $this->outlet1->id : $this->outlet2->id,
                        'entry_number' => "JE-BENCH-{$i}",
                        'entry_date' => "2026-09-{$day}",
                        'status' => JournalEntry::STATUS_POSTED,
                        'source_type' => JournalEntry::SOURCE_SALE,
                        'description' => "Benchmark Entry {$i}",
                        'posted_at' => now(),
                        'posted_by' => $this->owner->id,
                    ]);

                    JournalLine::create([
                        'tenant_id' => $tenant->id,
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accounts[Account::CODE_CASH]->id,
                        'debit_minor' => 100000,
                        'credit_minor' => 0,
                    ]);

                    JournalLine::create([
                        'tenant_id' => $tenant->id,
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accounts[Account::CODE_SALES_REVENUE]->id,
                        'debit_minor' => 0,
                        'credit_minor' => 100000,
                    ]);
                }
            });
        });

        $this->runForTenant(function () use ($tenant) {
            $this->assertSame(100, JournalEntry::where('tenant_id', $tenant->id)->count());

            // 2. Measure Execution Times across Core Reports
            $startMem = memory_get_usage();

            // P&L Benchmark
            $t0 = microtime(true);
            $pnl = $this->financeService->getProfitAndLoss(
                $tenant->id,
                null,
                Carbon::parse('2026-09-01'),
                Carbon::parse('2026-09-30'),
                'Asia/Jakarta',
                false
            );
            $pnlDurationMs = (microtime(true) - $t0) * 1000;

            // Balance Sheet Benchmark
            $t0 = microtime(true);
            $balanceSheet = $this->financeService->getBalanceSheet(
                $tenant->id,
                null,
                Carbon::parse('2026-09-30'),
                'Asia/Jakarta'
            );
            $bsDurationMs = (microtime(true) - $t0) * 1000;

            // Trial Balance Benchmark
            $t0 = microtime(true);
            $trialBalance = $this->financeService->getTrialBalance(
                $tenant->id,
                null,
                Carbon::parse('2026-09-30'),
                'Asia/Jakarta'
            );
            $tbDurationMs = (microtime(true) - $t0) * 1000;

            // Account Ledger Benchmark
            $t0 = microtime(true);
            $ledger = $this->financeService->getAccountLedger(
                $tenant->id,
                Account::CODE_CASH,
                null,
                Carbon::parse('2026-09-01'),
                Carbon::parse('2026-09-30'),
                'Asia/Jakarta',
                1,
                50
            );
            $ledgerDurationMs = (microtime(true) - $t0) * 1000;

            // Cash Flow Benchmark
            $t0 = microtime(true);
            $cashFlow = $this->financeService->getCashFlow(
                $tenant->id,
                null,
                Carbon::parse('2026-09-01'),
                Carbon::parse('2026-09-30'),
                'Asia/Jakarta'
            );
            $cfDurationMs = (microtime(true) - $t0) * 1000;

            // Clearing Report Benchmark
            $t0 = microtime(true);
            $clearing = $this->financeService->getClearingReport(
                $tenant->id,
                null,
                Carbon::parse('2026-09-01'),
                Carbon::parse('2026-09-30'),
                'Asia/Jakarta'
            );
            $clearingDurationMs = (microtime(true) - $t0) * 1000;

            $endMem = memory_get_usage();
            $memDiffKb = ($endMem - $startMem) / 1024;

            // Assert query results correctness
            $this->assertSame(10000000, $pnl['revenue_minor']);
            $this->assertSame(10000000, $balanceSheet['assets']['cash_minor']);
            $this->assertSame(10000000, $trialBalance['total_debit_minor']);
            $this->assertSame(100, $ledger['pagination']['total']);
            $this->assertSame(50, count($ledger['lines'])); // paginated
            $this->assertSame(10000000, $cashFlow['cash_in_minor']);

            // Assert performance is sub-second (under 500ms per query in local dev)
            $this->assertLessThan(500, $pnlDurationMs, "P&L query duration ({$pnlDurationMs}ms) exceeds 500ms");
            $this->assertLessThan(500, $bsDurationMs, "Balance Sheet duration ({$bsDurationMs}ms) exceeds 500ms");
            $this->assertLessThan(500, $tbDurationMs, "Trial Balance duration ({$tbDurationMs}ms) exceeds 500ms");
            $this->assertLessThan(500, $ledgerDurationMs, "Account Ledger duration ({$ledgerDurationMs}ms) exceeds 500ms");
            $this->assertLessThan(500, $cfDurationMs, "Cash Flow duration ({$cfDurationMs}ms) exceeds 500ms");
            $this->assertLessThan(500, $clearingDurationMs, "Clearing Report duration ({$clearingDurationMs}ms) exceeds 500ms");

            // 3. EXPLAIN Query Plan Audit on Core Aggregate Query
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['pgsql', 'sqlite', 'mysql'])) {
                $explainSql = 'EXPLAIN SELECT SUM(journal_lines.debit_minor - journal_lines.credit_minor) '.
                    'FROM journal_lines '.
                    'INNER JOIN journal_entries ON journal_lines.journal_entry_id = journal_entries.id '.
                    'WHERE journal_lines.tenant_id = ? AND journal_entries.entry_date <= ?';

                $explainResult = DB::select($explainSql, [$tenant->id, '2026-09-30']);
                $this->assertNotEmpty($explainResult, 'EXPLAIN query plan must execute successfully');
            }
        });
    }
}
