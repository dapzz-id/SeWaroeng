<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentSucceeded;
use App\Events\SaleFinalized;
use App\Models\Account;
use App\Models\Category;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\CostingService;
use App\Services\Finance\FinanceService;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\Shift\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class FinanceJournalAndPostingTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $cashier;

    protected User $accountant;

    protected FinanceService $financeService;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected CostingService $costingService;

    protected ShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Kopi Sedap');
        $tenant = $this->tenantData['tenant'];
        $this->owner = $this->tenantData['owner'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Joko');
        $this->cashier = $cashierData['user'];

        $accountantData = $this->addMemberToTenant($tenant, 'accountant', 'Akuntan Siti');
        $this->accountant = $accountantData['user'];

        $this->financeService = app(FinanceService::class);
        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
        $this->costingService = app(CostingService::class);
        $this->shiftService = app(ShiftService::class);

        $this->runForTenant(function () use ($tenant) {
            $loc = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->tenantData['outlet']->id,
                'name' => 'Main Kitchen',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $this->tenantData['outlet']->update(['default_pos_location_id' => $loc->id]);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_chart_of_accounts_creates_default_system_accounts(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->assertArrayHasKey(Account::CODE_CASH, $accounts);
            $this->assertArrayHasKey(Account::CODE_BANK_CLEARING, $accounts);
            $this->assertArrayHasKey(Account::CODE_INVENTORY, $accounts);
            $this->assertArrayHasKey(Account::CODE_TAX_PAYABLE, $accounts);
            $this->assertArrayHasKey(Account::CODE_OWNER_EQUITY, $accounts);
            $this->assertArrayHasKey(Account::CODE_SALES_REVENUE, $accounts);
            $this->assertArrayHasKey(Account::CODE_SALES_RETURN, $accounts);
            $this->assertArrayHasKey(Account::CODE_COGS, $accounts);
            $this->assertArrayHasKey(Account::CODE_OPERATING_EXPENSE, $accounts);

            $cashAccount = $accounts[Account::CODE_CASH];
            $this->assertEquals(Account::TYPE_ASSET, $cashAccount->type);
            $this->assertTrue($cashAccount->is_system);
            $this->assertTrue($cashAccount->is_active);
        });
    }

    public function test_journal_posting_enforces_debits_equal_credits_invariant(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            // Valid balanced journal: Rp 50.000 Dr Cash, Rp 50.000 Cr Sales Revenue
            $entry = $this->financeService->postJournal($tenant->id, [
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_MANUAL,
                'description' => 'Manual Balanced Entry',
            ], [
                [
                    'account_id' => $accounts[Account::CODE_CASH]->id,
                    'debit_minor' => 5000000,
                    'credit_minor' => 0,
                ],
                [
                    'account_id' => $accounts[Account::CODE_SALES_REVENUE]->id,
                    'debit_minor' => 0,
                    'credit_minor' => 5000000,
                ],
            ]);

            $this->assertNotNull($entry);
            $this->assertEquals(JournalEntry::STATUS_POSTED, $entry->status);
            $this->assertTrue($entry->isBalanced());
            $this->assertEquals(5000000, (int) $entry->lines->sum('debit_minor'));
            $this->assertEquals(5000000, (int) $entry->lines->sum('credit_minor'));

            // Imbalanced journal: Rp 50.000 Dr vs Rp 40.000 Cr must be strictly rejected
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Double-entry invariant violated');

            $this->financeService->postJournal($tenant->id, [
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_MANUAL,
            ], [
                [
                    'account_id' => $accounts[Account::CODE_CASH]->id,
                    'debit_minor' => 5000000,
                    'credit_minor' => 0,
                ],
                [
                    'account_id' => $accounts[Account::CODE_SALES_REVENUE]->id,
                    'debit_minor' => 0,
                    'credit_minor' => 4000000,
                ],
            ]);
        });
    }

    public function test_posted_journal_entry_is_strictly_immutable(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $accounts = $this->financeService->ensureDefaultAccounts($tenant->id);

            $entry = $this->financeService->postJournal($tenant->id, [
                'entry_date' => now()->toDateString(),
                'source_type' => JournalEntry::SOURCE_MANUAL,
            ], [
                [
                    'account_id' => $accounts[Account::CODE_CASH]->id,
                    'debit_minor' => 1000000,
                    'credit_minor' => 0,
                ],
                [
                    'account_id' => $accounts[Account::CODE_SALES_REVENUE]->id,
                    'debit_minor' => 0,
                    'credit_minor' => 1000000,
                ],
            ]);

            // Attempting to update posted journal description or dates must throw
            try {
                $entry->description = 'Tampered description';
                $entry->save();
                $this->fail('Expected DomainException when modifying posted journal entry.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }

            // Attempting to delete posted journal must throw
            try {
                $entry->delete();
                $this->fail('Expected DomainException when deleting posted journal entry.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }

            // Attempting to modify line must throw
            $line = $entry->lines->first();
            try {
                $line->debit_minor = 2000000;
                $line->save();
                $this->fail('Expected DomainException when modifying posted journal line.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('cannot be modified', $e->getMessage());
            }
        });
    }

    public function test_automated_sale_posting_creates_balanced_revenue_and_cogs_journals(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];
        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            // Setup Inventory, Recipe, Catalog
            $location = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'name' => 'Gudang Utama',
                'code' => 'LOC-01',
                'type' => 'warehouse',
                'is_active' => true,
            ]);
            $outlet->update(['default_pos_location_id' => $location->id]);

            $rawItem = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'name' => 'Biji Kopi Robusta',
                'sku' => 'RM-ROB-01',
                'type' => 'raw_material',
                'unit' => 'g',
                'storage_unit' => 'g',
                'storage_factor' => 1.0,
                'is_active' => true,
                'allow_negative_stock' => false,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $rawItem->id,
                'location_id' => $location->id,
                'on_hand' => 500.0,
                'allocated' => 0.0,
                'reserved' => 0.0,
            ]);

            // Cost state: 500g @ Rp 200/g
            InventoryCostState::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $rawItem->id,
                'location_id' => $location->id,
                'total_quantity' => 500.0,
                'total_cost_minor' => 100000,
                'average_cost_minor' => 200,
            ]);

            $category = Category::create([
                'tenant_id' => $tenant->id,
                'name' => 'Minuman',
                'slug' => 'minuman',
            ]);

            $product = Product::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'name' => 'Kopi Tubruk',
                'slug' => 'kopi-tubruk',
                'status' => 'active',
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'name' => 'Reguler',
                'sku' => 'VAR-TUBRUK',
            ]);

            Price::create([
                'tenant_id' => $tenant->id,
                'variant_id' => $variant->id,
                'outlet_id' => $outlet->id,
                'amount' => 1500000, // Rp 15.000
                'effective_from' => now()->subMinute(),
                'is_active' => true,
            ]);

            $recipe = Recipe::create([
                'tenant_id' => $tenant->id,
                'product_variant_id' => $variant->id,
                'name' => 'Resep Kopi Tubruk',
                'code' => 'RCP-TUBRUK',
                'status' => 'published',
                'created_by' => $this->owner->id,
            ]);

            $version = RecipeVersion::create([
                'tenant_id' => $tenant->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'status' => 'published',
                'yield_quantity' => 1.0,
                'yield_unit' => 'cup',
                'effective_from' => now()->subDay(),
                'created_by' => $this->owner->id,
            ]);

            RecipeComponent::create([
                'tenant_id' => $tenant->id,
                'recipe_version_id' => $version->id,
                'inventory_item_id' => $rawItem->id,
                'quantity' => 20.0, // 20g @ Rp 200 = Rp 4.000 COGS per cup
                'unit' => 'g',
            ]);
        });

        // 1. Create Sale: 2 cups = Rp 30.000 total, Rp 8.000 COGS
        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 2,
                    ],
                ],
            ]);
        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('data.id');

        // 2. Finalize Sale
        $finalizeRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize");
        $finalizeRes->assertStatus(200);

        // 3. Record Succeeded Cash Payment
        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 3000000,
                'method' => Payment::METHOD_CASH,
            ]);
        $payRes->assertStatus(201);
        $paymentId = $payRes->json('data.id');

        $processRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process");
        $processRes->assertStatus(200);

        // 4. Verify Financial Journal was posted automatically
        $this->runForTenant(function () use ($tenant, $saleId) {
            $entry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->first();

            $this->assertNotNull($entry);
            $this->assertTrue($entry->isBalanced());

            // Debits: Cash 3000000 + COGS 8000 = 3008000
            // Credits: Revenue 3000000 + Inventory 8000 = 3008000
            $this->assertEquals(3008000, (int) $entry->lines->sum('debit_minor'));
            $this->assertEquals(3008000, (int) $entry->lines->sum('credit_minor'));

            $cashLine = $entry->lines->firstWhere('account.code', Account::CODE_CASH);
            $this->assertNotNull($cashLine);
            $this->assertEquals(3000000, (int) $cashLine->debit_minor);

            $revenueLine = $entry->lines->firstWhere('account.code', Account::CODE_SALES_REVENUE);
            $this->assertNotNull($revenueLine);
            $this->assertEquals(3000000, (int) $revenueLine->credit_minor);

            $cogsLine = $entry->lines->firstWhere('account.code', Account::CODE_COGS);
            $this->assertNotNull($cogsLine);
            $this->assertEquals(8000, (int) $cogsLine->debit_minor);

            $inventoryLine = $entry->lines->firstWhere('account.code', Account::CODE_INVENTORY);
            $this->assertNotNull($inventoryLine);
            $this->assertEquals(8000, (int) $inventoryLine->credit_minor);

            // Replay idempotency test
            $finalized = Sale::findOrFail($saleId);
            $replayedEntry = $this->financeService->postSale($finalized);
            $this->assertEquals($entry->id, $replayedEntry->id);
            $this->assertEquals(1, JournalEntry::where('source_type', JournalEntry::SOURCE_SALE)->where('source_id', $saleId)->count());
        });
    }

    public function test_refund_posts_compensating_sales_return_journal(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Snack', 'slug' => 'snack']);
            $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Roti Bakar', 'slug' => 'roti-bakar', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Cokelat', 'sku' => 'ROTI-CKL']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 2000000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        // 1. Create Sale
        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('data.id');

        // 2. Finalize Sale
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        // 3. Pay
        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 2000000,
                'method' => Payment::METHOD_CASH,
            ]);
        $payRes->assertStatus(201);
        $paymentId = $payRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process")
            ->assertStatus(200);

        // 4. Refund Rp 10.000 (issued by authorized manager/owner)
        $refundRes = $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/refund", [
                'amount_minor' => 1000000,
                'reason' => 'Kelebihan bayar',
            ]);
        $refundRes->assertStatus(201);
        $refundId = $refundRes->json('data.id');

        // 5. Verify Refund Journal was posted
        $this->runForTenant(function () use ($tenant, $refundId) {
            $refundEntry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_REFUND)
                ->where('source_id', $refundId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->first();

            $this->assertNotNull($refundEntry);
            $this->assertTrue($refundEntry->isBalanced());
            $this->assertEquals(1000000, (int) $refundEntry->lines->sum('debit_minor'));
            $this->assertEquals(1000000, (int) $refundEntry->lines->sum('credit_minor'));

            $returnLine = $refundEntry->lines->firstWhere('account.code', Account::CODE_SALES_RETURN);
            $this->assertNotNull($returnLine);
            $this->assertEquals(1000000, (int) $returnLine->debit_minor);

            $cashLine = $refundEntry->lines->firstWhere('account.code', Account::CODE_CASH);
            $this->assertNotNull($cashLine);
            $this->assertEquals(1000000, (int) $cashLine->credit_minor);
        });
    }

    public function test_sale_finalized_and_payment_succeeded_posts_single_revenue_journal(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Kopi', 'slug' => 'kopi']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Americano', 'slug' => 'americano', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Iced', 'sku' => 'AME-ICE']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 2500000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        // Flow 1: Normal Sale Finalized -> Cash Payment Succeeded
        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 2500000,
                'method' => Payment::METHOD_CASH,
            ]);
        $paymentId = $payRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process")
            ->assertStatus(200);

        // Assert exactly ONE revenue journal exists
        $this->runForTenant(function () use ($tenant, $saleId) {
            $journalCount = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->count();
            $this->assertEquals(1, $journalCount);

            $journal = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->first();
            $revLine = $journal->lines->firstWhere('account.code', Account::CODE_SALES_REVENUE);
            $this->assertEquals(2500000, (int) $revLine->credit_minor);
        });

        // Flow 2: Reverse event arrival order simulation
        // Payment succeeded while Sale is not yet finalized (or arrived early)
        $sale2Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $sale2Id = $sale2Res->json('data.id');

        $this->runForTenant(function () use ($tenant, $sale2Id) {
            $sale2 = Sale::findOrFail($sale2Id);
            $paymentEarly = Payment::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale2->id,
                'reference_number' => 'PAY-EARLY-01',
                'method' => Payment::METHOD_CASH,
                'provider' => 'manual',
                'amount_minor' => 2500000,
                'currency' => 'IDR',
                'status' => Payment::STATUS_SUCCEEDED,
                'paid_at' => now(),
            ]);

            // Dispatch PaymentSucceeded while sale is still draft/open
            PaymentSucceeded::dispatch($paymentEarly);

            // Assert NO journal posted because sale is not finalized yet
            $countBeforeFinalize = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $sale2->id)
                ->count();
            $this->assertEquals(0, $countBeforeFinalize);
        });

        // Now finalize Sale 2
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$sale2Id}/finalize")
            ->assertStatus(200);

        // Assert exactly ONE revenue journal posted now
        $this->runForTenant(function () use ($tenant, $sale2Id) {
            $countAfterFinalize = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $sale2Id)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->count();
            $this->assertEquals(1, $countAfterFinalize);
        });
    }

    public function test_event_replay_does_not_duplicate_revenue_or_cogs_journals(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Makanan', 'slug' => 'makanan']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Nasi Goreng', 'slug' => 'nasi-goreng', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Spesial', 'sku' => 'NASGOR-SP']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 3500000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 3500000,
                'method' => Payment::METHOD_CASH,
            ]);
        $paymentId = $payRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process")
            ->assertStatus(200);

        // Replay events 3 times
        $this->runForTenant(function () use ($tenant, $saleId, $paymentId) {
            $sale = Sale::findOrFail($saleId);
            $payment = Payment::findOrFail($paymentId);

            SaleFinalized::dispatch($sale);
            SaleFinalized::dispatch($sale);
            SaleFinalized::dispatch($sale);

            PaymentSucceeded::dispatch($payment);
            PaymentSucceeded::dispatch($payment);
            PaymentSucceeded::dispatch($payment);

            // Exactly 1 journal entry exists
            $journals = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->get();

            $this->assertCount(1, $journals);

            // Exactly 1 revenue credit line of 3500000
            $revLines = $journals->first()->lines->where('account.code', Account::CODE_SALES_REVENUE);
            $this->assertCount(1, $revLines);
            $this->assertEquals(3500000, (int) $revLines->first()->credit_minor);
        });
    }

    public function test_split_payment_creates_single_balanced_revenue_journal(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Menu', 'slug' => 'menu']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Paket Hemat', 'slug' => 'paket-hemat', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Porsi', 'sku' => 'PKT-01']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 10000000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        // 1. Sale = Rp 100.000
        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        // 2. Payment 1: Cash = Rp 40.000
        $pay1Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 4000000,
                'method' => Payment::METHOD_CASH,
            ]);
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$pay1Res->json('data.id')}/process")
            ->assertStatus(200);

        // Assert: not fully settled yet, so no journal posted yet
        $this->runForTenant(function () use ($tenant, $saleId) {
            $this->assertEquals(0, JournalEntry::where('tenant_id', $tenant->id)->where('source_id', $saleId)->count());
        });

        // 3. Payment 2: QRIS / Bank Transfer = Rp 60.000
        $pay2Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 6000000,
                'method' => 'qris',
            ]);
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$pay2Res->json('data.id')}/process")
            ->assertStatus(200);

        // 4. Fully settled: assert single balanced journal entry
        $this->runForTenant(function () use ($tenant, $saleId) {
            $entry = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->first();

            $this->assertNotNull($entry);
            $this->assertTrue($entry->isBalanced());

            // Debit Cash = 40.000, Debit Bank Clearing = 60.000
            $cashLine = $entry->lines->firstWhere('account.code', Account::CODE_CASH);
            $bankLine = $entry->lines->firstWhere('account.code', Account::CODE_BANK_CLEARING);
            $revLine = $entry->lines->firstWhere('account.code', Account::CODE_SALES_REVENUE);

            $this->assertEquals(4000000, (int) $cashLine->debit_minor);
            $this->assertEquals(6000000, (int) $bankLine->debit_minor);
            $this->assertEquals(10000000, (int) $revLine->credit_minor);
            $this->assertEquals(10000000, (int) $entry->lines->sum('debit_minor'));
            $this->assertEquals(10000000, (int) $entry->lines->sum('credit_minor'));
        });
    }

    public function test_pending_or_failed_payment_does_not_post_revenue_journal(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Minum', 'slug' => 'minum']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Teh Manis', 'slug' => 'teh-manis', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Dingin', 'sku' => 'TEH-DGN']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 500000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        // Pending payment created
        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 500000,
                'method' => 'qris',
            ]);
        $payRes->assertStatus(201);
        $paymentId = $payRes->json('data.id');

        // While pending: no journal
        $this->runForTenant(function () use ($tenant, $saleId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertNull($this->financeService->postSale($sale));
            $this->assertEquals(0, JournalEntry::where('tenant_id', $tenant->id)->where('source_id', $saleId)->count());
        });

        // Mark payment failed
        $this->runForTenant(function () use ($tenant, $saleId, $paymentId) {
            $payment = Payment::findOrFail($paymentId);
            $payment->update(['status' => Payment::STATUS_FAILED]);

            $sale = Sale::findOrFail($saleId);
            $this->assertNull($this->financeService->postSale($sale));
            $this->assertEquals(0, JournalEntry::where('tenant_id', $tenant->id)->where('source_id', $saleId)->count());
        });
    }

    public function test_multiple_partial_refunds_and_replay_protection(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Kopi', 'slug' => 'kopi']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Latte', 'slug' => 'latte', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Hot', 'sku' => 'LATTE-HOT']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 10000000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 10000000,
                'method' => Payment::METHOD_CASH,
            ]);
        $paymentId = $payRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process")
            ->assertStatus(200);

        // Refund 1: Rp 30.000
        $ref1Res = $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/refund", ['amount_minor' => 3000000, 'reason' => 'Refund 1']);
        $ref1Res->assertStatus(201);
        $ref1Id = $ref1Res->json('data.id');

        // Refund 2: Rp 20.000
        $ref2Res = $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/refund", ['amount_minor' => 2000000, 'reason' => 'Refund 2']);
        $ref2Res->assertStatus(201);
        $ref2Id = $ref2Res->json('data.id');

        // Refund 3: Rp 50.000
        $ref3Res = $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/refund", ['amount_minor' => 5000000, 'reason' => 'Refund 3']);
        $ref3Res->assertStatus(201);
        $ref3Id = $ref3Res->json('data.id');

        // Replay Refund 1 three times
        $this->runForTenant(function () use ($tenant, $ref1Id) {
            $ref1 = PaymentRefund::findOrFail($ref1Id);
            $this->financeService->postRefund($ref1);
            $this->financeService->postRefund($ref1);
            $this->financeService->postRefund($ref1);

            $this->assertEquals(1, JournalEntry::where('tenant_id', $tenant->id)->where('source_type', JournalEntry::SOURCE_REFUND)->where('source_id', $ref1Id)->count());
        });

        // Verify total refund journals and amounts
        $this->runForTenant(function () use ($tenant) {
            $refundJournals = JournalEntry::where('tenant_id', $tenant->id)
                ->where('source_type', JournalEntry::SOURCE_REFUND)
                ->get();

            $this->assertCount(3, $refundJournals);

            // Total sales return debit equals 100.000 (30.000 + 20.000 + 50.000)
            $totalSalesReturn = 0;
            foreach ($refundJournals as $rj) {
                $retLine = $rj->lines->firstWhere('account.code', Account::CODE_SALES_RETURN);
                $totalSalesReturn += (int) $retLine->debit_minor;
            }
            $this->assertEquals(10000000, $totalSalesReturn);
        });
    }

    public function test_cash_movement_reconciliation_between_shift_and_finance(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Waroeng', 'slug' => 'waroeng']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Soto Ayam', 'slug' => 'soto-ayam', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Porsi', 'sku' => 'SOTO-01']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 3600000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        // 1. Shift Opening Float = Rp 100.000
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($outlet, 10000000, $this->cashier));

        // 2. Cash Sale = Rp 36.000
        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');
        $this->authenticateTenantUser($this->cashier, $tenant)->postJson("/api/v1/pos/sales/{$saleId}/finalize")->assertStatus(200);

        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 3600000,
                'method' => Payment::METHOD_CASH,
            ]);
        $paymentId = $payRes->json('data.id');
        $this->authenticateTenantUser($this->cashier, $tenant)->postJson("/api/v1/payments/{$paymentId}/process")->assertStatus(200);

        // 3. Cash In = Rp 10.000
        $this->actingAs($this->cashier)->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-in", [
                'amount_minor' => 1000000,
                'reason' => 'Tambah kas receh',
            ])->assertStatus(201);

        // 4. Cash Out = Rp 5.000
        $this->actingAs($this->cashier)->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-out", [
                'amount_minor' => 500000,
                'reason' => 'Beli es batu warung',
            ])->assertStatus(201);

        // 5. Cash Refund = Rp 4.000
        $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/refund", [
                'amount_minor' => 400000,
                'reason' => 'Diskon susulan',
            ])->assertStatus(201);

        // 6. Verify Shift Summary
        $this->runForTenant(function () use ($shift, $tenant, $outlet) {
            $shiftSummary = $this->shiftService->getCashSummary($shift->fresh());

            $this->assertEquals(10000000, $shiftSummary['opening_cash']);
            $this->assertEquals(3600000, $shiftSummary['cash_sales']);
            $this->assertEquals(1000000, $shiftSummary['cash_in']);
            $this->assertEquals(500000, $shiftSummary['cash_out']);
            $this->assertEquals(400000, $shiftSummary['cash_refund']);

            // Expected closing cash = 100.000 + 36.000 + 10.000 - 5.000 - 4.000 = 137.000
            $expectedPhysicalCash = 10000000 + 3600000 + 1000000 - 500000 - 400000;
            $this->assertEquals($expectedPhysicalCash, $shiftSummary['expected_closing']);
            $this->assertEquals(13700000, $shiftSummary['expected_closing']);

            // 7. Verify Finance Cash Flow Report
            $cashFlow = $this->financeService->getCashFlow(
                $tenant->id,
                $outlet->id,
                now()->startOfDay(),
                now()->endOfDay()
            );

            // Cash In = Cash Sales (36.000) + Other Cash In (10.000) = 46.000
            $this->assertEquals(4600000, $cashFlow['cash_in_minor']);
            $this->assertEquals(3600000, $cashFlow['breakdown']['cash_sales_minor']);
            $this->assertEquals(1000000, $cashFlow['breakdown']['other_cash_in_minor']);

            // Cash Out = Cash Refunds (4.000) + Other Cash Out (5.000) = 9.000
            $this->assertEquals(900000, $cashFlow['cash_out_minor']);
            $this->assertEquals(400000, $cashFlow['breakdown']['cash_refunds_minor']);
            $this->assertEquals(500000, $cashFlow['breakdown']['other_cash_out_minor']);

            // Net Cash Movement = 46.000 - 9.000 = +37.000
            $this->assertEquals(3700000, $cashFlow['net_cashflow_minor']);

            // Exact reconciliation: Opening Drawer Float + Net Cash Movement === Expected Drawer Cash
            $this->assertEquals(
                $shiftSummary['expected_closing'],
                $shiftSummary['opening_cash'] + $cashFlow['net_cashflow_minor']
            );

            // 8. Verify P&L report is NOT inflated by opening float or drawer cash-in
            $pnl = $this->financeService->getProfitAndLoss(
                $tenant->id,
                $outlet->id,
                now()->startOfDay(),
                now()->endOfDay()
            );

            // Net Revenue = Gross (36.000) - Sales Return (4.000) = 32.000
            $this->assertEquals(3200000, $pnl['revenue_minor']);
            $this->assertEquals(0, $pnl['expense_minor']); // Cash-out was drawer withdrawal, not operating expense
        });
    }

    public function test_non_cash_payments_do_not_distort_physical_cash_flow(): void
    {
        $tenant = $this->tenantData['tenant'];
        $outlet = $this->tenantData['outlet'];

        $variant = null;
        $this->runForTenant(function () use ($tenant, $outlet, &$variant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Minuman', 'slug' => 'minuman']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Jus Alpukat', 'slug' => 'jus-alpukat', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Gelas', 'sku' => 'JUS-ALP']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'amount' => 9500000, 'effective_from' => now()->subMinute(), 'is_active' => true]);
        });

        // Total Sale = Rp 95.000
        $saleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            ]);
        $saleId = $saleRes->json('data.id');
        $this->authenticateTenantUser($this->cashier, $tenant)->postJson("/api/v1/pos/sales/{$saleId}/finalize")->assertStatus(200);

        // QRIS = 50.000
        $pay1 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', ['sale_id' => $saleId, 'amount_minor' => 5000000, 'method' => 'qris']);
        $this->authenticateTenantUser($this->cashier, $tenant)->postJson("/api/v1/payments/{$pay1->json('data.id')}/process")->assertStatus(200);

        // Bank Transfer (non-cash) = 25.000
        $pay2 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', ['sale_id' => $saleId, 'amount_minor' => 2500000, 'method' => 'bank_transfer']);
        $this->authenticateTenantUser($this->cashier, $tenant)->postJson("/api/v1/payments/{$pay2->json('data.id')}/process")->assertStatus(200);

        // Cash = 20.000
        $pay3 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', ['sale_id' => $saleId, 'amount_minor' => 2000000, 'method' => Payment::METHOD_CASH]);
        $this->authenticateTenantUser($this->cashier, $tenant)->postJson("/api/v1/payments/{$pay3->json('data.id')}/process")->assertStatus(200);

        // Verify: Physical Cash Flow report only reports 20.000, NOT 95.000
        $this->runForTenant(function () use ($tenant, $outlet) {
            $cashFlow = $this->financeService->getCashFlow(
                $tenant->id,
                $outlet->id,
                now()->startOfDay(),
                now()->endOfDay()
            );

            $this->assertEquals(2000000, $cashFlow['cash_in_minor']);
            $this->assertEquals(2000000, $cashFlow['breakdown']['cash_sales_minor']);
            $this->assertEquals(0, $cashFlow['cash_out_minor']);
            $this->assertEquals(2000000, $cashFlow['net_cashflow_minor']);
        });
    }
}
