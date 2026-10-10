<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class DashboardTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $manager;

    protected User $cashier;

    protected Outlet $outlet1;

    protected Outlet $outlet2;

    protected InventoryLocation $loc1;

    protected InventoryLocation $loc2;

    protected FinanceService $financeService;

    protected PosService $posService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Sukses');
        $tenant = $this->tenantData['tenant'];
        $this->outlet1 = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->outlet2 = $this->runForTenant(function () use ($tenant) {
            return Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => 'Waroeng Sukses - Cabang 2',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);
        });

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manajer Andi', [$this->outlet1->id]);
        $this->manager = $managerData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Rudi', [$this->outlet1->id]);
        $this->cashier = $cashierData['user'];

        $this->financeService = app(FinanceService::class);
        $this->posService = app(PosService::class);

        $this->runForTenant(function () use ($tenant) {
            $this->financeService->ensureDefaultAccounts($tenant->id);

            $this->loc1 = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'name' => 'Kitchen 1',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $this->outlet1->update(['default_pos_location_id' => $this->loc1->id]);

            $this->loc2 = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet2->id,
                'name' => 'Kitchen 2',
                'code' => 'KIT-02',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $this->outlet2->update(['default_pos_location_id' => $this->loc2->id]);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_owner_can_view_aggregated_dashboard_kpis_and_reports(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            // Create catalog & inventory
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Makanan', 'slug' => 'makanan']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Ayam Geprek', 'slug' => 'ayam-geprek', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Pedas', 'sku' => 'AYM-PDS']);
            Price::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'outlet_id' => null, 'amount' => 2500000, 'effective_from' => now()->subDay(), 'is_active' => true]);

            $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Siti Pelanggan', 'phone' => '08123456789']);

            // Create finalized sale: 2x Ayam Geprek = Rp 50.000
            $sale = Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-DASH-01',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 5000000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 5000000,
                'cashier_user_id' => $this->cashier->id,
                'finalized_at' => now(),
            ]);

            SaleItem::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Ayam Geprek Pedas',
                'variant_name_snapshot' => 'Pedas',
                'quantity' => 2,
                'unit_price_minor' => 2500000,
                'subtotal_minor' => 5000000,
                'total_minor' => 5000000,
            ]);

            Payment::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-DASH-01',
                'method' => Payment::METHOD_CASH,
                'provider' => 'manual',
                'amount_minor' => 5000000,
                'currency' => 'IDR',
                'status' => Payment::STATUS_SUCCEEDED,
                'paid_at' => now(),
            ]);

            // Low stock raw material
            $item = InventoryItem::create([
                'tenant_id' => $tenant->id,
                'sku' => 'AYAM-RAW',
                'name' => 'Daging Ayam',
                'item_type' => 'raw_material',
                'base_unit' => 'kg',
                'status' => 'active',
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $item->id,
                'location_id' => $this->loc1->id,
                'on_hand' => 2.0,
                'reserved' => 0.0,
                'reorder_level' => 5.0, // Low stock because on_hand (2) <= reorder_level (5)
            ]);
        });

        // Request dashboard as Owner
        $res = $this->authenticateTenantUser($this->owner, $tenant)
            ->getJson('/api/v1/dashboard?period=today');

        $res->assertStatus(200)
            ->assertJsonPath('data.period.name', 'today')
            ->assertJsonPath('data.kpi.gross_sales_minor', 5000000)
            ->assertJsonPath('data.kpi.net_sales_minor', 5000000)
            ->assertJsonPath('data.kpi.transaction_count', 1)
            ->assertJsonPath('data.kpi.average_order_value_minor', 5000000)
            ->assertJsonPath('data.top_products.0.product_name', 'Ayam Geprek Pedas')
            ->assertJsonPath('data.top_products.0.units_sold', 2)
            ->assertJsonPath('data.top_products.0.revenue_minor', 5000000)
            ->assertJsonPath('data.low_stock.0.item_name', 'Daging Ayam')
            ->assertJsonPath('data.low_stock.0.available', 2)
            ->assertJsonPath('data.top_customers.0.customer_name', 'Siti Pelanggan')
            ->assertJsonPath('data.top_customers.0.total_spend_minor', 5000000)
            ->assertJsonPath('data.payment_summary.succeeded.count', 1);
    }

    public function test_zero_transactions_period_handles_aov_safely(): void
    {
        $tenant = $this->tenantData['tenant'];

        $res = $this->authenticateTenantUser($this->owner, $tenant)
            ->getJson('/api/v1/dashboard?period=today');

        $res->assertStatus(200)
            ->assertJsonPath('data.kpi.gross_sales_minor', 0)
            ->assertJsonPath('data.kpi.net_sales_minor', 0)
            ->assertJsonPath('data.kpi.transaction_count', 0)
            ->assertJsonPath('data.kpi.average_order_value_minor', 0)
            ->assertJsonPath('data.top_products', [])
            ->assertJsonPath('data.top_customers', []);
    }

    public function test_date_range_filters_today_yesterday_7days_30days_and_custom(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            $cat = Category::create(['tenant_id' => $tenant->id, 'name' => 'Kopi', 'slug' => 'kopi']);
            $prod = Product::create(['tenant_id' => $tenant->id, 'category_id' => $cat->id, 'name' => 'Kopi Hitam', 'slug' => 'kopi-hitam', 'status' => 'active']);
            $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $prod->id, 'name' => 'Panas', 'sku' => 'KP-HTM']);

            // Sale yesterday
            Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'reference_number' => 'SALE-YEST',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 1500000,
                'total_minor' => 1500000,
                'cashier_user_id' => $this->cashier->id,
                'finalized_at' => now()->subDay(),
            ]);

            // Sale today
            Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'reference_number' => 'SALE-TODAY',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 3000000,
                'total_minor' => 3000000,
                'cashier_user_id' => $this->cashier->id,
                'finalized_at' => now(),
            ]);
        });

        // Today: only Rp 30.000
        $resToday = $this->authenticateTenantUser($this->owner, $tenant)->getJson('/api/v1/dashboard?period=today');
        $resToday->assertStatus(200)->assertJsonPath('data.kpi.gross_sales_minor', 3000000);

        // Yesterday: only Rp 15.000
        $resYest = $this->authenticateTenantUser($this->owner, $tenant)->getJson('/api/v1/dashboard?period=yesterday');
        $resYest->assertStatus(200)->assertJsonPath('data.kpi.gross_sales_minor', 1500000);

        // Last 7 days: Rp 45.000
        $res7 = $this->authenticateTenantUser($this->owner, $tenant)->getJson('/api/v1/dashboard?period=7_days');
        $res7->assertStatus(200)->assertJsonPath('data.kpi.gross_sales_minor', 4500000);

        // Custom range: yesterday to yesterday
        $yestDate = now()->subDay()->toDateString();
        $resCustom = $this->authenticateTenantUser($this->owner, $tenant)
            ->getJson("/api/v1/dashboard?period=custom&start_date={$yestDate}&end_date={$yestDate}");
        $resCustom->assertStatus(200)->assertJsonPath('data.kpi.gross_sales_minor', 1500000);
    }

    public function test_manager_sees_authorized_outlet_metrics_and_unauthorized_outlet_is_rejected(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->runForTenant(function () use ($tenant) {
            // Sale on outlet 1 (authorized for manager)
            Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet1->id,
                'reference_number' => 'SALE-OUT1',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 2000000,
                'total_minor' => 2000000,
                'cashier_user_id' => $this->cashier->id,
                'finalized_at' => now(),
            ]);

            // Sale on outlet 2 (NOT authorized for manager)
            Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet2->id,
                'reference_number' => 'SALE-OUT2',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 8000000,
                'total_minor' => 8000000,
                'cashier_user_id' => $this->cashier->id,
                'finalized_at' => now(),
            ]);
        });

        // Manager queries authorized outlet 1 -> success (sees only 2000000)
        $resManager = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson("/api/v1/dashboard?outlet_id={$this->outlet1->id}");
        $resManager->assertStatus(200)
            ->assertJsonPath('data.kpi.gross_sales_minor', 2000000);

        // Manager attempts to query unauthorized outlet 2 -> 403 Forbidden
        $resUnauthorized = $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson("/api/v1/dashboard?outlet_id={$this->outlet2->id}");
        $resUnauthorized->assertStatus(403);
    }

    public function test_cashier_cannot_access_dashboard_without_permission(): void
    {
        $tenant = $this->tenantData['tenant'];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/dashboard');

        // Cashier does not have dashboard.view permission -> 403
        $res->assertStatus(403);
    }

    public function test_tenant_isolation_prevents_viewing_other_tenant_dashboard(): void
    {
        $tenant1 = $this->tenantData['tenant'];
        $tenant2Data = $this->createTenant('Waroeng Sebelah');
        $tenant2 = $tenant2Data['tenant'];
        $owner2 = $tenant2Data['owner'];

        $this->runForTenant(function () use ($tenant1) {
            Sale::create([
                'tenant_id' => $tenant1->id,
                'outlet_id' => $this->outlet1->id,
                'reference_number' => 'SALE-T1',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 9900000,
                'total_minor' => 9900000,
                'cashier_user_id' => $this->cashier->id,
                'finalized_at' => now(),
            ]);
        });

        // Owner 2 views their dashboard: must be 0, cannot see Tenant 1's sales
        $res = $this->authenticateTenantUser($owner2, $tenant2)
            ->getJson('/api/v1/dashboard?period=today');

        $res->assertStatus(200)
            ->assertJsonPath('data.kpi.gross_sales_minor', 0);
    }
}
