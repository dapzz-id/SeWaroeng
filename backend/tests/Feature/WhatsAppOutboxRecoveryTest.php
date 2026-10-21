<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\TenantManager;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppOutboxRecoveryService;
use Carbon\Carbon;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class WhatsAppOutboxRecoveryTest extends TenantTestCase
{
    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected TenantWhatsAppConnection $connection;

    protected WhatsAppOutboxRecoveryService $recoveryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        MockWhatsAppProvider::reset();

        $tenantData = $this->createTenant('Recovery Test Waroeng');
        $this->tenant = $tenantData['tenant'];
        $this->outlet = $tenantData['outlet'];
        $this->owner = $tenantData['owner'];

        $this->runForTenant(function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenant);

        $this->connection = $this->runForTenant(function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenant->id,
                'provider' => 'mock',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '9988776655',
                'encrypted_credentials' => [
                    'access_token' => 'mock_secret_recovery',
                    'webhook_verify_token' => 'verify_secret',
                ],
                'configuration' => [
                    'receipt_template_name' => 'transactional_receipt',
                ],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });

        $this->recoveryService = app(WhatsAppOutboxRecoveryService::class);
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenant, $callback);
    }

    protected function createSaleWithPayment(Customer $customer, string $ref, int $totalMinor): array
    {
        $cat = Category::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'makanan'], ['name' => 'Makanan']);
        $prod = Product::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'mie-goreng'], [
            'category_id' => $cat->id,
            'name' => 'Mie Goreng',
            'status' => 'active',
        ]);
        $variant = ProductVariant::firstOrCreate(['tenant_id' => $this->tenant->id, 'sku' => 'MIE-GOR-01'], [
            'product_id' => $prod->id,
            'name' => 'Pedas',
        ]);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'cashier_user_id' => $this->owner->id,
            'reference_number' => $ref,
            'status' => Sale::STATUS_FINALIZED,
            'subtotal_minor' => $totalMinor,
            'tax_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => $totalMinor,
            'finalized_at' => now(),
        ]);

        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'product_id' => $prod->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Mie Goreng',
            'variant_name_snapshot' => 'Pedas',
            'quantity' => 1,
            'unit_price_minor' => $totalMinor,
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'reference_number' => 'PAY-'.$ref,
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => $totalMinor,
            'paid_amount_minor' => $totalMinor,
            'change_minor' => 0,
            'paid_at' => now(),
        ]);

        return ['sale' => $sale, 'payment' => $payment];
    }

    public function test_pending_record_without_enqueued_job_is_recovered_and_sent(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stranded Customer',
            'phone' => '081234567891',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-REC-001', 2500000);
        $sale = $created['sale'];

        // Simulate stranded pending record (created 5 minutes ago without job)
        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281234567891',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 0,
        ]);
        $message->created_at = now()->subMinutes(5);
        $message->save();

        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Run recovery
        $stats = $this->recoveryService->recoverStuckMessages(limit: 10, stuckThresholdMinutes: 2, sync: true);

        $this->assertEquals(1, $stats['scanned']);
        $this->assertEquals(1, $stats['recovered']);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);

        $message->refresh();
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->provider_message_id);
    }

    public function test_crash_window_simulation_between_persistence_and_enqueue(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Crash Window Customer',
            'phone' => '081234567892',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-CRASH-001', 3000000);
        $sale = $created['sale'];

        // Transaction boundary: Outbox message written inside committed transaction,
        // but application process crashes immediately after commit before queue broker dispatch.
        DB::transaction(function () use ($sale, $customer) {
            WhatsAppMessage::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customer->id,
                'recipient_phone' => '+6281234567892',
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_PENDING,
                'provider' => 'mock',
                'idempotency_key' => "whatsapp:receipt:{$sale->id}",
                'payload' => ['sale_id' => $sale->id],
                'attempts' => 0,
            ]);
        });

        // Simulate time elapsing post-crash
        $message = WhatsAppMessage::where('idempotency_key', "whatsapp:receipt:{$sale->id}")->first();
        $message->created_at = now()->subMinutes(3);
        $message->save();

        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Scheduled outbox recovery picks up the stranded record
        $stats = $this->recoveryService->recoverStuckMessages(limit: 10, stuckThresholdMinutes: 2, sync: true);

        $this->assertEquals(1, $stats['recovered']);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_failed_enqueue_record_is_recovered_and_sent_without_mutating_sales_or_finance(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Enqueue Fault Customer',
            'phone' => '081234567893',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-ENQ-FAIL-001', 1800000);
        $sale = $created['sale'];
        $payment = $created['payment'];

        // Initial attempt resulted in ENQUEUE_FAILED
        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281234567893',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_FAILED,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'error_code' => 'ENQUEUE_FAILED',
            'error_message' => 'Redis connection refused',
            'attempts' => 1,
        ]);

        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Recovery run restores and sends message
        $stats = $this->recoveryService->recoverStuckMessages(limit: 10, stuckThresholdMinutes: 0, sync: true);

        $this->assertEquals(1, $stats['recovered']);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->fresh()->status);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);

        // Domain invariants remain 100% untouched
        $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
    }

    public function test_idempotency_concurrent_recovery_workers_only_dispatch_once(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Concurrent Customer',
            'phone' => '081234567894',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-CONC-001', 1500000);
        $sale = $created['sale'];

        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281234567894',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 0,
        ]);
        $message->created_at = now()->subMinutes(5);
        $message->save();

        // Worker 1 recovers
        $recovered1 = $this->recoveryService->recoverMessage($message, sync: true);
        $this->assertTrue($recovered1);

        // Worker 2 attempts concurrent recovery on the same message
        $recovered2 = $this->recoveryService->recoverMessage($message->fresh(), sync: true);
        $this->assertFalse($recovered2, 'Second concurrent recovery attempt must be rejected');

        // Only 1 message was ever transmitted
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);
    }

    public function test_terminal_statuses_sent_delivered_read_are_never_retransmitted_during_recovery(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Terminal Customer',
            'phone' => '081234567895',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-TERM-001', 2000000);
        $sale = $created['sale'];

        // Terminal message already sent
        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281234567895',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_SENT,
            'provider' => 'mock',
            'provider_message_id' => 'mock-wamid-already-sent',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'sent_at' => now()->subMinutes(10),
            'attempts' => 1,
        ]);
        $message->created_at = now()->subMinutes(15);
        $message->save();

        $recovered = $this->recoveryService->recoverMessage($message, sync: true);
        $this->assertFalse($recovered);
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Delivered state
        $message->status = WhatsAppMessage::STATUS_DELIVERED;
        $message->save();
        $this->assertFalse($this->recoveryService->recoverMessage($message, sync: true));
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Read state
        $message->status = WhatsAppMessage::STATUS_READ;
        $message->save();
        $this->assertFalse($this->recoveryService->recoverMessage($message, sync: true));
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);
    }

    public function test_opt_out_after_persistence_prevents_dispatch_during_recovery(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opt Out Later Customer',
            'phone' => '081234567896',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-OPTOUT-001', 1200000);
        $sale = $created['sale'];

        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281234567896',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 0,
        ]);
        $message->created_at = now()->subMinutes(5);
        $message->save();

        // Customer opts out BEFORE recovery executes
        $customer->optOutWhatsApp(source: 'customer_inbound', reason: 'STOP message received');

        $recovered = $this->recoveryService->recoverMessage($message, sync: true);
        $this->assertFalse($recovered);

        // Provider must NOT be called!
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Message captures opt-out terminal failure
        $message->refresh();
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertEquals('CUSTOMER_OPTED_OUT', $message->error_code);
    }

    public function test_multi_tenant_isolation_during_recovery_failure_on_tenant_a_does_not_block_tenant_b(): void
    {
        $membershipA = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membershipA));

        // Tenant A: Invalid credentials connection
        $customerA = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer Tenant A',
            'phone' => '081211110001',
            'whatsapp_opt_in' => true,
        ]);
        $saleA = $this->createSaleWithPayment($customerA, 'SALE-TENA-001', 1000000)['sale'];

        $messageA = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customerA->id,
            'recipient_phone' => '+6281211110001',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$saleA->id}",
            'payload' => ['sale_id' => $saleA->id],
            'attempts' => 0,
        ]);
        $messageA->created_at = now()->subMinutes(5);
        $messageA->save();

        // Tenant B: Valid setup
        $tenantBData = $this->createTenant('Tenant B Waroeng');
        $tenantB = $tenantBData['tenant'];
        $outletB = $tenantBData['outlet'];
        $ownerB = $tenantBData['owner'];

        app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB) {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $tenantB->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($tenantB);

        app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB) {
            TenantWhatsAppConnection::create([
                'tenant_id' => $tenantB->id,
                'provider' => 'mock',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '1122334455',
                'encrypted_credentials' => [
                    'access_token' => 'mock_secret_b',
                ],
                'configuration' => [
                    'receipt_template_name' => 'transactional_receipt',
                ],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });

        $customerB = app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB) {
            return Customer::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Customer Tenant B',
                'phone' => '081222220002',
                'whatsapp_opt_in' => true,
            ]);
        });

        $saleB = app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB, $outletB, $ownerB, $customerB) {
            $cat = Category::firstOrCreate(['tenant_id' => $tenantB->id, 'slug' => 'makanan'], ['name' => 'Makanan']);
            $prod = Product::firstOrCreate(['tenant_id' => $tenantB->id, 'slug' => 'roti-bakar'], ['category_id' => $cat->id, 'name' => 'Roti Bakar']);
            $variant = ProductVariant::firstOrCreate(['tenant_id' => $tenantB->id, 'sku' => 'ROT-BAK-01'], ['product_id' => $prod->id, 'name' => 'Coklat']);

            $sale = Sale::create([
                'tenant_id' => $tenantB->id,
                'outlet_id' => $outletB->id,
                'customer_id' => $customerB->id,
                'cashier_user_id' => $ownerB->id,
                'reference_number' => 'SALE-TENB-001',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 2000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 2000000,
                'finalized_at' => now(),
            ]);

            SaleItem::create([
                'tenant_id' => $tenantB->id,
                'sale_id' => $sale->id,
                'product_id' => $prod->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Roti Bakar',
                'variant_name_snapshot' => 'Coklat',
                'quantity' => 1,
                'unit_price_minor' => 2000000,
                'subtotal_minor' => 2000000,
                'total_minor' => 2000000,
            ]);

            Payment::create([
                'tenant_id' => $tenantB->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-TENB-001',
                'method' => Payment::METHOD_CASH,
                'status' => Payment::STATUS_SUCCEEDED,
                'amount_minor' => 2000000,
                'paid_amount_minor' => 2000000,
                'change_minor' => 0,
                'paid_at' => now(),
            ]);

            return $sale;
        });

        $messageB = app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB, $outletB, $customerB, $saleB) {
            $msg = WhatsAppMessage::create([
                'tenant_id' => $tenantB->id,
                'outlet_id' => $outletB->id,
                'customer_id' => $customerB->id,
                'recipient_phone' => '+6281222220002',
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_PENDING,
                'provider' => 'mock',
                'idempotency_key' => "whatsapp:receipt:{$saleB->id}",
                'payload' => ['sale_id' => $saleB->id],
                'attempts' => 0,
            ]);
            $msg->created_at = now()->subMinutes(5);
            $msg->save();

            return $msg;
        });

        // Deactivate Tenant A's connection to simulate a failure on Tenant A
        $this->connection->update(['status' => TenantWhatsAppConnection::STATUS_INACTIVE]);

        // Run recovery pass across all tenants
        $stats = $this->recoveryService->recoverStuckMessages(limit: 20, stuckThresholdMinutes: 2, sync: true);

        // Tenant A message skipped/failed due to inactive connection, but Tenant B successfully recovered!
        $this->assertEquals(2, $stats['scanned']);
        $this->assertEquals(1, $stats['recovered']);

        $refreshedB = app(TenantManager::class)->runForTenant($tenantB, fn () => $messageB->fresh());
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $refreshedB->status);
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $messageA->fresh()->status);
    }

    public function test_scheduled_artisan_command_recovers_stranded_messages(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Artisan Recovery Customer',
            'phone' => '081234567897',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-ARTISAN-001', 3500000);
        $sale = $created['sale'];

        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281234567897',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 0,
        ]);
        $message->created_at = now()->subMinutes(5);
        $message->save();

        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);

        // Execute artisan command directly
        $exitCode = Artisan::call('whatsapp:outbox-recover', [
            '--sync' => true,
            '--threshold' => 2,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('1 recovered', $output);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->fresh()->status);
    }

    public function test_domain_reconciliation_recovers_unrecorded_sale_receipt(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unrecorded Sale Customer',
            'phone' => '081288887777',
            'whatsapp_opt_in' => true,
        ]);

        // Create finalized sale, but deliberately simulate a crash: NO outbox record is inserted
        $created = $this->createSaleWithPayment($customer, 'SALE-UNRECORDED-001', 4500000);
        $sale = $created['sale'];

        // Confirm zero messages exist in outbox for this sale
        $initialCount = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
            ->count();
        $this->assertEquals(0, $initialCount);

        // Run domain reconciliation directly
        $stats = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24);

        $this->assertEquals(1, $stats['reconciled']);

        // Verify outbox record now exists and was processed
        $outboxRecord = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
            ->first();

        $this->assertNotNull($outboxRecord);
        $this->assertEquals(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $outboxRecord->message_type);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $outboxRecord->status);
    }

    public function test_ambiguous_delivery_recovered_and_resolved_by_late_webhook(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ambiguous Delivery Customer',
            'phone' => '081299991111',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-AMBIG-001', 5000000);
        $sale = $created['sale'];

        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281299991111',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 1,
            'error_code' => 'AMBIGUOUS_TIMEOUT',
            'error_message' => 'Connection to Meta timed out',
            'provider_message_id' => 'wamid.AMBIG_PROVIDER_001',
        ]);

        $this->assertTrue($message->isAmbiguous());

        // Late delivery status webhook arrives from Meta provider
        $webhookResponse = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', [
            'statuses' => [
                [
                    'id' => 'wamid.AMBIG_PROVIDER_001',
                    'status' => 'delivered',
                    'timestamp' => (string) now()->timestamp,
                    'recipient_id' => '6281299991111',
                ],
            ],
        ], [
            'X-Mock-Signature' => 'valid_mock_signature',
        ]);

        $webhookResponse->assertStatus(200);

        // Ambiguous delivery is monotonically resolved to DELIVERED
        $fresh = $message->fresh();
        $this->assertEquals(WhatsAppMessage::STATUS_DELIVERED, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_worker_crashed_before_saving_provider_id_resolved_by_opaque_callback_data(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opaque Match Customer',
            'phone' => '081299992222',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-OPAQUE-001', 6000000);
        $sale = $created['sale'];

        // Worker sent the message to Meta, so Meta received it and recorded callbackData = message->id.
        // But worker process crashed before updating status or provider_message_id in the DB.
        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281299992222',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_SENDING, // Stranded in sending
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 1,
            'provider_message_id' => null, // worker crashed before saving this!
        ]);

        // Meta status webhook arrives containing the newly minted wamid and echoing back callback_data
        $webhookResponse = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenant->id.'/mock', [
            'statuses' => [
                [
                    'id' => 'wamid.LATE_META_ASSIGNED_999',
                    'status' => 'delivered',
                    'timestamp' => (string) now()->timestamp,
                    'recipient_id' => '6281299992222',
                    'biz_opaque_callback_data' => $message->id,
                ],
            ],
        ], [
            'X-Mock-Signature' => 'valid_mock_signature',
        ]);

        $webhookResponse->assertStatus(200);

        // Verification: The webhook successfully found the stranded record via callback_data,
        // correlated provider_message_id, and advanced status to delivered!
        $fresh = $message->fresh();
        $this->assertEquals(WhatsAppMessage::STATUS_DELIVERED, $fresh->status);
        $this->assertEquals('wamid.LATE_META_ASSIGNED_999', $fresh->provider_message_id);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_ambiguous_delivery_is_scanned_for_observability_but_not_redispatched_by_recovery(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Redispatch Customer',
            'phone' => '081299993333',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-NO-REDISPATCH-001', 3500000);
        $sale = $created['sale'];

        $message = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281299993333',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'payload' => ['sale_id' => $sale->id],
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(10),
            'error_code' => 'AMBIGUOUS_TIMEOUT',
            'error_message' => 'Connection timed out',
        ]);

        // Run recovery pass
        $stats = $this->recoveryService->recoverStuckMessages(limit: 10, stuckThresholdMinutes: 2, sync: true);

        // Ambiguous delivery is observed in stats but NOT blindly redispatched
        $this->assertEquals(1, $stats['ambiguous_observed']);
        $this->assertEquals(0, $stats['recovered']);
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Provider must not receive blind re-dispatch for ambiguous delivery!');
        $this->assertEquals(WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY, $message->fresh()->status);
    }

    public function test_scheduler_downtime_over_24h_skips_customer_send_and_marks_expired_delivery_window(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Downtime Customer',
            'phone' => '081299994444',
            'whatsapp_opt_in' => true,
        ]);

        // Finalized 48 hours ago while scheduler was completely offline
        $created = $this->createSaleWithPayment($customer, 'SALE-DOWNTIME-001', 2500000);
        $sale = $created['sale'];
        $sale->finalized_at = now()->subHours(48);
        $sale->saveQuietly();

        // Run reconciliation catch-up with 72h scan window
        $stats = $this->recoveryService->reconcileUnrecordedReceipts(hours: 72, limit: 10, tenantId: $this->tenant->id);

        $this->assertEquals(1, $stats['scanned']);
        $this->assertEquals(0, $stats['reconciled'], 'Stale sale must not be sent to customer!');
        $this->assertEquals(1, $stats['expired_stale']);
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Provider must not be called for stale transactions!');

        // Durable outbox record created for operator audit
        $outboxRecord = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
            ->first();

        $this->assertNotNull($outboxRecord);
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $outboxRecord->status);
        $this->assertEquals('EXPIRED_DELIVERY_WINDOW', $outboxRecord->error_code);
    }

    public function test_cursor_pagination_and_scheduler_repetition_advances_watermark(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cursor Customer',
            'phone' => '081299995555',
            'whatsapp_opt_in' => true,
        ]);

        // Sale 1 finalized 5 hours ago
        $sale1 = $this->createSaleWithPayment($customer, 'SALE-CUR-001', 1000000)['sale'];
        $sale1->finalized_at = now()->subHours(5);
        $sale1->saveQuietly();

        // Sale 2 finalized 3 hours ago
        $sale2 = $this->createSaleWithPayment($customer, 'SALE-CUR-002', 2000000)['sale'];
        $sale2->finalized_at = now()->subHours(3);
        $sale2->saveQuietly();

        // Batch pass 1 with limit = 1
        $pass1 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 1, tenantId: $this->tenant->id);
        $this->assertEquals(1, $pass1['reconciled']);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);

        // Watermark cursor moved to sale1 timestamp
        $this->tenant->refresh();
        $this->assertNotNull($this->tenant->settings['whatsapp_receipt_reconciliation_cursor'] ?? null);

        // Batch pass 2 with limit = 1
        $pass2 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 1, tenantId: $this->tenant->id);
        $this->assertEquals(1, $pass2['reconciled']);
        $this->assertCount(2, MockWhatsAppProvider::$sentMessages);

        // Scheduler repetition: Run pass 3 immediately
        $pass3 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 10, tenantId: $this->tenant->id);
        $this->assertEquals(0, $pass3['scanned']);
        $this->assertEquals(0, $pass3['reconciled']);
        $this->assertCount(2, MockWhatsAppProvider::$sentMessages, 'Repetition must not duplicate messages!');
    }

    public function test_historical_transactions_prior_to_feature_activation_not_spammed(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ancient Customer',
            'phone' => '081299996666',
            'whatsapp_opt_in' => true,
        ]);

        // Sale from 10 days ago (prior to feature activation / 72h window)
        $ancientSale = $this->createSaleWithPayment($customer, 'SALE-ANCIENT-001', 9900000)['sale'];
        $ancientSale->finalized_at = now()->subDays(10);
        $ancientSale->saveQuietly();

        // Reset any existing cursor
        $settings = $this->tenant->settings ?? [];
        unset($settings['whatsapp_receipt_reconciliation_cursor']);
        $this->tenant->settings = $settings;
        $this->tenant->saveQuietly();

        // Run reconciliation
        $stats = $this->recoveryService->reconcileUnrecordedReceipts(hours: 72, limit: 10, tenantId: $this->tenant->id);

        $this->assertEquals(0, $stats['scanned']);
        $this->assertEquals(0, $stats['reconciled']);
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages);
    }

    public function test_cursor_deterministic_tie_breaking_handles_identical_finalized_at_across_batch_boundaries(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tie Break Customer',
            'phone' => '081299997777',
            'whatsapp_opt_in' => true,
        ]);

        $fixedTime = now()->subHours(2);
        $sale1 = $this->createSaleWithPayment($customer, 'SALE-TIE-001', 1000000)['sale'];
        $sale1->finalized_at = $fixedTime;
        $sale1->saveQuietly();

        $sale2 = $this->createSaleWithPayment($customer, 'SALE-TIE-002', 2000000)['sale'];
        $sale2->finalized_at = $fixedTime;
        $sale2->saveQuietly();

        $sale3 = $this->createSaleWithPayment($customer, 'SALE-TIE-003', 3000000)['sale'];
        $sale3->finalized_at = $fixedTime;
        $sale3->saveQuietly();

        // Pass 1 with limit = 2 (breaks on identical timestamp)
        $pass1 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 2, tenantId: $this->tenant->id);
        $this->assertEquals(2, $pass1['reconciled']);
        $this->assertCount(2, MockWhatsAppProvider::$sentMessages);

        // Verify watermark cursor contains both finalized_at and tie-breaker id
        $this->tenant->refresh();
        $cursor = $this->tenant->settings['whatsapp_receipt_reconciliation_cursor'] ?? null;
        $this->assertIsArray($cursor);
        $this->assertNotNull($cursor['finalized_at'] ?? null);
        $this->assertNotNull($cursor['id'] ?? null);

        // Pass 2 with limit = 2: Must pick up sale3 and NOT skip it despite identical finalized_at!
        $pass2 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 2, tenantId: $this->tenant->id);
        $this->assertEquals(1, $pass2['reconciled']);
        $this->assertCount(3, MockWhatsAppProvider::$sentMessages);

        // Pass 3: Clean watermark termination
        $pass3 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 2, tenantId: $this->tenant->id);
        $this->assertEquals(0, $pass3['scanned']);
        $this->assertEquals(0, $pass3['reconciled']);
        $this->assertCount(3, MockWhatsAppProvider::$sentMessages, 'All 3 sales reconciled with zero duplicates');
    }

    public function test_tenant_isolation_in_receipt_reconciliation(): void
    {
        $membershipA = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membershipA));

        $customerA = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant A Customer',
            'phone' => '081299998888',
            'whatsapp_opt_in' => true,
        ]);
        $saleA = $this->createSaleWithPayment($customerA, 'SALE-ISOL-A-001', 1500000)['sale'];

        // Create Tenant B
        $tenantBData = $this->createTenant('Tenant B Waroeng');
        $tenantB = $tenantBData['tenant'];
        $outletB = $tenantBData['outlet'];
        $ownerB = $tenantBData['owner'];

        app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB, $outletB, $ownerB, &$saleB) {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $tenantB->id)->update(['plan_id' => $businessPlan->id]);
            app(SubscriptionEntitlementService::class)->clearCache($tenantB);

            $customerB = Customer::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Tenant B Customer',
                'phone' => '081299998889',
                'whatsapp_opt_in' => true,
            ]);

            $saleB = Sale::create([
                'tenant_id' => $tenantB->id,
                'outlet_id' => $outletB->id,
                'customer_id' => $customerB->id,
                'cashier_user_id' => $ownerB->id,
                'reference_number' => 'SALE-ISOL-B-001',
                'status' => Sale::STATUS_FINALIZED,
                'subtotal_minor' => 2500000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 2500000,
                'finalized_at' => now()->subHour(),
            ]);

            Payment::create([
                'tenant_id' => $tenantB->id,
                'sale_id' => $saleB->id,
                'reference_number' => 'PAY-SALE-ISOL-B-001',
                'method' => Payment::METHOD_CASH,
                'status' => Payment::STATUS_SUCCEEDED,
                'amount_minor' => 2500000,
                'paid_amount_minor' => 2500000,
                'change_minor' => 0,
                'paid_at' => now()->subHour(),
            ]);
        });

        // Restore context to Tenant A
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membershipA));

        // Run reconciliation targeting ONLY Tenant A
        $statsA = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(1, $statsA['reconciled']);

        // Verify outbox for Tenant A exists
        $this->assertTrue(WhatsAppMessage::where('tenant_id', $this->tenant->id)->where('idempotency_key', "whatsapp:receipt:{$saleA->id}")->exists());

        // Verify Tenant B was NOT touched
        $this->assertFalse(WhatsAppMessage::where('tenant_id', $tenantB->id)->where('idempotency_key', "whatsapp:receipt:{$saleB->id}")->exists());
        $this->assertNull($tenantB->fresh()->settings['whatsapp_receipt_reconciliation_cursor'] ?? null);
    }

    public function test_failed_batch_does_not_advance_cursor_past_unprocessed_transactions(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fault Batch Customer',
            'phone' => '081299990000',
            'whatsapp_opt_in' => true,
        ]);

        $sale1 = $this->createSaleWithPayment($customer, 'SALE-FAIL-BATCH-001', 1000000)['sale'];
        $sale1->finalized_at = now()->subHours(3);
        $sale1->saveQuietly();

        $sale2 = $this->createSaleWithPayment($customer, 'SALE-FAIL-BATCH-002', 2000000)['sale'];
        $sale2->finalized_at = now()->subHours(2);
        $sale2->saveQuietly();

        $sale3 = $this->createSaleWithPayment($customer, 'SALE-FAIL-BATCH-003', 3000000)['sale'];
        $sale3->finalized_at = now()->subHour();
        $sale3->saveQuietly();

        // Inject fault on Sale 2
        $failSale2 = true;
        WhatsAppMessage::creating(function (WhatsAppMessage $msg) use (&$failSale2, $sale2) {
            if ($failSale2 && ($msg->payload['sale_id'] ?? null) === $sale2->id) {
                throw new \RuntimeException('Simulated failure during batch processing of Sale 2');
            }
        });

        // Run recovery pass
        $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 10, tenantId: $this->tenant->id);

        // Cursor must have advanced ONLY to Sale 1, NOT past Sale 2 or Sale 3!
        $this->tenant->refresh();
        $cursor = $this->tenant->settings['whatsapp_receipt_reconciliation_cursor'] ?? null;
        $this->assertNotNull($cursor);
        $this->assertEquals($sale1->id, $cursor['id']);

        // Verify Sale 1 is recorded, Sale 2 and Sale 3 are NOT yet recorded
        $this->assertTrue(WhatsAppMessage::where('tenant_id', $this->tenant->id)->where('idempotency_key', "whatsapp:receipt:{$sale1->id}")->exists());
        $this->assertFalse(WhatsAppMessage::where('tenant_id', $this->tenant->id)->where('idempotency_key', "whatsapp:receipt:{$sale2->id}")->exists());
        $this->assertFalse(WhatsAppMessage::where('tenant_id', $this->tenant->id)->where('idempotency_key', "whatsapp:receipt:{$sale3->id}")->exists());

        // Now remove fault and rerun: Sale 2 and Sale 3 must be recovered cleanly!
        $failSale2 = false;
        $stats2 = $this->recoveryService->reconcileUnrecordedReceipts(hours: 24, limit: 10, tenantId: $this->tenant->id);
        $this->assertEquals(2, $stats2['reconciled']);

        $this->tenant->refresh();
        $cursor2 = $this->tenant->settings['whatsapp_receipt_reconciliation_cursor'] ?? null;
        $this->assertEquals($sale3->id, $cursor2['id']);
        $this->assertTrue(WhatsAppMessage::where('tenant_id', $this->tenant->id)->where('idempotency_key', "whatsapp:receipt:{$sale2->id}")->exists());
        $this->assertTrue(WhatsAppMessage::where('tenant_id', $this->tenant->id)->where('idempotency_key', "whatsapp:receipt:{$sale3->id}")->exists());
    }

    public function test_domain_reconciliation_recovers_unrecorded_order(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Order Recovery Customer',
            'phone' => '081299990001',
            'whatsapp_opt_in' => true,
        ]);

        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'code' => 'TBL-01',
            'name' => 'Meja 1',
            'capacity' => 4,
            'status' => 'available',
        ]);

        $tableSession = TableSession::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'table_id' => $table->id,
            'session_code' => 'SESS-01',
            'status' => TableSession::STATUS_ACTIVE,
            'guest_count' => 2,
            'started_at' => now(),
        ]);

        $order = Order::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'table_id' => $table->id,
            'table_session_id' => $tableSession->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-REC-001',
            'status' => Order::STATUS_ACCEPTED,
            'subtotal_minor' => 5000000,
            'tax_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 5000000,
            'customer_name_snapshot' => 'Order Recovery Customer',
            'customer_contact_snapshot' => '081299990001',
        ]);

        $stats = $this->recoveryService->reconcileUnrecordedOrders(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(1, $stats['reconciled']);

        $outbox = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:order_confirmation:{$order->id}")
            ->first();
        $this->assertNotNull($outbox);
        $this->assertEquals(WhatsAppMessage::TYPE_ORDER_CONFIRMATION, $outbox->message_type);

        // Repetition is idempotent
        $stats2 = $this->recoveryService->reconcileUnrecordedOrders(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(0, $stats2['reconciled']);
        $this->assertEquals(1, $stats2['already_recorded']);
    }

    public function test_domain_reconciliation_recovers_unrecorded_booking(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Booking Recovery Customer',
            'phone' => '081299990002',
            'whatsapp_opt_in' => true,
        ]);

        $booking = Booking::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'booking_code' => 'BKG-REC-001',
            'customer_name_snapshot' => 'Booking Recovery Customer',
            'customer_contact_snapshot' => '081299990002',
            'party_size' => 4,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        $stats = $this->recoveryService->reconcileUnrecordedBookings(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(1, $stats['reconciled']);

        $outbox = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:booking_confirmation:{$booking->id}")
            ->first();
        $this->assertNotNull($outbox);
        $this->assertEquals(WhatsAppMessage::TYPE_BOOKING_CONFIRMATION, $outbox->message_type);

        // Repetition is idempotent
        $stats2 = $this->recoveryService->reconcileUnrecordedBookings(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(0, $stats2['reconciled']);
        $this->assertEquals(1, $stats2['already_recorded']);
    }

    public function test_domain_reconciliation_recovers_unrecorded_low_stock(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        // Configure merchant WhatsApp notification settings with consent
        $settings = $this->tenant->settings ?? [];
        $settings['whatsapp_notifications'] = [
            'merchant_phone' => '081299998888',
            'consent_given' => true,
            'low_stock' => true,
        ];
        $this->tenant->settings = $settings;
        $this->tenant->saveQuietly();

        $invItem = InventoryItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gula Pasir',
            'sku' => 'GUL-001',
            'base_unit' => 'kg',
            'status' => 'active',
        ]);

        $invLoc = InventoryLocation::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'code' => 'LOC-GUDANG',
            'name' => 'Gudang Utama',
            'type' => 'warehouse',
            'status' => 'active',
        ]);

        $itemLoc = InventoryItemLocation::create([
            'tenant_id' => $this->tenant->id,
            'inventory_item_id' => $invItem->id,
            'location_id' => $invLoc->id,
            'on_hand' => 2,
            'reserved' => 0,
            'reorder_level' => 10,
        ]);

        $stats = $this->recoveryService->reconcileUnrecordedLowStock(tenantId: $this->tenant->id);
        $this->assertEquals(1, $stats['reconciled']);

        $today = Carbon::now($this->tenant->timezone ?? 'Asia/Jakarta')->toDateString();
        $outbox = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:low_stock:{$this->tenant->id}:{$itemLoc->id}:{$today}")
            ->first();
        $this->assertNotNull($outbox);
        $this->assertEquals(WhatsAppMessage::TYPE_MERCHANT_LOW_STOCK, $outbox->message_type);

        // Repetition is idempotent
        $stats2 = $this->recoveryService->reconcileUnrecordedLowStock(tenantId: $this->tenant->id);
        $this->assertEquals(0, $stats2['reconciled']);
        $this->assertEquals(1, $stats2['already_recorded']);
    }
}
