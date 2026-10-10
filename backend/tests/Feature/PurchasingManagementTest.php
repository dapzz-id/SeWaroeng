<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\PurchaseReceipt;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingManagementTest extends TestCase
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

        $ownerRole = Role::where('slug', 'owner')->firstOrFail();
        $cashierRole = Role::where('slug', 'cashier')->firstOrFail();

        // 1. Setup Tenant A
        $this->ownerUserA = User::factory()->create(['name' => 'Owner A', 'is_active' => true]);
        $this->cashierUserA = User::factory()->create(['name' => 'Cashier A', 'is_active' => true]);
        $this->tenantA = Tenant::create([
            'name' => 'Waroeng Jaya Abadi',
            'code' => 'WJAYA',
            'slug' => 'waroeng-jaya-abadi',
            'business_type' => 'fnb',
            'status' => 'active',
            'is_active' => true,
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'owner_user_id' => $this->ownerUserA->id,
        ]);

        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($ownerRole, $cashierRole) {
            $this->outletA1 = Outlet::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Outlet Pusat',
                'code' => 'PUSAT',
                'status' => 'active',
            ]);

            UserTenantMembership::create([
                'user_id' => $this->ownerUserA->id,
                'tenant_id' => $this->tenantA->id,
                'role_id' => $ownerRole->id,
                'status' => 'active',
                'outlet_access' => 'all',
            ]);

            UserTenantMembership::create([
                'user_id' => $this->cashierUserA->id,
                'tenant_id' => $this->tenantA->id,
                'role_id' => $cashierRole->id,
                'status' => 'active',
                'outlet_access' => 'specific',
                'assigned_outlet_ids' => [$this->outletA1->id],
            ]);
        });
        $this->tokenA = $this->ownerUserA->createToken('owner-device')->plainTextToken;
        $this->tokenCashierA = $this->cashierUserA->createToken('cashier-device')->plainTextToken;

        // 2. Setup Tenant B (Isolation verification)
        $this->ownerUserB = User::factory()->create(['name' => 'Owner B', 'is_active' => true]);
        $this->tenantB = Tenant::create([
            'name' => 'Toko Kelontong Sukses',
            'code' => 'TSUKSES',
            'slug' => 'toko-kelontong-sukses',
            'business_type' => 'retail',
            'status' => 'active',
            'is_active' => true,
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'owner_user_id' => $this->ownerUserB->id,
        ]);

        app(TenantManager::class)->runForTenant($this->tenantB->id, function () use ($ownerRole) {
            $this->outletB1 = Outlet::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Cabang B1',
                'code' => 'B1',
                'status' => 'active',
            ]);

            UserTenantMembership::create([
                'user_id' => $this->ownerUserB->id,
                'tenant_id' => $this->tenantB->id,
                'role_id' => $ownerRole->id,
                'status' => 'active',
                'outlet_access' => 'all',
            ]);
        });
        $this->tokenB = $this->ownerUserB->createToken('owner-device-b')->plainTextToken;
    }

    public function test_supplier_lifecycle_and_tenant_isolation(): void
    {
        // 1. Create Supplier for Tenant A
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-sup-1',
        ])->postJson('/api/v1/purchasing/suppliers', [
            'code' => 'SUP-KOPI',
            'name' => 'PT Kopi Lestari Nusantara',
            'contact_name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@kopilestari.id',
            'payment_terms_days' => 30,
            'notes' => 'Supplier utama biji kopi mentah',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.code', 'SUP-KOPI')
            ->assertJsonPath('data.status', 'active');
        $supplierId = $res->json('data.id');

        // 2. Duplicate code in same tenant is rejected
        $dupRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-sup-2',
        ])->postJson('/api/v1/purchasing/suppliers', [
            'code' => 'SUP-KOPI',
            'name' => 'Kopi Sejahtera Lain',
        ]);
        $dupRes->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        // 3. Same code in different tenant (Tenant B) is allowed
        auth()->forgetGuards();
        $supBRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
            'Idempotency-Key' => 'idemp-sup-3',
        ])->postJson('/api/v1/purchasing/suppliers', [
            'code' => 'SUP-KOPI',
            'name' => 'Supplier B Kopi',
        ]);
        $supBRes->assertStatus(201);

        // 4. Cross-tenant read is blocked (Tenant B cannot read Tenant A's supplier)
        $crossRead = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
        ])->getJson("/api/v1/purchasing/suppliers/{$supplierId}");
        $crossRead->assertStatus(404);

        // 5. Update supplier status to inactive
        auth()->forgetGuards();
        $updRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-sup-4',
        ])->putJson("/api/v1/purchasing/suppliers/{$supplierId}", [
            'status' => 'inactive',
        ]);
        $updRes->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_inactive_supplier_cannot_be_used_for_new_purchase_order(): void
    {
        [$supplier, $location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $sup = Supplier::create([
                'tenant_id' => $this->tenantA->id,
                'code' => 'SUP-INACTIVE',
                'name' => 'Supplier Tutup',
                'status' => 'inactive',
            ]);
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Gudang Pusat',
                'code' => 'GD-01',
                'type' => 'warehouse',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Susu Segar',
                'sku' => 'MILK-01',
                'base_unit' => 'liter',
            ]);

            return [$sup, $loc, $itm];
        });

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-inactive',
        ])->postJson('/api/v1/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'items' => [
                [
                    'inventory_item_id' => $item->id,
                    'ordered_quantity' => 10,
                    'unit_cost_minor' => 18000,
                ],
            ],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_INACTIVE');
    }

    public function test_purchase_order_creation_and_approval_does_not_mutate_stock(): void
    {
        [$supplier, $location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $sup = Supplier::create([
                'tenant_id' => $this->tenantA->id,
                'code' => 'SUP-ROAST',
                'name' => 'Roastery Bandung',
                'status' => 'active',
            ]);
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kitchen Bar',
                'code' => 'KB-01',
                'type' => 'kitchen',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Biji Kopi Gayo',
                'sku' => 'GAYO-01',
                'base_unit' => 'kg',
            ]);

            return [$sup, $loc, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 5);

        // 1. Create Purchase Order (Draft)
        $poRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-create-1',
        ])->postJson('/api/v1/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'notes' => 'Restock biji kopi bulanan',
            'items' => [
                [
                    'inventory_item_id' => $item->id,
                    'ordered_quantity' => 20,
                    'unit_cost_minor' => 125000,
                ],
            ],
        ]);

        $poRes->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.ordered_quantity', '20.0000')
            ->assertJsonPath('data.items.0.received_quantity', '0.0000')
            ->assertJsonPath('data.items.0.remaining_quantity', '20.0000');
        $poId = $poRes->json('data.id');

        // INVARIANT CHECK: PO creation MUST NOT mutate inventory on_hand!
        $rec1 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('5.0000', $rec1['materialized_on_hand']);

        // 2. Submit PO
        $submitRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-sub-1',
        ])->postJson("/api/v1/purchasing/orders/{$poId}/submit");
        $submitRes->assertStatus(200)->assertJsonPath('data.status', 'submitted');

        // 3. Approve PO
        $approveRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-app-1',
        ])->postJson("/api/v1/purchasing/orders/{$poId}/approve");
        $approveRes->assertStatus(200)->assertJsonPath('data.status', 'approved');

        // INVARIANT CHECK: PO approval MUST NOT mutate inventory on_hand!
        $rec2 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('5.0000', $rec2['materialized_on_hand']);
    }

    public function test_goods_receipt_partial_and_full_receiving_with_over_quantity_rejection(): void
    {
        [$supplier, $location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $sup = Supplier::create([
                'tenant_id' => $this->tenantA->id,
                'code' => 'SUP-DAIRY',
                'name' => 'PT Diamond Milk',
                'status' => 'active',
            ]);
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Cold Storage',
                'code' => 'CS-01',
                'type' => 'storage',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Oat Milk Barista',
                'sku' => 'OAT-01',
                'base_unit' => 'liter',
            ]);

            return [$sup, $loc, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 0);

        // 1. Create and Approve PO for 100 units
        $poRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-100',
        ])->postJson('/api/v1/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'items' => [
                [
                    'inventory_item_id' => $item->id,
                    'ordered_quantity' => 100,
                    'unit_cost_minor' => 38000,
                ],
            ],
        ]);
        $poId = $poRes->json('data.id');
        $poItemId = $poRes->json('data.items.0.id');

        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-app-100',
        ])->postJson("/api/v1/purchasing/orders/{$poId}/approve")->assertStatus(200);

        // 2. Receipt #1: Partial receive 60 units
        $grn1Res = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-grn-1',
        ])->postJson('/api/v1/purchasing/receipts', [
            'purchase_order_id' => $poId,
            'notes' => 'Penerimaan bertahap batch 1',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity' => 60,
                    'unit_cost_minor' => 38000,
                ],
            ],
        ]);
        $grn1Res->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $grn1Id = $grn1Res->json('data.id');

        // Post Receipt #1
        $post1Res = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-post-grn-1',
        ])->postJson("/api/v1/purchasing/receipts/{$grn1Id}/post");

        $post1Res->assertStatus(200)->assertJsonPath('data.status', 'posted');

        // PO status should now be 'partially_received', stock = 60
        $poCheck1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/purchasing/orders/{$poId}");
        $poCheck1->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.items.0.received_quantity', '60.0000')
            ->assertJsonPath('data.items.0.remaining_quantity', '40.0000');

        $recStock1 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('60.0000', $recStock1['materialized_on_hand']);

        // 3. Over-receiving test: Remaining is 40. Attempting to receive 41 must be rejected!
        $overRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-grn-over',
        ])->postJson('/api/v1/purchasing/receipts', [
            'purchase_order_id' => $poId,
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity' => 41,
                ],
            ],
        ]);
        $overRes->assertStatus(422)
            ->assertJsonPath('code', 'PURCHASE_RECEIPT_OVER_QUANTITY');

        // 4. Receipt #2: Receive remaining 40 units
        $grn2Res = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-grn-2',
        ])->postJson('/api/v1/purchasing/receipts', [
            'purchase_order_id' => $poId,
            'notes' => 'Penerimaan pelunasan batch 2',
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity' => 40,
                    'unit_cost_minor' => 38000,
                ],
            ],
        ]);
        $grn2Id = $grn2Res->json('data.id');

        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-post-grn-2',
        ])->postJson("/api/v1/purchasing/receipts/{$grn2Id}/post")->assertStatus(200);

        // PO status should now be fully 'received', total received = 100, remaining = 0
        $poCheck2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/purchasing/orders/{$poId}");
        $poCheck2->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.items.0.received_quantity', '100.0000')
            ->assertJsonPath('data.items.0.remaining_quantity', '0.0000');

        // Physical inventory on_hand is now 100.0000
        $recStock2 = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('100.0000', $recStock2['materialized_on_hand']);
        $this->assertEquals('100.0000', $recStock2['ledger_sum']);
        $this->assertTrue($recStock2['is_consistent']);

        // Verify inventory movements created with movement_type = 'purchase_receipt'
        $movements = $invService->executeForTenant($this->tenantA->id, function () use ($item, $location) {
            return InventoryMovement::where('tenant_id', $this->tenantA->id)
                ->where('inventory_item_id', $item->id)
                ->where('location_id', $location->id)
                ->where('movement_type', 'purchase_receipt')
                ->get();
        });

        $this->assertCount(2, $movements);
        $this->assertEquals('60.0000', (string) $movements[0]->quantity_delta);
        $this->assertEquals('40.0000', (string) $movements[1]->quantity_delta);

        // 5. Immutability of posted receipt: updating or deleting a posted receipt throws DomainException
        $this->expectException(DomainException::class);
        app(TenantManager::class)->runForTenant($this->tenantA->id, function () use ($grn2Id) {
            $postedReceipt = PurchaseReceipt::findOrFail($grn2Id);
            $postedReceipt->notes = 'Tampered notes';
            $postedReceipt->save();
        });
    }

    public function test_receipt_posting_idempotency_prevents_duplicate_inventory_movements(): void
    {
        [$supplier, $location, $item] = app(TenantManager::class)->runForTenant($this->tenantA->id, function () {
            $sup = Supplier::create([
                'tenant_id' => $this->tenantA->id,
                'code' => 'SUP-SYRUP',
                'name' => 'PT Monin Sirup',
                'status' => 'active',
            ]);
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Bar Outlet',
                'code' => 'BAR-01',
                'type' => 'outlet',
            ]);
            $itm = InventoryItem::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Vanilla Syrup',
                'sku' => 'SYR-VAN',
                'base_unit' => 'liter',
            ]);

            return [$sup, $loc, $itm];
        });

        $invService = app(InventoryService::class);
        $invService->setOpeningBalance($this->tenantA->id, $item->id, $location->id, 0);

        // Create and Approve PO
        $poRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-syrup',
        ])->postJson('/api/v1/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'destination_location_id' => $location->id,
            'items' => [
                [
                    'inventory_item_id' => $item->id,
                    'ordered_quantity' => 12,
                    'unit_cost_minor' => 140000,
                ],
            ],
        ]);
        $poId = $poRes->json('data.id');
        $poItemId = $poRes->json('data.items.0.id');

        $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-po-app-syrup',
        ])->postJson("/api/v1/purchasing/orders/{$poId}/approve")->assertStatus(200);

        // Create draft receipt
        $grnRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-grn-syrup',
        ])->postJson('/api/v1/purchasing/receipts', [
            'purchase_order_id' => $poId,
            'items' => [
                [
                    'purchase_order_item_id' => $poItemId,
                    'quantity' => 12,
                ],
            ],
        ]);
        $grnId = $grnRes->json('data.id');

        // Post receipt with Idempotency-Key
        $postRes1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-post-syrup-repeat',
        ])->postJson("/api/v1/purchasing/receipts/{$grnId}/post");
        $postRes1->assertStatus(200);

        // Replay identical post request with same Idempotency-Key -> cached 200, stock NOT doubled to 24
        $postRes2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-post-syrup-repeat',
        ])->postJson("/api/v1/purchasing/receipts/{$grnId}/post");
        $postRes2->assertStatus(200);

        // Verify physical stock is 12.0000, NOT 24.0000
        $rec = $invService->reconcile($this->tenantA->id, $item->id, $location->id);
        $this->assertEquals('12.0000', $rec['materialized_on_hand']);
        $this->assertEquals('12.0000', $rec['ledger_sum']);
    }

    public function test_cashier_cannot_mutate_purchasing(): void
    {
        // Cashier attempts to create supplier -> 403 Forbidden
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenCashierA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'idemp-cashier-sup',
        ])->postJson('/api/v1/purchasing/suppliers', [
            'code' => 'SUP-ILLEGAL',
            'name' => 'Supplier Ilegal',
        ]);
        $res->assertStatus(403);
    }
}
