<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\CashMovement;
use App\Models\IdempotencyRecord;
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
use App\Models\Receipt;
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
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class OfflineSyncTest extends TenantTestCase
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

        $this->tenantData = $this->createTenant('Warung Kopi Offline Test');
        $tenant = $this->tenantData['tenant'];
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Offline', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manager Offline', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
        $this->shiftService = app(ShiftService::class);

        $this->runForTenant(function () use ($tenant) {
            $this->kitchenLocation = InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Dapur Kopi',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);

            $this->outlet->update(['default_pos_location_id' => $this->kitchenLocation->id]);

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
                'on_hand' => 1000.0,
                'reserved' => 0.0,
            ]);

            InventoryCostState::create([
                'tenant_id' => $tenant->id,
                'inventory_item_id' => $this->coffeeBeans->id,
                'location_id' => $this->kitchenLocation->id,
                'moving_weighted_average_cost_minor' => 200,
                'total_cost_minor' => 200000,
                'total_quantity' => 1000.0,
            ]);

            $this->product = Product::create([
                'tenant_id' => $tenant->id,
                'name' => 'Es Kopi Susu Offline',
                'status' => 'active',
            ]);

            $this->variant = ProductVariant::create([
                'tenant_id' => $tenant->id,
                'product_id' => $this->product->id,
                'name' => 'Regular',
                'sku' => 'SKU-KOPI-REG',
                'is_default' => true,
                'status' => 'active',
            ]);

            Price::create([
                'tenant_id' => $tenant->id,
                'variant_id' => $this->variant->id,
                'outlet_id' => $this->outlet->id,
                'amount' => 1800000, // Rp 18.000
                'currency' => 'IDR',
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            $recipe = Recipe::create([
                'tenant_id' => $tenant->id,
                'product_variant_id' => $this->variant->id,
                'name' => 'Resep Es Kopi',
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
                'yield_quantity' => 1.0,
                'yield_unit' => 'cup',
                'created_by' => $this->manager->id,
                'published_by' => $this->manager->id,
                'published_at' => now()->subDay(),
            ]);

            RecipeComponent::create([
                'tenant_id' => $tenant->id,
                'recipe_version_id' => $version->id,
                'inventory_item_id' => $this->coffeeBeans->id,
                'quantity' => 20.0, // 20g per cup
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
     * Helper to open a shift.
     */
    protected function openShift(): string
    {
        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 10000000, // Rp 100.000
                'notes' => 'Shift Kasir Pagi',
            ]);

        $res->assertStatus(201);

        return $res->json('data.id');
    }

    /**
     * 1. Happy Path: Offline sale sync finalization, payment, inventory, cash, receipt, finance.
     */
    public function test_offline_sale_sync_happy_path(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();

        $saleId = (string) Str::uuid7();
        $operationId = (string) Str::uuid7();
        $idempotencyKey = (string) Str::uuid7();

        $syncPayload = [
            'client_id' => 'device-pos-01',
            'device_id' => 'tablet-kasir-front',
            'checkpoint' => 'cp-001',
            'operations' => [
                [
                    'id' => $operationId,
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'idempotency_key' => $idempotencyKey,
                    'causation_id' => null,
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'customer_id' => null,
                        'items' => [
                            [
                                'id' => (string) Str::uuid7(),
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 2,
                                'unit_price_minor' => 1800000,
                            ],
                        ],
                        'discount_minor' => 0,
                        'tax_minor' => 0,
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 3600000,
                            'cash_received_minor' => 4000000,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);

        $this->assertEquals('accepted', $res->json('data.results.0.status'), $res->json('data.results.0.message') ?? '');
        $res->assertJsonPath('data.results.0.code', 'OK');

        // Verify Sale in database has client-generated UUIDv7
        $this->runForTenant(function () use ($saleId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertEquals(Sale::STATUS_FINALIZED, $sale->status);
            $this->assertEquals(3600000, $sale->total_minor);

            // Verify inventory decremented: 1000g - 40g = 960g
            $loc = InventoryItemLocation::where('inventory_item_id', $this->coffeeBeans->id)
                ->where('location_id', $this->kitchenLocation->id)
                ->first();
            $this->assertEquals(960.0, (float) $loc->on_hand);

            // Verify payment
            $payment = Payment::where('sale_id', $saleId)->first();
            $this->assertNotNull($payment);
            $this->assertEquals('succeeded', $payment->status);
            $this->assertEquals('cash', $payment->method);

            // Verify Cash Movement
            $cashMovement = CashMovement::where('reference_id', $payment->id)->first();
            $this->assertNotNull($cashMovement);
            $this->assertEquals(3600000, $cashMovement->amount_minor);

            // Verify Receipt
            $receipt = Receipt::where('sale_id', $saleId)->first();
            $this->assertNotNull($receipt);

            // Verify Finance Journal Entry exists
            $journal = JournalEntry::where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->first();
            $this->assertNotNull($journal);
        });
    }

    /**
     * 2. Idempotency Replay: same operation x2 and x3 returns cached response without duplicate side-effects.
     */
    public function test_idempotent_sync_replay_does_not_duplicate_side_effects(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();

        $saleId = (string) Str::uuid7();
        $operationId = (string) Str::uuid7();
        $idempotencyKey = (string) Str::uuid7();

        $syncPayload = [
            'client_id' => 'device-pos-01',
            'operations' => [
                [
                    'id' => $operationId,
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'idempotency_key' => $idempotencyKey,
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 1,
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 1800000,
                        ],
                    ],
                ],
            ],
        ];

        // 1st Attempt: Accepted
        $res1 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);
        $res1->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'accepted');

        // 2nd Attempt: Already Processed
        $res2 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);
        $res2->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'already_processed')
            ->assertJsonPath('data.results.0.code', 'ALREADY_SYNCED');

        // 3rd Attempt: Already Processed
        $res3 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);
        $res3->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'already_processed')
            ->assertJsonPath('data.results.0.code', 'ALREADY_SYNCED');

        // Verify side effects occurred EXACTLY once
        $this->runForTenant(function () use ($saleId) {
            $this->assertEquals(1, Sale::where('id', $saleId)->count());
            $this->assertEquals(1, Payment::where('sale_id', $saleId)->count());
            $this->assertEquals(1, Receipt::where('sale_id', $saleId)->count());

            // Inventory: 1000g - 20g = 980g (NOT 940g or 960g)
            $loc = InventoryItemLocation::where('inventory_item_id', $this->coffeeBeans->id)
                ->where('location_id', $this->kitchenLocation->id)
                ->first();
            $this->assertEquals(980.0, (float) $loc->on_hand);
        });
    }

    /**
     * 3. Inventory Conflict: Insufficient stock returns blocked and rolls back atomically.
     */
    public function test_insufficient_stock_returns_blocked_with_no_mutation(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();

        $saleId = (string) Str::uuid7();
        $operationId = (string) Str::uuid7();

        $syncPayload = [
            'client_id' => 'device-pos-01',
            'operations' => [
                [
                    'id' => $operationId,
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'idempotency_key' => (string) Str::uuid7(),
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 100, // Requires 2000g (only 1000g available)
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 180000000,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'blocked')
            ->assertJsonPath('data.results.0.code', 'INSUFFICIENT_STOCK');

        // Verify stock is untouched at 1000g
        $this->runForTenant(function () {
            $loc = InventoryItemLocation::where('inventory_item_id', $this->coffeeBeans->id)
                ->where('location_id', $this->kitchenLocation->id)
                ->first();
            $this->assertEquals(1000.0, (float) $loc->on_hand);
        });
    }

    /**
     * 4. Shift Awareness: Closed shift blocks offline sale synchronization.
     */
    public function test_closed_shift_blocks_offline_sale(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();

        // Close the shift first
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/shifts/{$shiftId}/close", [
                'actual_closing_cash_minor' => 10000000,
            ])
            ->assertStatus(200);

        $saleId = (string) Str::uuid7();
        $syncPayload = [
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId, // Shift is closed!
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 1,
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 1800000,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'blocked')
            ->assertJsonPath('data.results.0.code', 'SHIFT_NOT_AVAILABLE');
    }

    /**
     * 5. Offline Payment Restriction: Online gateway (e.g. Midtrans/QRIS) cannot be settled offline.
     */
    public function test_offline_gateway_payment_is_blocked(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();

        $saleId = (string) Str::uuid7();
        $syncPayload = [
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 1,
                            ],
                        ],
                        'payment' => [
                            'method' => 'midtrans', // Online gateway disallowed offline!
                            'amount_minor' => 1800000,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'blocked')
            ->assertJsonPath('data.results.0.code', 'OFFLINE_PAYMENT_METHOD_DISALLOWED');
    }

    /**
     * 6. Tenant & Outlet Security Isolation: Crafted cross-tenant and unauthorized outlet payloads are rejected.
     */
    public function test_cross_tenant_and_unauthorized_outlet_security(): void
    {
        $tenantA = $this->tenantData['tenant'];
        $tenantBData = $this->createTenant('Warung Kopi B');
        $tenantB = $tenantBData['tenant'];

        // A. Crafted tenant_id in payload pointing to Tenant B while authenticated as Tenant A
        $syncPayloadCrossTenant = [
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => (string) Str::uuid7(),
                    'payload' => [
                        'tenant_id' => $tenantB->id, // Crafted cross-tenant!
                        'outlet_id' => $this->outlet->id,
                        'items' => [],
                    ],
                ],
            ],
        ];

        $resCrossTenant = $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson('/api/v1/sync', $syncPayloadCrossTenant);

        $resCrossTenant->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'TENANT_MISMATCH');

        // B. Unauthorized Outlet access: Cashier attempts to sync to Tenant B's outlet
        $syncPayloadUnauthorizedOutlet = [
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => (string) Str::uuid7(),
                    'payload' => [
                        'outlet_id' => $tenantBData['outlet']->id, // Cashier has no access!
                        'items' => [],
                    ],
                ],
            ],
        ];

        $resOutlet = $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson('/api/v1/sync', $syncPayloadUnauthorizedOutlet);

        $resOutlet->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'blocked')
            ->assertJsonPath('data.results.0.code', 'OUTLET_ACCESS_DENIED');
    }

    /**
     * 7. Causal Ordering / Dependency check: Child operation retryable when causation parent is not ready.
     */
    public function test_causal_dependency_ordering(): void
    {
        $tenant = $this->tenantData['tenant'];
        $missingParentId = (string) Str::uuid7();

        $syncPayload = [
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => (string) Str::uuid7(),
                    'causation_id' => $missingParentId, // Parent not completed!
                    'payload' => [
                        'outlet_id' => $this->outlet->id,
                        'items' => [],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'retryable')
            ->assertJsonPath('data.results.0.code', 'DEPENDENCY_NOT_READY');
    }

    /**
     * 8. Reconciliation: Batch of N offline sales sync cleanly and totals/inventory reconcile perfectly.
     */
    public function test_batch_reconciliation_of_multiple_offline_sales(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();

        $sale1Id = (string) Str::uuid7();
        $sale2Id = (string) Str::uuid7();

        $syncPayload = [
            'client_id' => 'device-pos-batch',
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $sale1Id,
                    'idempotency_key' => (string) Str::uuid7(),
                    'payload' => [
                        'sale_id' => $sale1Id,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 2, // 40g
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 3600000,
                        ],
                    ],
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $sale2Id,
                    'idempotency_key' => (string) Str::uuid7(),
                    'payload' => [
                        'sale_id' => $sale2Id,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 3, // 60g
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 5400000,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.1.status', 'accepted');

        // Reconciliation verification
        $this->runForTenant(function () use ($sale1Id, $sale2Id) {
            $this->assertEquals(2, Sale::whereIn('id', [$sale1Id, $sale2Id])->where('status', 'finalized')->count());

            // Total cash movements: 3.600.000 + 5.400.000 = 9.000.000
            $totalCash = CashMovement::where('type', CashMovement::TYPE_SALE)->sum('amount_minor');
            $this->assertEquals(9000000, $totalCash);

            // Total stock consumed: 40g + 60g = 100g -> 1000g - 100g = 900g
            $loc = InventoryItemLocation::where('inventory_item_id', $this->coffeeBeans->id)
                ->where('location_id', $this->kitchenLocation->id)
                ->first();
            $this->assertEquals(900.0, (float) $loc->on_hand);
        });
    }

    public function test_multi_day_offline_replay_without_idempotency_record_is_safe(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();
        $saleId = (string) Str::uuid7();
        $opId = (string) Str::uuid7();
        $idempKey = (string) Str::uuid7();

        $syncPayload = [
            'client_id' => 'pos-device-tablet-01',
            'device_id' => 'device-uuid-1',
            'operations' => [
                [
                    'id' => $opId,
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'idempotency_key' => $idempKey,
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 1,
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 1800000,
                            'cash_received_minor' => 2000000,
                        ],
                    ],
                ],
            ],
        ];

        // 1. Initial Sync
        $res1 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $syncPayload);
        $res1->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'accepted');

        // 2. Simulate Idempotency Record expiration (> 24 hours / TTL purge)
        IdempotencyRecord::where('idempotency_key', $idempKey)->delete();
        $this->assertEquals(0, IdempotencyRecord::where('idempotency_key', $idempKey)->count());

        // 3. Replay sync request (with different operation id but same aggregate sale)
        $replayPayload = $syncPayload;
        $replayPayload['operations'][0]['id'] = (string) Str::uuid7();
        $replayPayload['operations'][0]['idempotency_key'] = (string) Str::uuid7();

        $res2 = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $replayPayload);

        $res2->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'already_processed')
            ->assertJsonPath('data.results.0.code', 'ALREADY_SYNCED');

        // 4. Assert zero duplicate side-effects
        $this->runForTenant(function () use ($saleId) {
            $this->assertEquals(1, Sale::where('id', $saleId)->count());
            $this->assertEquals(1, Payment::where('sale_id', $saleId)->count());
            $this->assertEquals(1, CashMovement::where('type', CashMovement::TYPE_SALE)->where('metadata->sale_id', $saleId)->count());
            $this->assertEquals(1, JournalEntry::where('source_type', 'sale')->where('source_id', $saleId)->count());
            $this->assertEquals(1, Receipt::where('sale_id', $saleId)->count());
        });
    }

    public function test_server_revalidates_and_does_not_trust_client_forged_totals(): void
    {
        $tenant = $this->tenantData['tenant'];
        $shiftId = $this->openShift();
        $saleId = (string) Str::uuid7();

        // Malicious client claims price is 100 minor units instead of 1,800,000
        $forgedPayload = [
            'client_id' => 'tampered-device-01',
            'device_id' => 'device-tamper',
            'operations' => [
                [
                    'id' => (string) Str::uuid7(),
                    'command_type' => 'sales.finalize',
                    'aggregate_type' => 'sale',
                    'aggregate_id' => $saleId,
                    'idempotency_key' => (string) Str::uuid7(),
                    'payload' => [
                        'sale_id' => $saleId,
                        'outlet_id' => $this->outlet->id,
                        'shift_id' => $shiftId,
                        'subtotal_minor' => 100, // Forged
                        'total_minor' => 100,    // Forged
                        'items' => [
                            [
                                'product_variant_id' => $this->variant->id,
                                'quantity' => 1,
                                'unit_price_minor' => 100, // Forged
                            ],
                        ],
                        'payment' => [
                            'method' => 'cash',
                            'amount_minor' => 100, // Forged
                            'cash_received_minor' => 500,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/sync', $forgedPayload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'accepted');

        // Server authoritative verification: Total must be 1,800,000, NOT 100
        $this->runForTenant(function () use ($saleId) {
            $sale = Sale::findOrFail($saleId);
            $this->assertEquals(1800000, $sale->subtotal_minor);
            $this->assertEquals(1800000, $sale->total_minor);

            $payment = Payment::where('sale_id', $saleId)->firstOrFail();
            $this->assertEquals(1800000, $payment->amount_minor);

            // Double entry debits == credits == 1800000
            $journal = JournalEntry::where('source_type', 'sale')->where('source_id', $saleId)->firstOrFail();
            $this->assertEquals(1800000, $journal->lines()->where('debit_minor', '>', 0)->sum('debit_minor'));
            $this->assertEquals(1800000, $journal->lines()->where('credit_minor', '>', 0)->sum('credit_minor'));
        });
    }
}
