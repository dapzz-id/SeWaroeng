<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Category;
use App\Models\Expense;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class FinanceEndToEndTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $cashier;

    protected User $accountant;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected FinanceService $financeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng E2E Finance');
        $tenant = $this->tenantData['tenant'];
        $this->outlet = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir E2E', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $accountantData = $this->addMemberToTenant($tenant, 'accountant', 'Akuntan E2E');
        $this->accountant = $accountantData['user'];

        $this->financeService = app(FinanceService::class);

        // Setup Kitchen, Item, Cost State, Product, Price & Recipe
        $this->runForTenant(function () use ($tenant) {
            $this->kitchenLocation = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Dapur Utama',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $this->outlet->update(['default_pos_location_id' => $this->kitchenLocation->id]);

            $this->financeService->ensureDefaultAccounts($tenant->id);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_full_finance_lifecycle_cash_and_non_cash_sales_with_expense_cogs_cashflow_and_pnl(): void
    {
        $tenant = $this->tenantData['tenant'];

        $variantCoffee = null;
        $variantTea = null;

        $this->runForTenant(function () use ($tenant, &$variantCoffee, &$variantTea) {
            // 1. Raw Materials:
            // Coffee: 1000g @ Rp 250/g (25000 minor per 100g)
            $coffeeItem = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'name' => 'Biji Kopi Premium',
                'sku' => 'RM-COF',
                'type' => 'raw_material',
                'unit' => 'g',
                'storage_unit' => 'g',
                'storage_factor' => 1.0,
                'is_active' => true,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $coffeeItem->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 1000.0,
                'allocated' => 0.0,
                'reserved' => 0.0,
            ]);

            InventoryCostState::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $coffeeItem->id,
                'location_id' => $this->kitchenLocation->id,
                'total_quantity' => 1000.0,
                'total_cost_minor' => 250000, // Rp 2.500
                'average_cost_minor' => 250, // Rp 2.5 per gram (250 minor units)
            ]);

            // Tea: 1000g @ Rp 100/g (100 minor units)
            $teaItem = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'name' => 'Daun Teh Manis',
                'sku' => 'RM-TEA',
                'type' => 'raw_material',
                'unit' => 'g',
                'storage_unit' => 'g',
                'storage_factor' => 1.0,
                'is_active' => true,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $teaItem->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 1000.0,
                'allocated' => 0.0,
                'reserved' => 0.0,
            ]);

            InventoryCostState::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $teaItem->id,
                'location_id' => $this->kitchenLocation->id,
                'total_quantity' => 1000.0,
                'total_cost_minor' => 100000,
                'average_cost_minor' => 100, // 100 minor units
            ]);

            // Category & Products
            $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Minuman', 'slug' => 'minuman']);

            // Product 1: Kopi Susu @ Rp 20.000 (2.000.000 minor)
            $prodCoffee = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Kopi Susu', 'slug' => 'kopi-susu', 'status' => 'active']);
            $variantCoffee = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prodCoffee->id, 'name' => 'Reguler', 'sku' => 'VAR-COF']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variantCoffee->id, 'outlet_id' => $this->outlet->id, 'amount' => 2000000, 'effective_from' => now()->subMinute(), 'is_active' => true]);

            $recipeCoffee = Recipe::create(['tenant_id' => $tenant->id, 'product_variant_id' => $variantCoffee->id, 'name' => 'Resep Kopi Susu', 'code' => 'RCP-COF', 'status' => 'published', 'created_by' => $this->owner->id]);
            $verCoffee = RecipeVersion::create(['tenant_id' => $tenant->id, 'recipe_id' => $recipeCoffee->id, 'version_number' => 1, 'status' => 'published', 'yield_quantity' => 1.0, 'yield_unit' => 'cup', 'effective_from' => now()->subDay(), 'created_by' => $this->owner->id]);
            RecipeComponent::create(['tenant_id' => $tenant->id, 'recipe_version_id' => $verCoffee->id, 'inventory_item_id' => $coffeeItem->id, 'quantity' => 20.0, 'unit' => 'g']); // 20g * 250 = 5000 COGS

            // Product 2: Teh Manis @ Rp 10.000 (1.000.000 minor)
            $prodTea = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Teh Manis', 'slug' => 'teh-manis', 'status' => 'active']);
            $variantTea = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prodTea->id, 'name' => 'Reguler', 'sku' => 'VAR-TEA']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variantTea->id, 'outlet_id' => $this->outlet->id, 'amount' => 1000000, 'effective_from' => now()->subMinute(), 'is_active' => true]);

            $recipeTea = Recipe::create(['tenant_id' => $tenant->id, 'product_variant_id' => $variantTea->id, 'name' => 'Resep Teh Manis', 'code' => 'RCP-TEA', 'status' => 'published', 'created_by' => $this->owner->id]);
            $verTea = RecipeVersion::create(['tenant_id' => $tenant->id, 'recipe_id' => $recipeTea->id, 'version_number' => 1, 'status' => 'published', 'yield_quantity' => 1.0, 'yield_unit' => 'cup', 'effective_from' => now()->subDay(), 'created_by' => $this->owner->id]);
            RecipeComponent::create(['tenant_id' => $tenant->id, 'recipe_version_id' => $verTea->id, 'inventory_item_id' => $teaItem->id, 'quantity' => 10.0, 'unit' => 'g']); // 10g * 100 = 1000 COGS
        });

        // 2. Open Cashier Shift with Rp 100.000 float
        $openShiftRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 10000000,
            ]);
        $openShiftRes->assertStatus(201);

        // 3. Sale 1 (Cash Sale): 2 Kopi Susu = Rp 40.000 (4.000.000 minor). COGS = 2 * 5000 = 10.000 (10000 minor)
        $sale1Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $this->outlet->id,
                'items' => [['product_variant_id' => $variantCoffee->id, 'quantity' => 2]],
            ]);
        $sale1Res->assertStatus(201);
        $sale1Id = $sale1Res->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$sale1Id}/finalize")
            ->assertStatus(200);

        $pay1Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $sale1Id,
                'amount_minor' => 4000000,
                'method' => Payment::METHOD_CASH,
            ]);
        $pay1Res->assertStatus(201);
        $payment1Id = $pay1Res->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$payment1Id}/process")
            ->assertStatus(200);

        // 4. Sale 2 (Non-Cash QRIS Sale): 3 Teh Manis = Rp 30.000 (3.000.000 minor). COGS = 3 * 1000 = 3.000 (3000 minor)
        $sale2Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $this->outlet->id,
                'items' => [['product_variant_id' => $variantTea->id, 'quantity' => 3]],
            ]);
        $sale2Res->assertStatus(201);
        $sale2Id = $sale2Res->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$sale2Id}/finalize")
            ->assertStatus(200);

        $pay2Res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $sale2Id,
                'amount_minor' => 3000000,
                'method' => Payment::METHOD_QRIS,
            ]);
        $pay2Res->assertStatus(201);
        $payment2Id = $pay2Res->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$payment2Id}/process")
            ->assertStatus(200);

        // 5. Operational Expense: Cash Expense of Rp 15.000 (1.500.000 minor)
        $expRes = $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson('/api/v1/finance/expenses', [
                'outlet_id' => $this->outlet->id,
                'category' => Expense::CATEGORY_SUPPLIES,
                'amount_minor' => 1500000,
                'expense_date' => now()->toDateString(),
                'payment_method' => Expense::PAYMENT_METHOD_CASH,
                'description' => 'Beli plastik dan sedotan ramah lingkungan',
            ]);
        $expRes->assertStatus(201);

        // 6. Verify Journals created:
        $this->runForTenant(function () use ($sale1Id, $sale2Id) {
            // Sale 1 journal: Dr Cash 4000000, Dr COGS 10000, Cr Revenue 4000000, Cr Inventory 10000
            $j1 = JournalEntry::where('source_type', JournalEntry::SOURCE_SALE)->where('source_id', $sale1Id)->first();
            $this->assertNotNull($j1);
            $this->assertTrue($j1->isBalanced());
            $this->assertEquals(4010000, (int) $j1->lines->sum('debit_minor'));

            // Sale 2 journal: Dr Bank 3000000, Dr COGS 3000, Cr Revenue 3000000, Cr Inventory 3000
            $j2 = JournalEntry::where('source_type', JournalEntry::SOURCE_SALE)->where('source_id', $sale2Id)->first();
            $this->assertNotNull($j2);
            $this->assertTrue($j2->isBalanced());
            $this->assertEquals(3003000, (int) $j2->lines->sum('debit_minor'));
        });

        // 7. Verify P&L:
        // Revenue = 4.000.000 (cash) + 3.000.000 (qris) = 7.000.000 (7000000)
        // COGS = 10.000 + 3.000 = 13.000 (13000)
        // Gross Profit = 7.000.000 - 13.000 = 6.987.000 (6987000)
        // Operating Expense = 1.500.000 (1500000)
        // Net Profit = 6.987.000 - 1.500.000 = 5.487.000 (5487000)
        $pnlRes = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/profit-loss?period=today');

        $pnlRes->assertStatus(200)
            ->assertJsonPath('data.revenue_minor', 7000000)
            ->assertJsonPath('data.cogs_minor', 13000)
            ->assertJsonPath('data.gross_profit_minor', 6987000)
            ->assertJsonPath('data.expense_minor', 1500000)
            ->assertJsonPath('data.net_profit_minor', 5487000);

        // 8. Verify Cash Flow:
        // Cash In = Cash sales only = 4.000.000 (QRIS sale does NOT count as cash)
        // Cash Out = Cash expenses = 1.500.000
        // Net Cashflow = 4.000.000 - 1.500.000 = 2.500.000 (2500000)
        $cfRes = $this->authenticateTenantUser($this->accountant, $tenant)
            ->getJson('/api/v1/finance/cash-flow?period=today');

        $cfRes->assertStatus(200)
            ->assertJsonPath('data.cash_in_minor', 4000000)
            ->assertJsonPath('data.cash_out_minor', 1500000)
            ->assertJsonPath('data.net_cashflow_minor', 2500000)
            ->assertJsonPath('data.breakdown.cash_sales_minor', 4000000)
            ->assertJsonPath('data.breakdown.cash_expenses_minor', 1500000);
    }
}
