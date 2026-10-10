<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Jobs\SendWhatsAppReceiptJob;
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
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class WhatsAppConsentAndPolicyTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected TenantWhatsAppConnection $connection;

    protected WhatsAppNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        MockWhatsAppProvider::reset();

        $tenantData = $this->createTenant('Consent Policy Waroeng');
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

        $this->service = app(WhatsAppNotificationService::class);
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenant, $callback);
    }

    protected function createSale(Customer $customer, string $ref, int $totalMinor): Sale
    {
        $cat = Category::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'snack'], ['name' => 'Snack']);
        $prod = Product::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'pisang-goreng'], [
            'category_id' => $cat->id,
            'name' => 'Pisang Goreng',
            'status' => 'active',
        ]);
        $variant = ProductVariant::firstOrCreate(['tenant_id' => $this->tenant->id, 'sku' => 'PIS-GOR-01'], [
            'product_id' => $prod->id,
            'name' => 'Crispy',
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
            'product_name_snapshot' => 'Pisang Goreng Crispy',
            'variant_name_snapshot' => 'Crispy',
            'quantity' => 1,
            'unit_price_minor' => $totalMinor,
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
        ]);

        Payment::create([
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

        return $sale;
    }

    public function test_customer_eligible_for_transactional_receipt_without_marketing_opt_in(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Transactional Only Customer',
                'phone' => '081211112222',
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => false, // Transactional receipt is eligible even without marketing consent
            ]);

            $this->assertTrue($customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $this->tenant->id));
            $this->assertFalse($customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_MARKETING_PROMO, $this->tenant->id));

            $sale = $this->createSale($customer, 'SALE-CONSENT-001', 2000000);
            $message = $this->service->sendReceipt($sale);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->status);
            $this->assertEquals('+6281211112222', $message->recipient_phone);
        });
    }

    public function test_phone_available_without_positive_consent_is_rejected(): void
    {
        $this->runForTenant(function () {
            // Customer has phone number, but NO positive WhatsApp consent on file
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Phone But No Consent Customer',
                'phone' => '081288887777',
                'whatsapp_opt_in' => false,
            ]);

            $this->assertFalse($customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $this->tenant->id));

            $sale = $this->createSale($customer, 'SALE-NO-CONSENT-001', 2000000);
            $message = $this->service->sendReceipt($sale);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('CONSENT_REQUIRED', $message->error_code);
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Provider must not be called when positive consent is absent');
        });
    }

    public function test_consent_from_another_tenant_is_strictly_rejected(): void
    {
        // Setup Tenant B
        $tenantBData = $this->createTenant('Tenant B Waroeng');
        $tenantB = $tenantBData['tenant'];

        $customerB = app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB) {
            return Customer::create([
                'tenant_id' => $tenantB->id,
                'name' => 'Tenant B Customer',
                'phone' => '081299998888',
                'whatsapp_opt_in' => true,
            ]);
        });

        $this->runForTenant(function () use ($customerB) {
            // Verifying Customer B's consent against Tenant A must FAIL
            $this->assertFalse(
                $customerB->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $this->tenant->id),
                'Consent for Tenant B must never be valid for Tenant A'
            );

            // Attempting to send receipt from Tenant A using Tenant B's customer phone override without Tenant A consent
            $anonCustomer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Tenant A Anon Customer',
                'phone' => null,
            ]);

            $sale = $this->createSale($anonCustomer, 'SALE-CROSS-TENANT-001', 1500000);
            $message = $this->service->sendReceipt($sale, overridePhone: '081299998888');

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('CONSENT_REQUIRED', $message->error_code);
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages);
        });
    }

    public function test_marketing_promo_requires_explicit_marketing_opt_in(): void
    {
        $this->runForTenant(function () {
            $optedIn = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Marketing Opted In Customer',
                'phone' => '081233334444',
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => true,
            ]);

            $optedOut = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Marketing Not Opted In',
                'phone' => '081255556666',
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => false,
            ]);

            $this->assertTrue($optedIn->isEligibleForWhatsApp(WhatsAppMessage::TYPE_MARKETING_PROMO, $this->tenant->id));
            $this->assertFalse($optedOut->isEligibleForWhatsApp(WhatsAppMessage::TYPE_MARKETING_PROMO, $this->tenant->id));
        });
    }

    public function test_marketing_opt_out_preserves_transactional_eligibility(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Dual Opt In Customer',
                'phone' => '081266667777',
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => true,
            ]);

            // Customer opts out of marketing specifically
            $customer->recordWhatsAppConsent('marketing', false, 'portal', 'User unchecked promo');

            $this->assertFalse($customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_MARKETING_PROMO, $this->tenant->id));
            $this->assertTrue($customer->isEligibleForWhatsApp(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $this->tenant->id));
        });
    }

    public function test_opt_out_prevents_dispatch_and_records_opted_out_status(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Opted Out Customer',
                'phone' => '081277778888',
                'whatsapp_opt_in' => true,
                'marketing_opt_in' => true,
            ]);

            // Opt-out WhatsApp communication explicitly
            $customer->optOutWhatsApp('Customer requested STOP via chat');
            $this->assertTrue($customer->fresh()->whatsapp_opted_out);
            $this->assertNotNull($customer->fresh()->whatsapp_opted_out_at);

            // Attempting to send receipt must be rejected by policy
            $sale = $this->createSale($customer, 'SALE-OPTOUT-001', 1000000);
            $message = $this->service->sendReceipt($sale);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('CUSTOMER_OPTED_OUT', $message->error_code);
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Provider must not be contacted when customer is opted out');
        });
    }

    public function test_opt_out_after_job_enqueued_prevents_dispatch_at_execution_time(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Late Opt Out Customer',
                'phone' => '081288889999',
                'whatsapp_opt_in' => true,
            ]);

            $sale = $this->createSale($customer, 'SALE-LATE-OPTOUT-001', 1000000);

            // Job is constructed while customer was eligible
            $job = new SendWhatsAppReceiptJob($sale->id);

            // Customer opts out BEFORE worker executes handle()
            $customer->optOutWhatsApp('Opt out received right before worker execution');

            // Worker executes job
            app()->call([$job, 'handle']);

            $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Provider must not be contacted if customer opted out after enqueue');

            $message = WhatsAppMessage::where('tenant_id', $this->tenant->id)
                ->where('recipient_phone', '+6281288889999')
                ->first();

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('CUSTOMER_OPTED_OUT', $message->error_code);
        });
    }

    public function test_manual_phone_override_without_positive_consent_is_rejected(): void
    {
        $this->runForTenant(function () {
            $anonymousCustomer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Anon Customer',
                'phone' => null,
            ]);

            $sale = $this->createSale($anonymousCustomer, 'SALE-OVERRIDE-NOCONSENT-001', 1500000);

            // Cashier attempts manual phone override with a phone number that has no positive consent on file
            $message = $this->service->sendReceipt($sale, overridePhone: '081299990000');

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('CONSENT_REQUIRED', $message->error_code);
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Manual override without registered consent must be rejected');
        });
    }

    public function test_manual_phone_override_matching_opted_out_recipient_is_rejected(): void
    {
        $this->runForTenant(function () {
            $optedOutCustomer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Known Opted Out Person',
                'phone' => '081299990000',
                'whatsapp_opt_in' => true,
            ]);
            $optedOutCustomer->optOutWhatsApp('Unsubscribe');

            $anonymousCustomer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Anon Customer',
                'phone' => null,
            ]);

            $sale = $this->createSale($anonymousCustomer, 'SALE-OVERRIDE-001', 1500000);

            // Cashier attempts manual phone override with the opted-out number
            $message = $this->service->sendReceipt($sale, overridePhone: '081299990000');

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $message->status);
            $this->assertEquals('RECIPIENT_OPTED_OUT', $message->error_code);
            $this->assertCount(0, MockWhatsAppProvider::$sentMessages, 'Manual override must not bypass recipient opt-out');
        });
    }

    public function test_resend_message_enforces_active_opt_out_policy(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Resend Policy Customer',
                'phone' => '081322223333',
                'whatsapp_opt_in' => true,
            ]);

            $sale = $this->createSale($customer, 'SALE-RESEND-001', 1200000);
            $message = $this->service->sendReceipt($sale);

            $this->assertNotNull($message);
            $this->assertEquals(WhatsAppMessage::STATUS_SENT, $message->status);

            // Customer opts out after initial delivery
            $customer->optOutWhatsApp('Customer asked to stop receipts');

            // Attempt manual resend of message
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pelanggan telah memilih berhenti (opt-out) dari notifikasi WhatsApp.');
            $this->service->resendMessage($message);
        });
    }

    public function test_consent_history_audit_provenance_is_preserved_on_updates(): void
    {
        $this->runForTenant(function () {
            $customer = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Audit History Customer',
                'phone' => '081344445555',
                'whatsapp_opt_in' => false,
            ]);

            $customer->recordWhatsAppConsent(
                category: WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                granted: true,
                source: 'pos_checkout',
                notes: 'Customer provided phone for digital receipt at POS'
            );

            $customer->recordWhatsAppConsent(
                category: WhatsAppMessage::TYPE_MARKETING_PROMO,
                granted: true,
                source: 'loyalty_registration',
                notes: 'Customer checked marketing opt-in box'
            );

            $customer->optOutWhatsApp(
                source: 'whatsapp_inbound_optout',
                reason: 'Customer sent STOP keyword via WhatsApp'
            );

            $history = $customer->fresh()->whatsapp_consent_history;
            $this->assertIsArray($history);
            $this->assertCount(3, $history, 'All consent changes must be chronologically appended to audit history');

            $this->assertEquals((string) $this->tenant->id, $history[0]['tenant_id']);
            $this->assertEquals('pos_checkout', $history[0]['source']);
            $this->assertEquals(WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT, $history[0]['category']);
            $this->assertTrue($history[0]['granted']);

            $this->assertEquals((string) $this->tenant->id, $history[1]['tenant_id']);
            $this->assertEquals('loyalty_registration', $history[1]['source']);
            $this->assertEquals(WhatsAppMessage::TYPE_MARKETING_PROMO, $history[1]['category']);
            $this->assertTrue($history[1]['granted']);

            $this->assertEquals((string) $this->tenant->id, $history[2]['tenant_id']);
            $this->assertEquals('whatsapp_inbound_optout', $history[2]['source']);
            $this->assertEquals('all', $history[2]['category']);
            $this->assertFalse($history[2]['granted']);
            $this->assertNotNull($history[2]['timestamp']);
        });
    }
}
