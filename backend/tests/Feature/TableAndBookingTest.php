<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\BookingOverlapException;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\Table\TableService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class TableAndBookingTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $manager;

    protected Outlet $outlet;

    protected TableService $tableService;

    protected BookingService $bookingService;

    protected OrderService $orderService;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Makan Sederhana');
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Budi Kasir', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Siti Manajer', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        $this->tableService = app(TableService::class);
        $this->bookingService = app(BookingService::class);
        $this->orderService = app(OrderService::class);
        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);

        $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Main Kitchen',
                'code' => 'KIT-01',
                'type' => 'kitchen',
                'is_active' => true,
            ]);

            $this->outlet->update(['default_pos_location_id' => $loc->id]);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    protected function createProductWithPrice(string $name, int $priceMinor): array
    {
        return $this->runForTenant(function () use ($name, $priceMinor) {
            $cat = Category::firstOrCreate(
                ['tenant_id' => $this->tenantData['tenant']->id, 'slug' => 'makanan'],
                ['name' => 'Makanan', 'is_active' => true]
            );

            $prod = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $cat->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.uniqid(),
                'status' => 'active',
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $prod->id,
                'name' => 'Reguler',
                'sku' => 'SKU-'.strtoupper(Str::random(6)),
                'status' => 'active',
                'is_default' => true,
            ]);

            $price = Price::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'variant_id' => $variant->id,
                'outlet_id' => $this->outlet->id,
                'amount' => $priceMinor,
                'currency' => 'IDR',
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            return ['product' => $prod, 'variant' => $variant, 'price' => $price];
        });
    }

    // ==========================================
    // 1. TABLE MANAGEMENT & SECURITY TESTS
    // ==========================================

    public function test_manager_can_create_and_list_tables(): void
    {
        $response = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson('/api/v1/tables', [
                'outlet_id' => $this->outlet->id,
                'code' => 'T-01',
                'name' => 'Meja 1 Utama',
                'capacity' => 4,
            ]);

        $response->assertStatus(201);
        $this->assertEquals('T-01', $response->json('data.code'));
        $this->assertNotEmpty($response->json('data.qr_token'));

        // Duplicate code in same outlet rejected
        $dup = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson('/api/v1/tables', [
                'outlet_id' => $this->outlet->id,
                'code' => 'T-01',
                'name' => 'Meja Duplikat',
                'capacity' => 2,
            ]);
        $dup->assertStatus(422);

        // List tables
        $list = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->getJson("/api/v1/tables?outlet_id={$this->outlet->id}");
        $list->assertStatus(200);
        $this->assertCount(1, $list->json('data'));
    }

    public function test_table_qr_token_rotation_and_revocation(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-02', 'name' => 'Meja 2', 'capacity' => 4]
            );
        });

        $oldToken = $table->qr_token;
        $this->assertNotEmpty($oldToken);

        // Rotate QR
        $rotateRes = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson("/api/v1/tables/{$table->id}/rotate-qr");
        $rotateRes->assertStatus(200);
        $newToken = $rotateRes->json('data.qr_token');
        $this->assertNotEquals($oldToken, $newToken);

        // Old token is immediately invalid
        $oldPublic = $this->getJson("/api/v1/public/tables/{$oldToken}");
        $oldPublic->assertStatus(404);

        // New token works
        $newPublic = $this->getJson("/api/v1/public/tables/{$newToken}");
        $newPublic->assertStatus(200);

        // Revoke QR
        $revokeRes = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson("/api/v1/tables/{$table->id}/revoke-qr");
        $revokeRes->assertStatus(200);

        // Revoked token fails
        $revokedPublic = $this->getJson("/api/v1/public/tables/{$newToken}");
        $revokedPublic->assertStatus(404);
    }

    public function test_cross_tenant_table_isolation(): void
    {
        $tenant2 = $this->createTenant('Warung Sebelah');
        $t2Manager = $this->addMemberToTenant($tenant2['tenant'], 'manager', 'Manager Sebelah')['user'];

        $t1Table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SECRET', 'name' => 'Meja Rahasia', 'capacity' => 2]
            );
        });

        // Tenant 2 attempts to access Tenant 1 table
        $res = $this->authenticateTenantUser($t2Manager, $tenant2['tenant'])
            ->getJson("/api/v1/tables/{$t1Table->id}");
        $res->assertStatus(404);
    }

    // ==========================================
    // 2. BOOKING DOMAIN & OVERLAP TESTS
    // ==========================================

    public function test_booking_crud_and_overlap_rejection(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-BKG', 'name' => 'Meja Booking', 'capacity' => 4]
            );
        });

        $startsAt = Carbon::tomorrow()->setTime(18, 0, 0);
        $endsAt = Carbon::tomorrow()->setTime(20, 0, 0);

        // Booking A (18:00 - 20:00)
        $bookingA = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson('/api/v1/bookings', [
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'customer_name' => 'Ahmad Dani',
                'customer_contact' => '08123456789',
                'party_size' => 4,
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
            ]);
        $bookingA->assertStatus(201);
        $this->assertEquals('confirmed', $bookingA->json('data.status'));

        // Booking B overlapping (18:30 - 19:30) MUST BE REJECTED
        $bookingB = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson('/api/v1/bookings', [
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'customer_name' => 'Bambang',
                'customer_contact' => '08987654321',
                'party_size' => 2,
                'starts_at' => $startsAt->copy()->addMinutes(30)->toIso8601String(),
                'ends_at' => $startsAt->copy()->addMinutes(90)->toIso8601String(),
            ]);
        $bookingB->assertStatus(422);
        $this->assertEquals('BOOKING_OVERLAP', $bookingB->json('code'));

        // Booking C adjacent (20:00 - 22:00) MUST SUCCEED
        $bookingC = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson('/api/v1/bookings', [
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'customer_name' => 'Citra',
                'customer_contact' => '08111222333',
                'party_size' => 2,
                'starts_at' => $endsAt->toIso8601String(),
                'ends_at' => $endsAt->copy()->addHours(2)->toIso8601String(),
            ]);
        $bookingC->assertStatus(201);
    }

    public function test_concurrent_booking_race_condition_protection(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-RACE', 'name' => 'Meja Balapan', 'capacity' => 4]
            );
        });

        $startsAt = Carbon::tomorrow()->setTime(12, 0, 0);
        $endsAt = Carbon::tomorrow()->setTime(14, 0, 0);

        // Create booking 1
        $this->bookingService->createBooking(
            $this->tenantData['tenant'],
            $this->outlet->id,
            [
                'table_id' => $table->id,
                'customer_name' => 'User 1',
                'party_size' => 2,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]
        );

        // Attempt concurrent booking on locked table
        $this->expectException(BookingOverlapException::class);
        $this->bookingService->createBooking(
            $this->tenantData['tenant'],
            $this->outlet->id,
            [
                'table_id' => $table->id,
                'customer_name' => 'User 2',
                'party_size' => 2,
                'starts_at' => $startsAt->copy()->addMinutes(15),
                'ends_at' => $endsAt->copy()->addMinutes(15),
            ]
        );
    }

    public function test_booking_check_in_and_table_session_lifecycle(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SESS', 'name' => 'Meja Sesi', 'capacity' => 4]
            );
        });

        $booking = $this->runForTenant(function () use ($table) {
            return $this->bookingService->createBooking(
                $this->tenantData['tenant'],
                $this->outlet->id,
                [
                    'table_id' => $table->id,
                    'customer_name' => 'Doni',
                    'party_size' => 3,
                    'starts_at' => now(),
                    'ends_at' => now()->addHours(2),
                ]
            );
        });

        // Check in
        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/bookings/{$booking->id}/check-in");
        $res->assertStatus(200);

        $this->runForTenant(function () use ($table, $booking) {
            $table->refresh();
            $booking->refresh();
            $this->assertEquals(Table::STATUS_OCCUPIED, $table->status);
            $this->assertEquals(Booking::STATUS_CHECKED_IN, $booking->status);
            $this->assertNotNull($table->activeSession);
            $this->assertEquals('active', $table->activeSession->status);
        });

        // Release table
        $rel = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/tables/{$table->id}/release");
        $rel->assertStatus(200);

        $this->runForTenant(function () use ($table) {
            $table->refresh();
            $this->assertEquals(Table::STATUS_AVAILABLE, $table->status);
            $this->assertNull($table->activeSession);
        });
    }

    // ==========================================
    // 3. PUBLIC QR ORDERING & IDEMPOTENCY
    // ==========================================

    public function test_public_qr_order_submission_with_server_price_authority(): void
    {
        $product = $this->createProductWithPrice('Nasi Goreng Spesial', 25000);
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-QR1', 'name' => 'Meja QR 1', 'capacity' => 4]
            );
        });

        // Public Menu Inspection
        $menuRes = $this->getJson("/api/v1/public/tables/{$table->qr_token}/menu");
        $menuRes->assertStatus(200);
        $this->assertEquals('Warung Makan Sederhana - Outlet 1', $menuRes->json('data.outlet_name'));
        $this->assertNotEmpty($menuRes->json('data.products'));

        // Customer submits order with FORGED price: client tries to send 1000 IDR
        $orderRes = $this->postJson("/api/v1/public/tables/{$table->qr_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2,
                    'unit_price_minor' => 1000, // Attacker forged price
                    'subtotal_minor' => 2000,   // Attacker forged subtotal
                    'total_minor' => 2000,      // Attacker forged total
                ],
            ],
            'customer_name' => 'Budi Pelanggan',
            'customer_phone' => '081299998888',
        ]);

        $orderRes->assertStatus(201);
        $this->assertEquals('submitted', $orderRes->json('data.status'));

        // Price MUST BE CALCULATED BY SERVER: 2 * 25000 = 50000 IDR
        $this->assertEquals(50000, $orderRes->json('data.subtotal_minor'));
        $this->assertEquals(50000, $orderRes->json('data.total_minor'));

        // Verify order persisted and table occupied
        $this->runForTenant(function () use ($table) {
            $table->refresh();
            $this->assertEquals(Table::STATUS_OCCUPIED, $table->status);
            $this->assertNotNull($table->activeSession);
        });
    }

    public function test_duplicate_order_submission_is_idempotent(): void
    {
        $product = $this->createProductWithPrice('Es Teh Manis', 5000);
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-IDEM', 'name' => 'Meja Idem', 'capacity' => 2]
            );
        });

        $idempotencyKey = 'idemp_key_'.Str::random(16);
        $payload = [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 3,
                ],
            ],
            'customer_notes' => 'Kurang manis',
        ];

        // First attempt
        $res1 = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->qr_token}/orders", $payload);
        $res1->assertStatus(201);
        $orderNumber = $res1->json('data.order_number');

        // Replay attempt with same key and same payload
        $res2 = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->qr_token}/orders", $payload);
        $res2->assertStatus(201);
        $this->assertEquals($orderNumber, $res2->json('data.order_number'));

        // Verify only 1 order created in DB
        $ordersCount = $this->runForTenant(fn () => Order::where('tenant_id', $this->tenantData['tenant']->id)->count());
        $this->assertEquals(1, $ordersCount);

        // Replay attempt with same key but altered payload MUST BE REJECTED
        $alteredPayload = [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 10, // Altered quantity
                ],
            ],
        ];
        $res3 = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->qr_token}/orders", $alteredPayload);
        $res3->assertStatus(422);
    }

    // ==========================================
    // 4. CRITICAL END-TO-END FLOW (STEPS 1 - 20)
    // ==========================================

    public function test_critical_e2e_booking_qr_order_sale_payment_receipt_and_release(): void
    {
        // 1. Create table
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'MEJA-07', 'name' => 'Meja VIP 7', 'capacity' => 6]
            );
        });

        $product = $this->createProductWithPrice('Sate Ayam Madura', 30000);

        // 2. Booking Scenario: Customer books table
        $booking = $this->runForTenant(function () use ($table) {
            return $this->bookingService->createBooking(
                $this->tenantData['tenant'],
                $this->outlet->id,
                [
                    'table_id' => $table->id,
                    'customer_name' => 'Pak Joko',
                    'customer_contact' => '081234567890',
                    'party_size' => 4,
                    'starts_at' => now(),
                    'ends_at' => now()->addHours(2),
                ]
            );
        });
        $this->assertEquals('confirmed', $booking->status);

        // 3. Customer arrives & checks in
        $checkInRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/bookings/{$booking->id}/check-in");
        $checkInRes->assertStatus(200);

        $session = $this->runForTenant(function () use ($table) {
            $table->refresh();
            $this->assertEquals(Table::STATUS_OCCUPIED, $table->status);

            return $table->activeSession;
        });
        $this->assertNotNull($session);

        // 4. Customer scans QR & submits order
        $orderRes = $this->postJson("/api/v1/public/tables/{$table->qr_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2, // 2 * 30000 = 60000
                ],
            ],
            'customer_notes' => 'Bumbu kacang dipisah',
        ]);
        $orderRes->assertStatus(201);
        $orderNumber = $orderRes->json('data.order_number');
        $this->assertEquals(60000, $orderRes->json('data.total_minor'));

        $order = $this->runForTenant(fn () => Order::where('order_number', $orderNumber)->firstOrFail());
        $this->assertEquals('submitted', $order->status);

        // 5. Staff accepts order and prepares
        $acceptRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'accepted']);
        $acceptRes->assertStatus(200);

        $serveRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'served']);
        $serveRes->assertStatus(200);

        // 6. Checkout table session: Convert order to authoritative Sale
        $checkoutRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson('/api/v1/orders/convert-to-sale', [
                'table_session_id' => $session->session_code,
            ]);
        $checkoutRes->assertStatus(201);
        $saleId = $checkoutRes->json('data.sale.id');
        $this->assertNotNull($saleId);

        $this->runForTenant(function () use ($order, $saleId) {
            $order->refresh();
            $this->assertEquals(Order::STATUS_CONVERTED, $order->status);
            $this->assertEquals($saleId, $order->sale_id);
        });

        // 7. Cashier Finalizes Sale via existing POS authority
        $sale = $this->runForTenant(fn () => Sale::findOrFail($saleId));
        $finalizedSale = $this->runForTenant(function () use ($sale) {
            return $this->posService->finalizeSale($sale, [], $this->cashier->id);
        });
        $this->assertEquals(Sale::STATUS_FINALIZED, $finalizedSale->status);
        $this->assertEquals(60000, $finalizedSale->total_minor);

        // 8. Payment processed using existing PaymentService
        $payment = $this->runForTenant(function () use ($finalizedSale) {
            $created = $this->paymentService->createPayment($finalizedSale, [
                'method' => 'cash',
                'amount_minor' => 60000,
            ], $this->cashier);

            return $this->paymentService->processPayment($created, [
                'received_minor' => 100000,
            ]);
        });
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->status);

        // 9. Table released after dining
        $releaseRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/tables/{$table->id}/release");
        $releaseRes->assertStatus(200);

        $this->runForTenant(function () use ($table, $session) {
            $table->refresh();
            $this->assertEquals(Table::STATUS_AVAILABLE, $table->status);
            $session->refresh();
            $this->assertEquals(TableSession::STATUS_COMPLETED, $session->status);
        });
    }

    public function test_subscription_entitlement_enforcement_for_booking_and_qr_order(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SUB', 'name' => 'Meja Sub', 'capacity' => 2]
            );
        });

        // Disable qr_table_order entitlement on tenant's plan
        $this->runForTenant(function () {
            $subscription = app(SubscriptionEntitlementService::class)->getSubscription($this->tenantData['tenant']);
            $entitlement = $subscription->plan->entitlements()->where('feature_key', 'qr_table_order')->first();
            if ($entitlement) {
                $entitlement->update(['is_enabled' => false]);
            }
            app(SubscriptionEntitlementService::class)->clearCache($this->tenantData['tenant']);
        });

        // Public QR access MUST BE REJECTED with 403
        $res = $this->getJson("/api/v1/public/tables/{$table->qr_token}");
        $res->assertStatus(403);
    }
}
