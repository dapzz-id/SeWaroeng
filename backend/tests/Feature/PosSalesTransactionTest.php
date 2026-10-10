<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Category;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\SaleConsumption;
use App\Models\User;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PosService;
use App\Services\PurchasingService;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PosSalesTransactionTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $staff;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected PosService $posService;

    protected InventoryService $inventoryService;

    protected CostingService $costingService;

    protected RecipeService $recipeService;

    protected PurchasingService $purchasingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Sejahtera');
        $this->outlet = $this->tenantData['outlet'];

        // Create Cashier user
        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Budi Kasir', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        // Create Staff user (no POS permissions)
        $staffData = $this->addMemberToTenant($this->tenantData['tenant'], 'staff', 'Siti Staff', [$this->outlet->id]);
        $this->staff = $staffData['user'];

        $this->posService = app(PosService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->costingService = app(CostingService::class);
        $this->recipeService = app(RecipeService::class);
        $this->purchasingService = app(PurchasingService::class);

        // Setup kitchen location linked to outlet and configure as default POS location
        $this->kitchenLocation = $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Main Kitchen',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);

            $this->outlet->update(['default_pos_location_id' => $loc->id]);

            return $loc;
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    /**
     * Helper to create a product with default variant and price.
     */
    protected function createProductWithPrice(
        string $productName,
        string $variantName,
        int $priceMinor,
        ?string $outletId = null
    ): array {
        return $this->runForTenant(function () use ($productName, $variantName, $priceMinor, $outletId) {
            $category = Category::firstOrCreate(
                ['tenant_id' => $this->tenantData['tenant']->id, 'slug' => 'food'],
                ['name' => 'Food', 'is_active' => true]
            );

            $product = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $category->id,
                'name' => $productName,
                'slug' => Str::slug($productName).'-'.uniqid(),
                'status' => 'active',
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $product->id,
                'name' => $variantName,
                'sku' => 'SKU-'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'status' => 'active',
                'is_default' => true,
            ]);

            $price = Price::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'variant_id' => $variant->id,
                'outlet_id' => $outletId,
                'amount' => $priceMinor,
                'currency' => 'IDR',
                'effective_from' => now()->subMinute(),
                'is_active' => true,
            ]);

            return ['product' => $product, 'variant' => $variant, 'price' => $price];
        });
    }

    /**
     * Helper to create inventory item with opening balance and MWA.
     */
    protected function createInventoryWithStock(
        string $name,
        string $sku,
        string $unit,
        float $quantity,
        int $costMinor,
        bool $allowNegativeStock = false
    ): InventoryItem {
        return $this->runForTenant(function () use ($name, $sku, $unit, $quantity, $costMinor, $allowNegativeStock) {
            $item = InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => $name,
                'sku' => $sku,
                'type' => 'raw_material',
                'unit' => $unit,
                'storage_unit' => $unit,
                'storage_factor' => 1.0,
                'is_active' => true,
                'allow_negative_stock' => $allowNegativeStock,
            ]);

            if ($quantity > 0) {
                $this->inventoryService->setOpeningBalance(
                    tenantId: $this->tenantData['tenant']->id,
                    inventoryItemId: $item->id,
                    locationId: $this->kitchenLocation->id,
                    quantity: $quantity,
                    userId: $this->tenantData['owner']->id,
                    unitCostMinor: $costMinor,
                );
            }

            return $item;
        });
    }

    // =========================================================================
    // 1. SALE LIFECYCLE & CART MANAGEMENT
    // =========================================================================

    public function test_cashier_can_create_and_update_open_sale(): void
    {
        $prod = $this->createProductWithPrice('Nasi Goreng Spesial', 'Regular', 25000);

        $payload = [
            'outlet_id' => $this->outlet->id,
            'notes' => 'Table 5',
            'items' => [
                [
                    'product_variant_id' => $prod['variant']->id,
                    'quantity' => 2,
                    'notes' => 'Pedas level 3',
                ],
            ],
        ];

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/pos/sales', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.subtotal_minor', 50000);
        $response->assertJsonPath('data.total_minor', 50000);
        $this->assertCount(1, $response->json('data.items'));
        $saleId = $response->json('data.id');

        // Update cart: add discount & change notes
        $updateResponse = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->putJson("/api/v1/pos/sales/{$saleId}", [
                'notes' => 'Table 5 - Takeaway',
                'discount_minor' => 5000,
                'items' => [
                    [
                        'product_variant_id' => $prod['variant']->id,
                        'quantity' => 3,
                    ],
                ],
            ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.subtotal_minor', 75000);
        $updateResponse->assertJsonPath('data.discount_minor', 5000);
        $updateResponse->assertJsonPath('data.total_minor', 70000);
    }

    // =========================================================================
    // 2. PRICE SNAPSHOT & PRICE PRECEDENCE
    // =========================================================================

    public function test_finalization_fails_when_price_not_configured_never_fallback_to_zero(): void
    {
        $prod = $this->runForTenant(function () {
            $cat = Category::firstOrCreate(['tenant_id' => $this->tenantData['tenant']->id, 'slug' => 'food'], ['name' => 'Food']);
            $product = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $cat->id,
                'name' => 'Menu Tanpa Harga',
                'slug' => 'menu-tanpa-harga-'.uniqid(),
                'status' => 'active',
            ]);
            $variant = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $product->id,
                'name' => 'Standard',
                'sku' => 'SKU-NOPRICE',
                'status' => 'active',
            ]);

            return ['variant' => $variant];
        });

        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'PRICE_NOT_CONFIGURED');
    }

    public function test_outlet_specific_price_override_is_respected_at_finalization(): void
    {
        $prod = $this->createProductWithPrice('Es Teh Manis', 'Regular', 5000); // Tenant default 5.000

        // Create outlet override: 6.500
        $this->runForTenant(function () use ($prod) {
            Price::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'variant_id' => $prod['variant']->id,
                'outlet_id' => $this->outlet->id,
                'amount' => 6500,
                'currency' => 'IDR',
                'effective_from' => now()->subMinute(),
                'is_active' => true,
            ]);
        });

        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 2],
                ],
            ], $this->cashier->id);
        });

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'finalized');
        $response->assertJsonPath('data.items.0.unit_price_minor', 6500);
        $response->assertJsonPath('data.subtotal_minor', 13000);
        $response->assertJsonPath('data.total_minor', 13000);
    }

    // =========================================================================
    // 3. MODIFIERS SNAPSHOT & INTEGRATION
    // =========================================================================

    public function test_modifier_selection_and_price_snapshot(): void
    {
        $prod = $this->createProductWithPrice('Kopi Susu Gula Aren', 'Regular', 18000);

        // Add Modifier Group & Modifier
        $modifier = $this->runForTenant(function () use ($prod) {
            $group = ModifierGroup::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Topping',
                'selection_mode' => 'multiple',
                'min_selection' => 0,
                'max_selection' => 3,
                'is_required' => false,
                'status' => 'active',
            ]);
            $group->products()->attach($prod['product']->id, [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenantData['tenant']->id,
            ]);

            return Modifier::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'modifier_group_id' => $group->id,
                'name' => 'Extra Shot Espresso',
                'price_delta' => 4000,
                'status' => 'active',
            ]);
        });

        $sale = $this->runForTenant(function () use ($prod, $modifier) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    [
                        'product_variant_id' => $prod['variant']->id,
                        'quantity' => 2,
                        'modifiers' => [
                            ['modifier_id' => $modifier->id, 'quantity' => 1],
                        ],
                    ],
                ],
            ], $this->cashier->id);
        });

        // Subtotal = (18000 + 4000) * 2 = 44000
        $this->assertEquals(44000, $sale->subtotal_minor);

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(200);
        $response->assertJsonPath('data.items.0.modifiers.0.modifier_name_snapshot', 'Extra Shot Espresso');
        $response->assertJsonPath('data.items.0.modifiers.0.unit_price_adjustment_minor', 4000);
        $response->assertJsonPath('data.items.0.total_minor', 44000);

        // Later catalog modification does NOT mutate finalized snapshot
        $this->runForTenant(function () use ($modifier) {
            $modifier->update(['name' => 'Renamed Shot', 'price_delta' => 8000]);
        });

        $reloaded = $this->runForTenant(fn () => $this->posService->getSale($sale->id));
        $this->assertEquals('Extra Shot Espresso', $reloaded->items->first()->modifiers->first()->modifier_name_snapshot);
        $this->assertEquals(4000, $reloaded->items->first()->modifiers->first()->unit_price_adjustment_minor);
        $this->assertEquals(44000, $reloaded->total_minor);
    }

    // =========================================================================
    // 4. RECIPE / NESTED BOM EXPLOSION & INVENTORY CONSUMPTION
    // =========================================================================

    public function test_recipe_resolution_nested_bom_explosion_and_snapshot(): void
    {
        $prod = $this->createProductWithPrice('Chicken Rice Bowl', 'Standard', 35000);

        // Raw materials
        $rice = $this->createInventoryWithStock('Beras Pandan Wangi', 'RAW-RICE', 'g', 10000, 15); // Rp15/g
        $chicken = $this->createInventoryWithStock('Daging Ayam', 'RAW-CHKN', 'g', 5000, 40); // Rp40/g
        $chili = $this->createInventoryWithStock('Cabai Rawit', 'RAW-CHLI', 'g', 2000, 50); // Rp50/g
        $garlic = $this->createInventoryWithStock('Bawang Putih', 'RAW-GRLC', 'g', 2000, 30); // Rp30/g
        $cup = $this->createInventoryWithStock('Paper Bowl 650ml', 'PKG-BOWL', 'pcs', 100, 1200); // Rp1200/pcs

        // Semi-finished item: Sambal Pedas (configured as make_to_order)
        $sambal = $this->runForTenant(function () {
            return InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Sambal Pedas',
                'sku' => 'SEMI-SAMBAL',
                'item_type' => 'semi_finished',
                'production_mode' => 'make_to_order',
                'base_unit' => 'g',
                'status' => 'active',
            ]);
        });

        // Sambal Recipe: 20g chili + 10g garlic = 30g sambal
        $this->runForTenant(function () use ($sambal, $chili, $garlic) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Sambal Pedas',
                'code' => 'RCP-SAMBAL',
                'inventory_item_id' => $sambal->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $ver = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'yield_quantity' => 30.0,
                'yield_unit' => 'g',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $ver->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $chili->id,
                'quantity' => 20.0,
                'unit' => 'g',
                'waste_factor' => 0.0,
            ]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $garlic->id,
                'quantity' => 10.0,
                'unit' => 'g',
                'waste_factor' => 0.0,
            ]);
        });

        // Chicken Rice Bowl Recipe: 200g Rice, 150g Chicken, 30g Sambal, 1pcs Bowl
        $this->runForTenant(function () use ($prod, $rice, $chicken, $sambal, $cup) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Chicken Rice Bowl',
                'code' => 'RCP-CRB',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $ver = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $ver->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $rice->id,
                'quantity' => 200.0,
                'unit' => 'g',
            ]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $chicken->id,
                'quantity' => 150.0,
                'unit' => 'g',
            ]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $sambal->id,
                'quantity' => 30.0,
                'unit' => 'g',
            ]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $cup->id,
                'quantity' => 1.0,
                'unit' => 'pcs',
            ]);
        });

        // Sale: 2 Portions of Chicken Rice Bowl
        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 2],
                ],
            ], $this->cashier->id);
        });

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'finalized');

        // Check exploded nested leaf consumptions:
        // Rice = 200g * 2 = 400g
        // Chicken = 150g * 2 = 300g
        // Chili (from Sambal) = (20g / 30g yield) * 30g required * 2 = 40g
        // Garlic (from Sambal) = (10g / 30g yield) * 30g required * 2 = 20g
        // Cup = 1 * 2 = 2 pcs
        $consumptions = $this->runForTenant(fn () => SaleConsumption::where('sale_id', $sale->id)->get());
        $this->assertCount(5, $consumptions);

        $riceCons = $consumptions->firstWhere('inventory_item_id', $rice->id);
        $this->assertEquals(400.0, (float) $riceCons->consumed_quantity);
        $this->assertEquals(15, $riceCons->unit_cost_minor);
        $this->assertEquals(6000, $riceCons->total_cost_minor);

        $chickenCons = $consumptions->firstWhere('inventory_item_id', $chicken->id);
        $this->assertEquals(300.0, (float) $chickenCons->consumed_quantity);
        $this->assertEquals(40, $chickenCons->unit_cost_minor);
        $this->assertEquals(12000, $chickenCons->total_cost_minor);

        $chiliCons = $consumptions->firstWhere('inventory_item_id', $chili->id);
        $this->assertEqualsWithDelta(40.0, (float) $chiliCons->consumed_quantity, 0.001);

        $garlicCons = $consumptions->firstWhere('inventory_item_id', $garlic->id);
        $this->assertEqualsWithDelta(20.0, (float) $garlicCons->consumed_quantity, 0.001);

        $cupCons = $consumptions->firstWhere('inventory_item_id', $cup->id);
        $this->assertEquals(2.0, (float) $cupCons->consumed_quantity);

        // Verify physical inventory movements created
        $movements = $this->runForTenant(fn () => InventoryMovement::where('reference_id', $sale->id)->get());
        $this->assertCount(5, $movements);
        foreach ($movements as $m) {
            $this->assertEquals('sales_consumption', $m->movement_type);
            $this->assertLessThan(0, (float) $m->quantity_delta); // Outbound is negative
        }

        // Verify remaining physical stock in location
        $riceLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $rice->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertEquals(9600.0, (float) $riceLoc->on_hand); // 10000 - 400
    }

    public function test_inventory_availability_aggregation_for_duplicate_components(): void
    {
        // 2 different products that both consume the same Coffee Bean raw material
        $prodA = $this->createProductWithPrice('Espresso', 'Single', 15000);
        $prodB = $this->createProductWithPrice('Americano', 'Standard', 20000);

        // Coffee Beans: only 30g in stock!
        $coffee = $this->createInventoryWithStock('Biji Kopi Arabika', 'RAW-COFFEE', 'g', 30.0, 100);

        // Espresso needs 18g
        $this->runForTenant(function () use ($prodA, $coffee) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Espresso',
                'code' => 'RCP-ESP',
                'product_variant_id' => $prodA['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $ver = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $ver->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $coffee->id,
                'quantity' => 18.0,
                'unit' => 'g',
            ]);
        });

        // Americano also needs 18g
        $this->runForTenant(function () use ($prodB, $coffee) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Americano',
                'code' => 'RCP-AME',
                'product_variant_id' => $prodB['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $ver = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $ver->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $coffee->id,
                'quantity' => 18.0,
                'unit' => 'g',
            ]);
        });

        // Cart with 1 Espresso (18g) + 1 Americano (18g) = 36g total required > 30g stock
        $sale = $this->runForTenant(function () use ($prodA, $prodB) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prodA['variant']->id, 'quantity' => 1],
                    ['product_variant_id' => $prodB['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        // Verify atomic rollback: no movements, stock untouched
        $coffeeLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $coffee->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertEquals(30.0, (float) $coffeeLoc->on_hand);
        $this->assertEquals(0, $this->runForTenant(fn () => InventoryMovement::where('reference_id', $sale->id)->count()));
    }

    public function test_negative_stock_allowed_when_item_policy_permits(): void
    {
        $prod = $this->createProductWithPrice('Kopi O', 'Regular', 10000);
        // Zero stock, but allow_negative_stock = true
        $coffee = $this->createInventoryWithStock('Kopi Tubruk Raw', 'RAW-TUBRUK', 'g', 0.0, 50, allowNegativeStock: true);

        $this->runForTenant(function () use ($prod, $coffee) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Kopi Tubruk',
                'code' => 'RCP-TUBRUK',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $ver = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $ver->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $coffee->id,
                'quantity' => 15.0,
                'unit' => 'g',
            ]);
        });

        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'finalized');

        // Location stock should now be -15.0
        $coffeeLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $coffee->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertEquals(-15.0, (float) $coffeeLoc->on_hand);
    }

    // =========================================================================
    // 5. CONCURRENCY & RACE CONDITIONS (Section 31)
    // =========================================================================

    public function test_concurrent_finalization_exactly_one_succeeds(): void
    {
        $prod = $this->createProductWithPrice('Limited Cheesecake', 'Slice', 45000);
        // Only 1 slice in stock!
        $cake = $this->createInventoryWithStock('Cheesecake Slice Inventory', 'CAKE-01', 'pcs', 1.0, 20000);

        $this->runForTenant(function () use ($prod, $cake) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Cheesecake Slice',
                'code' => 'RCP-CAKE',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $ver = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'pcs',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $ver->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $ver->id,
                'inventory_item_id' => $cake->id,
                'quantity' => 1.0,
                'unit' => 'pcs',
            ]);
        });

        // Cashier 1 creates Sale A for 1 slice
        $saleA = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Cashier 2 creates Sale B for 1 slice
        $saleB = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Finalize Sale A
        $resA = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$saleA->id}/finalize");

        $resA->assertStatus(200);
        $resA->assertJsonPath('data.status', 'finalized');

        // Finalize Sale B immediately after -> MUST fail with INSUFFICIENT_STOCK
        $resB = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$saleB->id}/finalize");

        $resB->assertStatus(422);
        $resB->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        // Verify exactly 1 movement and stock is 0.0
        $this->assertEquals(1, $this->runForTenant(fn () => InventoryMovement::where('movement_type', 'sales_consumption')->count()));
        $cakeLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $cake->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertEquals(0.0, (float) $cakeLoc->on_hand);
    }

    // =========================================================================
    // 6. HISTORICAL SNAPSHOT IMMUTABILITY (Section 32)
    // =========================================================================

    public function test_historical_snapshot_remains_unchanged_after_day2_updates(): void
    {
        // Day 1:
        // Selling price = Rp15.000
        // Recipe v1: 100g Rice
        // MWA = Rp8.000/kg => Rp8/g
        $prod = $this->createProductWithPrice('Nasi Putih Day 1', 'Portion', 15000);
        $rice = $this->createInventoryWithStock('Beras Day 1', 'RICE-D1', 'g', 10000, 8); // Rp8/g

        $recipe = $this->runForTenant(function () use ($prod, $rice) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Nasi Putih',
                'code' => 'RCP-NASI-D1',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v1 = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDays(2),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v1->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v1->id,
                'inventory_item_id' => $rice->id,
                'quantity' => 100.0,
                'unit' => 'g',
            ]);

            return $r;
        });

        // Finalize Sale A on Day 1
        $saleA = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$saleA->id}/finalize")
            ->assertStatus(200);

        // Verify Day 1 Sale A
        $saleADay1 = $this->runForTenant(fn () => $this->posService->getSale($saleA->id));
        $this->assertEquals(15000, $saleADay1->total_minor);
        $this->assertEquals(1, $saleADay1->items->first()->recipe_snapshot['version_number']);
        $this->assertEquals(8, $saleADay1->consumptions->first()->unit_cost_minor);
        $this->assertEquals(800, $saleADay1->consumptions->first()->total_cost_minor); // 100g * Rp8

        // Day 2 Changes:
        // 1. Price increases to Rp17.000
        $this->runForTenant(function () use ($prod) {
            $prod['price']->update(['is_active' => false]);
            Price::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'variant_id' => $prod['variant']->id,
                'outlet_id' => null,
                'amount' => 17000,
                'currency' => 'IDR',
                'effective_from' => now()->subMinute(),
                'is_active' => true,
            ]);
        });

        // 2. Recipe v2: 120g Rice
        $this->runForTenant(function () use ($recipe, $rice) {
            RecipeVersion::where('recipe_id', $recipe->id)
                ->where('version_number', 1)
                ->update(['effective_until' => now()->subHours(2)]);

            $v2 = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 2,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subHour(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $recipe->update(['active_version_id' => $v2->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v2->id,
                'inventory_item_id' => $rice->id,
                'quantity' => 120.0,
                'unit' => 'g',
            ]);
        });

        // 3. Inbound stock adjustment changes MWA to Rp10/g
        $this->runForTenant(function () use ($rice) {
            $this->inventoryService->recordMovement(
                tenantId: $this->tenantData['tenant']->id,
                inventoryItemId: $rice->id,
                locationId: $this->kitchenLocation->id,
                movementType: 'adjustment_in',
                delta: 10000.0,
                unit: 'g',
                referenceType: 'StockAdjustment',
                referenceId: (string) Str::uuid7(),
                reason: 'Inbound stock adjustment pushes MWA up',
                userId: $this->tenantData['owner']->id,
                unitCostMinor: 12,
            );
        });

        // Verify MWA has risen to ~Rp10/g
        $newCostState = $this->runForTenant(fn () => InventoryCostState::where('inventory_item_id', $rice->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertGreaterThan(8, $newCostState->average_cost_minor);

        // CRITICAL CHECK: Reload Day 1 Sale A
        $saleADay2 = $this->runForTenant(fn () => $this->posService->getSale($saleA->id));
        $this->assertEquals(15000, $saleADay2->total_minor, 'Historical total must remain Rp15.000');
        $this->assertEquals(15000, $saleADay2->items->first()->unit_price_minor);
        $this->assertEquals(1, $saleADay2->items->first()->recipe_snapshot['version_number'], 'Historical recipe version must remain 1');
        $this->assertEquals(100.0, (float) $saleADay2->consumptions->first()->consumed_quantity);
        $this->assertEquals(8, $saleADay2->consumptions->first()->unit_cost_minor, 'Historical MWA snapshot must remain Rp8');
        $this->assertEquals(800, $saleADay2->consumptions->first()->total_cost_minor, 'Historical COGS must remain Rp800');
    }

    // =========================================================================
    // 7. CANCELLATION & COMPENSATING REVERSAL
    // =========================================================================

    public function test_open_sale_cancellation_voids_without_stock_movement(): void
    {
        $prod = $this->createProductWithPrice('Batagor Bandung', 'Porsi', 20000);
        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Manager / Authorized user cancels sale
        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Manager Agus');
        $response = $this->authenticateTenantUser($managerData['user'], $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/cancel", ['reason' => 'Customer changed mind']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(0, $this->runForTenant(fn () => InventoryMovement::where('reference_id', $sale->id)->count()));
    }

    public function test_finalized_sale_cancellation_creates_compensating_reversal_movements(): void
    {
        $prod = $this->createProductWithPrice('Sate Ayam', '10 Tusuk', 30000);
        $meat = $this->createInventoryWithStock('Daging Tusuk', 'RAW-SATE', 'pcs', 100.0, 1500);

        $this->runForTenant(function () use ($prod, $meat) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Sate',
                'code' => 'RCP-SATE',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $meat->id,
                'quantity' => 10.0,
                'unit' => 'pcs',
            ]);
        });

        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Finalize
        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize")
            ->assertStatus(200);

        // Stock decreased to 90
        $meatLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $meat->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertEquals(90.0, (float) $meatLoc->on_hand);

        // Cancel finalized sale (by Manager)
        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Manager Agus');
        $cancelResponse = $this->authenticateTenantUser($managerData['user'], $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/cancel", ['reason' => 'Wrong order entered']);

        $cancelResponse->assertStatus(200);
        $cancelResponse->assertJsonPath('data.status', 'cancelled');

        // Compensating reversal movement created
        $reversalMovements = $this->runForTenant(fn () => InventoryMovement::where('reference_id', $sale->id)
            ->where('movement_type', 'sales_reversal')
            ->get());
        $this->assertCount(1, $reversalMovements);
        $this->assertEquals(10.0, (float) $reversalMovements->first()->quantity_delta);

        // Stock restored back to 100.0
        $meatLocAfter = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $meat->id)
            ->where('location_id', $this->kitchenLocation->id)
            ->first());
        $this->assertEquals(100.0, (float) $meatLocAfter->on_hand);
    }

    // =========================================================================
    // 8. IDEMPOTENCY
    // =========================================================================

    public function test_idempotent_replay_and_fingerprint_mismatch(): void
    {
        $prod = $this->createProductWithPrice('Mie Goreng Jawa', 'Porsi', 22000);
        $idempotencyKey = (string) Str::uuid7();

        $payload = [
            'outlet_id' => $this->outlet->id,
            'items' => [
                ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
            ],
        ];

        // 1st request
        $res1 = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/pos/sales', $payload);

        $res1->assertStatus(201);
        $saleId1 = $res1->json('data.id');

        // 2nd request with SAME KEY & SAME PAYLOAD -> Replay
        $res2 = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/pos/sales', $payload);

        $res2->assertStatus(201);
        $res2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertEquals($saleId1, $res2->json('data.id'));

        // 3rd request with SAME KEY but DIFFERENT PAYLOAD -> Conflict 409
        $res3 = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/pos/sales', array_merge($payload, ['notes' => 'Tampered']));

        $res3->assertStatus(409);
        $res3->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD');
    }

    // =========================================================================
    // 9. TENANT ISOLATION & SECURITY
    // =========================================================================

    public function test_tenant_isolation_rejects_cross_tenant_access(): void
    {
        $tenantB = $this->createTenant('Tenant Rival');
        $cashierB = $this->addMemberToTenant($tenantB['tenant'], 'cashier', 'Kasir Rival')['user'];

        // Tenant A creates a sale
        $prodA = $this->createProductWithPrice('Soto Ayam A', 'Porsi', 18000);
        $saleA = $this->runForTenant(function () use ($prodA) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prodA['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Cashier B attempts to view Tenant A's sale -> 404 / ModelNotFound
        $res = $this->authenticateTenantUser($cashierB, $tenantB['tenant'])
            ->getJson("/api/v1/pos/sales/{$saleA->id}");

        $res->assertStatus(404);

        // Staff without pos.finalize permission attempts to finalize -> 403 Forbidden
        $resStaff = $this->authenticateTenantUser($this->staff, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$saleA->id}/finalize");

        $resStaff->assertStatus(403);
    }

    // =========================================================================
    // 10. SERVER-AUTHORITATIVE INVENTORY SOURCE LOCATION
    // =========================================================================

    public function test_sale_consumes_from_server_authoritative_pos_location(): void
    {
        $prod = $this->createProductWithPrice('Es Teh Manis', 'Gelas', 5000);
        $tea = $this->createInventoryWithStock('Teh Celup', 'TEA-01', 'pcs', 100, 500);

        // Recipe for Es Teh Manis
        $this->runForTenant(function () use ($prod, $tea) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Es Teh',
                'code' => 'RCP-TEH',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $tea->id,
                'quantity' => 1.0,
                'unit' => 'pcs',
            ]);
        });

        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Finalize without passing location_id -> must use server-authoritative outlet default
        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(200);

        // Verify movement occurred in $this->kitchenLocation
        $movement = $this->runForTenant(fn () => InventoryMovement::where('reference_id', $sale->id)->first());
        $this->assertNotNull($movement);
        $this->assertEquals($this->kitchenLocation->id, $movement->location_id);
    }

    public function test_arbitrary_unrelated_location_is_rejected(): void
    {
        $prod = $this->createProductWithPrice('Es Jeruk', 'Gelas', 7000);
        $this->createInventoryWithStock('Jeruk Peras', 'JRK-01', 'pcs', 50, 1000);

        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Pass a random non-existent location UUID
        $fakeLocationId = (string) Str::uuid7();
        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize", [
                'location_id' => $fakeLocationId,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'NO_POS_INVENTORY_LOCATION_FOR_OUTLET');
    }

    public function test_cross_outlet_location_is_rejected(): void
    {
        // Create second outlet and second kitchen location in Tenant A
        $outlet2 = $this->runForTenant(function () {
            $o = Outlet::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Outlet Cabang Dua',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);
            $loc2 = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $o->id,
                'name' => 'Kitchen Cabang Dua',
                'code' => 'KIT-02',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $o->update(['default_pos_location_id' => $loc2->id]);

            return ['outlet' => $o, 'location' => $loc2];
        });

        $prod = $this->createProductWithPrice('Air Mineral', 'Botol', 4000);
        $this->createInventoryWithStock('Air Botol', 'AIR-01', 'pcs', 100, 2000);

        // Sale is for Outlet 1
        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        // Client attempts to finalize Outlet 1 sale using Outlet 2's location -> MUST BE REJECTED
        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize", [
                'location_id' => $outlet2['location']->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INVENTORY_LOCATION_OUTLET_MISMATCH');
    }

    public function test_outlet_without_inventory_location_fails_safely(): void
    {
        // Create an outlet with NO inventory locations at all
        $bareOutlet = $this->runForTenant(function () {
            return Outlet::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Outlet Tanpa Gudang',
                'code' => 'OUT-EMPTY',
                'is_active' => true,
            ]);
        });

        $this->cashier->memberships()
            ->where('tenant_id', $this->tenantData['tenant']->id)
            ->first()
            ?->update([
                'assigned_outlet_ids' => [$this->outlet->id, $bareOutlet->id],
            ]);

        $prod = $this->createProductWithPrice('Krupuk', 'Bungkus', 2000);

        $sale = $this->runForTenant(function () use ($bareOutlet, $prod) {
            return $this->posService->createSale([
                'outlet_id' => $bareOutlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 1],
                ],
            ], $this->cashier->id);
        });

        $response = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'NO_POS_INVENTORY_LOCATION_FOR_OUTLET');
    }

    // =========================================================================
    // 11. SEMI-FINISHED INVENTORY CONSUMPTION: STOCKED (MODE A) VS MAKE-TO-ORDER (MODE B)
    // =========================================================================

    public function test_stocked_semi_finished_item_is_consumed_directly_without_child_materials(): void
    {
        // Product: Bakso Sapi Kuah
        $prod = $this->createProductWithPrice('Bakso Sapi Kuah', 'Porsi', 25000);

        // Pre-produced semi-finished item: Kuah Bakso (stocked)
        // 50,000 ml on hand @ Rp 20/ml
        $broth = $this->createInventoryWithStock('Kuah Kaldu Sapi', 'SEMI-BROTH', 'ml', 50000, 20);
        // Pre-produced semi-finished item: Pentol Bakso (stocked)
        // 500 pcs on hand @ Rp 1,500/pcs
        $meatball = $this->createInventoryWithStock('Pentol Bakso Sapi', 'SEMI-BAKSO', 'pcs', 500, 1500);

        // Raw materials of Kuah Kaldu (e.g. Tulang Sapi, Bawang) - has 100kg in stock
        $bone = $this->createInventoryWithStock('Tulang Sapi Mentah', 'RAW-BONE', 'kg', 100, 40000);

        // Broth Recipe: 1kg Tulang -> 10,000ml Kuah Kaldu
        $this->runForTenant(function () use ($broth, $bone) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Kuah Kaldu Sapi',
                'code' => 'RCP-BROTH',
                'inventory_item_id' => $broth->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 10000.0,
                'yield_unit' => 'ml',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $bone->id,
                'quantity' => 1.0,
                'unit' => 'kg',
            ]);
        });

        // Bakso Sapi Kuah Recipe: 300ml Kuah Kaldu (stocked) + 5 pcs Pentol Bakso (stocked)
        $this->runForTenant(function () use ($prod, $broth, $meatball) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Bakso Sapi Kuah',
                'code' => 'RCP-BAKSO',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v->id]);
            // Component 1: Kuah Kaldu (stocked)
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $broth->id,
                'quantity' => 300.0,
                'unit' => 'ml',
                'consumption_mode' => 'stocked', // Explicitly Mode A: Stocked
            ]);
            // Component 2: Pentol Bakso (stocked)
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $meatball->id,
                'quantity' => 5.0,
                'unit' => 'pcs',
                'consumption_mode' => 'stocked',
            ]);
        });

        // Sale: 2 Portions of Bakso Sapi Kuah
        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 2],
                ],
            ], $this->cashier->id);
        });

        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $res->assertStatus(200);

        // CRITICAL INVARIANT: EXACTLY 2 items consumed (Broth & Meatball).
        // Bone (raw material of Broth) MUST NOT BE CONSUMED!
        $consumptions = $this->runForTenant(fn () => SaleConsumption::where('sale_id', $sale->id)->get());
        $this->assertCount(2, $consumptions, 'Only pre-produced semi-finished items must be consumed');

        $brothCons = $consumptions->firstWhere('inventory_item_id', $broth->id);
        $this->assertNotNull($brothCons);
        $this->assertEquals(600.0, (float) $brothCons->consumed_quantity); // 300ml * 2
        $this->assertEquals(20, $brothCons->unit_cost_minor); // MWA of broth: Rp20/ml
        $this->assertEquals(12000, $brothCons->total_cost_minor); // 600 * 20 = 12,000

        $meatballCons = $consumptions->firstWhere('inventory_item_id', $meatball->id);
        $this->assertNotNull($meatballCons);
        $this->assertEquals(10.0, (float) $meatballCons->consumed_quantity); // 5pcs * 2
        $this->assertEquals(1500, $meatballCons->unit_cost_minor);
        $this->assertEquals(15000, $meatballCons->total_cost_minor); // 10 * 1500 = 15,000

        // Zero consumption for raw bone
        $boneCons = $consumptions->firstWhere('inventory_item_id', $bone->id);
        $this->assertNull($boneCons, 'Bone must NOT be consumed in Mode A');

        // Check on-hand stock decrements
        $brothLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $broth->id)
            ->where('location_id', $this->kitchenLocation->id)->first());
        $this->assertEquals(49400.0, (float) $brothLoc->on_hand); // 50000 - 600

        $boneLoc = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $bone->id)
            ->where('location_id', $this->kitchenLocation->id)->first());
        $this->assertEquals(100.0, (float) $boneLoc->on_hand, 'Raw bone stock must remain completely untouched at 100kg');
    }

    public function test_make_to_order_semi_finished_item_explodes_without_consuming_parent_item(): void
    {
        // Product: Jus Campur Segar
        $prod = $this->createProductWithPrice('Jus Sirup Jeruk', 'Gelas', 15000);

        // Raw materials: Jeruk (g) & Gula (g)
        $orange = $this->createInventoryWithStock('Jeruk Peras Fresh', 'RAW-ORANGE', 'g', 10000, 25);
        $sugar = $this->createInventoryWithStock('Gula Pasir', 'RAW-SUGAR', 'g', 5000, 15);

        // Semi-finished item: Sirup Jeruk Konsentrat (configured as make_to_order)
        // Zero stock on hand!
        $syrup = $this->runForTenant(function () {
            return InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Sirup Jeruk Konsentrat',
                'sku' => 'SEMI-SYRUP',
                'item_type' => 'semi_finished',
                'production_mode' => 'make_to_order', // Mode B
                'base_unit' => 'ml',
                'status' => 'active',
            ]);
        });

        // Syrup Recipe: 100g Jeruk + 50g Gula -> 100ml Syrup
        $this->runForTenant(function () use ($syrup, $orange, $sugar) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Sirup Jeruk',
                'code' => 'RCP-SYRUP',
                'inventory_item_id' => $syrup->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 100.0,
                'yield_unit' => 'ml',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $orange->id,
                'quantity' => 100.0,
                'unit' => 'g',
            ]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $sugar->id,
                'quantity' => 50.0,
                'unit' => 'g',
            ]);
        });

        // Jus Recipe: 50ml Syrup per glass
        $this->runForTenant(function () use ($prod, $syrup) {
            $r = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Resep Jus Sirup',
                'code' => 'RCP-JUS',
                'product_variant_id' => $prod['variant']->id,
                'status' => 'published',
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $v = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $r->id,
                'version_number' => 1,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'status' => 'published',
                'effective_from' => now()->subDay(),
                'created_by' => $this->tenantData['owner']->id,
            ]);
            $r->update(['active_version_id' => $v->id]);
            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $v->id,
                'inventory_item_id' => $syrup->id,
                'quantity' => 50.0,
                'unit' => 'ml',
            ]);
        });

        // Sale: 2 Glasses of Jus
        $sale = $this->runForTenant(function () use ($prod) {
            return $this->posService->createSale([
                'outlet_id' => $this->outlet->id,
                'items' => [
                    ['product_variant_id' => $prod['variant']->id, 'quantity' => 2],
                ],
            ], $this->cashier->id);
        });

        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize");

        $res->assertStatus(200);

        // Required 50ml * 2 = 100ml Syrup.
        // In Mode B (Make-to-Order):
        // Explodes to: Jeruk (100g) + Gula (50g)
        // Syrup itself MUST NOT be in sale_consumptions!
        $consumptions = $this->runForTenant(fn () => SaleConsumption::where('sale_id', $sale->id)->get());
        $this->assertCount(2, $consumptions);

        $syrupCons = $consumptions->firstWhere('inventory_item_id', $syrup->id);
        $this->assertNull($syrupCons, 'Syrup parent item must NOT be consumed in Mode B');

        $orangeCons = $consumptions->firstWhere('inventory_item_id', $orange->id);
        $this->assertNotNull($orangeCons);
        $this->assertEquals(100.0, (float) $orangeCons->consumed_quantity);
        $this->assertEquals(25, $orangeCons->unit_cost_minor);
        $this->assertEquals(2500, $orangeCons->total_cost_minor); // 100 * 25

        $sugarCons = $consumptions->firstWhere('inventory_item_id', $sugar->id);
        $this->assertNotNull($sugarCons);
        $this->assertEquals(50.0, (float) $sugarCons->consumed_quantity);
        $this->assertEquals(15, $sugarCons->unit_cost_minor);
        $this->assertEquals(750, $sugarCons->total_cost_minor); // 50 * 15

        // Verify NO double consumption: no movements for syrup
        $syrupMovements = $this->runForTenant(fn () => InventoryMovement::where('reference_id', $sale->id)
            ->where('inventory_item_id', $syrup->id)->count());
        $this->assertEquals(0, $syrupMovements);
    }
}
