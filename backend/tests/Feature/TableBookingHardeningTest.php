<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Finance\FinanceService;
use App\Services\Notification\NotificationService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\ReceiptService;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\Table\TableService;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class TableBookingHardeningTest extends TenantTestCase
{
    protected array $tenantData;

    protected Outlet $outlet;

    protected User $manager;

    protected User $cashier;

    protected TableService $tableService;

    protected BookingService $bookingService;

    protected OrderService $orderService;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected ReceiptService $receiptService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Hardening V1');
        $this->outlet = $this->tenantData['outlet'];

        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Manajer Hardening', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Kasir Hardening', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $this->tableService = app(TableService::class);
        $this->bookingService = app(BookingService::class);
        $this->orderService = app(OrderService::class);
        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
        $this->receiptService = app(ReceiptService::class);

        $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Kitchen Hardening',
                'code' => 'KIT-HD',
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

    protected function createProduct(string $name, int $priceMinor): array
    {
        return $this->runForTenant(function () use ($name, $priceMinor) {
            $cat = Category::firstOrCreate(
                ['tenant_id' => $this->tenantData['tenant']->id, 'slug' => 'kategori-hd'],
                ['name' => 'Kategori HD', 'is_active' => true]
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
                'name' => 'Porsi Normal',
                'sku' => 'SKU-HD-'.strtoupper(Str::random(5)),
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

    // =========================================================================
    // 1. QR TOKEN STORAGE, HASHING & AUDIT REDACTION
    // =========================================================================

    public function test_qr_token_stored_as_sha256_hash_and_raw_token_never_logged(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-HASH-1', 'name' => 'Meja Hashed', 'capacity' => 4],
                $this->manager->id
            );
        });

        $rawToken = $table->raw_token;
        $this->assertNotEmpty($rawToken);
        $this->assertEquals(64, strlen($rawToken));

        // 1. Verify DB stores only the sha256 hash
        $expectedHash = hash('sha256', $rawToken);
        $dbRow = $this->runForTenant(function () use ($table) {
            return DB::table('tables')->where('id', $table->id)->first();
        });

        $this->assertNotNull($dbRow);
        $this->assertEquals($expectedHash, $dbRow->qr_token_hash);
        $this->assertFalse(property_exists($dbRow, 'qr_token'), 'Database column qr_token must not exist');

        // 2. Verify model serialization hides qr_token_hash
        $serialized = $table->toArray();
        $this->assertArrayNotHasKey('qr_token_hash', $serialized, 'qr_token_hash must be hidden in toArray()');

        // 3. Verify AuditLog never contains the raw token
        $audit = AuditLog::where('entity_id', $table->id)
            ->where('action', 'table_created')
            ->first();

        $this->assertNotNull($audit);
        $auditString = json_encode($audit->after);
        $this->assertStringNotContainsString($rawToken, $auditString, 'Raw QR token must not leak into audit logs');
    }

    public function test_qr_token_rotation_and_revocation_enforcement(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-ROT-1', 'name' => 'Meja Rotasi', 'capacity' => 2],
                $this->manager->id
            );
        });

        $initialToken = $table->raw_token;

        // Initial token works
        $res = $this->getJson("/api/v1/public/tables/{$initialToken}");
        $res->assertStatus(200);

        // Rotate token via manager endpoint
        $rotateRes = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson("/api/v1/tables/{$table->id}/rotate-qr");
        $rotateRes->assertStatus(200);

        $newToken = $rotateRes->json('data.raw_token');
        $this->assertNotEmpty($newToken);
        $this->assertNotEquals($initialToken, $newToken);

        // Old token MUST FAIL IMMEDIATELY (404)
        $oldRes = $this->getJson("/api/v1/public/tables/{$initialToken}");
        $oldRes->assertStatus(404);

        // New token SUCCEEDS
        $newRes = $this->getJson("/api/v1/public/tables/{$newToken}");
        $newRes->assertStatus(200);

        // Revoke token via manager endpoint
        $revokeRes = $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson("/api/v1/tables/{$table->id}/revoke-qr");
        $revokeRes->assertStatus(200);

        // Revoked token MUST FAIL for show, menu, and orders
        $this->getJson("/api/v1/public/tables/{$newToken}")->assertStatus(404);
        $this->getJson("/api/v1/public/tables/{$newToken}/menu")->assertStatus(404);
        $this->postJson("/api/v1/public/tables/{$newToken}/orders", ['items' => []])->assertStatus(404);
    }

    // =========================================================================
    // 2. PUBLIC API MINIMAL CONTRACT & REDACTION
    // =========================================================================

    public function test_public_table_response_redaction(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-RED-1', 'name' => 'Meja Redaksi', 'capacity' => 6]
            );
        });

        $res = $this->getJson("/api/v1/public/tables/{$table->raw_token}");
        $res->assertStatus(200);

        $data = $res->json('data');

        // Must expose safe table & outlet info
        $this->assertEquals('T-RED-1', $data['table']['code']);
        $this->assertEquals('Meja Redaksi', $data['table']['name']);
        $this->assertEquals(6, $data['table']['capacity']);
        $this->assertEquals('Warung Hardening V1 - Outlet 1', $data['outlet']['name']);

        // MUST NOT LEAK internal identifiers
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('tenant_id', $data);
        $this->assertArrayNotHasKey('outlet_id', $data);
        $this->assertArrayNotHasKey('table_id', $data);
        $this->assertArrayNotHasKey('qr_token_hash', $data);
    }

    // =========================================================================
    // 3. PUBLIC WEB CUSTOMER VIEW & ORDER STATUS
    // =========================================================================

    public function test_public_customer_web_page_and_status_tracking(): void
    {
        $product = $this->createProduct('Sate Ayam HD', 30000);

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-WEB-1', 'name' => 'Meja Web Page', 'capacity' => 4]
            );
        });

        // 1. Web Page GET /order/{token} serves HTML
        $webRes = $this->get("/order/{$table->raw_token}");
        $webRes->assertStatus(200);
        $webRes->assertSee('SeWaroeng Ordering');
        $webRes->assertSee($table->raw_token);

        // Invalid token web page returns 404
        $invalidWeb = $this->get('/order/invalid-fake-token-123');
        $invalidWeb->assertStatus(404);
        $invalidWeb->assertSee('Meja tidak ditemukan');

        // 2. Submit Order
        $orderRes = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2,
                ],
            ],
            'customer_name' => 'Pak Joko',
        ]);
        $orderRes->assertStatus(201);
        $orderNumber = $orderRes->json('data.order_number');
        $trackingToken = $orderRes->json('data.tracking_token');
        $this->assertNotEmpty($orderNumber);
        $this->assertNotEmpty($trackingToken);

        // Attempting to track order with guessable orderNumber MUST FAIL (404)
        $guessRes = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$orderNumber}");
        $guessRes->assertStatus(404);

        // 3. Customer checks order status via cryptographically random trackingToken
        $statusRes = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingToken}");
        $statusRes->assertStatus(200);

        $statusData = $statusRes->json('data');
        $this->assertEquals($orderNumber, $statusData['order_number']);
        $this->assertEquals('submitted', $statusData['status']);
        $this->assertEquals('Diterima', $statusData['status_label']);
        $this->assertEquals('Pak Joko', $statusData['customer_name']);
        $this->assertEquals(60000, $statusData['total_minor']);

        // Must not expose internal IDs
        $this->assertArrayNotHasKey('id', $statusData);
        $this->assertArrayNotHasKey('tenant_id', $statusData);
        $this->assertArrayNotHasKey('outlet_id', $statusData);
        $this->assertArrayNotHasKey('table_id', $statusData);
    }

    // =========================================================================
    // 4. TABLE SESSION CONCURRENCY & ISOLATION
    // =========================================================================

    public function test_table_session_cannot_have_concurrent_active_sessions(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SESS-1', 'name' => 'Meja Sesi', 'capacity' => 4]
            );
        });

        // Open session 1
        $session1 = $this->runForTenant(function () use ($table) {
            return $this->tableService->startSession($table, 4);
        });
        $this->assertEquals(TableSession::STATUS_ACTIVE, $session1->status);

        // Attempting to open concurrent session 2 on the same table returns existing active session
        $session2 = $this->runForTenant(function () use ($table) {
            return $this->tableService->startSession($table, 2);
        });
        $this->assertEquals($session1->id, $session2->id);

        $activeCount = $this->runForTenant(function () use ($table) {
            return TableSession::where('table_id', $table->id)
                ->where('status', TableSession::STATUS_ACTIVE)
                ->count();
        });
        $this->assertEquals(1, $activeCount, 'Table must never have more than 1 active session concurrently');
    }

    // =========================================================================
    // 5. NOTIFICATION CREATION & DEDUPLICATION
    // =========================================================================

    public function test_notification_creation_and_idempotent_deduplication(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-NOTIF-1', 'name' => 'Meja Notifikasi', 'capacity' => 4]
            );
        });

        // 1. Create booking -> dispatches booking_created notification
        $startsAt = Carbon::tomorrow()->setTime(19, 0, 0);
        $endsAt = Carbon::tomorrow()->setTime(21, 0, 0);

        $booking = $this->runForTenant(function () use ($table, $startsAt, $endsAt) {
            return $this->bookingService->createBooking(
                $this->tenantData['tenant'],
                $this->outlet->id,
                [
                    'table_id' => $table->id,
                    'customer_name' => 'Ibu Ratna',
                    'party_size' => 4,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]
            );
        });

        $this->runForTenant(function () use ($booking) {
            $notif = Notification::where('tenant_id', $this->tenantData['tenant']->id)
                ->where('type', 'booking_created')
                ->where('dedup_key', "booking_created:{$booking->id}")
                ->first();

            $this->assertNotNull($notif, 'booking_created notification must exist');
            $this->assertStringContainsString('Ibu Ratna', $notif->message);

            // Replay creation with same dedup_key via notification service
            $replayed = app(NotificationService::class)->createNotification(
                $this->tenantData['tenant']->id,
                [
                    'type' => 'booking_created',
                    'title' => 'Reservasi Baru',
                    'message' => 'Duplicate attempt',
                    'dedup_key' => "booking_created:{$booking->id}",
                ]
            );
            $this->assertEquals($notif->id, $replayed->id, 'Replay must return original notification');

            // Count total booking_created notifications
            $count = Notification::where('tenant_id', $this->tenantData['tenant']->id)
                ->where('dedup_key', "booking_created:{$booking->id}")
                ->count();
            $this->assertEquals(1, $count, 'Notification with same dedup_key must be deduplicated');
        });
    }

    // =========================================================================
    // 6. CROSS-TENANT & CROSS-OUTLET PUBLIC ATTACK PREVENTION
    // =========================================================================

    public function test_cross_tenant_and_cross_outlet_public_qr_attack_prevention(): void
    {
        $tenant2 = $this->createTenant('Tenant Penyerang');
        $product1 = $this->createProduct('Produk Asli Warung 1', 20000);

        $table1 = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SAFE-1', 'name' => 'Meja Aman 1', 'capacity' => 2]
            );
        });

        // Attacker attempts to forge tenant header when using Tenant 1 token
        $res = $this->withHeader('X-Tenant-ID', $tenant2['tenant']->id)
            ->getJson("/api/v1/public/tables/{$table1->raw_token}");

        // Public resolution strictly uses server-side token hash, IGNORING forged header
        $res->assertStatus(200);
        $this->assertEquals('Warung Hardening V1 - Outlet 1', $res->json('data.outlet.name'));

        // Attacker tries to submit order with forged tenant_id in body
        $orderRes = $this->withHeader('X-Tenant-ID', $tenant2['tenant']->id)
            ->postJson("/api/v1/public/tables/{$table1->raw_token}/orders", [
                'tenant_id' => $tenant2['tenant']->id,
                'outlet_id' => $tenant2['outlet']->id,
                'items' => [
                    [
                        'product_variant_id' => $product1['variant']->id,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $orderRes->assertStatus(201);
        $orderNumber = $orderRes->json('data.order_number');

        // Verify order strictly created under Tenant 1 and Outlet 1 inside tenant context
        $this->runForTenant(function () use ($orderNumber) {
            $createdOrder = Order::where('order_number', $orderNumber)->firstOrFail();
            $this->assertEquals($this->tenantData['tenant']->id, $createdOrder->tenant_id);
            $this->assertEquals($this->outlet->id, $createdOrder->outlet_id);
        });
    }

    // =========================================================================
    // 7. PUBLIC ROUTE RATE LIMITING (READ & ORDER SUBMISSION)
    // =========================================================================

    public function test_public_qr_read_route_rate_limiting_enforcement_and_no_leakage(): void
    {
        Cache::flush();

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-RATE-1', 'name' => 'Meja Rate Limit Read', 'capacity' => 4]
            );
        });

        // 1. Request 1 to 60 must succeed (HTTP 200)
        for ($i = 1; $i <= 60; $i++) {
            $res = $this->getJson("/api/v1/public/tables/{$table->raw_token}");
            $res->assertStatus(200);
        }

        // 2. Request 61 must return HTTP 429
        $res61 = $this->getJson("/api/v1/public/tables/{$table->raw_token}");
        $res61->assertStatus(429);

        // 3. Verify response 429 does not leak stack trace, tenant ID, table ID, or internal credentials
        $content = $res61->getContent();
        $this->assertStringNotContainsString($this->tenantData['tenant']->id, $content, '429 response must not leak tenant ID');
        $this->assertStringNotContainsString($table->id, $content, '429 response must not leak table ID');
        $this->assertStringNotContainsString('SQLSTATE', $content, '429 response must not leak SQL trace');
        $this->assertStringNotContainsString('password', $content, '429 response must not leak credentials');
    }

    public function test_public_qr_order_submission_rate_limiting_enforcement(): void
    {
        Cache::flush();

        $product = $this->createProduct('Es Teh Hardening Rate', 5000);

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-RATE-2', 'name' => 'Meja Rate Limit Order', 'capacity' => 2]
            );
        });

        // 1. Requests 1 to 30 must be processed through to the controller (not throttled)
        for ($i = 1; $i <= 30; $i++) {
            $res = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
                'items' => [
                    [
                        'product_variant_id' => $product['variant']->id,
                        'quantity' => 1,
                    ],
                ],
                'customer_name' => "Pemesan {$i}",
                'idempotency_key' => "rate-limit-order-key-{$i}",
            ]);
            $this->assertNotEquals(429, $res->getStatusCode(), "Request {$i} must not be rate limited");
            $res->assertStatus(201);
        }

        // 2. Request 31 must return HTTP 429
        $res31 = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 1,
                ],
            ],
            'customer_name' => 'Pemesan 31',
            'idempotency_key' => 'rate-limit-order-key-31',
        ]);
        $res31->assertStatus(429);

        // Verify clean 429 response
        $content = $res31->getContent();
        $this->assertStringNotContainsString($this->tenantData['tenant']->id, $content);
        $this->assertStringNotContainsString($table->id, $content);
        $this->assertStringNotContainsString('SQLSTATE', $content);
    }

    // =========================================================================
    // 8. TABLE SESSION DATABASE-LEVEL INVARIANT & RACE RECOVERY
    // =========================================================================

    public function test_table_session_database_level_invariant_enforcement(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-DB-INV-1', 'name' => 'Meja Invariant DB', 'capacity' => 4]
            );
        });

        // 1. First session created successfully
        $session1 = $this->runForTenant(function () use ($table) {
            return $this->tableService->startSession($table, 4);
        });
        $this->assertEquals(TableSession::STATUS_ACTIVE, $session1->status);

        // 2. Direct raw SQL insert trying to create duplicate active session must trigger DB constraint error
        $this->runForTenant(function () use ($table) {
            $constraintViolated = false;
            try {
                DB::transaction(function () use ($table) {
                    DB::table('table_sessions')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $table->tenant_id,
                        'outlet_id' => $table->outlet_id,
                        'table_id' => $table->id,
                        'session_code' => 'SESS-RAW-RACE-001',
                        'status' => 'active',
                        'guest_count' => 2,
                        'started_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                $constraintViolated = true;
                $this->assertTrue(
                    str_contains($e->getMessage(), 'uq_table_sessions_active_table') || ($e->errorInfo[0] ?? null) === '23505',
                    'Database must reject duplicate active session via uq_table_sessions_active_table'
                );
            }
            $this->assertTrue($constraintViolated, 'Database must enforce unique partial index on active sessions');
        });

        // 3. Service handles concurrent race gracefully into single active session
        $sessionRecovered = $this->runForTenant(function () use ($table) {
            return $this->tableService->startSession($table, 2);
        });
        $this->assertEquals($session1->id, $sessionRecovered->id);

        // 4. Release table to complete first session
        $this->runForTenant(function () use ($table) {
            $this->tableService->releaseTable($table);
        });

        // 5. Subsequent new active session succeeds without colliding with historical closed session
        $session2 = $this->runForTenant(function () use ($table) {
            return $this->tableService->startSession($table, 4);
        });
        $this->assertNotEquals($session1->id, $session2->id);
        $this->assertEquals(TableSession::STATUS_ACTIVE, $session2->status);

        // Historical closed session remains preserved
        $totalSessions = $this->runForTenant(function () use ($table) {
            return TableSession::where('table_id', $table->id)->count();
        });
        $this->assertEquals(2, $totalSessions, 'Historical sessions must be preserved');
    }

    // =========================================================================
    // 9. NOTIFICATION DATABASE-LEVEL DEDUPLICATION CONSTRAINT
    // =========================================================================

    public function test_notification_database_level_dedup_constraint_and_race_safety(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $dedupKey = 'unique_event_dedup_test_'.Str::random(8);

        // 1. First raw insert succeeds
        $this->runForTenant(function () use ($tenantId, $dedupKey) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'type' => 'test_event',
                'title' => 'First Notification',
                'message' => 'First message',
                'severity' => 'info',
                'dedup_key' => $dedupKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // 2. Direct duplicate raw insert with identical dedup_key must trigger DB unique constraint
        $this->runForTenant(function () use ($tenantId, $dedupKey) {
            $constraintViolated = false;
            try {
                DB::transaction(function () use ($tenantId, $dedupKey) {
                    DB::table('notifications')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'type' => 'test_event',
                        'title' => 'Second Notification',
                        'message' => 'Duplicate message',
                        'severity' => 'info',
                        'dedup_key' => $dedupKey,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException $e) {
                $constraintViolated = true;
                $this->assertTrue(
                    str_contains($e->getMessage(), 'uq_notifications_tenant_dedup') || ($e->errorInfo[0] ?? null) === '23505',
                    'Database must reject duplicate active notification via uq_notifications_tenant_dedup'
                );
            }
            $this->assertTrue($constraintViolated, 'Database must enforce unique dedup_key per tenant');
        });

        // 3. NotificationService handles concurrent duplicate gracefully and returns the persistent record
        $this->runForTenant(function () use ($tenantId, $dedupKey) {
            $notif = app(NotificationService::class)->createNotification($tenantId, [
                'type' => 'test_event',
                'title' => 'Third attempt via service',
                'message' => 'Should return existing',
                'dedup_key' => $dedupKey,
            ]);

            $this->assertEquals('First Notification', $notif->title);

            $count = Notification::where('tenant_id', $tenantId)
                ->where('dedup_key', $dedupKey)
                ->count();
            $this->assertEquals(1, $count);
        });
    }

    // =========================================================================
    // 10. SERVER PRICE AUTHORITY (MANIPULATED CLIENT PRICE IGNORED)
    // =========================================================================

    public function test_server_price_authority_strictly_ignores_client_tampering(): void
    {
        $product = $this->createProduct('Bebek Goreng Spesial Tamper', 45000);

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-PRICE-1', 'name' => 'Meja Cek Harga', 'capacity' => 2]
            );
        });

        // Attacker sends manipulated client price (Rp 1) and manipulated totals (Rp 1)
        $orderRes = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2,
                    'unit_price' => 1,
                    'unit_price_minor' => 100,
                    'subtotal' => 1,
                    'subtotal_minor' => 100,
                    'total' => 1,
                    'total_minor' => 100,
                ],
            ],
            'subtotal_minor' => 100,
            'total_minor' => 100,
            'customer_name' => 'Attacker Diskon Palsu',
        ]);

        $orderRes->assertStatus(201);
        $orderNumber = $orderRes->json('data.order_number');

        // Server MUST calculate 2 x 45,000 = 90,000 minor strictly ignoring client input
        $this->assertEquals(90000, $orderRes->json('data.total_minor'));

        $this->runForTenant(function () use ($orderNumber) {
            $order = Order::where('order_number', $orderNumber)->with('items')->firstOrFail();
            $this->assertEquals(90000, $order->subtotal_minor);
            $this->assertEquals(90000, $order->total_minor);

            $item = $order->items->first();
            $this->assertEquals(45000, $item->unit_price_minor);
            $this->assertEquals(90000, $item->subtotal_minor);
        });
    }

    // =========================================================================
    // 11. INVENTORY + FINANCE SINGLE EFFECT (END-TO-END)
    // =========================================================================

    public function test_inventory_and_finance_single_effect_end_to_end(): void
    {
        // 1. Setup inventory item & recipe
        $invLocation = $this->runForTenant(function () {
            return InventoryLocation::where('tenant_id', $this->tenantData['tenant']->id)
                ->where('outlet_id', $this->outlet->id)
                ->firstOrFail();
        });

        $invItem = $this->runForTenant(function () use ($invLocation) {
            $item = InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Ayam Potong E2E',
                'sku' => 'RAW-AYAM-E2E-'.Str::random(4),
                'storage_unit' => 'g',
                'storage_factor' => 1.0,
                'cogs_method' => 'fifo',
                'is_active' => true,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'inventory_item_id' => $item->id,
                'location_id' => $invLocation->id,
                'on_hand' => 1000.0000,
                'reserved' => 0.0000,
            ]);

            InventoryCostState::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'inventory_item_id' => $item->id,
                'location_id' => $invLocation->id,
                'total_quantity' => 1000.0000,
                'total_cost_minor' => 50000,
                'average_cost_minor' => 50,
            ]);

            return $item;
        });

        $product = $this->createProduct('Ayam Bakar E2E', 35000);

        // Recipe: 1 portion uses 200 grams
        $this->runForTenant(function () use ($product, $invItem) {
            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_variant_id' => $product['variant']->id,
                'name' => 'Resep Ayam Bakar E2E',
                'code' => 'RCP-AYAM-'.Str::random(4),
                'status' => Recipe::STATUS_PUBLISHED,
                'created_by' => $this->manager->id,
            ]);

            $version = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'status' => RecipeVersion::STATUS_PUBLISHED,
                'yield_quantity' => 1.0,
                'yield_unit' => 'portion',
                'effective_from' => now()->subDay(),
                'created_by' => $this->manager->id,
            ]);

            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $version->id,
                'inventory_item_id' => $invItem->id,
                'quantity' => 200.0,
                'unit' => 'g',
            ]);
        });

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-E2E-1', 'name' => 'Meja E2E Flow', 'capacity' => 2]
            );
        });

        $session = $this->runForTenant(function () use ($table) {
            return $this->tableService->startSession($table, 2);
        });

        // 2. Public customer submits order for 2 portions
        $orderRes = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2,
                ],
            ],
            'customer_name' => 'Pelanggan E2E',
        ]);
        $orderRes->assertStatus(201);
        $orderNumber = $orderRes->json('data.order_number');

        // Verify inventory is NOT deducted yet!
        $stockBefore = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $invItem->id)->value('on_hand'));
        $this->assertEquals(1000, (float) $stockBefore, 'Inventory must not be deducted upon order submission');

        $order = $this->runForTenant(fn () => Order::where('order_number', $orderNumber)->firstOrFail());

        // 3. Staff accepts order
        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'accepted'])
            ->assertStatus(200);

        // Inventory still NOT deducted
        $stockAfterAccept = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $invItem->id)->value('on_hand'));
        $this->assertEquals(1000, (float) $stockAfterAccept, 'Inventory must not be deducted upon order acceptance');

        // 4. Staff converts order to Sale
        $checkoutRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson('/api/v1/orders/convert-to-sale', [
                'table_session_id' => $session->session_code,
            ]);
        $checkoutRes->assertStatus(201);
        $saleId = $checkoutRes->json('data.sale.id');

        // Inventory still NOT deducted upon sale creation
        $stockAfterSaleCreate = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $invItem->id)->value('on_hand'));
        $this->assertEquals(1000, (float) $stockAfterSaleCreate, 'Inventory must not be deducted upon sale creation (only on finalization)');

        // 5. Finalize Sale via PosService
        $sale = $this->runForTenant(fn () => Sale::findOrFail($saleId));
        $finalizedSale = $this->runForTenant(function () use ($sale) {
            return $this->posService->finalizeSale($sale, [], $this->cashier->id);
        });

        // Inventory is consumed EXACTLY ONCE (2 portions * 200g = 400g deducted -> 600g remaining)
        $stockAfterFinalize = $this->runForTenant(fn () => InventoryItemLocation::where('inventory_item_id', $invItem->id)->value('on_hand'));
        $this->assertEquals(600, (float) $stockAfterFinalize, 'Inventory must be deducted exactly once upon sale finalization');

        // 6. Finalize Payment via PaymentService
        $payment = $this->runForTenant(function () use ($finalizedSale) {
            $created = $this->paymentService->createPayment($finalizedSale, [
                'method' => 'cash',
                'amount_minor' => 70000,
            ], $this->cashier);

            return $this->paymentService->processPayment($created, [
                'received_minor' => 100000,
            ]);
        });
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->status);

        // Journal entry for sale posted exactly once
        $saleJournals = $this->runForTenant(function () use ($saleId) {
            return JournalEntry::where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->count();
        });
        $this->assertEquals(1, $saleJournals, 'Sale journal entry must be posted exactly once');

        // Retrying postSale does not double-post
        $this->runForTenant(function () use ($finalizedSale) {
            app(FinanceService::class)->postSale($finalizedSale);
        });
        $saleJournalsAfterRetry = $this->runForTenant(function () use ($saleId) {
            return JournalEntry::where('source_type', JournalEntry::SOURCE_SALE)
                ->where('source_id', $saleId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->count();
        });
        $this->assertEquals(1, $saleJournalsAfterRetry, 'Retry must not create duplicate journal entry');

        // Receipt generated via ReceiptService
        $receiptData = $this->receiptService->getReceiptForSale($this->tenantData['tenant']->id, $finalizedSale->id);
        $this->assertNotNull($receiptData);
        $this->assertNotEmpty($receiptData['receipt_number']);
    }

    // =========================================================================
    // 12. SUBSCRIPTION ENTITLEMENT ENFORCEMENT
    // =========================================================================

    public function test_entitlement_enforcement_for_booking_and_qr_order(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SUB-ENT', 'name' => 'Meja Entitlement', 'capacity' => 2]
            );
        });

        // 1. Disable qr_table_order entitlement on tenant's plan
        $this->runForTenant(function () {
            $subscription = app(SubscriptionEntitlementService::class)->getSubscription($this->tenantData['tenant']);
            $entitlement = $subscription->plan->entitlements()->where('feature_key', 'qr_table_order')->first();
            if ($entitlement) {
                $entitlement->update(['is_enabled' => false]);
            }
            app(SubscriptionEntitlementService::class)->clearCache($this->tenantData['tenant']);
        });

        // Public QR access MUST BE REJECTED with 403
        $this->getJson("/api/v1/public/tables/{$table->raw_token}")->assertStatus(403);
        $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", ['items' => []])->assertStatus(403);

        // 2. Disable booking entitlement
        $this->runForTenant(function () {
            $subscription = app(SubscriptionEntitlementService::class)->getSubscription($this->tenantData['tenant']);
            $entitlement = $subscription->plan->entitlements()->where('feature_key', 'booking')->first();
            if ($entitlement) {
                $entitlement->update(['is_enabled' => false]);
            }
            app(SubscriptionEntitlementService::class)->clearCache($this->tenantData['tenant']);
        });

        // Booking creation MUST BE REJECTED with 403
        $this->authenticateTenantUser($this->manager, $this->tenantData['tenant'])
            ->postJson('/api/v1/bookings', [
                'outlet_id' => $this->outlet->id,
                'table_id' => $table->id,
                'customer_name' => 'Tamu Tolak Entitlement',
                'party_size' => 2,
                'starts_at' => Carbon::tomorrow()->setTime(18, 0, 0)->toIso8601String(),
                'ends_at' => Carbon::tomorrow()->setTime(20, 0, 0)->toIso8601String(),
            ])
            ->assertStatus(403);
    }

    // =========================================================================
    // 13. PUBLIC WEB SECURITY HEADERS & NO LEAKAGE
    // =========================================================================

    public function test_public_customer_web_security_headers_and_no_leakage(): void
    {
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-SEC-HEAD-1', 'name' => 'Meja Security Headers', 'capacity' => 4]
            );
        });

        $res = $this->get("/order/{$table->raw_token}");
        $res->assertStatus(200);

        // Verify security headers
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertTrue($res->headers->has('Content-Security-Policy'));

        // Verify NO leakage of tenant id, outlet id, or database credentials
        $html = $res->getContent();
        $this->assertStringNotContainsString($this->tenantData['tenant']->id, $html);
        $this->assertStringNotContainsString($this->outlet->id, $html);
        $this->assertStringNotContainsString($table->id, $html);
        $this->assertStringNotContainsString('sewaroeng_password', $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
    }

    // =========================================================================
    // 14. PUBLIC ORDER TRACKING & IDEMPOTENCY RETRY SEMANTICS
    // =========================================================================

    public function test_public_order_tracking_idempotency_retry_semantics(): void
    {
        $product = $this->createProduct('Es Kopi Susu Idemp', 18000);

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-IDEM-RETRY', 'name' => 'Meja Idempotency Retry', 'capacity' => 2]
            );
        });

        $idempotencyKey = 'IDEMP-RETRY-'.Str::random(16);
        $orderPayload = [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2,
                    'notes' => 'Gula aren sedikit',
                ],
            ],
            'customer_name' => 'Budi Idemp',
            'customer_phone' => '08123456789',
        ];

        // 1. REQUEST A: Initial submission
        $resA = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->raw_token}/orders", $orderPayload);

        $resA->assertStatus(201);
        $orderNumberA = $resA->json('data.order_number');
        $trackingTokenA = $resA->json('data.tracking_token');
        $this->assertNotEmpty($orderNumberA);
        $this->assertNotEmpty($trackingTokenA);

        // Verify tracking token has high cryptographic entropy (24 random bytes / 48 hex chars)
        $this->assertEquals(48, strlen($trackingTokenA));

        // 2. REQUEST B: Simulated network retry with identical Idempotency-Key
        $resB = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->raw_token}/orders", $orderPayload);

        $resB->assertStatus(201);
        $orderNumberB = $resB->json('data.order_number');
        $trackingTokenB = $resB->json('data.tracking_token');

        // Assert deterministic replay semantics
        $this->assertEquals($orderNumberA, $orderNumberB, 'Replayed request must return identical order_number');
        $this->assertEquals($trackingTokenA, $trackingTokenB, 'Replayed request must return identical raw tracking_token for customer tracking');

        // Assert exactly one order exists in database
        $orderCount = $this->runForTenant(function () use ($idempotencyKey) {
            return Order::where('tenant_id', $this->tenantData['tenant']->id)
                ->where('idempotency_key', $idempotencyKey)
                ->count();
        });
        $this->assertEquals(1, $orderCount, 'Exactly one order must exist in DB for same idempotency key');

        // Assert customer can still access tracking status with replayed token
        $statusRes = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingTokenB}");
        $statusRes->assertStatus(200);
        $this->assertEquals($orderNumberA, $statusRes->json('data.order_number'));
        $this->assertEquals('Budi Idemp', $statusRes->json('data.customer_name'));

        // Assert tracking cannot be accessed using order_number (IDOR prevention)
        $idorRes = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$orderNumberA}");
        $idorRes->assertStatus(404);

        // Assert plaintext tracking token is NOT stored in orders table
        $dbRow = $this->runForTenant(function () use ($idempotencyKey) {
            return DB::table('orders')->where('idempotency_key', $idempotencyKey)->first();
        });
        $this->assertEquals(hash('sha256', $trackingTokenA), $dbRow->public_tracking_token_hash);
        $this->assertNotEmpty($dbRow->public_tracking_token_ciphertext);
        $this->assertNotEquals($trackingTokenA, $dbRow->public_tracking_token_ciphertext);
        $this->assertEquals($trackingTokenA, Crypt::decryptString($dbRow->public_tracking_token_ciphertext));
        $this->assertFalse(property_exists($dbRow, 'raw_tracking_token'));
        $this->assertFalse(property_exists($dbRow, 'tracking_token'));

        // Assert metadata no longer holds encrypted credential
        $meta = json_decode((string) $dbRow->metadata, true);
        $this->assertArrayNotHasKey('encrypted_tracking_token', $meta, 'encrypted_tracking_token must not exist in metadata');

        // Assert serialized response does NOT leak internal hashes or encrypted credentials
        $this->assertArrayNotHasKey('public_tracking_token_hash', $resA->json('data'));
        $this->assertArrayNotHasKey('public_tracking_token_ciphertext', $resA->json('data'));
        $this->assertArrayNotHasKey('encrypted_tracking_token', $resA->json('data'));
        $this->assertArrayNotHasKey('public_tracking_token_hash', $resB->json('data'));
        $this->assertArrayNotHasKey('public_tracking_token_ciphertext', $resB->json('data'));
        $this->assertArrayNotHasKey('encrypted_tracking_token', $resB->json('data'));

        // Assert AuditLog never contains raw tracking token or ciphertext
        $auditLog = AuditLog::where('entity_id', $dbRow->id)
            ->where('action', 'order_submitted')
            ->first();
        $this->assertNotNull($auditLog);
        $auditJson = json_encode($auditLog->after);
        $this->assertStringNotContainsString($trackingTokenA, $auditJson, 'Raw tracking token must not leak into audit log');
        $this->assertStringNotContainsString($dbRow->public_tracking_token_hash, $auditJson, 'Tracking hash must not leak into audit log');
        $this->assertStringNotContainsString($dbRow->public_tracking_token_ciphertext, $auditJson, 'Ciphertext must not leak into audit log');

        // Assert wrong tracking token returns 404
        $wrongToken = bin2hex(random_bytes(24));
        $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$wrongToken}")->assertStatus(404);

        // Assert retry with altered payload is rejected
        $alteredPayload = $orderPayload;
        $alteredPayload['items'][0]['quantity'] = 99;
        $resAltered = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->raw_token}/orders", $alteredPayload);
        $resAltered->assertStatus(422);
    }

    // =========================================================================
    // 15. NOTIFICATION TRANSACTION SAFETY (POSTGRESQL 23505 SAVEPOINT)
    // =========================================================================

    public function test_notification_transaction_safety_under_race_with_nested_transaction(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $dedupKey = 'notif_race_'.Str::random(12);

        // Run within tenant context so PostgreSQL RLS app.current_tenant_id is active
        $result = $this->runForTenant(function () use ($tenantId, $dedupKey) {
            // Create initial notification
            $initial = app(NotificationService::class)->createNotification($tenantId, [
                'type' => 'race_test',
                'title' => 'Initial Notification',
                'message' => 'Initial event message',
                'dedup_key' => $dedupKey,
            ]);
            $this->assertNotNull($initial->id);

            // Now run inside an outer active DB::transaction
            // Simulate a race where the service encounters 23505 unique violation
            // Verify that the savepoint ensures the outer transaction remains valid and subsequent queries succeed
            return DB::transaction(function () use ($tenantId, $dedupKey, $initial) {
                // Call NotificationService which will catch 23505, rollback savepoint, and fetch existing row
                $resolved = app(NotificationService::class)->createNotification($tenantId, [
                    'type' => 'race_test',
                    'title' => 'Duplicate In Outer Tx',
                    'message' => 'Should return existing without aborting tx',
                    'dedup_key' => $dedupKey,
                ]);

                // Crucial verification: run another query in the same outer transaction
                // If PostgreSQL transaction were aborted (25P02), this query would fail!
                $afterCheck = DB::table('notifications')
                    ->where('tenant_id', $tenantId)
                    ->where('dedup_key', $dedupKey)
                    ->count();

                return ['initial' => $initial, 'resolved' => $resolved, 'count' => $afterCheck];
            });
        });

        $this->assertEquals($result['initial']->id, $result['resolved']->id);
        $this->assertEquals(1, $result['count']);
    }

    // =========================================================================
    // 16. IDEMPOTENCY DECRYPT FAILURE CONTROLLED RESPONSE (HTTP 409)
    // =========================================================================

    public function test_idempotency_replay_decryption_failure_returns_controlled_409_without_leakage(): void
    {
        $product = $this->createProduct('Kopi Decrypt Fail', 20000);

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-DEC-FAIL', 'name' => 'Meja Decrypt Fail', 'capacity' => 2]
            );
        });

        $idempotencyKey = 'IDEMP-DEC-FAIL-'.Str::random(12);
        $payload = [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 1,
                ],
            ],
            'customer_name' => 'Budi Decrypt',
        ];

        // 1. Initial submission succeeds with 201
        $resInitial = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->raw_token}/orders", $payload);
        $resInitial->assertStatus(201);
        $orderNumber = $resInitial->json('data.order_number');
        $this->assertNotEmpty($orderNumber);

        // 2. Simulate corrupted ciphertext in database
        $this->runForTenant(function () use ($idempotencyKey) {
            DB::table('orders')
                ->where('idempotency_key', $idempotencyKey)
                ->update(['public_tracking_token_ciphertext' => 'corrupted_ciphertext_payload_invalid']);
        });

        // 3. Repeat identical request with same Idempotency-Key
        $resRetry = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/public/tables/{$table->raw_token}/orders", $payload);

        // Must return controlled HTTP 409 Conflict with code IDEMPOTENCY_REPLAY_UNAVAILABLE
        $resRetry->assertStatus(409);
        $this->assertEquals('IDEMPOTENCY_REPLAY_UNAVAILABLE', $resRetry->json('code'));

        // Assert exactly one order exists in DB (NO duplicate order created)
        $orderCount = $this->runForTenant(function () use ($idempotencyKey) {
            return DB::table('orders')->where('idempotency_key', $idempotencyKey)->count();
        });
        $this->assertEquals(1, $orderCount, 'No duplicate order must be created upon decryption failure');

        // Assert response does NOT leak APP_KEY, ciphertext, exception traces, or internal IDs
        $content = $resRetry->getContent();
        $this->assertStringNotContainsString('DecryptException', $content);
        $this->assertStringNotContainsString(config('app.key'), $content);
        $this->assertStringNotContainsString('corrupted_ciphertext_payload_invalid', $content);
        $this->assertStringNotContainsString($this->tenantData['tenant']->id, $content);
        $this->assertStringNotContainsString($table->id, $content);
    }

    // =========================================================================
    // 17. PUBLIC TRACKING CONTINUITY ACROSS ORDER CONVERSION TO SALE
    // =========================================================================

    public function test_public_tracking_continuity_across_order_conversion_to_sale(): void
    {
        $product = $this->createProduct('Mie Goreng Lifecycle', 25000);

        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-TRACK-CONT', 'name' => 'Meja Tracking Continuity', 'capacity' => 4]
            );
        });

        // 1. Submit Order
        $res = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
            'items' => [
                [
                    'product_variant_id' => $product['variant']->id,
                    'quantity' => 2,
                ],
            ],
            'customer_name' => 'Dewi Tracking',
        ]);
        $res->assertStatus(201);
        $trackingToken = $res->json('data.tracking_token');
        $orderNumber = $res->json('data.order_number');
        $this->assertNotEmpty($trackingToken);

        // Initial check: public status is 'received', not terminal
        $track1 = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingToken}");
        $track1->assertStatus(200);
        $this->assertEquals('received', $track1->json('data.public_status'));
        $this->assertEquals('Diterima', $track1->json('data.status_label'));
        $this->assertFalse($track1->json('data.is_terminal'), 'Initial order must not be terminal');

        // 2. Convert Order to Sale via OrderService
        $order = $this->runForTenant(fn () => Order::where('order_number', $orderNumber)->firstOrFail());
        $sale = $this->runForTenant(function () use ($order) {
            return $this->orderService->convertOrdersToSale($order->id, $this->cashier->id);
        });
        $this->assertEquals(Sale::STATUS_OPEN, $sale->status);

        // 3. Tracking must CONTINUE across conversion while Sale is open
        $trackConverted = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingToken}");
        $trackConverted->assertStatus(200);
        $this->assertEquals('received', $trackConverted->json('data.public_status'));
        $this->assertEquals('Diterima', $trackConverted->json('data.status_label'));
        $this->assertFalse($trackConverted->json('data.is_terminal'), 'Converted order with open sale must NOT stop polling');

        // 4. Operational state: Kitchen transitions to 'preparing'
        $this->runForTenant(function () use ($order) {
            $this->orderService->updateOrderStatus($order->fresh(), Order::STATUS_PREPARING, $this->manager->id);
        });
        $trackPreparing = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingToken}");
        $trackPreparing->assertStatus(200);
        $this->assertEquals('preparing', $trackPreparing->json('data.public_status'));
        $this->assertEquals('Sedang Disiapkan', $trackPreparing->json('data.status_label'));
        $this->assertFalse($trackPreparing->json('data.is_terminal'), 'Preparing order must not be terminal');

        // 5. Operational state: Kitchen transitions to 'ready'
        $this->runForTenant(function () use ($order) {
            $this->orderService->updateOrderStatus($order->fresh(), Order::STATUS_READY, $this->manager->id);
        });
        $trackReady = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingToken}");
        $trackReady->assertStatus(200);
        $this->assertEquals('ready', $trackReady->json('data.public_status'));
        $this->assertEquals('Siap Disajikan', $trackReady->json('data.status_label'));
        $this->assertFalse($trackReady->json('data.is_terminal'), 'Ready order must not be terminal');

        // 6. Operational state: Staff transitions to 'served' (True terminal state)
        $this->runForTenant(function () use ($order) {
            $this->orderService->updateOrderStatus($order->fresh(), Order::STATUS_SERVED, $this->cashier->id);
        });
        $trackServed = $this->getJson("/api/v1/public/tables/{$table->raw_token}/orders/{$trackingToken}");
        $trackServed->assertStatus(200);
        $this->assertEquals('served', $trackServed->json('data.public_status'));
        $this->assertEquals('Selesai', $trackServed->json('data.status_label'));
        $this->assertTrue($trackServed->json('data.is_terminal'), 'Served order must be terminal to allow polling to stop');
    }

    // =========================================================================
    // 18. TRACKING TOKEN ACCESS BOUNDARY & MULTI-TENANT ISOLATION
    // =========================================================================

    public function test_tracking_token_access_boundary_and_tenant_isolation(): void
    {
        $product = $this->createProduct('Soto Ayam Boundary', 22000);

        // Table 1 in Tenant A
        $table1 = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-BND-1', 'name' => 'Meja Boundary 1', 'capacity' => 2]
            );
        });

        // Table 2 in Tenant A
        $table2 = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-BND-2', 'name' => 'Meja Boundary 2', 'capacity' => 2]
            );
        });

        // Submit order on Table 1
        $res = $this->postJson("/api/v1/public/tables/{$table1->raw_token}/orders", [
            'items' => [
                ['product_variant_id' => $product['variant']->id, 'quantity' => 1],
            ],
            'customer_name' => 'Customer Boundary',
        ]);
        $res->assertStatus(201);
        $trackingToken = $res->json('data.tracking_token');
        $orderNumber = $res->json('data.order_number');

        // 1. Valid tracking token + matching table token -> 200 (exact order)
        $validRes = $this->getJson("/api/v1/public/tables/{$table1->raw_token}/orders/{$trackingToken}");
        $validRes->assertStatus(200);
        $this->assertEquals($orderNumber, $validRes->json('data.order_number'));

        // Assert response contains NO internal IDs
        $data = $validRes->json('data');
        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('tenant_id', $data);
        $this->assertArrayNotHasKey('outlet_id', $data);
        $this->assertArrayNotHasKey('table_id', $data);
        $this->assertArrayNotHasKey('sale_id', $data);
        $this->assertArrayNotHasKey('customer_id', $data);

        // 2. Wrong tracking token on Table 1 -> 404
        $wrongToken = bin2hex(random_bytes(24));
        $this->getJson("/api/v1/public/tables/{$table1->raw_token}/orders/{$wrongToken}")->assertStatus(404);

        // 3. Valid tracking token with DIFFERENT table token in same tenant -> 404
        $this->getJson("/api/v1/public/tables/{$table2->raw_token}/orders/{$trackingToken}")->assertStatus(404);

        // 4. Valid tracking token with table from Tenant B -> 404
        $tenantBData = $this->createTenant('Tenant Boundary Other');
        $outletB = $tenantBData['outlet'];
        $tableB = app(TenantManager::class)->runForTenant($tenantBData['tenant'], function () use ($tenantBData, $outletB) {
            return $this->tableService->createTable(
                $tenantBData['tenant'],
                $outletB->id,
                ['code' => 'T-BND-B', 'name' => 'Meja Tenant B', 'capacity' => 4]
            );
        });

        $this->getJson("/api/v1/public/tables/{$tableB->raw_token}/orders/{$trackingToken}")->assertStatus(404);
    }

    // =========================================================================
    // 19. LARAVEL FRAMEWORK ENCRYPTION KEY ROTATION VERIFICATION
    // =========================================================================

    public function test_laravel_framework_key_rotation_with_previous_keys(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));
        $cipher = 'AES-256-CBC';

        // 1. Encrypt with old key (simulating legacy order token creation)
        $oldKeyParsed = base64_decode(substr($oldKey, 7));
        $oldEncrypter = new Encrypter($oldKeyParsed, $cipher);
        $secretMessage = 'token_secret_'.bin2hex(random_bytes(16));
        $legacyCiphertext = $oldEncrypter->encryptString($secretMessage);

        // 2. Rotate to new primary key, with old key in previous_keys
        $newKeyParsed = base64_decode(substr($newKey, 7));
        $rotatedEncrypter = new Encrypter($newKeyParsed, $cipher);
        $rotatedEncrypter->previousKeys([$oldKeyParsed]);

        // 3. Assert seamless decryption of legacy ciphertext via previous_keys
        $decrypted = $rotatedEncrypter->decryptString($legacyCiphertext);
        $this->assertEquals($secretMessage, $decrypted, 'Rotated encrypter must decrypt previous key ciphertexts');

        // 4. Assert new encrypter without previous keys fails to decrypt legacy ciphertext
        $unconfiguredEncrypter = new Encrypter($newKeyParsed, $cipher);
        $this->expectException(DecryptException::class);
        $unconfiguredEncrypter->decryptString($legacyCiphertext);
    }

    // =========================================================================
    // 20. PRODUCTION-LIKE ERROR ENVELOPE SANITIZATION (APP_DEBUG=false)
    // =========================================================================

    public function test_production_like_error_handling_omits_debug_traces_when_app_debug_false(): void
    {
        config(['app.debug' => false]);

        // 1. Not Found 404 endpoint
        $res404 = $this->getJson('/api/v1/public/tables/non_existent_token_12345');
        $res404->assertStatus(404);
        $content404 = $res404->getContent();
        $this->assertStringNotContainsString('trace', $content404);
        $this->assertStringNotContainsString('file', $content404);
        $this->assertStringNotContainsString('line', $content404);
        $this->assertStringNotContainsString('SQLSTATE', $content404);

        // 2. Validation 422 error
        $table = $this->runForTenant(function () {
            return $this->tableService->createTable(
                $this->tenantData['tenant'],
                $this->outlet->id,
                ['code' => 'T-PROD-DBG', 'name' => 'Meja Prod Debug', 'capacity' => 2]
            );
        });

        $res422 = $this->postJson("/api/v1/public/tables/{$table->raw_token}/orders", [
            'items' => 'invalid_items_type',
        ]);
        $res422->assertStatus(422);
        $content422 = $res422->getContent();
        $this->assertStringNotContainsString('trace', $content422);
        $this->assertStringNotContainsString('SQLSTATE', $content422);
        $this->assertStringNotContainsString(config('app.key'), $content422);
    }

    // =========================================================================
    // 21. STAGING QR ARTIFACT ISOLATION & NON-WEB ACCESSIBILITY
    // =========================================================================

    public function test_staging_qr_artifact_files_are_not_web_accessible(): void
    {
        // 1. Direct web requests to artifact paths must return 404 (not routed / not served)
        $this->get('/staging-qa/qr/T-QA-01.png')->assertNotFound();
        $this->get('/staging-qa/qr/T-QA-01.svg')->assertNotFound();
        $this->get('/staging-qa/qr/T-QA-01.html')->assertNotFound();

        // 2. Direct web requests to storage app paths must also return non-accessible (404 or 403)
        $resApp = $this->get('/storage/app/staging-qa/qr/T-QA-01.png');
        $this->assertTrue(in_array($resApp->getStatusCode(), [403, 404], true), 'Artifact must not be publicly served');
        $resStorage = $this->get('/storage/staging-qa/qr/T-QA-01.png');
        $this->assertTrue(in_array($resStorage->getStatusCode(), [403, 404], true), 'Artifact must not be publicly served');
    }

    public function test_staging_qa_prep_rotation_safely_purges_obsolete_artifacts(): void
    {
        $qrDir = storage_path('app/staging-qa/qr');
        if (! is_dir($qrDir)) {
            mkdir($qrDir, 0755, true);
        }

        // 1. Create a dummy test artifact for T-QA-01
        $dummyArtifact = $qrDir.DIRECTORY_SEPARATOR.'T-QA-01.png';
        file_put_contents($dummyArtifact, 'dummy_previous_qr_content');
        $this->assertFileExists($dummyArtifact);

        // 2. Run staging QA prep command with rotate and clean
        $exitCode = Artisan::call('sewaroeng:staging-qa-prep', [
            '--rotate' => true,
            '--clean' => true,
        ]);
        $this->assertEquals(0, $exitCode);

        // 3. Verify fresh artifacts are generated and dummy content was replaced
        $this->assertFileExists($qrDir.DIRECTORY_SEPARATOR.'T-QA-01.png');
        $this->assertFileExists($qrDir.DIRECTORY_SEPARATOR.'T-QA-01.svg');
        $this->assertFileExists($qrDir.DIRECTORY_SEPARATOR.'T-QA-01.html');
        $this->assertNotEquals('dummy_previous_qr_content', file_get_contents($dummyArtifact));
    }

    public function test_trusted_proxy_configuration_behavior(): void
    {
        // 1. Verify request behind trusted proxy forwarding HTTPS
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('/up');

        $response->assertStatus(200);
    }
}
