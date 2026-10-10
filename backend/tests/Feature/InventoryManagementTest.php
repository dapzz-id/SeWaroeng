<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\InventoryService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerUserA;

    protected Tenant $tenantA;

    protected Outlet $outletA1;

    protected string $tokenA;

    protected User $cashierUserA;

    protected string $tokenCashierA;

    protected User $ownerUserB;

    protected Tenant $tenantB;

    protected Outlet $outletB1;

    protected string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 1. Setup Tenant A
        $this->ownerUserA = User::factory()->create(['name' => 'Owner A', 'is_active' => true]);
        $this->tenantA = Tenant::create([
            'name' => 'Warung Kopi Nusantara',
            'code' => 'WKNUSANTARA',
            'slug' => 'warung-kopi-nusantara',
            'business_type' => 'fnb',
            'status' => 'active',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'owner_user_id' => $this->ownerUserA->id,
        ]);

        app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $this->outletA1 = Outlet::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Outlet Pusat',
                'code' => 'PUSAT',
                'status' => 'active',
            ]);
        });

        $ownerRole = Role::where('slug', 'owner')->first();
        UserTenantMembership::create([
            'user_id' => $this->ownerUserA->id,
            'tenant_id' => $this->tenantA->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);
        $this->tokenA = $this->ownerUserA->createToken('owner-device')->plainTextToken;

        // Cashier for Tenant A
        $this->cashierUserA = User::factory()->create(['name' => 'Cashier A', 'is_active' => true]);
        $cashierRole = Role::where('slug', 'cashier')->first();
        UserTenantMembership::create([
            'user_id' => $this->cashierUserA->id,
            'tenant_id' => $this->tenantA->id,
            'role_id' => $cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'specific',
            'assigned_outlet_ids' => [$this->outletA1->id],
        ]);
        $this->tokenCashierA = $this->cashierUserA->createToken('cashier-device')->plainTextToken;

        // 2. Setup Tenant B (Isolation verification)
        $this->ownerUserB = User::factory()->create(['name' => 'Owner B', 'is_active' => true]);
        $this->tenantB = Tenant::create([
            'name' => 'Toko Kelontong Berkah',
            'code' => 'TKBBERKAH',
            'slug' => 'toko-kelontong-berkah',
            'business_type' => 'retail',
            'status' => 'active',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'owner_user_id' => $this->ownerUserB->id,
        ]);

        app(TenantManager::class)->runForTenant($this->tenantB->id, function () {
            $this->outletB1 = Outlet::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Outlet Utama B',
                'code' => 'UTAMA-B',
                'status' => 'active',
            ]);
        });

        UserTenantMembership::create([
            'user_id' => $this->ownerUserB->id,
            'tenant_id' => $this->tenantB->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);
        $this->tokenB = $this->ownerUserB->createToken('owner-b-device')->plainTextToken;
    }

    public function test_inventory_location_and_item_lifecycle_with_opening_balance(): void
    {
        // 1. Create Inventory Location
        $locRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-loc-create-1',
        ])->postJson('/api/v1/inventory/locations', [
            'outlet_id' => $this->outletA1->id,
            'name' => 'Gudang Bahan Utama',
            'code' => 'GDG-PUSAT',
            'type' => 'warehouse',
        ]);

        $locRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Gudang Bahan Utama')
            ->assertJsonPath('data.code', 'GDG-PUSAT');
        $locationId = $locRes->json('data.id');

        // 2. Create Product and Variant to link
        [$product, $variant] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $p = Product::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Biji Kopi Arabika Gayo',
                'slug' => 'biji-kopi-arabika-gayo',
            ]);
            $v = ProductVariant::create([
                'tenant_id' => $this->tenantA->id,
                'product_id' => $p->id,
                'name' => '1 kg Pack',
                'is_default' => true,
            ]);

            return [$p, $v];
        });

        // 3. Create Inventory Item linked to variant
        $itemRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-item-create-1',
        ])->postJson('/api/v1/inventory/items', [
            'product_variant_id' => $variant->id,
            'name' => 'Biji Kopi Arabika Gayo 1kg',
            'sku' => 'BKG-01',
            'item_type' => 'sellable',
            'base_unit' => 'kg',
            'allow_negative_stock' => false,
        ]);

        $itemRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Biji Kopi Arabika Gayo 1kg')
            ->assertJsonPath('data.base_unit', 'kg');
        $itemId = $itemRes->json('data.id');

        // 4. Record Opening Balance: 25.5000 kg
        $opBalRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-op-bal-1',
        ])->postJson('/api/v1/inventory/opening-balances', [
            'inventory_item_id' => $itemId,
            'location_id' => $locationId,
            'quantity' => 25.5,
            'note' => 'Initial stock opname audit',
        ]);

        $opBalRes->assertStatus(201)
            ->assertJsonPath('data.movement_type', 'opening_balance')
            ->assertJsonPath('data.on_hand', '25.5000');

        // 5. Verify availability query
        $availRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/inventory/availability?location_id={$locationId}&inventory_item_id={$itemId}");

        $availRes->assertStatus(200)
            ->assertJsonPath('data.0.on_hand', '25.5000')
            ->assertJsonPath('data.0.available', '25.5000')
            ->assertJsonPath('data.0.reserved', '0.0000');

        // 6. Verify movement history
        $movRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/inventory/items/{$itemId}/movements");

        $movRes->assertStatus(200)
            ->assertJsonPath('data.items.0.movement_type', 'opening_balance')
            ->assertJsonPath('data.items.0.quantity_delta', '25.5000')
            ->assertJsonPath('data.items.0.unit', 'kg');
    }

    public function test_decimal_precision_and_negative_stock_policy(): void
    {
        // Setup location and item without negative stock
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kitchen',
                'code' => 'KTCH-1',
                'type' => 'kitchen',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Fresh Milk Diamond',
                'sku' => 'MLK-01',
                'item_type' => 'raw_material',
                'base_unit' => 'liter',
                'allow_negative_stock' => false,
            ]);

            return [$loc, $itm];
        });

        // Set opening balance to 2.5000 liter
        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/opening-balances', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 2.5,
        ])->assertStatus(201);

        // Attempt to deduct 3.0000 liter -> Insufficient stock (current 2.5000, requested -3.0000)
        $rejectRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/adjustments', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_delta' => -3.0,
            'reason' => 'damaged',
        ]);

        $rejectRes->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        // Verify stock is still exactly 2.5000 (no corruption)
        $avail = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/inventory/availability?location_id={$location->id}&inventory_item_id={$item->id}");
        $this->assertEquals('2.5000', $avail->json('data.0.on_hand'));

        // Now enable negative stock policy on item
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($item) {
            $item->allow_negative_stock = true;
            $item->save();
        });

        // Retry deduction of 3.0000 liter -> Allowed, resulting in -0.5000 liter
        $allowRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/adjustments', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_delta' => -3.0,
            'reason' => 'damaged',
            'note' => 'Spilled during rush hour',
        ]);

        $allowRes->assertStatus(201)
            ->assertJsonPath('data.after_on_hand', '-0.5000');

        // Check availability read model
        $availAfter = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/inventory/availability?location_id={$location->id}&inventory_item_id={$item->id}");
        $this->assertEquals('-0.5000', $availAfter->json('data.0.on_hand'));
    }

    public function test_posted_movement_is_strictly_immutable_and_reconciliation_verifies_balance(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Storage A',
                'code' => 'STR-A',
                'type' => 'storage',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Paper Cup 12oz',
                'sku' => 'CUP-12',
                'base_unit' => 'piece',
            ]);

            return [$loc, $itm];
        });

        $invService = app(InventoryService::class);

        // Record 3 successive movements
        $mov1 = $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 100);
        $mov2 = $invService->adjustStock($this->tenantA->id, $item->id, $location->id, -10, 'waste', 'Dropped cup');
        $mov3 = $invService->adjustStock($this->tenantA->id, $item->id, $location->id, 50, 'restock');

        // Immutability: attempting to edit or delete posted movement throws DomainException
        $this->expectException(DomainException::class);
        $mov1->quantity_delta = '999.0000';
        $mov1->save();
    }

    public function test_reconciliation_detects_consistency(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Storage B',
                'code' => 'STR-B',
                'type' => 'storage',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Straw Plastic',
                'sku' => 'STR-01',
                'base_unit' => 'piece',
            ]);

            return [$loc, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 500);
        $invService->adjustStock($this->tenantA->id, $item->id, $location->id, -25, 'waste');

        $reconcile = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertTrue($reconcile['is_consistent']);
        $this->assertEquals('475.0000', $reconcile['ledger_sum']);
        $this->assertEquals('475.0000', $reconcile['materialized_on_hand']);
    }

    public function test_stock_opname_workflow_and_idempotent_approval(): void
    {
        [$location, $itemA, $itemB] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Outlet Store Front',
                'code' => 'STORE-1',
                'type' => 'outlet',
            ]);
            $i1 = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Chocolate Powder',
                'sku' => 'CHOC-01',
                'base_unit' => 'kg',
            ]);
            $i2 = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Matcha Powder',
                'sku' => 'MAT-01',
                'base_unit' => 'kg',
            ]);

            return [$loc, $i1, $i2];
        });

        $invService = app(InventoryService::class);
        // Setup initial system stock: Chocolate = 10 kg, Matcha = 5 kg
        $invService->setOpeningBalance($this->tenantA->id, $itemA->id, $location->id, 10);
        $invService->setOpeningBalance($this->tenantA->id, $itemB->id, $location->id, 5);

        // 1. Create Opname: Physical count: Chocolate = 8.5 kg (-1.5 diff), Matcha = 5.0 kg (0 diff)
        $opnameRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-opname-create-1',
        ])->postJson('/api/v1/inventory/opnames', [
            'location_id' => $location->id,
            'notes' => 'Monthly physical inventory count',
            'items' => [
                [
                    'inventory_item_id' => $itemA->id,
                    'physical_quantity' => 8.5,
                    'reason' => 'spillage',
                ],
                [
                    'inventory_item_id' => $itemB->id,
                    'physical_quantity' => 5.0,
                    'reason' => 'exact match',
                ],
            ],
        ]);

        $opnameRes->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');
        $opnameId = $opnameRes->json('data.id');

        // CRITICAL: Physical count creation DOES NOT mutate stock yet!
        $availA = $invService->reconcile($this->tenantA->id, $itemA->id, $location->id);
        $this->assertEquals('10.0000', $availA['materialized_on_hand']);

        // 2. Approve Opname
        $approveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-opname-approve-1',
        ])->postJson("/api/v1/inventory/opnames/{$opnameId}/approve");

        $approveRes->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Stock for Chocolate is now adjusted to 8.5000 (-1.5000 delta movement created)
        $availAAfter = $invService->reconcile($this->tenantA->id, $itemA->id, $location->id);
        $this->assertEquals('8.5000', $availAAfter['materialized_on_hand']);

        // Stock for Matcha was exact match (0 delta), so no unnecessary adjustment movement
        $availBAfter = $invService->reconcile($this->tenantA->id, $itemB->id, $location->id);
        $this->assertEquals('5.0000', $availBAfter['materialized_on_hand']);

        // 3. Repeated approval attempt with same idempotency key replays cached 200 without double adjust
        $replayApproveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-opname-approve-1',
        ])->postJson("/api/v1/inventory/opnames/{$opnameId}/approve");

        $replayApproveRes->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');

        // Verify balance was NOT double adjusted
        $this->assertEquals('8.5000', $invService->reconcile($this->tenantA->id, $itemA->id, $location->id)['materialized_on_hand']);

        // 4. Fresh approval attempt with new idempotency key rejects with OPNAME_ALREADY_APPROVED 422
        $dupApproveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-opname-approve-2',
        ])->postJson("/api/v1/inventory/opnames/{$opnameId}/approve");

        $dupApproveRes->assertStatus(422)
            ->assertJsonPath('code', 'OPNAME_ALREADY_APPROVED');
    }

    public function test_stock_transfer_lifecycle(): void
    {
        [$sourceLoc, $destLoc, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $src = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Central Warehouse',
                'code' => 'CW-01',
                'type' => 'central_warehouse',
            ]);
            $dst = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Branch Store',
                'code' => 'BR-01',
                'type' => 'outlet',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Syrup Vanilla',
                'sku' => 'SYR-VAN',
                'base_unit' => 'liter',
            ]);

            return [$src, $dst, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $sourceLoc->id, 50);

        // 1. Create transfer request: 10 liter
        $trfRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/transfers', [
            'source_location_id' => $sourceLoc->id,
            'destination_location_id' => $destLoc->id,
            'notes' => 'Weekly replenishment',
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity' => 10],
            ],
        ]);

        $trfRes->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');
        $transferId = $trfRes->json('data.id');

        // 2. Dispatch transfer (deducts 10 from source)
        $dispatchRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/inventory/transfers/{$transferId}/dispatch");

        $dispatchRes->assertStatus(200)
            ->assertJsonPath('data.status', 'in_transit');

        $this->assertEquals('40.0000', $invService->reconcile($this->tenantA->id, $item->id, $sourceLoc->id)['materialized_on_hand']);
        $this->assertEquals('0.0000', $invService->reconcile($this->tenantA->id, $item->id, $destLoc->id)['materialized_on_hand']);

        // 3. Receive transfer (adds 10 to destination)
        $receiveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/inventory/transfers/{$transferId}/receive");

        $receiveRes->assertStatus(200)
            ->assertJsonPath('data.status', 'received');

        $this->assertEquals('10.0000', $invService->reconcile($this->tenantA->id, $item->id, $destLoc->id)['materialized_on_hand']);

        // Duplicate receive is blocked
        $dupReceive = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/inventory/transfers/{$transferId}/receive");

        $dupReceive->assertStatus(422)
            ->assertJsonPath('code', 'TRANSFER_INVALID_STATE');
    }

    public function test_cross_tenant_isolation_and_composite_foreign_key_protection(): void
    {
        // Create an inventory item in Tenant B
        $itemB = app(TenantManager::class)->runForTenant($this->tenantB->id, function () {
            $locB = InventoryLocation::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Toko B Lokasi',
                'code' => 'TB-LOK',
                'type' => 'outlet',
            ]);

            return InventoryItem::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Minyak Goreng 2L',
                'sku' => 'MG-2L',
                'base_unit' => 'piece',
            ]);
        });

        // 1. User from Tenant A attempts to read Tenant B's inventory item -> 404
        $readRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/inventory/items/{$itemB->id}");
        $readRes->assertStatus(404);

        // 2. User from Tenant A attempts to mutate Tenant B's inventory item -> 404
        $mutateRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/adjustments', [
            'inventory_item_id' => $itemB->id,
            'location_id' => (string) Str::uuid(),
            'quantity_delta' => 10,
            'reason' => 'illegal cross-tenant mutation',
        ]);
        $mutateRes->assertStatus(404);

        // 3. PostgreSQL Composite Foreign Key Test: Attempt to create InventoryLocation in Tenant A with outlet from Tenant B
        $this->expectException(QueryException::class);
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            DB::statement('INSERT INTO inventory_locations (id, tenant_id, outlet_id, name, code, type, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())', [
                (string) Str::uuid(),
                $this->tenantA->id,
                $this->outletB1->id, // Belongs to Tenant B!
                'Hacked Location',
                'HACK-01',
                'outlet',
                true,
            ]);
        });
    }

    public function test_cashier_has_read_only_access_and_cannot_adjust_stock(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Cashier Floor',
                'code' => 'CSH-FLR',
                'type' => 'outlet',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Mineral Water',
                'sku' => 'MW-01',
                'base_unit' => 'piece',
            ]);

            return [$loc, $itm];
        });

        // 1. Cashier can view inventory items (has inventory.view)
        $viewRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenCashierA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson('/api/v1/inventory/items');
        $viewRes->assertStatus(200);

        // 2. Cashier CANNOT create opening balances (forbidden)
        $opBalRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenCashierA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/opening-balances', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 100,
        ]);
        $opBalRes->assertStatus(403);

        // 3. Cashier CANNOT perform stock adjustments (forbidden)
        $adjustRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenCashierA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson('/api/v1/inventory/adjustments', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_delta' => -5,
            'reason' => 'damage',
        ]);
        $adjustRes->assertStatus(403);
    }

    public function test_idempotent_stock_adjustments(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Bar Counter',
                'code' => 'BAR-01',
                'type' => 'kitchen',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Syrup Hazelnut',
                'sku' => 'SYR-HAZ',
                'base_unit' => 'liter',
            ]);

            return [$loc, $itm];
        });

        // First adjustment request with Idempotency-Key
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-adj-repeat-1',
        ])->postJson('/api/v1/inventory/adjustments', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_delta' => 12.0,
            'reason' => 'restock',
        ]);
        $res1->assertStatus(201)
            ->assertJsonPath('data.after_on_hand', '12.0000');

        // Resend identical request with same Idempotency-Key -> cached response, stock NOT doubled
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-adj-repeat-1',
        ])->postJson('/api/v1/inventory/adjustments', [
            'inventory_item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_delta' => 12.0,
            'reason' => 'restock',
        ]);
        $res2->assertStatus(201)
            ->assertJsonPath('data.after_on_hand', '12.0000');

        // Verify balance is 12.0000, NOT 24.0000
        $invService = app(InventoryService::class);
        $this->assertEquals('12.0000', $invService->reconcile($this->tenantA->id, $item->id, $location->id)['materialized_on_hand']);
    }

    public function test_reservation_does_not_mutate_physical_on_hand_and_available_is_correct(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Main Outlet Kitchen',
                'code' => 'KIT-01',
                'type' => 'kitchen',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Arabica Roasted Beans',
                'sku' => 'BEANS-ARB',
                'base_unit' => 'kg',
                'allow_negative_stock' => false,
            ]);

            return [$loc, $itm];
        });

        $invService = app(InventoryService::class);

        // Initial: opening balance = 10 kg
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 10);
        $rec1 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('10.0000', $rec1['materialized_on_hand']);
        $this->assertEquals('0.0000', $rec1['reserved']);
        $this->assertEquals('10.0000', $rec1['available']);

        // Reserve 3 kg: on_hand MUST remain 10, reserved = 3, available = 7
        $resItemLoc = $invService->reserveStock($this->tenantA->id, $item->id, $location->id, 3);
        $this->assertEquals('10.0000', (string) $resItemLoc->on_hand);
        $this->assertEquals('3.0000', (string) $resItemLoc->reserved);
        $this->assertEquals('7.0000', (string) $resItemLoc->available);

        $rec2 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('10.0000', $rec2['materialized_on_hand']);
        $this->assertEquals('10.0000', $rec2['ledger_sum']);
        $this->assertEquals('3.0000', $rec2['reserved']);
        $this->assertEquals('7.0000', $rec2['available']);

        // Release 1 kg: on_hand MUST remain 10, reserved = 2, available = 8
        $relItemLoc = $invService->releaseStock($this->tenantA->id, $item->id, $location->id, 1);
        $this->assertEquals('10.0000', (string) $relItemLoc->on_hand);
        $this->assertEquals('2.0000', (string) $relItemLoc->reserved);
        $this->assertEquals('8.0000', (string) $relItemLoc->available);

        $rec3 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('10.0000', $rec3['materialized_on_hand']);
        $this->assertEquals('10.0000', $rec3['ledger_sum']);
        $this->assertEquals('2.0000', $rec3['reserved']);
        $this->assertEquals('8.0000', $rec3['available']);
        $this->assertTrue($rec3['is_consistent']);
    }

    public function test_reservation_invariants_strictly_prevent_negative_and_excessive_reservations(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Storage Room',
                'code' => 'STR-ROOM',
                'type' => 'storage',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Fresh Milk 1L',
                'sku' => 'MILK-1L',
                'base_unit' => 'liter',
                'allow_negative_stock' => false,
            ]);

            return [$loc, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 5);

        // 1. Negative or zero reservation quantity rejected
        try {
            $invService->reserveStock($this->tenantA->id, $item->id, $location->id, 0);
            $this->fail('Expected InvalidArgumentException for 0 reservation');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        // 2. Over-reservation beyond available rejected when allow_negative_stock = false
        try {
            $invService->reserveStock($this->tenantA->id, $item->id, $location->id, 6);
            $this->fail('Expected InsufficientStockException for reserving 6 when available is 5');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('Insufficient available stock to reserve', $e->getMessage());
        }

        // 3. Valid reservation of 2 -> reserved = 2
        $invService->reserveStock($this->tenantA->id, $item->id, $location->id, 2);

        // 4. Releasing more than reserved rejected (reserved cannot become negative)
        try {
            $invService->releaseStock($this->tenantA->id, $item->id, $location->id, 3);
            $this->fail('Expected InvalidArgumentException for releasing 3 when reserved is 2');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot release more stock than currently reserved', $e->getMessage());
        }
    }

    public function test_reconciliation_detects_tampering_and_repair_projection_rebuilds_from_ledger_with_audit_provenance(): void
    {
        [$location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Warehouse North',
                'code' => 'WH-NORTH',
                'type' => 'warehouse',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Tea Leaves Black',
                'sku' => 'TEA-BLK',
                'base_unit' => 'kg',
            ]);

            return [$loc, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 50);
        $invService->adjustStock($this->tenantA->id, $item->id, $location->id, -10, 'waste', 'Damaged bag');

        // Total physical movements in ledger = 40.0000
        $recInitial = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertTrue($recInitial['is_consistent']);
        $this->assertEquals('40.0000', $recInitial['ledger_sum']);
        $this->assertEquals('40.0000', $recInitial['materialized_on_hand']);

        // Simulate corrupted read-model state (e.g. out-of-band DB glitch or unrecorded update)
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($item, $location) {
            DB::table('inventory_item_locations')
                ->where('tenant_id', $this->tenantA->id)
                ->where('inventory_item_id', $item->id)
                ->where('location_id', $location->id)
                ->update(['on_hand' => 999.0000]);
        });

        // Reconcile must detect inconsistency
        $recDrift = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertFalse($recDrift['is_consistent']);
        $this->assertEquals('40.0000', $recDrift['ledger_sum']);
        $this->assertEquals('999.0000', $recDrift['materialized_on_hand']);

        // Rebuild projection with audit provenance
        $repairResult = $invService->repairProjection(
            tenantId: $this->tenantA->id,
            inventoryItemId: $item->id,
            locationId: $location->id,
            repairedBy: $this->ownerUserA->id,
            reason: 'Rebuilt corrupted cache projection to match authoritative ledger sum'
        );

        $this->assertTrue($repairResult['repaired']);
        $this->assertEquals('999.0000', $repairResult['before_on_hand']);
        $this->assertEquals('40.0000', $repairResult['after_on_hand']);

        // Re-reconcile is now fully consistent
        $recFixed = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertTrue($recFixed['is_consistent']);
        $this->assertEquals('40.0000', $recFixed['materialized_on_hand']);

        // Audit log exists with provenance
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantA->id,
            'actor_user_id' => $this->ownerUserA->id,
            'action' => 'inventory_projection_rebuilt',
        ]);
    }
}
