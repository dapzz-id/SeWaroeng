<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
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
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

class WhatsAppEndpointsAndConsentTest extends TenantTestCase
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

        $tenantData = $this->createTenant('Waroeng Consent Test');
        $this->tenant = $tenantData['tenant'];
        $this->outlet = $tenantData['outlet'];
        $this->owner = $tenantData['owner'];

        $cashierData = $this->addMemberToTenant($this->tenant, 'cashier', 'Kasir Budi', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        // Entitle tenant
        app(TenantManager::class)->runForTenant($this->tenant, function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenant);

        // Connection
        $this->connection = app(TenantManager::class)->runForTenant($this->tenant, function () {
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

    protected function createSale(Customer $customer, int $totalMinor): Sale
    {
        return app(TenantManager::class)->runForTenant($this->tenant, function () use ($customer, $totalMinor) {
            $cat = Category::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'makanan'], ['name' => 'Makanan']);
            $prod = Product::firstOrCreate(['tenant_id' => $this->tenant->id, 'slug' => 'sate-ayam'], ['category_id' => $cat->id, 'name' => 'Sate Ayam', 'status' => 'active']);
            $variant = ProductVariant::firstOrCreate(['tenant_id' => $this->tenant->id, 'sku' => 'SAT-AYM-01'], ['product_id' => $prod->id, 'name' => '10 Tusuk']);

            $sale = Sale::create([
                'tenant_id' => $this->tenant->id,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customer->id,
                'cashier_user_id' => $this->cashier->id,
                'reference_number' => 'SALE-CONS-'.uniqid(),
                'subtotal_minor' => $totalMinor,
                'total_minor' => $totalMinor,
                'status' => Sale::STATUS_FINALIZED,
                'finalized_at' => now(),
            ]);

            SaleItem::create([
                'tenant_id' => $this->tenant->id,
                'sale_id' => $sale->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Sate Ayam 10 Tusuk',
                'variant_name_snapshot' => '10 Tusuk',
                'quantity' => 1,
                'unit_price_minor' => $totalMinor,
                'subtotal_minor' => $totalMinor,
                'total_minor' => $totalMinor,
            ]);

            Payment::create([
                'tenant_id' => $this->tenant->id,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-'.$sale->reference_number,
                'method' => Payment::METHOD_CASH,
                'amount_minor' => $totalMinor,
                'status' => Payment::STATUS_SUCCEEDED,
            ]);

            return $sale;
        });
    }

    public function test_manual_receipt_send_endpoint_via_api(): void
    {
        $customer = app(TenantManager::class)->runForTenant($this->tenant, function () {
            return Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Hendra Setiawan',
                'phone' => '081234567899',
                'whatsapp_opt_in' => true,
            ]);
        });

        $sale = $this->createSale($customer, 3000000);

        // Cashier sends receipt via API
        $response = $this->authenticateTenantUser($this->cashier, $this->tenant)
            ->postJson("/api/v1/whatsapp/receipt/{$sale->id}/send");

        $response->assertStatus(200);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $response->json('data.status'));
        $this->assertEquals('+6281234567899', $response->json('data.recipient_phone'));
    }

    public function test_manual_receipt_send_with_phone_override(): void
    {
        $customer = app(TenantManager::class)->runForTenant($this->tenant, function () {
            Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Consented Recipient',
                'phone' => '087812345678',
                'whatsapp_opt_in' => true,
            ]);

            return Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Customer Without Phone',
                'phone' => null,
            ]);
        });

        $sale = $this->createSale($customer, 1500000);

        // Cashier supplies recipient phone directly at checkout
        $response = $this->authenticateTenantUser($this->cashier, $this->tenant)
            ->postJson("/api/v1/whatsapp/receipt/{$sale->id}/send", [
                'phone' => '087812345678',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('+6287812345678', $response->json('data.recipient_phone'));
    }

    public function test_test_send_endpoint_sends_test_message(): void
    {
        $response = $this->authenticateTenantUser($this->owner, $this->tenant)
            ->postJson('/api/v1/whatsapp/connection/test', [
                'recipient_phone' => '081299988877',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('test_verification', $response->json('data.message_type'));
    }

    public function test_verify_connection_endpoint_verifies_connection(): void
    {
        $response = $this->authenticateTenantUser($this->owner, $this->tenant)
            ->postJson('/api/v1/whatsapp/connection/verify', [
                'provider' => 'mock',
            ]);

        $response->assertStatus(200);
        $this->assertEquals(TenantWhatsAppConnection::VERIFICATION_VERIFIED, $response->json('data.verification_status'));
    }

    public function test_meta_cloud_provider_handles_http_calls_and_rate_limits(): void
    {
        // Mock HTTP calls to Meta Graph API
        Http::fake([
            'https://graph.facebook.com/*/11223344/messages' => Http::response([
                'error' => [
                    'message' => 'Rate limit hit',
                    'type' => 'OAuthException',
                    'code' => 80007,
                ],
            ], 429),
        ]);

        $metaConnection = app(TenantManager::class)->runForTenant($this->tenant, function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenant->id,
                'provider' => 'meta_cloud',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'phone_number_id' => '11223344',
                'encrypted_credentials' => [
                    'access_token' => 'meta_valid_token',
                    'app_secret' => 'meta_secret',
                    'webhook_verify_token' => 'meta_webhook_token',
                ],
            ]);
        });

        $provider = app(WhatsAppProviderManager::class)->resolve('meta_cloud');
        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            textBody: 'Nota Transaksi'
        );

        $result = $provider->sendMessage($metaConnection, $payload);

        $this->assertFalse($result->success);
        $this->assertTrue($result->isRetryable); // 429 classified as retryable!
        $this->assertEquals('META_ERR_80007', $result->errorCode);
    }
}
