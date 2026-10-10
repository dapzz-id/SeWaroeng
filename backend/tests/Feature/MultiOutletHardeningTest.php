<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductOutletAvailability;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Services\PosService;
use App\Services\Shift\ShiftService;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class MultiOutletHardeningTest extends TenantTestCase
{
    protected array $tenantData;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected array $cashierOutlet1;

    protected array $managerOutlet1;

    protected array $managerOutlet2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Enak');
        $this->outlet1 = $this->tenantData['outlet'];

        // Create Outlet 2
        $this->outlet2 = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () {
            $o = Outlet::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Waroeng Enak - Cabang 2',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);

            InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $o->id,
                'name' => 'Toko Cabang 2',
                'code' => 'LOC-CB2',
                'type' => 'store',
                'is_active' => true,
            ]);

            return $o;
        });

        // Cashier 1 assigned only to Outlet 1
        $this->cashierOutlet1 = $this->addMemberToTenant(
            $this->tenantData['tenant'],
            'cashier',
            'Kasir Satu',
            [(string) $this->outlet1->id]
        );

        // Manager 1 assigned only to Outlet 1
        $this->managerOutlet1 = $this->addMemberToTenant(
            $this->tenantData['tenant'],
            'manager',
            'Manajer Satu',
            [(string) $this->outlet1->id]
        );

        // Manager 2 assigned only to Outlet 2
        $this->managerOutlet2 = $this->addMemberToTenant(
            $this->tenantData['tenant'],
            'manager',
            'Manajer Dua',
            [(string) $this->outlet2->id]
        );
    }

    public function test_cashier_cannot_create_or_mutate_sale_at_unassigned_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        // 1. Cashier 1 attempts to create sale at Outlet 2 -> FORBIDDEN
        $res = $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => (string) $this->outlet2->id,
            ]);

        $res->assertStatus(403);
        $res->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // 2. Owner creates sale at Outlet 2
        $sale = app(TenantManager::class)->runForTenant($tenant, function () {
            return app(PosService::class)->createSale(
                tenantId: $this->tenantData['tenant']->id,
                outletId: $this->outlet2->id,
                cashierUserId: $this->tenantData['owner']->id
            );
        });

        // 3. Cashier 1 tries to view, update, finalize sale of Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->getJson("/api/v1/pos/sales/{$sale->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->putJson("/api/v1/pos/sales/{$sale->id}", ['notes' => 'Hacked'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson("/api/v1/pos/sales/{$sale->id}/finalize", ['payment_method' => 'cash'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');
    }

    public function test_pos_catalog_filters_unavailable_products_and_rejects_sale(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Create product with variant and price
        [$product, $variant] = app(TenantManager::class)->runForTenant($tenant, function () {
            $cat = Category::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Makanan',
                'slug' => 'makanan',
            ]);

            $p = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $cat->id,
                'name' => 'Nasi Padang Spesial',
                'slug' => 'nasi-padang-spesial',
                'product_type' => 'simple',
                'status' => 'active',
            ]);

            $v = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $p->id,
                'name' => 'Default',
                'sku' => 'NPD-01',
                'status' => 'active',
            ]);

            $v->prices()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'amount' => 25000,
                'currency' => 'IDR',
                'effective_from' => now()->subMinute(),
                'is_active' => true,
            ]);

            // Disable product availability at Outlet 1
            ProductOutletAvailability::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $p->id,
                'outlet_id' => $this->outlet1->id,
                'is_available' => false,
            ]);

            return [$p, $v];
        });

        // 1. POS catalog for Outlet 1 should NOT contain this product
        $catalogRes = $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->getJson("/api/v1/pos/catalog?outlet_id={$this->outlet1->id}");

        $catalogRes->assertStatus(200);
        $products = collect($catalogRes->json('data.products'));
        $this->assertFalse($products->contains('id', (string) $product->id));

        // 2. POS catalog for Outlet 2 should contain this product
        $catalogRes2 = $this->authenticateTenantUser($this->tenantData['owner'], $tenant)
            ->getJson("/api/v1/pos/catalog?outlet_id={$this->outlet2->id}");

        $catalogRes2->assertStatus(200);
        $products2 = collect($catalogRes2->json('data.products'));
        $this->assertTrue($products2->contains('id', (string) $product->id));

        // 3. Creating sale with this variant at Outlet 1 should be rejected
        $saleRes = $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson('/api/v1/pos/sales', [
                'outlet_id' => (string) $this->outlet1->id,
                'items' => [
                    [
                        'product_variant_id' => (string) $variant->id,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $saleRes->assertStatus(422);
        $saleRes->assertJsonPath('code', 'PRODUCT_NOT_AVAILABLE_AT_OUTLET');
    }

    public function test_payment_and_receipt_isolated_by_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Create finalized sale at Outlet 2
        $sale = app(TenantManager::class)->runForTenant($tenant, function () {
            $cat = Category::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Minuman',
                'slug' => 'minuman',
            ]);
            $p = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $cat->id,
                'name' => 'Es Teh Manis',
                'slug' => 'es-teh-manis',
                'product_type' => 'simple',
                'status' => 'active',
            ]);
            $v = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $p->id,
                'name' => 'Reguler',
                'sku' => 'ETM-01',
                'status' => 'active',
            ]);
            $v->prices()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'amount' => 5000,
                'currency' => 'IDR',
                'effective_from' => now()->subMinute(),
                'is_active' => true,
            ]);

            $pos = app(PosService::class);
            $s = $pos->createSale(
                tenantId: $this->tenantData['tenant']->id,
                outletId: $this->outlet2->id,
                cashierUserId: $this->tenantData['owner']->id,
                items: [['product_variant_id' => $v->id, 'quantity' => 1]]
            );

            return $pos->finalizeSale(
                sale: $s,
                options: [],
                userId: $this->tenantData['owner']->id
            );
        });

        // Cashier 1 attempts to store payment against Outlet 2 sale -> FORBIDDEN
        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson('/api/v1/payments', [
                'sale_id' => (string) $sale->id,
                'amount_minor' => 5000,
                'method' => 'cash',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Cashier 1 attempts to access receipt for Outlet 2 sale -> FORBIDDEN
        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->getJson("/api/v1/pos/sales/{$sale->id}/receipt")
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');
    }

    public function test_shift_operations_blocked_for_unassigned_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Cashier 1 attempts to open shift at Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson("/api/v1/outlets/{$this->outlet2->id}/shifts/open", [
                'opening_cash_minor' => 100000,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Owner opens shift at Outlet 2
        $shift = app(TenantManager::class)->runForTenant($tenant, function () {
            return app(ShiftService::class)->openShift(
                outlet: $this->outlet2,
                openingCashMinor: 100000,
                user: $this->tenantData['owner']
            );
        });

        // Cashier 1 attempts to cashIn or close Outlet 2 shift -> FORBIDDEN
        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson("/api/v1/shifts/{$shift->id}/cash-in", [
                'amount_minor' => 50000,
                'reason' => 'Test',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson("/api/v1/shifts/{$shift->id}/close", [
                'actual_closing_cash_minor' => 150000,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');
    }

    public function test_financial_reports_and_expenses_scoped_by_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Manager 1 attempts to read P&L for Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->managerOutlet1['user'], $tenant)
            ->getJson("/api/v1/finance/profit-loss?outlet_id={$this->outlet2->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Manager 1 attempts to create expense for Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->managerOutlet1['user'], $tenant)
            ->postJson('/api/v1/finance/expenses', [
                'outlet_id' => (string) $this->outlet2->id,
                'category' => 'Operasional',
                'amount_minor' => 15000,
                'expense_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Owner can read P&L tenant-wide or per-outlet
        $this->authenticateTenantUser($this->tenantData['owner'], $tenant)
            ->getJson('/api/v1/finance/profit-loss')
            ->assertStatus(200);

        $this->authenticateTenantUser($this->tenantData['owner'], $tenant)
            ->getJson("/api/v1/finance/profit-loss?outlet_id={$this->outlet1->id}")
            ->assertStatus(200);
    }

    public function test_offline_sync_sales_create_rejected_for_unassigned_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        $batch = [
            'client_id' => 'device-pos-1',
            'device_id' => 'pos-terminal-a',
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.create',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => (string) Str::uuid7(),
                    'payload' => [
                        'outlet_id' => (string) $this->outlet2->id,
                        'items' => [],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashierOutlet1['user'], $tenant)
            ->postJson('/api/v1/sync', $batch);

        $res->assertStatus(200);
        $result = $res->json('data.results.0');
        $this->assertEquals('blocked', $result['status']);
        $this->assertEquals('OUTLET_ACCESS_DENIED', $result['code']);
    }

    public function test_stock_transfer_access_control(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Create locations for Outlet 1 and Outlet 2
        [$loc1, $loc2, $item] = app(TenantManager::class)->runForTenant($tenant, function () {
            $l1 = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet1->id,
                'name' => 'Gudang Outlet 1',
                'code' => 'LOC-01',
                'type' => 'store',
                'is_active' => true,
            ]);

            $l2 = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet2->id,
                'name' => 'Gudang Outlet 2',
                'code' => 'LOC-02',
                'type' => 'store',
                'is_active' => true,
            ]);

            $it = InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Beras Pandan Wangi',
                'sku' => 'BRS-01',
                'base_unit' => 'kg',
                'cost_minor' => 12000,
            ]);

            return [$l1, $l2, $it];
        });

        // Manager 2 (assigned only to Outlet 2) attempts to create transfer from Outlet 1 (loc1) -> FORBIDDEN
        $this->authenticateTenantUser($this->managerOutlet2['user'], $tenant)
            ->postJson('/api/v1/inventory/transfers', [
                'source_location_id' => (string) $loc1->id,
                'destination_location_id' => (string) $loc2->id,
                'items' => [
                    [
                        'inventory_item_id' => (string) $item->id,
                        'quantity' => 10,
                    ],
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');
    }

    public function test_tables_and_bookings_scoped_by_outlet(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Manager 1 attempts to create table at Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->managerOutlet1['user'], $tenant)
            ->postJson('/api/v1/tables', [
                'outlet_id' => (string) $this->outlet2->id,
                'code' => 'T-02',
                'name' => 'Meja 2',
                'capacity' => 4,
            ])
            ->assertStatus(403);

        // Manager 1 attempts to create booking at Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->managerOutlet1['user'], $tenant)
            ->postJson('/api/v1/bookings', [
                'outlet_id' => (string) $this->outlet2->id,
                'party_size' => 2,
                'starts_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertStatus(403);
    }

    public function test_dashboard_outlet_authorization(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Manager 1 attempts to query dashboard metrics for Outlet 2 -> FORBIDDEN
        $this->authenticateTenantUser($this->managerOutlet1['user'], $tenant)
            ->getJson("/api/v1/dashboard?outlet_id={$this->outlet2->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Manager 1 queries without outlet_id -> 200 (scoped to their assigned outlet)
        $this->authenticateTenantUser($this->managerOutlet1['user'], $tenant)
            ->getJson('/api/v1/dashboard')
            ->assertStatus(200);

        // Owner queries Outlet 2 -> 200
        $this->authenticateTenantUser($this->tenantData['owner'], $tenant)
            ->getJson("/api/v1/dashboard?outlet_id={$this->outlet2->id}")
            ->assertStatus(200);
    }
}
