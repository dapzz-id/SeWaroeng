<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\InventoryCostLayer;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\CostingService;
use App\Services\InventoryService;
use App\Services\PurchasingService;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

class CostingAuthorityAndMwaTest extends TenantTestCase
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

    private function makeLocation(string $name = 'Main Kitchen'): InventoryLocation
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
    // 1. INBOUND PURCHASE CHANGES MWA CORRECTLY
    // =========================================================================

    public function test_inbound_purchase_changes_mwa_correctly(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Warehouse 1');
        $item = $this->makeItem('Ground Coffee', 'raw_material', 'kg');

        $supplier = $this->runForTenant(fn () => Supplier::create([
            'tenant_id' => $tenantId,
            'name' => 'Coffee Supplier',
            'code' => 'SUP-'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'status' => 'active',
        ]));

        // Receipt 1: 10 kg @ Rp 100,000/kg
        $po1 = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $loc->id,
            'ordered_at' => now(),
            'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '10.0000', 'unit_cost_minor' => 100000]],
        ], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po1->id, $this->ctx['owner']->id);
        $grn1 = $this->purchasingService->createReceipt($tenantId, $po1->id, [
            ['purchase_order_item_id' => $po1->items->first()->id, 'quantity' => '10.0000', 'unit_cost_minor' => 100000],
        ], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $grn1->id, $this->ctx['owner']->id);

        $state1 = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(100000, $state1);

        // Receipt 2: 10 kg @ Rp 120,000/kg
        // Total value = (10 × 100000) + (10 × 120000) = 2,200,000 / 20 = 110,000
        $po2 = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $loc->id,
            'ordered_at' => now(),
            'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '10.0000', 'unit_cost_minor' => 120000]],
        ], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po2->id, $this->ctx['owner']->id);
        $grn2 = $this->purchasingService->createReceipt($tenantId, $po2->id, [
            ['purchase_order_item_id' => $po2->items->first()->id, 'quantity' => '10.0000', 'unit_cost_minor' => 120000],
        ], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $grn2->id, $this->ctx['owner']->id);

        $state2 = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(110000, $state2);
    }

    // =========================================================================
    // 2. OUTBOUND VALUATION USES CURRENT MWA
    // =========================================================================

    public function test_outbound_valuation_uses_current_mwa(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Kitchen Outlet');
        $item = $this->makeItem('Fresh Milk', 'raw_material', 'liter');

        // Set initial stock: 20 liters @ Rp 15,000
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '20.0000', unitCostMinor: 15000);

        // Outbound valuation contract check: 5.5000 liters
        $outbound = $this->costingService->calculateOutboundCost($tenantId, $item->id, $loc->id, '5.5000');

        $this->assertEquals(15000, $outbound['applied_mwa_minor']);
        // 5.5000 × 15,000 = 82,500
        $this->assertEquals(82500, $outbound['total_cost_minor']);
        $this->assertEquals('moving_weighted_average', $outbound['costing_method']);
    }

    // =========================================================================
    // 3. OUTBOUND DOES NOT USE FIFO
    // =========================================================================

    public function test_outbound_does_not_use_fifo(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Coffee Bar');
        $item = $this->makeItem('Sugar', 'raw_material', 'kg');

        // Layer 1: 10 kg @ Rp 10,000 (value = 100,000)
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '10.0000', unitCostMinor: 10000);

        // Layer 2: 10 kg @ Rp 20,000 (value = 200,000)
        // MWA = (100,000 + 200,000) / 20 = Rp 15,000
        $movement2 = $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '10.0000', 'Restock', unitCostMinor: 20000);

        // Verify two distinct layers exist in provenance history
        $layerCount = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)->where('inventory_item_id', $item->id)->count());
        $this->assertEquals(2, $layerCount);

        // Under FIFO, consuming 5 units would draw from Layer 1 @ Rp 10,000 = Rp 50,000.
        // Under authoritative MWA, consuming 5 units draws at current location MWA Rp 15,000 = Rp 75,000.
        $outboundValuation = $this->costingService->calculateOutboundCost($tenantId, $item->id, $loc->id, '5.0000');

        $this->assertNotEquals(50000, $outboundValuation['total_cost_minor'], 'Must NOT use FIFO layer 1 unit cost');
        $this->assertEquals(75000, $outboundValuation['total_cost_minor'], 'Must use authoritative MWA of 15000');
    }

    // =========================================================================
    // 4. OUTBOUND DOES NOT SILENTLY RECALCULATE MWA INCORRECTLY
    // =========================================================================

    public function test_outbound_does_not_silently_recalculate_mwa_incorrectly(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Roastery');
        $item = $this->makeItem('Robusta Green Beans', 'raw_material', 'kg');

        // Initial: 100 kg @ Rp 50,000
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '100.0000', unitCostMinor: 50000);
        $initialMwa = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(50000, $initialMwa);

        // Outbound movement: 35 kg consumed
        $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '-35.0000', 'Roasting batch');

        // MWA must remain strictly 50,000 (outbound does not change unit cost basis)
        $mwaAfterOutbound = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(50000, $mwaAfterOutbound);
    }

    // =========================================================================
    // 5. TRANSFER PRESERVES SOURCE COST BASIS
    // =========================================================================

    public function test_transfer_preserves_source_cost_basis(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $locA = $this->makeLocation('Source Central');
        $locB = $this->makeLocation('Dest Outlet');
        $item = $this->makeItem('Paper Cups', 'packaging', 'piece');

        // Source has 1000 cups @ Rp 450
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $locA->id, '1000.0000', unitCostMinor: 450);

        // Transfer 200 cups to Dest Outlet
        $transfer = $this->inventoryService->createTransfer($tenantId, $locA->id, $locB->id, [
            ['inventory_item_id' => $item->id, 'quantity' => '200.0000'],
        ], $this->ctx['owner']->id);
        $this->inventoryService->dispatchTransfer($tenantId, $transfer->id, $this->ctx['owner']->id);
        $this->inventoryService->receiveTransfer($tenantId, $transfer->id, $this->ctx['owner']->id);

        // Cost layer created at destination must have unit_cost_minor = 450 (source basis preserved)
        $layerDest = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)
            ->where('location_id', $locB->id)
            ->where('source_type', 'transfer_in')
            ->first());

        $this->assertNotNull($layerDest);
        $this->assertEquals(450, $layerDest->unit_cost_minor);
        $this->assertEquals(90000, $layerDest->total_cost_minor);
    }

    // =========================================================================
    // 6. DESTINATION TRANSFER UPDATES MWA CORRECTLY
    // =========================================================================

    public function test_destination_transfer_updates_mwa_correctly(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $locA = $this->makeLocation('Warehouse Alpha');
        $locB = $this->makeLocation('Warehouse Beta');
        $item = $this->makeItem('Vanilla Syrup', 'raw_material', 'liter');

        // Loc A: 50L @ Rp 80,000
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $locA->id, '50.0000', unitCostMinor: 80000);

        // Loc B: 10L @ Rp 100,000 (value = 1,000,000)
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $locB->id, '10.0000', unitCostMinor: 100000);

        // Transfer 10L from Loc A to Loc B (transferred value = 10 × 80,000 = 800,000)
        $transfer = $this->inventoryService->createTransfer($tenantId, $locA->id, $locB->id, [
            ['inventory_item_id' => $item->id, 'quantity' => '10.0000'],
        ], $this->ctx['owner']->id);
        $this->inventoryService->dispatchTransfer($tenantId, $transfer->id, $this->ctx['owner']->id);
        $this->inventoryService->receiveTransfer($tenantId, $transfer->id, $this->ctx['owner']->id);

        // Loc B MWA: (1,000,000 + 800,000) / 20 = 1,800,000 / 20 = 90,000
        $mwaB = $this->costingService->getAverageCost($tenantId, $item->id, $locB->id);
        $this->assertEquals(90000, $mwaB);
    }

    // =========================================================================
    // 7. ADJUSTMENT-IN USES DETERMINISTIC COST BASIS
    // =========================================================================

    public function test_adjustment_in_uses_deterministic_cost_basis(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Storage Spot');
        $item = $this->makeItem('Matcha Powder', 'raw_material', 'g');

        // 1. Establish initial MWA of Rp 250/g
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '500.0000', unitCostMinor: 250);

        // 2. Inbound adjustment without explicit cost adopts current MWA
        $mov1 = $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '100.0000', 'Found audit excess');
        $layer1 = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)
            ->where('source_movement_id', $mov1->id)
            ->first());

        $this->assertNotNull($layer1);
        $this->assertEquals(250, $layer1->unit_cost_minor, 'Adopts current MWA');

        // 3. Inbound adjustment with explicit cost uses explicit cost
        $mov2 = $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '100.0000', 'Supplier sample', unitCostMinor: 300);
        $layer2 = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)
            ->where('source_movement_id', $mov2->id)
            ->first());

        $this->assertNotNull($layer2);
        $this->assertEquals(300, $layer2->unit_cost_minor, 'Uses explicit cost');
        $this->assertNotEquals(0, $layer2->unit_cost_minor, 'Never silently fabricates Rp 0');
    }

    // =========================================================================
    // 8. NEGATIVE STOCK + LATER INBOUND REMAINS DETERMINISTIC
    // =========================================================================

    public function test_negative_stock_and_later_inbound_remains_deterministic(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Fast Service Bar');
        $item = $this->makeItem('Cold Brew Bottle', 'finished_good', 'bottle', allowNegative: true);

        // Initial: 5 bottles @ Rp 20,000
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '5.0000', unitCostMinor: 20000);
        $this->assertEquals(20000, $this->costingService->getAverageCost($tenantId, $item->id, $loc->id));

        // Sell / adjust 10 bottles -> on_hand becomes -5
        $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '-10.0000', 'Sales rush');

        // Negative stock: MWA remains preserved at 20,000 (denominator guarded, no silent corruption)
        $mwaDuringNegative = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(20000, $mwaDuringNegative);

        // Outbound valuation contract under negative stock:
        // Consuming 2 more bottles is valued at current MWA Rp 20,000 = Rp 40,000
        $outboundNegative = $this->costingService->calculateOutboundCost($tenantId, $item->id, $loc->id, '2.0000');
        $this->assertEquals(20000, $outboundNegative['applied_mwa_minor']);
        $this->assertEquals(40000, $outboundNegative['total_cost_minor']);

        // Later inbound stock arrives: 20 bottles @ Rp 24,000
        // on_hand_before is -5 <= 0: formula establishes fresh cost basis = 24,000
        $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '20.0000', 'Fresh delivery', unitCostMinor: 24000);

        $mwaAfterFreshReceipt = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(24000, $mwaAfterFreshReceipt);
    }

    // =========================================================================
    // 9. RECIPE ESTIMATED COST USES MWA
    // =========================================================================

    public function test_recipe_estimated_cost_uses_mwa(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Brew Station');
        $beans = $this->makeItem('Espresso Beans', 'raw_material', 'g');
        $water = $this->makeItem('Filtered Water', 'raw_material', 'ml');

        // Beans MWA = Rp 25/g
        $this->inventoryService->setOpeningBalance($tenantId, $beans->id, $loc->id, '1000.0000', unitCostMinor: 25);
        // Water MWA = Rp 1/ml
        $this->inventoryService->setOpeningBalance($tenantId, $water->id, $loc->id, '5000.0000', unitCostMinor: 1);

        $variant = $this->makeVariant('Americano');
        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Americano Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subMinute(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [
            ['inventory_item_id' => $beans->id, 'quantity' => '18.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $water->id, 'quantity' => '150.0000', 'unit' => 'ml', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $estimate = $this->recipeService->estimateCost($tenantId, $recipe->id, $version->id, $loc->id);

        $this->assertTrue($estimate['is_complete']);
        // Beans: 18g × 25 = 450
        // Water: 150ml × 1 = 150
        // Total = 600
        $this->assertEquals(600, $estimate['estimated_total_cost_minor']);
        $this->assertEquals('Estimasi Biaya', $estimate['cost_label']);
    }

    // =========================================================================
    // 10. NESTED RECIPE ESTIMATED COST USES MWA RECURSIVELY
    // =========================================================================

    public function test_nested_recipe_estimated_cost_uses_mwa_recursively(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Bakery Kitchen');

        // Leaf items
        $flour = $this->makeItem('Wheat Flour', 'raw_material', 'g');
        $butter = $this->makeItem('Butter', 'raw_material', 'g');
        $this->inventoryService->setOpeningBalance($tenantId, $flour->id, $loc->id, '10000.0000', unitCostMinor: 15);
        $this->inventoryService->setOpeningBalance($tenantId, $butter->id, $loc->id, '5000.0000', unitCostMinor: 80);

        // Semi-finished: Pastry Dough (Yield: 500g)
        // Flour: 300g @ 15 = 4500
        // Butter: 200g @ 80 = 16000
        // Total batch = 20500 / 500g = 41 minor/g
        $dough = $this->makeItem('Pastry Dough', 'semi_finished', 'g');
        $rDough = $this->recipeService->createRecipe($tenantId, ['inventory_item_id' => $dough->id, 'name' => 'Dough Recipe'], $this->ctx['owner']->id);
        $vDough = $this->recipeService->createVersion($tenantId, $rDough->id, ['effective_from' => now()->subMinute(), 'yield_quantity' => 500, 'yield_unit' => 'g'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rDough->id, $vDough->id, [
            ['inventory_item_id' => $flour->id, 'quantity' => '300.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $butter->id, 'quantity' => '200.0000', 'unit' => 'g', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rDough->id, $vDough->id, $this->ctx['owner']->id);

        // Sellable: Croissant (Yield: 1 piece)
        // Uses 80g of Pastry Dough: 80g × 41 = 3280
        $croissant = $this->makeVariant('Butter Croissant');
        $rCroissant = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $croissant->id, 'name' => 'Croissant Recipe'], $this->ctx['owner']->id);
        $vCroissant = $this->recipeService->createVersion($tenantId, $rCroissant->id, ['effective_from' => now()->subMinute(), 'yield_quantity' => 1, 'yield_unit' => 'piece'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $rCroissant->id, $vCroissant->id, [
            ['inventory_item_id' => $dough->id, 'quantity' => '80.0000', 'unit' => 'g', 'component_type' => 'semi_finished'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $rCroissant->id, $vCroissant->id, $this->ctx['owner']->id);

        $estimate = $this->recipeService->estimateCost($tenantId, $rCroissant->id, $vCroissant->id, $loc->id);

        $this->assertTrue($estimate['is_complete']);
        $this->assertEquals(3280, $estimate['estimated_total_cost_minor']);
    }

    // =========================================================================
    // 11. COST-LAYER HISTORY DOES NOT SECRETLY IMPLY FIFO VALUATION
    // =========================================================================

    public function test_cost_layer_history_does_not_secretly_imply_fifo_valuation(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $loc = $this->makeLocation('Warehouse 2');
        $item = $this->makeItem('Black Tea', 'raw_material', 'kg');

        // Layer A: 10 kg @ Rp 60,000 (value 600,000)
        $this->inventoryService->setOpeningBalance($tenantId, $item->id, $loc->id, '10.0000', unitCostMinor: 60000);

        // Layer B: 20 kg @ Rp 90,000 (value 1,800,000)
        $this->inventoryService->adjustStock($tenantId, $item->id, $loc->id, '20.0000', 'Purchase Receipt', unitCostMinor: 90000);

        // Current MWA = (600,000 + 1,800,000) / 30 = 80,000
        $mwa = $this->costingService->getAverageCost($tenantId, $item->id, $loc->id);
        $this->assertEquals(80000, $mwa);

        // Verify layers exist as immutable historical provenance (Model A: no live lot depletion)
        $layers = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->orderBy('unit_cost_minor', 'asc')
            ->get());
        $this->assertCount(2, $layers);
        $this->assertEquals('10.0000', $layers[0]->quantity);
        $this->assertEquals(60000, $layers[0]->unit_cost_minor);
        $this->assertEquals('20.0000', $layers[1]->quantity);
        $this->assertEquals(90000, $layers[1]->unit_cost_minor);

        // Model A: Cost layers are strictly immutable after creation
        $this->assertFalse(Schema::hasColumn('inventory_cost_layers', 'remaining_quantity'), 'Must not have remaining_quantity column');

        try {
            $this->runForTenant(function () use ($layers) {
                $layers[0]->quantity = '99.0000';
                $layers[0]->save();
            });
            $this->fail('Expected DomainException when modifying cost layer');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('strictly immutable', $e->getMessage());
        }

        // When evaluating outbound consumption under MWA, it uses location MWA (80,000)
        // NOT layer A unit cost (60,000)
        $outbound = $this->costingService->calculateOutboundCost($tenantId, $item->id, $loc->id, '8.0000');
        $this->assertEquals(80000, $outbound['applied_mwa_minor']);
        $this->assertEquals(640000, $outbound['total_cost_minor']);
        $this->assertNotEquals(480000, $outbound['total_cost_minor'], 'FIFO would produce 8 × 60,000 = 480,000; MWA must produce 640,000');
    }

    // =========================================================================
    // 12. CATALOG SELLING PRICE REMAINS IN DEDICATED PRICE DOMAIN
    // =========================================================================

    public function test_catalog_selling_price_remains_in_dedicated_price_domain(): void
    {
        $tenantId = $this->ctx['tenant']->id;

        // Verify ProductVariant schema: DOES NOT possess a scalar price column
        $this->assertFalse(Schema::hasColumn('product_variants', 'price_minor'), 'ProductVariant must not have price_minor column');
        $this->assertFalse(Schema::hasColumn('product_variants', 'price'), 'ProductVariant must not have price column');

        // Create product variant
        $variant = $this->makeVariant('Signature Kopi Susu');

        // Authoritative price lives in prices table
        $price = $this->runForTenant(fn () => Price::create([
            'tenant_id' => $tenantId,
            'variant_id' => $variant->id,
            'outlet_id' => null, // Default tenant price
            'amount' => 18000,
            'currency' => 'IDR',
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]));

        $this->assertNotNull($price);
        $this->assertEquals(18000, $price->amount);

        // Verify price model connects to variant without variant storing price
        $fetchedPrice = $this->runForTenant(fn () => Price::where('tenant_id', $tenantId)->where('variant_id', $variant->id)->first());
        $this->assertEquals(18000, $fetchedPrice->amount);
    }
}
