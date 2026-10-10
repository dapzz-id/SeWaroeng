<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Account;
use App\Models\Expense;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Finance\FinanceService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class ExpenseManagementTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $manager;

    protected User $accountant;

    protected User $cashier;

    protected FinanceService $financeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Padang Minang');
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manager Joni', [$outlet->id]);
        $this->manager = $managerData['user'];

        $accountantData = $this->addMemberToTenant($tenant, 'accountant', 'Akuntan Lisa');
        $this->accountant = $accountantData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Rudi', [$outlet->id]);
        $this->cashier = $cashierData['user'];

        $this->financeService = app(FinanceService::class);

        $this->runForTenant(function () use ($tenant, $outlet) {
            $loc = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'name' => 'Main Kitchen',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $outlet->update(['default_pos_location_id' => $loc->id]);
            $this->financeService->ensureDefaultAccounts($tenant->id);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_can_create_and_post_cash_expense(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $payload = [
            'outlet_id' => $outlet->id,
            'category' => Expense::CATEGORY_UTILITIES,
            'amount_minor' => 25000000, // Rp 250.000
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_CASH,
            'description' => 'Pembayaran tagihan listrik bulanan',
            'reference_number' => 'PLN-202610-001',
        ];

        $res = $this->authenticateTenantUser($this->manager, $tenant)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/finance/expenses', $payload);

        $res->assertStatus(201)
            ->assertJsonPath('data.category', Expense::CATEGORY_UTILITIES)
            ->assertJsonPath('data.amount_minor', 25000000)
            ->assertJsonPath('data.payment_method', Expense::PAYMENT_METHOD_CASH)
            ->assertJsonPath('data.status', Expense::STATUS_POSTED);

        $expenseId = $res->json('data.id');

        // Verify Journal Entry was created and posted
        $this->runForTenant(function () use ($tenant, $expenseId) {
            $entry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_EXPENSE)
                ->where('source_id', $expenseId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->first();

            $this->assertNotNull($entry);
            $this->assertTrue($entry->isBalanced());
            $this->assertEquals(25000000, (int) $entry->lines->sum('debit_minor'));
            $this->assertEquals(25000000, (int) $entry->lines->sum('credit_minor'));

            $expenseLine = $entry->lines->firstWhere('account.code', Account::CODE_OPERATING_EXPENSE);
            $this->assertNotNull($expenseLine);
            $this->assertEquals(25000000, (int) $expenseLine->debit_minor);

            $cashLine = $entry->lines->firstWhere('account.code', Account::CODE_CASH);
            $this->assertNotNull($cashLine);
            $this->assertEquals(25000000, (int) $cashLine->credit_minor);
        });
    }

    public function test_can_create_and_post_bank_expense(): void
    {
        $tenant = $this->tenantData['tenant'];

        $payload = [
            'category' => Expense::CATEGORY_RENT,
            'amount_minor' => 500000000, // Rp 5.000.000
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_BANK,
            'description' => 'Sewa ruko bulan Oktober via transfer bank',
        ];

        $res = $this->authenticateTenantUser($this->manager, $tenant)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/finance/expenses', $payload);

        $res->assertStatus(201);
        $expenseId = $res->json('data.id');

        $this->runForTenant(function () use ($tenant, $expenseId) {
            $entry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_EXPENSE)
                ->where('source_id', $expenseId)
                ->first();

            $this->assertNotNull($entry);
            $bankLine = $entry->lines->firstWhere('account.code', Account::CODE_BANK_CLEARING);
            $this->assertNotNull($bankLine);
            $this->assertEquals(500000000, (int) $bankLine->credit_minor);
        });
    }

    public function test_expense_requires_positive_amount(): void
    {
        $tenant = $this->tenantData['tenant'];

        $payload = [
            'category' => Expense::CATEGORY_SUPPLIES,
            'amount_minor' => 0,
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_CASH,
        ];

        $res = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/finance/expenses', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['amount_minor']);

        $payload['amount_minor'] = -50000;
        $resNegative = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/finance/expenses', $payload);

        $resNegative->assertStatus(422)
            ->assertJsonValidationErrors(['amount_minor']);
    }

    public function test_rbac_cashier_cannot_manage_expenses_but_manager_and_accountant_can(): void
    {
        $tenant = $this->tenantData['tenant'];

        $payload = [
            'category' => Expense::CATEGORY_MAINTENANCE,
            'amount_minor' => 10000000,
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_CASH,
        ];

        // Cashier has NO finance.expense permissions
        $cashierRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/finance/expenses', $payload);
        $cashierRes->assertStatus(403);

        $cashierGetRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/finance/expenses');
        $cashierGetRes->assertStatus(403);

        // Manager can create and view
        $managerRes = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/finance/expenses', $payload);
        $managerRes->assertStatus(201);
        $expenseId = $managerRes->json('data.id');

        // Cashier cannot reverse
        $cashierRevRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/finance/expenses/{$expenseId}/reverse", ['reason' => 'Batal']);
        $cashierRevRes->assertStatus(403);

        // Accountant can reverse
        $accountantRevRes = $this->authenticateTenantUser($this->accountant, $tenant)
            ->postJson("/api/v1/finance/expenses/{$expenseId}/reverse", ['reason' => 'Salah input nominal']);
        $accountantRevRes->assertStatus(200)
            ->assertJsonPath('data.status', Expense::STATUS_REVERSED);
    }

    public function test_reverse_posted_expense_creates_compensating_journal_and_marks_status_reversed(): void
    {
        $tenant = $this->tenantData['tenant'];

        $payload = [
            'category' => Expense::CATEGORY_SUPPLIES,
            'amount_minor' => 7500000, // Rp 75.000
            'expense_date' => now()->toDateString(),
            'payment_method' => Expense::PAYMENT_METHOD_CASH,
            'description' => 'Beli sabun cuci piring',
        ];

        $createRes = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/finance/expenses', $payload);
        $createRes->assertStatus(201);
        $expenseId = $createRes->json('data.id');

        // Reverse
        $reverseRes = $this->authenticateTenantUser($this->accountant, $tenant)
            ->postJson("/api/v1/finance/expenses/{$expenseId}/reverse", [
                'reason' => 'Nota pembelian dibatalkan oleh kasir',
            ]);

        $reverseRes->assertStatus(200)
            ->assertJsonPath('data.status', Expense::STATUS_REVERSED)
            ->assertJsonPath('data.reversed_at', fn ($val) => ! empty($val));

        $this->runForTenant(function () use ($tenant, $expenseId) {
            $origEntry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_EXPENSE)
                ->where('source_id', $expenseId)
                ->first();

            $this->assertNotNull($origEntry);
            $this->assertEquals(JournalEntry::STATUS_REVERSED, $origEntry->status);

            // Reversal entry
            $revEntry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_EXPENSE_REVERSAL)
                ->where('source_id', $expenseId)
                ->first();

            $this->assertNotNull($revEntry);
            $this->assertTrue($revEntry->isBalanced());
            $this->assertEquals(7500000, (int) $revEntry->lines->sum('debit_minor'));
            $this->assertEquals(7500000, (int) $revEntry->lines->sum('credit_minor'));

            // Compensating: Cash debited back, Operating Expense credited back
            $cashDebitLine = $revEntry->lines->firstWhere('account.code', Account::CODE_CASH);
            $this->assertNotNull($cashDebitLine);
            $this->assertEquals(7500000, (int) $cashDebitLine->debit_minor);

            $expenseCreditLine = $revEntry->lines->firstWhere('account.code', Account::CODE_OPERATING_EXPENSE);
            $this->assertNotNull($expenseCreditLine);
            $this->assertEquals(7500000, (int) $expenseCreditLine->credit_minor);
        });
    }

    public function test_posted_expense_is_immutable_against_direct_modification(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $expense = $this->financeService->createExpense($tenant->id, [
                'category' => Expense::CATEGORY_UTILITIES,
                'amount_minor' => 10000000,
                'expense_date' => now()->toDateString(),
                'payment_method' => Expense::PAYMENT_METHOD_CASH,
                'status' => Expense::STATUS_POSTED,
            ], $this->manager->id);

            $this->assertEquals(Expense::STATUS_POSTED, $expense->status);

            // Directly modifying amount must throw DomainException
            try {
                $expense->amount_minor = 20000000;
                $expense->save();
                $this->fail('Expected DomainException on modifying posted expense amount.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cannot be modified', $e->getMessage());
            }

            // Deleting posted expense must throw DomainException
            try {
                $expense->delete();
                $this->fail('Expected DomainException on deleting posted expense.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }
        });
    }

    public function test_tenant_isolation_and_rls_on_expenses(): void
    {
        $tenantA = $this->tenantData['tenant'];

        // Create Tenant B
        $tenantBData = $this->createTenant('Warung Kopi Sebelah');
        $tenantB = $tenantBData['tenant'];
        $managerBData = $this->addMemberToTenant($tenantB, 'manager', 'Manager B', [$tenantBData['outlet']->id]);
        $managerB = $managerBData['user'];

        // Create expense in Tenant A
        $resA = $this->authenticateTenantUser($this->manager, $tenantA)
            ->postJson('/api/v1/finance/expenses', [
                'category' => Expense::CATEGORY_RENT,
                'amount_minor' => 100000000,
                'expense_date' => now()->toDateString(),
                'payment_method' => Expense::PAYMENT_METHOD_CASH,
            ]);
        $resA->assertStatus(201);
        $expenseAId = $resA->json('data.id');

        // Manager B cannot view Tenant A's expense
        $viewRes = $this->authenticateTenantUser($managerB, $tenantB)
            ->getJson("/api/v1/finance/expenses/{$expenseAId}");
        $viewRes->assertStatus(404);

        // Owner B (who has full finance permissions in Tenant B) cannot reverse Tenant A's expense
        $revRes = $this->authenticateTenantUser($tenantBData['owner'], $tenantB)
            ->postJson("/api/v1/finance/expenses/{$expenseAId}/reverse", ['reason' => 'Cross tenant reversal attempt']);
        $revRes->assertStatus(404);
    }
}
