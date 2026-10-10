<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentSucceeded;
use App\Events\SaleFinalized;
use App\Models\Category;
use App\Models\Customer;
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
use App\Services\Subscription\SubscriptionService;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class WhatsAppIntegrationTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected User $cashier;

    protected TenantWhatsAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);

        MockWhatsAppProvider::reset();

        $tenantData = $this->createTenant('Waroeng WhatsApp Test');
        $this->tenant = $tenantData['tenant'];
        $this->outlet = $tenantData['outlet'];
        $this->owner = $tenantData['owner'];

        $cashierData = $this->addMemberToTenant($this->tenant, 'cashier', 'Kasir WA', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        // Entitle tenant to WhatsApp via Business plan
        $this->runForTenant(function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenant);

        // Setup WhatsApp Connection with Mock provider
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
        $cat = Category::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'slug' => 'makanan'],
            ['name' => 'Makanan']
        );
        $prod = Product::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'slug' => 'nasi-goreng'],
            ['category_id' => $cat->id, 'name' => 'Nasi Goreng', 'status' => 'active']
        );
        $variant = ProductVariant::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'sku' => 'NAS-GOR-01'],
            ['product_id' => $prod->id, 'name' => 'Spesial']
        );

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'outlet_id' => $this->outlet->id,
            'customer_id' => $customer->id,
            'cashier_user_id' => $this->cashier->id,
            'reference_number' => $ref,
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
            'status' => Sale::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);

        SaleItem::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Nasi Goreng Spesial',
            'variant_name_snapshot' => 'Spesial',
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
            'amount_minor' => $totalMinor,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);

        return ['sale' => $sale, 'payment' => $payment];
    }

    public function test_mock_provider_adapter_normalizes_request_and_records_message(): void
    {
        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+6281234567890',
            messageType: 'transactional_receipt',
            textBody: 'Nota Pembelian SeWaroeng'
        );

        $provider = app(WhatsAppProviderManager::class)->resolve('mock');
        $result = $provider->sendMessage($this->connection, $payload);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->providerMessageId);
        $this->assertStringStartsWith('mock-wamid-', $result->providerMessageId);
        $this->assertCount(1, MockWhatsAppProvider::$sentMessages);
    }

    public function test_receipt_sent_automatically_on_payment_succeeded(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Budi Santoso',
                'phone' => '081299988877', // Normalized to +6281299988877
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => false, // Transactional receipt is eligible even without marketing consent
            ]);

            $created = $this->createSaleWithPayment($customer, 'SALE-TEST-001', 5000000);

            // Fire PaymentSucceeded domain event
            event(new PaymentSucceeded($created['payment']));

            // Assert message recorded in whatsapp_messages
            $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('recipient_phone', '+6281299988877')
                ->first();

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->status);
            $this->assertEquals('mock', $message->provider);
            $this->assertNotNull($message->provider_message_id);
            $this->assertStringContainsString('Nasi Goreng Spesial', $message->payload['summary']);
        });
    }

    public function test_provider_failure_does_not_abort_payment_or_sale_transaction(): void
    {
        MockWhatsAppProvider::$scenarioOverride = 'retryable_server_error';

        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Rina Wijaya',
                'phone' => '081311122233',
                'whatsapp_opt_in' => true,
            ]);

            $created = $this->createSaleWithPayment($customer, 'SALE-TEST-FAIL-001', 2000000);
            $sale = $created['sale'];
            $payment = $created['payment'];

            // Event should NOT throw or rollback payment
            event(new PaymentSucceeded($payment));

            // Payment and Sale remain finalized and successful!
            $this->assertEquals(Sale::STATUS_FINALIZED, $sale->fresh()->status);
            $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);

            // WhatsApp message should exist with status 'failed'
            $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('recipient_phone', '+6281311122233')
                ->first();

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('PROVIDER_INTERNAL_ERROR', $message->error_code);
        });
    }

    public function test_idempotent_duplicate_events_do_not_send_duplicate_whatsapp_messages(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Dewi Lestari',
                'phone' => '081244455566',
                'whatsapp_opt_in' => true,
            ]);

            $created = $this->createSaleWithPayment($customer, 'SALE-IDEMP-001', 1500000);
            $sale = $created['sale'];
            $payment = $created['payment'];

            // Dispatch PaymentSucceeded first time
            event(new PaymentSucceeded($payment));
            $this->assertCount(1, MockWhatsAppProvider::$sentMessages);

            // Dispatch PaymentSucceeded second time (duplicate event replay)
            event(new PaymentSucceeded($payment));
            // Also fire SaleFinalized (duplicate lifecycle notification)
            event(new SaleFinalized($sale));

            // Still only 1 message sent!
            $this->assertCount(1, MockWhatsAppProvider::$sentMessages);
            $count = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('idempotency_key', "whatsapp:receipt:{$sale->id}")
                ->count();
            $this->assertEquals(1, $count);
        });
    }

    public function test_unentitled_tenant_cannot_send_whatsapp_message(): void
    {
        $freeTenantData = $this->createTenant('Toko Mikro Gratis');
        $freeTenant = $freeTenantData['tenant'];
        $outlet = $freeTenantData['outlet'];
        $owner = $freeTenantData['owner'];

        $freePlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_FREE)->first();
        app(SubscriptionService::class)->createTrialSubscription($freeTenant, $freePlan);

        app(TenantManager::class)->runForTenant($freeTenant, function () use ($freeTenant, $outlet, $owner) {
            $customer = Customer::create([
                'tenant_id' => $freeTenant->id,
                'name' => 'Pelanggan Free',
                'phone' => '081277788899',
            ]);

            $sale = Sale::create([
                'tenant_id' => $freeTenant->id,
                'outlet_id' => $outlet->id,
                'customer_id' => $customer->id,
                'cashier_user_id' => $owner->id,
                'reference_number' => 'SALE-FREE-001',
                'subtotal_minor' => 1000000,
                'total_minor' => 1000000,
                'status' => Sale::STATUS_FINALIZED,
                'finalized_at' => now(),
            ]);

            $service = app(WhatsAppNotificationService::class);
            $result = $service->sendReceipt($sale);

            // Result is null because WhatsApp is blocked by subscription entitlement
            $this->assertNull($result);
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages);
        });
    }
}
