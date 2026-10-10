<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\Shift\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class ReceiptAndFirstSaleEndToEndTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $manager;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected InventoryItem $coffeeBeans;

    protected Product $product;

    protected ProductVariant $variant;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected ShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Kopi Sejahtera');
        $tenant = $this->tenantData['tenant'];
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Rudi', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manager Andi', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
        $this->shiftService = app(ShiftService::class);

        // Setup Catalog, Inventory & Recipe within tenant
        $this->runForTenant(function () use ($tenant) {
            // 1. Kitchen Location
            $this->kitchenLocation = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Dapur Kopi',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);

            $this->outlet->update(['default_pos_location_id' => $this->kitchenLocation->id]);

            // 2. Raw Material: Coffee Beans (1000g on-hand @ Rp 200/g)
            $this->coffeeBeans = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'name' => 'Biji Kopi Arabika',
                'sku' => 'RM-KOPI-01',
                'type' => 'raw_material',
                'unit' => 'g',
                'storage_unit' => 'g',
                'storage_factor' => 1.0,
                'is_active' => true,
                'allow_negative_stock' => false,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $this->coffeeBeans->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 1000.0000,
                'reserved' => 0.0000,
            ]);

            InventoryCostState::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $this->coffeeBeans->id,
                'location_id' => $this->kitchenLocation->id,
                'total_quantity' => 1000.0000,
                'total_cost_minor' => 200000,
                'average_cost_minor' => 200,
            ]);

            // 3. Category & Product: Es Kopi Susu @ Rp 18.000 (1.800.000 minor)
            $category = Category::create([
                'tenant_id' => $tenant->id,
                'name' => 'Minuman',
                'slug' => 'minuman',
            ]);

            $this->product = Product::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'name' => 'Es Kopi Susu',
                'slug' => 'es-kopi-susu',
                'status' => 'active',
                'has_variants' => false,
            ]);

            $this->variant = ProductVariant::create([
                'tenant_id' => $tenant->id,
                'product_id' => $this->product->id,
                'name' => 'Reguler',
                'sku' => 'COF-REG',
                'is_default' => true,
                'status' => 'active',
                'sort_order' => 1,
            ]);

            Price::create([
                'tenant_id' => $tenant->id,
                'variant_id' => $this->variant->id,
                'currency' => 'IDR',
                'amount' => 1800000, // Rp 18.000 in minor
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            // 4. Recipe: 20g Biji Kopi per cup
            $recipe = Recipe::create([
                'tenant_id' => $tenant->id,
                'product_variant_id' => $this->variant->id,
                'name' => 'Resep Es Kopi Susu',
                'code' => 'RCP-KOPI',
                'status' => 'published',
                'created_by' => $this->manager->id,
            ]);

            $version = RecipeVersion::create([
                'tenant_id' => $tenant->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'status' => 'published',
                'effective_from' => now()->subDays(2),
                'yield_quantity' => 1.0000,
                'yield_unit' => 'cup',
                'created_by' => $this->manager->id,
                'published_by' => $this->manager->id,
                'published_at' => now()->subDay(),
            ]);

            RecipeComponent::create([
                'tenant_id' => $tenant->id,
                'recipe_version_id' => $version->id,
                'inventory_item_id' => $this->coffeeBeans->id,
                'quantity' => 20.0000,
                'unit' => 'g',
                'sort_order' => 1,
            ]);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    /**
     * E2E Flow A: Walk-in customer (customer = null) with Cash payment and Receipt generation.
     */
    public function test_first_sale_end_to_end_walkin_customer_with_cash_payment_and_receipt(): void
    {
        $tenant = $this->tenantData['tenant'];

        // 1. Open Shift with Rp 100.000 opening float
        $openShiftRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 10000000,
                'notes' => 'Buka shift kasir pagi',
            ]);
        $openShiftRes->assertStatus(201);
        $shiftId = $openShiftRes->json('data.id');

        // 2. Create Sale as Walk-in (customer_id = null), 2 cups of Es Kopi Susu
        $createSaleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $this->outlet->id,
                'customer_id' => null,
                'items' => [
                    [
                        'product_variant_id' => $this->variant->id,
                        'quantity' => 2,
                    ],
                ],
            ]);
        $createSaleRes->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.total_minor', 3600000)
            ->assertJsonPath('data.customer_id', null);

        $saleId = $createSaleRes->json('data.id');

        // 3. Finalize Sale: Atomically deducts inventory (40g consumed)
        $finalizeRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize");
        $finalizeRes->assertStatus(200)
            ->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.total_minor', 3600000);

        // Verify inventory deduction: 1000g - 40g = 960g remaining
        $this->runForTenant(function () {
            $stock = InventoryItemLocation::where('inventory_item_id', $this->coffeeBeans->id)
                ->where('location_id', $this->kitchenLocation->id)
                ->first();
            $this->assertEquals(960.0, (float) $stock->on_hand);
        });

        // 4. Create and Process Cash Payment for Rp 36.000
        $paymentRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 3600000,
                'method' => 'cash',
            ]);
        $paymentRes->assertStatus(201);
        $paymentId = $paymentRes->json('data.id');

        $processRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process");
        $processRes->assertStatus(200)
            ->assertJsonPath('data.status', 'succeeded');

        // 5. Verify Cash Movement exists for the active shift
        $this->runForTenant(function () use ($shiftId, $paymentId) {
            $movement = CashMovement::where('shift_id', $shiftId)
                ->where('reference_type', 'payment')
                ->where('reference_id', $paymentId)
                ->first();

            $this->assertNotNull($movement);
            $this->assertEquals(CashMovement::TYPE_SALE, $movement->type);
            $this->assertEquals(CashMovement::DIRECTION_IN, $movement->direction);
            $this->assertEquals(3600000, $movement->amount_minor);
        });

        // 6. Verify Authoritative Receipt is available
        $receiptRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/pos/sales/{$saleId}/receipt");

        $receiptRes->assertStatus(200)
            ->assertJsonPath('data.sale_id', $saleId)
            ->assertJsonPath('data.total_minor', 3600000)
            ->assertJsonPath('data.paid_amount_minor', 3600000)
            ->assertJsonPath('data.change_amount_minor', 0)
            ->assertJsonPath('data.is_fully_paid', true)
            ->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.reprint_count', 0)
            ->assertJsonPath('data.business.name', $tenant->name)
            ->assertJsonPath('data.outlet.name', $this->outlet->name);

        $receiptNumber = $receiptRes->json('data.receipt_number');
        $this->assertStringStartsWith('REC-', $receiptNumber);

        // 7. Reprint Receipt: increments reprint count without altering financial ledger
        $reprintRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/receipt/reprint");

        $reprintRes->assertStatus(200)
            ->assertJsonPath('data.receipt_number', $receiptNumber)
            ->assertJsonPath('data.reprint_count', 1);

        // Assert no duplicate payment or cash movement was created
        $this->runForTenant(function () use ($saleId) {
            $this->assertEquals(1, Payment::where('sale_id', $saleId)->count());
            $this->assertEquals(1, CashMovement::where('type', CashMovement::TYPE_SALE)->count());
        });
    }

    /**
     * E2E Flow B: Identified customer with Non-Cash (QRIS) payment and Receipt generation.
     */
    public function test_first_sale_end_to_end_identified_customer_with_non_cash_payment(): void
    {
        $tenant = $this->tenantData['tenant'];

        // 1. Open Shift
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 5000000,
            ])->assertStatus(201);

        // 2. Create Customer: Siti Rahma
        $custRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'Siti Rahma',
                'phone' => '081234567888',
                'email' => 'siti@example.com',
            ]);
        $custRes->assertStatus(201);
        $customerId = $custRes->json('data.id');

        // 3. Create Sale with Identified Customer
        $createSaleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customerId,
                'items' => [
                    [
                        'product_variant_id' => $this->variant->id,
                        'quantity' => 1,
                    ],
                ],
            ]);
        $createSaleRes->assertStatus(201)
            ->assertJsonPath('data.customer_id', $customerId)
            ->assertJsonPath('data.total_minor', 1800000);

        $saleId = $createSaleRes->json('data.id');

        // 4. Finalize Sale
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/pos/sales/{$saleId}/finalize")
            ->assertStatus(200);

        // 5. Create and Process QRIS Payment
        $payRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => $saleId,
                'amount_minor' => 1800000,
                'method' => 'qris',
                'provider' => 'mock',
            ]);
        $payRes->assertStatus(201);
        $paymentId = $payRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/payments/{$paymentId}/process")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'succeeded');

        // 6. Assert NO cash movement was created for non-cash payment
        $this->runForTenant(function () {
            $this->assertEquals(0, CashMovement::where('type', CashMovement::TYPE_SALE)->count());
        });

        // 7. Verify Receipt contains customer data
        $receiptRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/pos/sales/{$saleId}/receipt");

        $receiptRes->assertStatus(200)
            ->assertJsonPath('data.customer.id', $customerId)
            ->assertJsonPath('data.customer.name', 'Siti Rahma')
            ->assertJsonPath('data.customer.phone', '+6281234567888')
            ->assertJsonPath('data.total_minor', 1800000);

        // 8. Verify Customer Order History
        $historyRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/customers/{$customerId}/history");

        $historyRes->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sale_id', $saleId)
            ->assertJsonPath('data.0.payment_status', 'paid')
            ->assertJsonPath('data.0.total_minor', 1800000);

        // 9. Verify Customer CRM Summary & Segmentation
        $summaryRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/customers/{$customerId}/summary");

        $summaryRes->assertStatus(200)
            ->assertJsonPath('data.total_transactions', 1)
            ->assertJsonPath('data.successful_transactions', 1)
            ->assertJsonPath('data.total_spend_minor', 1800000)
            ->assertJsonPath('data.segment', Customer::SEGMENT_NEW);
    }

    /**
     * Unfinalized open sale cannot produce a finalized receipt.
     */
    public function test_open_sale_cannot_access_receipt(): void
    {
        $tenant = $this->tenantData['tenant'];

        $createSaleRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $this->outlet->id,
                'items' => [
                    [
                        'product_variant_id' => $this->variant->id,
                        'quantity' => 1,
                    ],
                ],
            ]);
        $saleId = $createSaleRes->json('data.id');

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/pos/sales/{$saleId}/receipt")
            ->assertStatus(422);
    }

    /**
     * Cross-tenant security: Tenant A cannot attach Tenant B customer or access Tenant B receipt.
     */
    public function test_cross_tenant_isolation_on_customer_and_receipt(): void
    {
        $tenantA = $this->tenantData['tenant'];
        $tenantBData = $this->createTenant('Tenant Kompetitor B');
        $tenantB = $tenantBData['tenant'];
        $outletB = $tenantBData['outlet'];
        $cashierB = $tenantBData['owner'];

        // Customer in Tenant A
        $custARes = $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson('/api/v1/customers', ['name' => 'Customer A']);
        $customerAId = $custARes->json('data.id');

        // Tenant B tries to create sale attaching Customer A -> rejected 404
        $this->authenticateTenantUser($cashierB, $tenantB)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outletB->id,
                'customer_id' => $customerAId,
            ])
            ->assertStatus(404);

        // Finalize a sale in Tenant A
        $saleARes = $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $this->outlet->id,
            ]);
        $saleAId = $saleARes->json('data.id');
        $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson("/api/v1/pos/sales/{$saleAId}/finalize");

        // Tenant B attempts to read Tenant A's receipt -> 404
        $this->authenticateTenantUser($cashierB, $tenantB)
            ->getJson("/api/v1/pos/sales/{$saleAId}/receipt")
            ->assertStatus(404);
    }

    /**
     * Outlet authorization: Cashier restricted to Outlet 1 cannot finalize sale for Outlet 2.
     */
    public function test_outlet_authorization_cashier_cannot_finalize_for_unassigned_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Create Outlet 2
        $outlet2 = $this->runForTenant(function () use ($tenant) {
            return Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => 'Outlet Cabang Dua',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);
        });

        // Cashier assigned ONLY to Outlet 1 tries to create sale for Outlet 2
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => $outlet2->id,
            ])
            ->assertStatus(403);
    }
}
