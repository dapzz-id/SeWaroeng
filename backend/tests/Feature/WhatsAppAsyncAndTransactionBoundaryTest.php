<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\TenantManager;
use App\Events\PaymentSucceeded;
use App\Jobs\SendWhatsAppReceiptJob;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppOutboxRecoveryService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

class WhatsAppAsyncAndTransactionBoundaryTest extends TenantTestCase
{
    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected TenantWhatsAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        MockWhatsAppProvider::reset();
        config(['queue.defer_whatsapp_in_testing' => true]);
        config(['whatsapp.force_after_commit' => true]);

        $tenantData = $this->createTenant('Async Boundary Test Waroeng');
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
                'phone_number_id' => '1029384756',
                'encrypted_credentials' => [
                    'access_token' => 'mock_secret_token_12345',
                    'webhook_verify_token' => 'my_verify_secret_token',
                ],
                'configuration' => [
                    'receipt_template_name' => 'transactional_receipt',
                ],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenant, $callback);
    }

    protected function createSaleWithPayment(Customer $customer, string $ref, int $totalMinor): array
    {
        $cat = Category::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'minuman'], ['name' => 'Minuman']);
        $prod = Product::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'kopi-tubruk'], [
            'category_id' => $cat->id,
            'name' => 'Kopi Tubruk',
            'status' => 'active',
        ]);
        $variant = ProductVariant::firstOrCreate(['tenant_id' => $this->tenant->id, 'sku' => 'KOP-TUB-01'], [
            'product_id' => $prod->id,
            'name' => 'Panas',
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
            'product_name_snapshot' => 'Kopi Tubruk',
            'variant_name_snapshot' => 'Panas',
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

    public function test_provider_request_not_executed_synchronously_during_open_transaction(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Doni Transaction',
            'phone' => '081234567890',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-ASYNC-001', 3000000);
        $payment = $created['payment'];

        // Simulate execution within active database transaction
        DB::transaction(function () use ($payment) {
            event(new PaymentSucceeded($payment));

            // Inside the open database transaction, provider request must NOT have executed yet!
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Provider must not be called inside open transaction');
        });

        // Once committed, the afterCommit handler processes the dispatch
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages, 'Provider must be called after transaction commit');

        $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('recipient_phone', '+6281234567890')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->status);
    }

    public function test_transaction_rollback_prevents_whatsapp_dispatch(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rollback Customer',
            'phone' => '081298765432',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-ROLLBACK-001', 1500000);
        $payment = $created['payment'];

        try {
            DB::transaction(function () use ($payment) {
                event(new PaymentSucceeded($payment));
                // Force transaction rollback
                throw new \RuntimeException('Intentional rollback for test');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Intentional rollback for test', $e->getMessage());
        }

        // Since the transaction was rolled back, the message must NEVER be dispatched!
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Rolled back transaction must not send WhatsApp message');

        $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('recipient_phone', '+6281298765432')
            ->first();

        $this->assertNull($message, 'No outbox message should be committed on rollback');
    }

    public function test_provider_failure_does_not_abort_or_mutate_sale_payment_or_finance(): void
    {
        MockWhatsAppProvider::$scenarioOverride = 'retryable_server_error';

        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Failure Isolation Customer',
            'phone' => '081255566677',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-FAIL-ISO-001', 2500000);
        $sale = $created['sale'];
        $payment = $created['payment'];

        // Commit transaction with event
        DB::transaction(function () use ($payment) {
            event(new PaymentSucceeded($payment));
        });

        // Financial and POS state must remain 100% intact!
        $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);

        // Double-entry journal should be posted without being affected
        $journalExists = JournalEntry::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->exists();
        $this->assertTrue($journalExists);

        // WhatsApp outbox record captures the failure safely
        $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('recipient_phone', '+6281255566677')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
    }

    public function test_job_has_after_commit_and_bounded_retry_properties(): void
    {
        $job = new SendWhatsAppReceiptJob('sale-uuid-test');

        $this->assertTrue($job->afterCommit, 'Job must have afterCommit enabled');
        $this->assertEquals(3, $job->tries, 'Job must have bounded retry budget of 3');
        $this->assertIsArray($job->backoff, 'Job must use array backoff strategy');
        $this->assertGreaterThan(0, $job->timeout, 'Job must have execution timeout');
    }

    public function test_enqueue_failure_safely_persists_failed_outbox_record_without_aborting_sale_payment(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Enqueue Fault Customer',
            'phone' => '081233332222',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-ENQUEUE-FAULT-001', 1800000);
        $sale = $created['sale'];

        // Mock Queue facade to simulate connection failure (e.g. Redis timeout/down)
        Queue::shouldReceive('connection')
            ->andThrow(new \RuntimeException('Connection to queue broker lost (ECONNREFUSED)'));

        try {
            $service = app(WhatsAppNotificationService::class);
            $message = $service->sendReceipt($sale);
        } finally {
            Queue::clearResolvedInstances();
        }

        $this->assertNotNull($message);
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertEquals('ENQUEUE_FAILED', $message->error_code);
        $this->assertStringContainsString('ECONNREFUSED', $message->error_message);

        // Crucial domain invariant: Sale and Payment are completely unaffected!
        $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $created['payment']->fresh()->status);
    }

    public function test_duplicate_dispatch_is_idempotent_and_does_not_resend(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Idempotent Customer',
            'phone' => '081244443333',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-IDEMP-JOB-001', 2000000);
        $sale = $created['sale'];

        $job = new SendWhatsAppReceiptJob($sale->id);

        // Execute job 1st time
        app()->call([$job, 'handle']);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);

        // Execute job 2nd time (duplicate worker retry or duplicate trigger)
        app()->call([$job, 'handle']);

        // Must still be only 1 message sent
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages, 'Duplicate worker execution must be strictly idempotent');
    }

    public function test_ambiguous_provider_timeout_is_classified_as_retryable_without_status_corruption(): void
    {
        MockWhatsAppProvider::$scenarioOverride = 'ambiguous_timeout';

        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Timeout Customer',
            'phone' => '081266665555',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-TIMEOUT-001', 1200000);
        $sale = $created['sale'];

        $job = new SendWhatsAppReceiptJob($sale->id);
        app()->call([$job, 'handle']);

        $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('recipient_phone', '+6281266665555')
            ->first();

        $this->assertNotNull($message);
        $this->assertEquals(WhatsAppMessage::STATUS_AMBIGUOUS_DELIVERY, $message->status);
        $this->assertTrue($message->isAmbiguous());
        $this->assertEquals('AMBIGUOUS_TIMEOUT', $message->error_code);
        $this->assertEquals(1, $message->attempts);
        $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
    }

    public function test_outbox_message_can_be_reprocessed_without_mutating_domain_transaction(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reprocess Customer',
            'phone' => '081277776666',
            'whatsapp_opt_in' => true,
        ]);

        $created = $this->createSaleWithPayment($customer, 'SALE-REPROCESS-001', 2500000);
        $sale = $created['sale'];

        // Initial attempt simulated as failed (e.g. transient enqueue or network glitch)
        $service = app(WhatsAppNotificationService::class);
        $initialMessage = WhatsAppMessage::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $sale->outlet_id,
            'customer_id' => $customer->id,
            'recipient_phone' => '+6281277776666',
            'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
            'status' => WhatsAppMessage::STATUS_FAILED,
            'provider' => 'mock',
            'idempotency_key' => "whatsapp:receipt:{$sale->id}",
            'error_code' => 'ENQUEUE_FAILED',
            'attempts' => 1,
        ]);

        // Reprocess outbox record with force = true
        MockWhatsAppProvider::reset();
        $reprocessed = $service->sendReceipt($sale, force: true);

        $this->assertNotNull($reprocessed);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $reprocessed->status);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);

        // Core domain invariants remain untouched
        $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $created['payment']->fresh()->status);
    }

    public function test_fault_injection_outbox_persistence_failure_during_pos_checkout_preserves_sale_and_reconciles(): void
    {
        $membership = $this->tenant->memberships()->first();
        app(TenantManager::class)->setContext(new TenantContext($this->tenant, $membership));
        config(['whatsapp.force_after_commit' => true]);

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fault Injection Customer',
            'phone' => '081288889999',
            'whatsapp_opt_in' => true,
        ]);

        $failOutboxWrite = true;
        WhatsAppMessage::creating(function (WhatsAppMessage $msg) use (&$failOutboxWrite) {
            if ($failOutboxWrite) {
                throw new \PDOException('SQLSTATE[HY000]: General error: simulated outbox persistence write failure');
            }
        });

        // 1. Finalize sale and payment within active domain transaction
        $sale = null;
        $payment = null;

        DB::transaction(function () use ($customer, &$sale, &$payment) {
            $created = $this->createSaleWithPayment($customer, 'SALE-FAULT-INJ-001', 3500000);
            $sale = $created['sale'];
            $payment = $created['payment'];

            // Domain event triggers outbox persistence handler (deferred to afterCommit)
            event(new PaymentSucceeded($payment));
        });

        // 2. Verify POS sale committed cleanly despite outbox failure!
        $this->assertNotNull($sale);
        $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);

        // 3. Verify receipt outbox record was NOT recorded due to injected persistence failure
        $outboxCount = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
            ->count();
        $this->assertEquals(0, $outboxCount, 'Outbox record must not exist due to simulated write failure');
        $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'No message must have been sent');

        // 4. Disable fault injection so recovery service can write outbox
        $failOutboxWrite = false;

        // 5. Run domain reconciliation to discover unrecorded sale
        $recoveryService = app(WhatsAppOutboxRecoveryService::class);
        $stats = $recoveryService->reconcileUnrecordedReceipts(hours: 24, tenantId: $this->tenant->id);

        $this->assertEquals(1, $stats['reconciled'], 'Unrecorded sale receipt must be discovered and reconciled');
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages, 'Provider must receive exactly 1 message');

        $outboxRecord = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
            ->first();
        $this->assertNotNull($outboxRecord);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $outboxRecord->status);

        // 6. Verify retries / repeated recovery runs do NOT produce duplicate receipt records
        $statsRetry = $recoveryService->reconcileUnrecordedReceipts(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(0, $statsRetry['reconciled'], 'Retry must not re-reconcile already recorded sale');
        $this->assertEquals(0, $statsRetry['scanned'], 'Watermark cursor prevents re-scanning already processed transaction');
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages, 'Provider must not receive duplicate message');

        // 7. Verify that even if cursor is reset (full historical rescan), duplicate outbox message is NOT created
        $settings = $this->tenant->fresh()->settings ?? [];
        unset($settings['whatsapp_receipt_reconciliation_cursor']);
        $this->tenant->settings = $settings;
        $this->tenant->saveQuietly();

        $statsRescan = $recoveryService->reconcileUnrecordedReceipts(hours: 24, tenantId: $this->tenant->id);
        $this->assertEquals(1, $statsRescan['already_recorded'], 'Deduplication key prevents duplicate dispatch on rescan');
        $this->assertEquals(0, $statsRescan['reconciled']);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages, 'Provider must not receive duplicate message');

        $totalOutboxRecords = WhatsAppMessage::where('tenant_id', $this->tenant->id)
            ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
            ->count();
        $this->assertEquals(1, $totalOutboxRecords, 'Exactly 1 outbox record must exist');
    }
}
