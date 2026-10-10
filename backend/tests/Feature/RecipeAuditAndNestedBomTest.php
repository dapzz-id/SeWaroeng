<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\RecipeCycleDetectedException;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Supplier;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PurchasingService;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class RecipeAuditAndNestedBomTest extends TenantTestCase
{
    use RefreshDatabase;

    private array $ctx;

    private InventoryService $inventoryService;

    private PurchasingService $purchasingService;

    private CostingService $costingService;

    private RecipeService $recipeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createTenant();
        $this->costingService = app(CostingService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->purchasingService = app(PurchasingService::class);
        $this->recipeService = app(RecipeService::class);
    }

    private function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->ctx['tenant'], $callback);
    }

    private function makeLocation(string $name = 'Central Kitchen'): InventoryLocation
    {
        return $this->runForTenant(fn () => InventoryLocation::create([
            'tenant_id' => $this->ctx['tenant']->id,
            'name' => $name,
            'code' => strtoupper(substr(md5($name.uniqid()), 0, 6)),
            'type' => 'kitchen',
            'is_active' => true,
        ]));
    }

    private function makeItem(string $name, string $type = 'raw_material', string $unit = 'g', bool $allowNegative = false): InventoryItem
    {
        return $this->runForTenant(fn () => InventoryItem::create([
            'tenant_id' => $this->ctx['tenant']->id,
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(substr(md5($name.uniqid()), 0, 6)),
            'item_type' => $type,
            'base_unit' => $unit,
            'status' => 'active',
            'allow_negative_stock' => $allowNegative,
        ]));
    }

    private function makeVariant(string $productName, string $variantName = 'Regular'): ProductVariant
    {
        return $this->runForTenant(function () use ($productName, $variantName) {
            $product = Product::create([
                'tenant_id' => $this->ctx['tenant']->id,
                'name' => $productName,
                'status' => 'active',
                'created_by' => $this->ctx['owner']->id,
            ]);

            return ProductVariant::create([
                'tenant_id' => $this->ctx['tenant']->id,
                'product_id' => $product->id,
                'name' => $variantName,
                'status' => 'active',
                'is_default' => true,
            ]);
        });
    }

    // =========================================================================
    // 1. NESTED BOM CREATION & OWNERSHIP
    // =========================================================================

    public function test_semi_finished_inventory_item_can_own_a_recipe(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $sauceItem = $this->makeItem('Sambal Matah', 'semi_finished', 'g');

        $recipe = $this->recipeService->createRecipe($tenantId, [
            'inventory_item_id' => $sauceItem->id,
            'name' => 'Resep Sambal Matah',
            'code' => 'RCP-MATAH',
        ], $this->ctx['owner']->id);

        $this->assertEquals('draft', $recipe->status);
        $this->assertNull($recipe->product_variant_id);
        $this->assertEquals($sauceItem->id, $recipe->inventory_item_id);
        $this->assertTrue($recipe->isItemRecipe());
        $this->assertFalse($recipe->isVariantRecipe());
    }

    public function test_recipe_must_have_exactly_one_owner(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeVariant('Ayam Geprek');
        $item = $this->makeItem('Ayam Prep', 'semi_finished', 'portion');

        // Cannot provide both
        $this->expectException(\InvalidArgumentException::class);
        $this->recipeService->createRecipe($tenantId, [
            'product_variant_id' => $variant->id,
            'inventory_item_id' => $item->id,
            'name' => 'Invalid Both Recipe',
        ], $this->ctx['owner']->id);
    }

    // =========================================================================
    // 2. CYCLE DETECTION (DIRECT, 2-NODE, 3-NODE, DIAMOND DAG)
    // =========================================================================

    public function test_detects_direct_self_cycle_on_item_recipe(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $sauce = $this->makeItem('Special Sauce', 'semi_finished', 'g');

        $recipe = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $sauce->id, 'name' => 'Self Sauce'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subHour(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);

        // Recipe for Special Sauce contains Special Sauce!
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [
            ['inventory_item_id' => $sauce->id, 'quantity' => '10.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);

        $this->expectException(RecipeCycleDetectedException::class);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);
    }

    public function test_detects_two_node_cycle(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $itemA = $this->makeItem('Item A', 'semi_finished', 'g');
        $itemB = $this->makeItem('Item B', 'semi_finished', 'g');

        // Recipe A: produces Item A, requires Item B
        $recA = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $itemA->id, 'name' => 'Recipe A'], $this->ctx['owner']->id);
        $verA = $this->recipeService->createVersion($tenantId, $recA->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recA->id, $verA->id, [
            ['inventory_item_id' => $itemB->id, 'quantity' => '50.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recA->id, $verA->id, $this->ctx['owner']->id);

        // Recipe B: produces Item B, requires Item A -> Cycle A <-> B
        $recB = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $itemB->id, 'name' => 'Recipe B'], $this->ctx['owner']->id);
        $verB = $this->recipeService->createVersion($tenantId, $recB->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recB->id, $verB->id, [
            ['inventory_item_id' => $itemA->id, 'quantity' => '50.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);

        $this->expectException(RecipeCycleDetectedException::class);
        $this->recipeService->publishVersion($tenantId, $recB->id, $verB->id, $this->ctx['owner']->id);
    }

    public function test_detects_three_node_cycle(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $item1 = $this->makeItem('Node 1', 'semi_finished', 'g');
        $item2 = $this->makeItem('Node 2', 'semi_finished', 'g');
        $item3 = $this->makeItem('Node 3', 'semi_finished', 'g');

        // 1 -> 2
        $r1 = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $item1->id, 'name' => 'R1'], $this->ctx['owner']->id);
        $v1 = $this->recipeService->createVersion($tenantId, $r1->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $r1->id, $v1->id, [['inventory_item_id' => $item2->id, 'quantity' => '10', 'unit' => 'g', 'component_type' => 'semi_finished']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $r1->id, $v1->id, $this->ctx['owner']->id);

        // 2 -> 3
        $r2 = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $item2->id, 'name' => 'R2'], $this->ctx['owner']->id);
        $v2 = $this->recipeService->createVersion($tenantId, $r2->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $r2->id, $v2->id, [['inventory_item_id' => $item3->id, 'quantity' => '10', 'unit' => 'g', 'component_type' => 'semi_finished']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $r2->id, $v2->id, $this->ctx['owner']->id);

        // 3 -> 1 (Cycle: 1 -> 2 -> 3 -> 1)
        $r3 = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $item3->id, 'name' => 'R3'], $this->ctx['owner']->id);
        $v3 = $this->recipeService->createVersion($tenantId, $r3->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $r3->id, $v3->id, [['inventory_item_id' => $item1->id, 'quantity' => '10', 'unit' => 'g', 'component_type' => 'semi_finished']], $this->ctx['owner']->id);

        $this->expectException(RecipeCycleDetectedException::class);
        $this->recipeService->publishVersion($tenantId, $r3->id, $v3->id, $this->ctx['owner']->id);
    }

    public function test_allows_valid_diamond_dag_repeated_shared_component(): void
    {
        $tenantId = $this->ctx['tenant']->id;

        // Shared leaf component: Cooking Oil
        $oil = $this->makeItem('Cooking Oil', 'raw_material', 'ml');

        // Intermediate A: Fried Garlic uses Cooking Oil
        $garlic = $this->makeItem('Garlic', 'raw_material', 'g');
        $friedGarlic = $this->makeItem('Fried Garlic', 'semi_finished', 'g');
        $rGarlic = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $friedGarlic->id, 'name' => 'Fried Garlic Recipe'], $this->ctx['owner']->id);
        $vGarlic = $this->recipeService->createVersion($tenantId, $rGarlic->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rGarlic->id, $vGarlic->id, [
            ['inventory_item_id' => $garlic->id, 'quantity' => '120.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $oil->id, 'quantity' => '50.0000', 'unit' => 'ml', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rGarlic->id, $vGarlic->id, $this->ctx['owner']->id);

        // Intermediate B: Chili Oil uses Cooking Oil directly
        $chili = $this->makeItem('Chili Flakes', 'raw_material', 'g');
        $chiliOil = $this->makeItem('Chili Oil', 'semi_finished', 'ml');
        $rChili = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $chiliOil->id, 'name' => 'Chili Oil Recipe'], $this->ctx['owner']->id);
        $vChili = $this->recipeService->createVersion($tenantId, $rChili->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 100, 'yield_unit' => 'ml'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rChili->id, $vChili->id, [
            ['inventory_item_id' => $chili->id, 'quantity' => '30.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $oil->id, 'quantity' => '100.0000', 'unit' => 'ml', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rChili->id, $vChili->id, $this->ctx['owner']->id);

        // Final Bowl: uses BOTH Fried Garlic AND Chili Oil (Diamond dependency: Bowl -> Garlic, ChiliOil -> Oil)
        $variant = $this->makeVariant('Mie Pedas Special');
        $rBowl = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Mie Pedas Recipe'], $this->ctx['owner']->id);
        $vBowl = $this->recipeService->createVersion($tenantId, $rBowl->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'portion'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rBowl->id, $vBowl->id, [
            ['inventory_item_id' => $friedGarlic->id, 'quantity' => '10.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
            ['inventory_item_id' => $chiliOil->id, 'quantity' => '15.0000', 'unit' => 'ml', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);

        // MUST succeed without false positive cycle!
        $published = $this->recipeService->publishVersion($tenantId, $rBowl->id, $vBowl->id, $this->ctx['owner']->id);
        $this->assertEquals('published', $published->status);
    }

    // =========================================================================
    // 3. RECIPE EXPLOSION READ MODEL & YIELD NORMALIZATION
    // =========================================================================

    public function test_recipe_explosion_deterministically_calculates_leaf_requirements(): void
    {
        $tenantId = $this->ctx['tenant']->id;

        // Leaf raw materials
        $rice = $this->makeItem('Rice', 'raw_material', 'g');
        $chicken = $this->makeItem('Chicken', 'raw_material', 'g');
        $chili = $this->makeItem('Chili', 'raw_material', 'g');
        $garlic = $this->makeItem('Garlic', 'raw_material', 'g');
        $oil = $this->makeItem('Oil', 'raw_material', 'ml');

        // Semi-finished: Chili Sauce
        // Yield: 100g from: Chili 10g, Garlic 5g, Oil 15ml
        $sauce = $this->makeItem('Chili Sauce', 'semi_finished', 'g');
        $rSauce = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $sauce->id, 'name' => 'Chili Sauce Recipe'], $this->ctx['owner']->id);
        $vSauce = $this->recipeService->createVersion($tenantId, $rSauce->id, ['effective_from' => now()->subHour(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rSauce->id, $vSauce->id, [
            ['inventory_item_id' => $chili->id, 'quantity' => '10.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $garlic->id, 'quantity' => '5.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $oil->id, 'quantity' => '15.0000', 'unit' => 'ml', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rSauce->id, $vSauce->id, $this->ctx['owner']->id);

        // Product Variant: Chicken Rice Bowl
        // Yield: 1 portion
        // Requires: Rice 200g, Chicken 150g, Chili Sauce 30g
        $bowl = $this->makeVariant('Chicken Rice Bowl');
        $rBowl = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $bowl->id, 'name' => 'Chicken Rice Bowl Recipe'], $this->ctx['owner']->id);
        $vBowl = $this->recipeService->createVersion($tenantId, $rBowl->id, ['effective_from' => now()->subHour(), 'yield_quantity' => 1, 'yield_unit' => 'portion'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rBowl->id, $vBowl->id, [
            ['inventory_item_id' => $rice->id, 'quantity' => '200.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $chicken->id, 'quantity' => '150.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $sauce->id, 'quantity' => '30.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rBowl->id, $vBowl->id, $this->ctx['owner']->id);

        // 1. Explode 1 bowl
        $explosion1 = $this->recipeService->explodeRecipe($tenantId, $rBowl->id, multiplier: '1.0000');

        $leaves1 = collect($explosion1['leaf_components'])->keyBy('inventory_item_id');
        $this->assertEquals('200.0000', $leaves1[$rice->id]['quantity']);
        $this->assertEquals('150.0000', $leaves1[$chicken->id]['quantity']);
        // 30g sauce / 100g yield = 0.3 factor
        // Chili: 10 * 0.3 = 3.0000 g
        $this->assertEquals('3.0000', $leaves1[$chili->id]['quantity']);
        // Garlic: 5 * 0.3 = 1.5000 g
        $this->assertEquals('1.5000', $leaves1[$garlic->id]['quantity']);
        // Oil: 15 * 0.3 = 4.5000 ml
        $this->assertEquals('4.5000', $leaves1[$oil->id]['quantity']);

        // 2. Explode 2 bowls (multiplier = 2.0000)
        $explosion2 = $this->recipeService->explodeRecipe($tenantId, $rBowl->id, multiplier: '2.0000');
        $leaves2 = collect($explosion2['leaf_components'])->keyBy('inventory_item_id');
        $this->assertEquals('400.0000', $leaves2[$rice->id]['quantity']);
        $this->assertEquals('300.0000', $leaves2[$chicken->id]['quantity']);
        $this->assertEquals('6.0000', $leaves2[$chili->id]['quantity']);
        $this->assertEquals('3.0000', $leaves2[$garlic->id]['quantity']);
        $this->assertEquals('9.0000', $leaves2[$oil->id]['quantity']);
    }

    // =========================================================================
    // 4. RECURSIVE ESTIMATED COST CALCULATION
    // =========================================================================

    public function test_recursive_estimated_cost_calculates_semi_finished_contribution(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Cost Kitchen');

        // Leaf items
        $chili = $this->makeItem('Chili Raw', 'raw_material', 'g');
        $garlic = $this->makeItem('Garlic Raw', 'raw_material', 'g');
        $oil = $this->makeItem('Oil Raw', 'raw_material', 'ml');
        $rice = $this->makeItem('Rice Raw', 'raw_material', 'g');
        $chicken = $this->makeItem('Chicken Raw', 'raw_material', 'g');

        // Stock and costs for raw items via opening balances / receipts:
        // Chili: 1000g @ Rp 100/g = Rp 100,000
        $this->inventoryService->setOpeningBalance($tenantId, $chili->id, $loc->id, '1000.0000', unitCostMinor: 100);
        // Garlic: 1000g @ Rp 60/g = Rp 60,000
        $this->inventoryService->setOpeningBalance($tenantId, $garlic->id, $loc->id, '1000.0000', unitCostMinor: 60);
        // Oil: 1000ml @ Rp 33.333333... minor unit
        // Let's use clean numbers:
        // Chili 10g @ 100 = Rp 1,000
        // Garlic 5g @ 60 = Rp 300
        // Oil 15ml @ 33.3333 (let's set unit_cost = 33 -> 15 * 33 = 495 ~ 500)
        // Or exact: Oil unit cost = 34 -> 15 * 34 = 510
        // Let's use Oil @ 34
        $this->inventoryService->setOpeningBalance($tenantId, $oil->id, $loc->id, '1000.0000', unitCostMinor: 34);

        // Rice: 10000g @ Rp 12/g
        $this->inventoryService->setOpeningBalance($tenantId, $rice->id, $loc->id, '10000.0000', unitCostMinor: 12);
        // Chicken: 10000g @ Rp 40/g
        $this->inventoryService->setOpeningBalance($tenantId, $chicken->id, $loc->id, '10000.0000', unitCostMinor: 40);

        // Semi-finished Chili Sauce Recipe:
        // Yield: 100g
        // Components:
        // Chili: 10g @ 100 = 1000
        // Garlic: 5g @ 60 = 300
        // Oil: 15ml @ 34 = 510
        // Total batch = 1810 for 100g -> 18.1 minor / g -> rounded unit cost = 18 minor units
        $sauce = $this->makeItem('Chili Sauce Prep', 'semi_finished', 'g');
        $rSauce = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $sauce->id, 'name' => 'Sauce Prep Recipe'], $this->ctx['owner']->id);
        $vSauce = $this->recipeService->createVersion($tenantId, $rSauce->id, ['effective_from' => now()->subHour(), 'yield_quantity' => 100, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rSauce->id, $vSauce->id, [
            ['inventory_item_id' => $chili->id, 'quantity' => '10.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $garlic->id, 'quantity' => '5.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $oil->id, 'quantity' => '15.0000', 'unit' => 'ml', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rSauce->id, $vSauce->id, $this->ctx['owner']->id);

        $sauceEstimate = $this->recipeService->estimateCost($tenantId, $rSauce->id, $vSauce->id, $loc->id);
        $this->assertTrue($sauceEstimate['is_complete']);
        // Batch cost = 1000 + 300 + 510 = 1810
        $this->assertEquals(1810, $sauceEstimate['estimated_batch_cost_minor']);
        // Unit cost per gram = 1810 / 100 = 18.1 -> 18
        $this->assertEquals(18, $sauceEstimate['estimated_unit_cost_minor']);
        $this->assertEquals(18, $sauceEstimate['estimated_total_cost_minor']);

        // Chicken Rice Bowl:
        // Yield: 1 portion
        // Rice 200g @ 12 = 2400
        // Chicken 150g @ 40 = 6000
        // Sauce 30g @ 18 = 540
        // Total = 2400 + 6000 + 540 = 8940
        $bowl = $this->makeVariant('Chicken Rice Bowl Premium');
        $rBowl = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $bowl->id, 'name' => 'Bowl Recipe'], $this->ctx['owner']->id);
        $vBowl = $this->recipeService->createVersion($tenantId, $rBowl->id, ['effective_from' => now()->subHour(), 'yield_quantity' => 1, 'yield_unit' => 'portion'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rBowl->id, $vBowl->id, [
            ['inventory_item_id' => $rice->id, 'quantity' => '200.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $chicken->id, 'quantity' => '150.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $sauce->id, 'quantity' => '30.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rBowl->id, $vBowl->id, $this->ctx['owner']->id);

        $bowlEstimate = $this->recipeService->estimateCost($tenantId, $rBowl->id, $vBowl->id, $loc->id);
        $this->assertTrue($bowlEstimate['is_complete']);
        // Sauce unit cost is 18 minor/g. 30g * 18 = 540.
        // Rice: 200 * 12 = 2400.
        // Chicken: 150 * 40 = 6000.
        // Total expected = 8940.
        $this->assertEquals(8940, $bowlEstimate['estimated_total_cost_minor']);
    }

    // =========================================================================
    // 5. MWA PRECISION WITH FRACTIONAL QUANTITIES
    // =========================================================================

    public function test_mwa_precision_with_fractional_quantities(): void
    {
        // Test case specified in prompt: quantity = 0.125, unit_cost = 125000
        $totalCost = $this->costingService->calculateTotalCostMinor('0.1250', 125000);
        $this->assertEquals(15625, $totalCost);

        // MWA calculation: on_hand_before = 0.3750 @ 120000 (value = 45000)
        // inbound: 0.1250 @ 125000 (value = 15625)
        // total_qty = 0.5000, total_value = 60625
        // new_avg = 60625 / 0.5 = 121250
        $mwa = $this->costingService->computeMwa('0.3750', '120000', '0.1250', '125000');
        $this->assertEquals(121250, $mwa);
    }

    // =========================================================================
    // 6. TRANSFER COST PRESERVATION & DESTINATION MWA CALCULATION
    // =========================================================================

    public function test_transfer_preserves_source_cost_basis_and_updates_destination_mwa(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $locA = $this->makeLocation('Warehouse A');
        $locB = $this->makeLocation('Outlet B');

        $item = $this->makeItem('Arabica Coffee Beans', 'raw_material', 'kg');

        // Loc A: 100 units @ Rp 10,000 (value 1,000,000)
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $locA->id, '100.0000', unitCostMinor: 10000);

        // Loc B: 10 units @ Rp 12,000 (value 120,000)
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $locB->id, '10.0000', unitCostMinor: 12000);

        // Transfer 20 units from Loc A to Loc B
        $transfer = $this->inventoryService->createTransfer(
            $tenantId,
            $locA->id,
            $locB->id,
            [
                ['inventory_item_id' => $item->id, 'quantity' => '20.0000'],
            ],
            $this->ctx['owner']->id
        );

        $this->inventoryService->dispatchTransfer($tenantId, $transfer->id, $this->ctx['owner']->id);
        $this->inventoryService->receiveTransfer($tenantId, $transfer->id, $this->ctx['owner']->id);

        // Loc B received 20 units at source cost basis (Rp 10,000).
        // Loc B previous: 10 units @ Rp 12,000 = 120,000
        // Received: 20 units @ Rp 10,000 = 200,000
        // Total value = 320,000, Total qty = 30
        // Expected MWA: 320000 / 30 = 10666.6666... -> ROUND HALF UP = 10667
        $stateB = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $locB->id)
            ->first());

        $this->assertNotNull($stateB);
        $this->assertEquals(10667, $stateB->average_cost_minor);

        // Loc A remains at 10,000
        $stateA = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $locA->id)
            ->first());
        $this->assertEquals(10000, $stateA->average_cost_minor);
    }

    // =========================================================================
    // 7. INVENTORY ADJUSTMENT COSTING POLICY
    // =========================================================================

    public function test_adjustment_in_posts_cost_layer_using_existing_mwa(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Adjust Loc');
        $item = $this->makeItem('Flour', 'raw_material', 'kg');

        // Initial receipt to establish MWA of Rp 15,000
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '10.0000', unitCostMinor: 15000);

        // Now an adjustment_in of +5 kg without explicit cost
        $movement = $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '5.0000', 'Found extra sack', userId: $this->ctx['owner']->id);

        // Cost layer MUST be created using the existing MWA (Rp 15,000), not zero!
        $layer = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)
            ->where('source_movement_id', $movement->id)
            ->first());

        $this->assertNotNull($layer);
        $this->assertEquals('adjustment_in', $layer->source_type);
        $this->assertEquals(15000, $layer->unit_cost_minor);
        $this->assertEquals(75000, $layer->total_cost_minor);
    }

    // =========================================================================
    // 8. NEGATIVE STOCK COSTING POLICY
    // =========================================================================

    public function test_negative_stock_costing_resets_fresh_basis_on_new_receipt(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Negative Stock Loc');
        // Item with allow_negative_stock = true
        $item = $this->makeItem('Ice Cream', 'finished_good', 'cup', allowNegative: true);

        // Force stock to -5 via adjustment_out
        $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '-5.0000', 'Oversold negative');

        $itemLoc = $this->runForTenant(fn () => InventoryItemLocation::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $loc->id)
            ->first());
        $this->assertEquals('-5.0000', $itemLoc->on_hand);

        // Later inbound receipt: 10 units @ Rp 20,000
        $supplier = $this->runForTenant(fn () => Supplier::create(['tenant_id' => $tenantId, 'code' => 'SUP-ICE', 'name' => 'Ice Supplier', 'status' => 'active']));
        $po = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $loc->id,
            'ordered_at' => now(),
            'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '10.0000', 'unit_cost_minor' => 20000]],
        ], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po->id, $this->ctx['owner']->id);
        $receipt = $this->purchasingService->createReceipt($tenantId, $po->id, [
            ['purchase_order_item_id' => $po->items->first()->id, 'quantity' => '10.0000', 'unit_cost_minor' => 20000],
        ], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $receipt->id, $this->ctx['owner']->id);

        // Because on_hand_before <= 0, MWA policy resets to fresh cost basis = 20,000
        $state = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $loc->id)
            ->first());

        $this->assertNotNull($state);
        $this->assertEquals(20000, $state->average_cost_minor);

        // On hand is now -5 + 10 = +5
        $this->runForTenant(fn () => $itemLoc->refresh());
        $this->assertEquals('5.0000', $itemLoc->on_hand);
    }
}
