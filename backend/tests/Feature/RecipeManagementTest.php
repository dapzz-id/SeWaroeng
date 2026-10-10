<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\RecipeCycleDetectedException;
use App\Exceptions\RecipeNotConfiguredException;
use App\Exceptions\RecipeVersionInvalidStateException;
use App\Models\InventoryCostLayer;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
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

class RecipeManagementTest extends TenantTestCase
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

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function makeLocation(string $name = 'Main Kitchen'): InventoryLocation
    {
        return app(TenantManager::class)->runForTenant(
            $this->ctx['tenant'],
            fn () => InventoryLocation::create([
                'tenant_id' => $this->ctx['tenant']->id,
                'name' => $name,
                'code' => strtoupper(substr(md5($name.uniqid()), 0, 6)),
                'type' => 'kitchen',
                'is_active' => true,
            ])
        );
    }

    private function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->ctx['tenant'], $callback);
    }

    private function makeInventoryItem(string $name, string $type = 'raw_material', string $unit = 'kg'): InventoryItem
    {
        return app(TenantManager::class)->runForTenant(
            $this->ctx['tenant'],
            fn () => InventoryItem::create([
                'tenant_id' => $this->ctx['tenant']->id,
                'name' => $name,
                'sku' => 'SKU-'.strtoupper(substr(md5($name.uniqid()), 0, 6)),
                'item_type' => $type,
                'base_unit' => $unit,
                'status' => 'active',
            ])
        );
    }

    private function makeProductVariant(string $name): ProductVariant
    {
        return app(TenantManager::class)->runForTenant(
            $this->ctx['tenant'],
            function () use ($name) {
                $product = Product::create([
                    'tenant_id' => $this->ctx['tenant']->id,
                    'name' => $name,
                    'status' => 'active',
                    'created_by' => $this->ctx['owner']->id,
                ]);

                return ProductVariant::create([
                    'tenant_id' => $this->ctx['tenant']->id,
                    'product_id' => $product->id,
                    'name' => 'Standard',
                    'status' => 'active',
                    'is_default' => true,
                ]);
            }
        );
    }

    // =========================================================================
    // RECIPE CRUD
    // =========================================================================

    /** @test */
    public function test_it_creates_a_draft_recipe_via_api(): void
    {
        $this->authenticateTenantUser($this->ctx['owner'], $this->ctx['tenant']);
        $variant = $this->makeProductVariant('Iced Coffee');

        $response = $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $variant->id,
            'name' => 'Iced Coffee Recipe',
            'code' => 'RCP-ICE-01',
        ], ['Idempotency-Key' => 'test-recipe-create-01']);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name', 'Iced Coffee Recipe')
            ->assertJsonPath('data.code', 'RCP-ICE-01');
    }

    /** @test */
    public function test_it_enforces_unique_recipe_code_within_tenant(): void
    {
        $this->authenticateTenantUser($this->ctx['owner'], $this->ctx['tenant']);
        $v1 = $this->makeProductVariant('Latte');
        $v2 = $this->makeProductVariant('Espresso');

        $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $v1->id,
            'name' => 'Latte Recipe',
            'code' => 'RCP-DUPE',
        ], ['Idempotency-Key' => 'test-uniq-1']);

        $response = $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $v2->id,
            'name' => 'Espresso Recipe',
            'code' => 'RCP-DUPE',
        ], ['Idempotency-Key' => 'test-uniq-2']);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_it_enforces_one_recipe_per_variant(): void
    {
        $this->authenticateTenantUser($this->ctx['owner'], $this->ctx['tenant']);
        $variant = $this->makeProductVariant('Cappuccino');

        $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $variant->id,
            'name' => 'Cappuccino Recipe v1',
        ], ['Idempotency-Key' => 'test-one-1']);

        $response = $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $variant->id,
            'name' => 'Cappuccino Recipe v2',
        ], ['Idempotency-Key' => 'test-one-2']);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_it_rejects_update_on_published_recipe(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Green Tea');
        $item = $this->makeInventoryItem('Green Tea Powder');

        $recipe = $this->recipeService->createRecipe($tenantId, [
            'product_variant_id' => $variant->id,
            'name' => 'Green Tea Recipe',
        ], $this->ctx['owner']->id);

        $version = $this->recipeService->createVersion($tenantId, $recipe->id, [
            'effective_from' => now()->subDay(),
            'yield_quantity' => 1,
            'yield_unit' => 'portion',
        ], $this->ctx['owner']->id);

        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [[
            'inventory_item_id' => $item->id,
            'quantity' => '0.0200',
            'unit' => 'kg',
            'component_type' => 'raw_material',
        ]], $this->ctx['owner']->id);

        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $this->expectException(RecipeVersionInvalidStateException::class);
        $this->recipeService->updateRecipe($tenantId, $recipe->id, ['name' => 'New Name'], $this->ctx['owner']->id);
    }

    /** @test */
    public function test_it_archives_a_recipe_and_cascades_to_versions(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Matcha');
        $item = $this->makeInventoryItem('Matcha Powder');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Matcha Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'portion'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0200', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $this->recipeService->archiveRecipe($tenantId, $recipe->id, $this->ctx['owner']->id);

        app(TenantManager::class)->runForTenant($this->ctx['tenant'], function () use ($recipe, $version) {
            $recipe->refresh();
            $version->refresh();

            $this->assertEquals('archived', $recipe->status);
            $this->assertEquals('archived', $version->status);
        });
    }

    // =========================================================================
    // VERSION MANAGEMENT
    // =========================================================================

    /** @test */
    public function test_it_auto_increments_version_numbers_within_recipe(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Black Coffee');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Black Coffee Recipe'], $this->ctx['owner']->id);

        $v1 = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $v2 = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->addMonth(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);

        $this->assertEquals(1, $v1->version_number);
        $this->assertEquals(2, $v2->version_number);
    }

    /** @test */
    public function test_it_rejects_publishing_version_without_components(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Empty Recipe Item');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Empty Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now(), 'yield_quantity' => 1, 'yield_unit' => 'pcs'], $this->ctx['owner']->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);
    }

    /** @test */
    public function test_it_rejects_publishing_already_published_version(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Americano');
        $item = $this->makeInventoryItem('Coffee Beans');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Americano Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0180', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $this->expectException(RecipeVersionInvalidStateException::class);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);
    }

    /** @test */
    public function test_it_rejects_overlapping_published_versions_via_api(): void
    {
        $this->authenticateTenantUser($this->ctx['owner'], $this->ctx['tenant']);
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Milo Dinosaur');
        $item = $this->makeInventoryItem('Milo Powder');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Milo Recipe'], $this->ctx['owner']->id);

        $v1 = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subMonth(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $v1->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0350', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $v1->id, $this->ctx['owner']->id);

        // V2 overlaps V1 (also has no end date)
        $v2 = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subWeek(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $v2->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0400', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);

        $response = $this->postJson("/api/v1/recipes/{$recipe->id}/versions/{$v2->id}/publish", [], ['Idempotency-Key' => 'test-overlap-pub']);
        $response->assertStatus(422);
    }

    // =========================================================================
    // COMPONENT VALIDATION
    // =========================================================================

    /** @test */
    public function test_it_rejects_component_with_wrong_unit(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Wrong Unit Product');
        $item = $this->makeInventoryItem('Sugar', 'raw_material', 'kg');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Bad Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now(), 'yield_quantity' => 1, 'yield_unit' => 'pcs'], $this->ctx['owner']->id);

        $this->expectException(\InvalidArgumentException::class);
        // Providing 'gram' instead of 'kg' — should be rejected
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [[
            'inventory_item_id' => $item->id,
            'quantity' => '10',
            'unit' => 'gram', // WRONG — item's base_unit is 'kg'
            'component_type' => 'raw_material',
        ]], $this->ctx['owner']->id);
    }

    /** @test */
    public function test_it_rejects_disallowed_component_type(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Bad Type Product');
        $item = $this->makeInventoryItem('Water', 'raw_material', 'liter');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Bad Type Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [[
            'inventory_item_id' => $item->id,
            'quantity' => '0.2000',
            'unit' => 'liter',
            'component_type' => 'asset', // NOT ALLOWED
        ]], $this->ctx['owner']->id);
    }

    // =========================================================================
    // CYCLE DETECTION
    // =========================================================================

    /** @test */
    public function test_it_detects_direct_cycle_in_bom(): void
    {
        $tenantId = $this->ctx['tenant']->id;

        // Item A → recipe requires Item B
        // Item B → recipe requires Item A  ← CYCLE
        $variantA = $this->makeProductVariant('Product A');
        $itemA = $this->makeInventoryItem('Item A', 'semi_finished', 'kg');

        // Link itemA to variantA
        app(TenantManager::class)->runForTenant($this->ctx['tenant'], function () use ($itemA, $variantA) {
            $itemA->product_variant_id = $variantA->id;
            $itemA->save();
        });

        $variantB = $this->makeProductVariant('Product B');
        $itemB = $this->makeInventoryItem('Item B', 'semi_finished', 'kg');

        // Recipe A: uses Item B
        $recipeA = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variantA->id, 'name' => 'Recipe A'], $this->ctx['owner']->id);
        $verA = $this->recipeService->createVersion($tenantId, $recipeA->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'kg'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipeA->id, $verA->id, [['inventory_item_id' => $itemB->id, 'quantity' => '0.5000', 'unit' => 'kg', 'component_type' => 'semi_finished']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipeA->id, $verA->id, $this->ctx['owner']->id);

        // Recipe B: uses Item A → creates cycle A→B→A
        app(TenantManager::class)->runForTenant($this->ctx['tenant'], function () use ($itemB, $variantB) {
            $itemB->product_variant_id = $variantB->id;
            $itemB->save();
        });

        $recipeB = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variantB->id, 'name' => 'Recipe B'], $this->ctx['owner']->id);
        $verB = $this->recipeService->createVersion($tenantId, $recipeB->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'kg'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipeB->id, $verB->id, [['inventory_item_id' => $itemA->id, 'quantity' => '0.5000', 'unit' => 'kg', 'component_type' => 'semi_finished']], $this->ctx['owner']->id);

        $this->expectException(RecipeCycleDetectedException::class);
        $this->recipeService->publishVersion($tenantId, $recipeB->id, $verB->id, $this->ctx['owner']->id);
    }

    // =========================================================================
    // RECIPE RESOLUTION
    // =========================================================================

    /** @test */
    public function test_it_resolves_correct_version_by_timestamp(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Teh Tarik');
        $item = $this->makeInventoryItem('Teh Tarik Powder');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Teh Tarik Recipe'], $this->ctx['owner']->id);

        // V1: effective 1 month ago to yesterday
        $v1 = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subMonth(), 'effective_until' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $v1->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0300', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $v1->id, $this->ctx['owner']->id);

        // V2: effective today
        $v2 = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $v2->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0350', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $v2->id, $this->ctx['owner']->id);

        // Resolve at 2 weeks ago → should get V1
        $resolvedOld = $this->recipeService->resolveRecipe($tenantId, $variant->id, now()->subWeeks(2));
        $this->assertEquals($v1->id, $resolvedOld['version']->id);

        // Resolve at now → should get V2
        $resolvedNow = $this->recipeService->resolveRecipe($tenantId, $variant->id, now()->addHour());
        $this->assertEquals($v2->id, $resolvedNow['version']->id);
    }

    /** @test */
    public function test_it_throws_recipe_not_configured_for_missing_recipe(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Unloved Product');

        $this->expectException(RecipeNotConfiguredException::class);
        $this->recipeService->resolveRecipe($tenantId, $variant->id);
    }

    // =========================================================================
    // COSTING — MWA CALCULATION
    // =========================================================================

    /** @test */
    public function test_costing_service_computes_mwa_correctly(): void
    {
        // Fresh cost basis: on_hand = 0
        $mwa0 = $this->costingService->computeMwa('0', '0', '100', '15000');
        $this->assertEquals(15000, $mwa0);

        // Weighted: 100kg @ 15000 + 50kg @ 18000 = (1500000 + 900000) / 150 = 16000
        $mwa1 = $this->costingService->computeMwa('100', '15000', '50', '18000');
        $this->assertEquals(16000, $mwa1);

        // Weighted with fractional quantities
        // 10kg @ 10000 + 5kg @ 13000 = (100000 + 65000) / 15 = 11000
        $mwa2 = $this->costingService->computeMwa('10', '10000', '5', '13000');
        $this->assertEquals(11000, $mwa2);
    }

    /** @test */
    public function test_costing_service_uses_no_float_arithmetic(): void
    {
        // Verify that total_cost_minor calculation avoids float
        $totalCost = $this->costingService->calculateTotalCostMinor('18.5000', 15000);
        // 18.5 * 15000 = 277500
        $this->assertEquals(277500, $totalCost);
        $this->assertIsInt($totalCost);
    }

    /** @test */
    public function test_purchase_receipt_creates_cost_layer_atomically(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $tenantManager = app(TenantManager::class);

        $location = $this->makeLocation();
        $item = $this->makeInventoryItem('Coffee Beans');

        $supplier = $tenantManager->runForTenant($this->ctx['tenant'], fn () => Supplier::create([
            'tenant_id' => $tenantId,
            'code' => 'SUP-COFFEE',
            'name' => 'Coffee Supplier',
            'status' => 'active',
        ]));

        $po = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'ordered_at' => now(),
            'items' => [[
                'inventory_item_id' => $item->id,
                'ordered_quantity' => '100.0000',
                'unit_cost_minor' => 15000,
            ]],
        ], $this->ctx['owner']->id);

        $this->purchasingService->approvePurchaseOrder($tenantId, $po->id, $this->ctx['owner']->id);

        $receipt = $this->purchasingService->createReceipt($tenantId, $po->id, [
            [
                'purchase_order_item_id' => $po->items->first()->id,
                'quantity' => '50.0000',
                'unit_cost_minor' => 15000,
            ],
        ], $this->ctx['owner']->id);

        $this->purchasingService->postReceipt($tenantId, $receipt->id, $this->ctx['owner']->id);

        // Verify cost layer created
        $layer = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->first());

        $this->assertNotNull($layer);
        $this->assertEquals('50.0000', $layer->quantity);
        $this->assertEquals(15000, $layer->unit_cost_minor);
        $this->assertEquals(750000, $layer->total_cost_minor); // 50 * 15000
        $this->assertEquals('purchase_receipt', $layer->source_type);

        // Verify cost state MWA
        $state = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->first());

        $this->assertNotNull($state);
        $this->assertEquals(15000, $state->average_cost_minor);
    }

    /** @test */
    public function test_duplicate_receipt_replay_does_not_create_duplicate_cost_layer(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $tenantManager = app(TenantManager::class);

        $location = $this->makeLocation('Test Idempotent Location');
        $item = $this->makeInventoryItem('Tea Leaves');

        $supplier = $tenantManager->runForTenant($this->ctx['tenant'], fn () => Supplier::create([
            'tenant_id' => $tenantId, 'code' => 'SUP-TEA', 'name' => 'Tea Supplier', 'status' => 'active',
        ]));

        $po = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'ordered_at' => now(),
            'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '100.0000', 'unit_cost_minor' => 10000]],
        ], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po->id, $this->ctx['owner']->id);

        $receipt = $this->purchasingService->createReceipt($tenantId, $po->id, [[
            'purchase_order_item_id' => $po->items->first()->id,
            'quantity' => '50.0000',
            'unit_cost_minor' => 10000,
        ]], $this->ctx['owner']->id);

        $this->purchasingService->postReceipt($tenantId, $receipt->id, $this->ctx['owner']->id);

        // Attempt to post same receipt again — should throw (receipt is posted)
        try {
            $this->purchasingService->postReceipt($tenantId, $receipt->id, $this->ctx['owner']->id);
            $this->fail('Expected exception when posting already posted receipt');
        } catch (\Throwable) {
            // Expected
        }

        // Only one cost layer must exist
        $count = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)->where('inventory_item_id', $item->id)->count());
        $this->assertEquals(1, $count);
    }

    /** @test */
    public function test_weighted_average_updates_correctly_after_second_receipt(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $tenantManager = app(TenantManager::class);

        $location = $this->makeLocation('MWA Test Location');
        $item = $this->makeInventoryItem('Rice');

        $supplier = $tenantManager->runForTenant($this->ctx['tenant'], fn () => Supplier::create([
            'tenant_id' => $tenantId, 'code' => 'SUP-RICE', 'name' => 'Rice Supplier', 'status' => 'active',
        ]));

        // First receipt: 100kg @ 10000/kg
        $po1 = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'ordered_at' => now(),
            'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '100.0000', 'unit_cost_minor' => 10000]],
        ], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po1->id, $this->ctx['owner']->id);
        $r1 = $this->purchasingService->createReceipt($tenantId, $po1->id, [['purchase_order_item_id' => $po1->items->first()->id, 'quantity' => '100.0000', 'unit_cost_minor' => 10000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $r1->id, $this->ctx['owner']->id);

        // Second receipt: 50kg @ 13000/kg
        // Expected MWA: (100*10000 + 50*13000) / 150 = 1650000 / 150 = 11000
        $po2 = $this->purchasingService->createPurchaseOrder($tenantId, [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'ordered_at' => now(),
            'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '50.0000', 'unit_cost_minor' => 13000]],
        ], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po2->id, $this->ctx['owner']->id);
        $r2 = $this->purchasingService->createReceipt($tenantId, $po2->id, [['purchase_order_item_id' => $po2->items->first()->id, 'quantity' => '50.0000', 'unit_cost_minor' => 13000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $r2->id, $this->ctx['owner']->id);

        $state = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)
            ->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)
            ->first());

        $this->assertNotNull($state);
        $this->assertEquals(11000, $state->average_cost_minor);
    }

    /** @test */
    public function test_it_maintains_location_specific_cost_states(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $tenantManager = app(TenantManager::class);

        $locA = $this->makeLocation('Location A');
        $locB = $this->makeLocation('Location B');
        $item = $this->makeInventoryItem('Milk');

        $supplier = $tenantManager->runForTenant($this->ctx['tenant'], fn () => Supplier::create([
            'tenant_id' => $tenantId, 'code' => 'SUP-MILK', 'name' => 'Milk Supplier', 'status' => 'active',
        ]));

        // Receive 100 units @ 12000 at Location A
        $poA = $this->purchasingService->createPurchaseOrder($tenantId, ['supplier_id' => $supplier->id, 'destination_location_id' => $locA->id, 'ordered_at' => now(), 'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '100.0000', 'unit_cost_minor' => 12000]]], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $poA->id, $this->ctx['owner']->id);
        $rA = $this->purchasingService->createReceipt($tenantId, $poA->id, [['purchase_order_item_id' => $poA->items->first()->id, 'quantity' => '100.0000', 'unit_cost_minor' => 12000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $rA->id, $this->ctx['owner']->id);

        // Receive 50 units @ 15000 at Location B
        $poB = $this->purchasingService->createPurchaseOrder($tenantId, ['supplier_id' => $supplier->id, 'destination_location_id' => $locB->id, 'ordered_at' => now(), 'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '50.0000', 'unit_cost_minor' => 15000]]], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $poB->id, $this->ctx['owner']->id);
        $rB = $this->purchasingService->createReceipt($tenantId, $poB->id, [['purchase_order_item_id' => $poB->items->first()->id, 'quantity' => '50.0000', 'unit_cost_minor' => 15000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $rB->id, $this->ctx['owner']->id);

        $stateA = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)->where('inventory_item_id', $item->id)->where('location_id', $locA->id)->first());
        $stateB = $this->runForTenant(fn () => InventoryCostState::where('tenant_id', $tenantId)->where('inventory_item_id', $item->id)->where('location_id', $locB->id)->first());

        // Location A: 12000, Location B: 15000 — DIFFERENT average costs
        $this->assertEquals(12000, $stateA->average_cost_minor);
        $this->assertEquals(15000, $stateB->average_cost_minor);
    }

    // =========================================================================
    // RECIPE COST ESTIMATION
    // =========================================================================

    /** @test */
    public function test_recipe_cost_estimation_returns_correct_values(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $tenantManager = app(TenantManager::class);

        $location = $this->makeLocation('Estimation Kitchen');
        $itemCoffee = $this->makeInventoryItem('Coffee Beans Est', 'raw_material', 'kg');
        $itemMilk = $this->makeInventoryItem('Milk Est', 'raw_material', 'liter');
        $variant = $this->makeProductVariant('Iced Latte Est');

        // Set up cost states via receipt
        $supplier = $tenantManager->runForTenant($this->ctx['tenant'], fn () => Supplier::create([
            'tenant_id' => $tenantId, 'code' => 'SUP-EST', 'name' => 'Est Supplier', 'status' => 'active',
        ]));

        // Coffee: 100 kg @ Rp 100,000/kg
        $poCoffee = $this->purchasingService->createPurchaseOrder($tenantId, ['supplier_id' => $supplier->id, 'destination_location_id' => $location->id, 'ordered_at' => now(), 'items' => [['inventory_item_id' => $itemCoffee->id, 'ordered_quantity' => '100.0000', 'unit_cost_minor' => 100000]]], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $poCoffee->id, $this->ctx['owner']->id);
        $rCoffee = $this->purchasingService->createReceipt($tenantId, $poCoffee->id, [['purchase_order_item_id' => $poCoffee->items->first()->id, 'quantity' => '100.0000', 'unit_cost_minor' => 100000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $rCoffee->id, $this->ctx['owner']->id);

        // Milk: 200 liters @ Rp 15,000/liter
        $poMilk = $this->purchasingService->createPurchaseOrder($tenantId, ['supplier_id' => $supplier->id, 'destination_location_id' => $location->id, 'ordered_at' => now(), 'items' => [['inventory_item_id' => $itemMilk->id, 'ordered_quantity' => '200.0000', 'unit_cost_minor' => 15000]]], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $poMilk->id, $this->ctx['owner']->id);
        $rMilk = $this->purchasingService->createReceipt($tenantId, $poMilk->id, [['purchase_order_item_id' => $poMilk->items->first()->id, 'quantity' => '200.0000', 'unit_cost_minor' => 15000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $rMilk->id, $this->ctx['owner']->id);

        // Recipe: yield 10 portions
        //   Coffee: 0.180 kg (per batch of 10) → 0.018 kg per portion @ 100000/kg = 1800
        //   Milk: 1.200 liter (per batch of 10) → 0.120 liter per portion @ 15000/liter = 1800
        //   Total per portion: 3600
        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Iced Latte Est Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 10, 'yield_unit' => 'portion'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [
            ['inventory_item_id' => $itemCoffee->id, 'quantity' => '0.1800', 'unit' => 'kg', 'component_type' => 'raw_material'],
            ['inventory_item_id' => $itemMilk->id, 'quantity' => '1.2000', 'unit' => 'liter', 'component_type' => 'raw_material'],
        ], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $estimate = $this->recipeService->estimateCost($tenantId, $recipe->id, $version->id, $location->id);

        $this->assertTrue($estimate['is_complete']);
        $this->assertEquals(3600, $estimate['estimated_total_cost_minor']);
        $this->assertEquals('Estimasi Biaya', $estimate['cost_label']);
        $this->assertNotEmpty($estimate['disclaimer']);
    }

    /** @test */
    public function test_recipe_cost_estimate_shows_incomplete_when_missing_cost_data(): void
    {
        $tenantId = $this->ctx['tenant']->id;

        $location = $this->makeLocation('No Cost Kitchen');
        $item = $this->makeInventoryItem('Exotic Spice', 'raw_material', 'kg');
        $variant = $this->makeProductVariant('Exotic Drink');

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Exotic Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'cup'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0100', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $estimate = $this->recipeService->estimateCost($tenantId, $recipe->id, $version->id, $location->id);

        $this->assertFalse($estimate['is_complete']);
        $this->assertNull($estimate['estimated_total_cost_minor']);
    }

    // =========================================================================
    // TENANT ISOLATION (RLS)
    // =========================================================================

    /** @test */
    public function test_recipe_is_isolated_between_tenants(): void
    {
        $ctx2 = $this->createTenant('Second Business');

        $this->authenticateTenantUser($this->ctx['owner'], $this->ctx['tenant']);
        $variant = $this->makeProductVariant('Tenant 1 Product');

        $response = $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $variant->id,
            'name' => 'Tenant 1 Recipe',
        ], ['Idempotency-Key' => 'rls-test-001']);
        $response->assertStatus(201);
        $recipeId = $response->json('data.id');

        // Tenant 2 should not be able to see Tenant 1's recipe
        $this->authenticateTenantUser($ctx2['owner'], $ctx2['tenant']);
        $this->getJson("/api/v1/recipes/{$recipeId}")->assertStatus(404);
    }

    // =========================================================================
    // RBAC
    // =========================================================================

    /** @test */
    public function test_cashier_cannot_create_recipe(): void
    {
        $cashier = $this->addMemberToTenant($this->ctx['tenant'], 'cashier', 'Test Cashier');
        $this->authenticateTenantUser($cashier['user'], $this->ctx['tenant']);

        $variant = $this->makeProductVariant('Cashier Blocked Product');

        $response = $this->postJson('/api/v1/recipes', [
            'product_variant_id' => $variant->id,
            'name' => 'Should Fail',
        ], ['Idempotency-Key' => 'rbac-cashier-01']);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_cashier_cannot_view_recipe_cost(): void
    {
        $cashier = $this->addMemberToTenant($this->ctx['tenant'], 'cashier', 'Cashier Cost User');
        $variant = $this->makeProductVariant('Cost Restricted Product');
        $item = $this->makeInventoryItem('Cost Item', 'raw_material', 'kg');
        $tenantId = $this->ctx['tenant']->id;

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Cost Test Recipe'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now()->subDay(), 'yield_quantity' => 1, 'yield_unit' => 'pcs'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [['inventory_item_id' => $item->id, 'quantity' => '0.0100', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);
        $this->recipeService->publishVersion($tenantId, $recipe->id, $version->id, $this->ctx['owner']->id);

        $this->authenticateTenantUser($cashier['user'], $this->ctx['tenant']);
        $this->getJson("/api/v1/recipes/{$recipe->id}/cost")->assertStatus(403);
    }

    // =========================================================================
    // REGRESSION: EXISTING INVARIANTS
    // =========================================================================

    /** @test */
    public function test_draft_recipe_does_not_affect_inventory(): void
    {
        // Creating a recipe in draft status must NOT trigger any inventory movements
        $tenantId = $this->ctx['tenant']->id;
        $variant = $this->makeProductVariant('Draft No Impact');
        $item = $this->makeInventoryItem('Draft Item');

        $before = $this->runForTenant(fn () => InventoryMovement::where('tenant_id', $tenantId)->count());

        $recipe = $this->recipeService->createRecipe($tenantId, ['product_variant_id' => $variant->id, 'name' => 'Draft Recipe No Impact'], $this->ctx['owner']->id);
        $version = $this->recipeService->createVersion($tenantId, $recipe->id, ['effective_from' => now(), 'yield_quantity' => 1, 'yield_unit' => 'pcs'], $this->ctx['owner']->id);
        $this->recipeService->setComponents($tenantId, $recipe->id, $version->id, [['inventory_item_id' => $item->id, 'quantity' => '0.1000', 'unit' => 'kg', 'component_type' => 'raw_material']], $this->ctx['owner']->id);

        $after = $this->runForTenant(fn () => InventoryMovement::where('tenant_id', $tenantId)->count());

        $this->assertEquals($before, $after, 'Draft recipe must not create inventory movements.');
    }

    /** @test */
    public function test_cost_layer_is_immutable_after_creation(): void
    {
        $tenantId = $this->ctx['tenant']->id;
        $tenantManager = app(TenantManager::class);
        $location = $this->makeLocation('Immutable Test Location');
        $item = $this->makeInventoryItem('Immutable Item');
        $supplier = $tenantManager->runForTenant($this->ctx['tenant'], fn () => Supplier::create(['tenant_id' => $tenantId, 'code' => 'SUP-IMM', 'name' => 'Imm Supplier', 'status' => 'active']));

        $po = $this->purchasingService->createPurchaseOrder($tenantId, ['supplier_id' => $supplier->id, 'destination_location_id' => $location->id, 'ordered_at' => now(), 'items' => [['inventory_item_id' => $item->id, 'ordered_quantity' => '10.0000', 'unit_cost_minor' => 5000]]], $this->ctx['owner']->id);
        $this->purchasingService->approvePurchaseOrder($tenantId, $po->id, $this->ctx['owner']->id);
        $r = $this->purchasingService->createReceipt($tenantId, $po->id, [['purchase_order_item_id' => $po->items->first()->id, 'quantity' => '10.0000', 'unit_cost_minor' => 5000]], $this->ctx['owner']->id);
        $this->purchasingService->postReceipt($tenantId, $r->id, $this->ctx['owner']->id);

        $layer = $this->runForTenant(fn () => InventoryCostLayer::where('tenant_id', $tenantId)->where('inventory_item_id', $item->id)->first());

        $this->expectException(\DomainException::class);
        $this->runForTenant(function () use ($layer) {
            $layer->unit_cost_minor = 99999;
            $layer->save();
        });
    }
}
