<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\PaymentRefundNotSupportedException;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\TenantPaymentConnection;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Payment\Providers\DuitkuPaymentProvider;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class DuitkuPaymentProviderTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected string $merchantCode = 'D12345';

    protected string $apiKey = 'duitku-test-api-key-abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.duitku.merchant_code' => $this->merchantCode,
            'services.duitku.api_key' => $this->apiKey,
            'services.duitku.is_production' => false,
        ]);

        $this->tenantData = $this->createTenant('Waroeng Duitku Cafe');
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Kasir Duitku', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);

        $this->kitchenLocation = $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Duitku Kitchen',
                'code' => 'KIT-DK',
                'type' => 'kitchen',
                'is_active' => true,
            ]);

            $this->outlet->update(['default_pos_location_id' => $loc->id]);

            return $loc;
        });

        // Set up active tenant connection for Duitku
        $this->runForTenant(function () {
            TenantPaymentConnection::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'provider' => 'duitku',
                'environment' => 'sandbox',
                'status' => TenantPaymentConnection::STATUS_ACTIVE,
                'is_default' => true,
                'merchant_identifier' => $this->merchantCode,
                'encrypted_credentials' => ['api_key' => $this->apiKey],
                'verification_status' => TenantPaymentConnection::VERIFICATION_VERIFIED,
            ]);
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    protected function createAndFinalizeSale(int $unitPriceMinor = 50000): Sale
    {
        return $this->runForTenant(function () use ($unitPriceMinor) {
            $category = Category::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Minuman',
                'slug' => 'minuman-'.Str::random(5),
            ]);

            $product = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $category->id,
                'name' => 'Kopi Duitku',
                'slug' => 'kopi-duitku-'.Str::random(5),
                'product_type' => 'standard',
                'status' => 'active',
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $product->id,
                'name' => 'Regular',
                'sku' => 'DK-'.strtoupper(Str::random(6)),
                'status' => 'active',
            ]);

            Price::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'variant_id' => $variant->id,
                'outlet_id' => null,
                'amount' => $unitPriceMinor,
                'currency' => 'IDR',
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            $sale = $this->posService->createSale(
                tenantId: $this->tenantData['tenant']->id,
                outletId: $this->outlet->id,
                cashierUserId: $this->cashier->id,
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 1.0,
                    ],
                ]
            );

            return $this->posService->finalizeSale(
                sale: $sale,
                options: [],
                userId: $this->cashier->id,
                tenantId: $this->tenantData['tenant']->id
            );
        });
    }

    public function test_create_invoice_sends_exact_amount_and_hmac_sha256_signature(): void
    {
        Http::fake([
            DuitkuPaymentProvider::CREATE_INVOICE_SANDBOX_URL => function (Request $request) {
                // Verify authentication headers
                $this->assertTrue($request->hasHeader('x-duitku-timestamp'));
                $this->assertTrue($request->hasHeader('x-duitku-signature'));
                $this->assertEquals($this->merchantCode, $request->header('x-duitku-merchantcode')[0]);

                $timestamp = $request->header('x-duitku-timestamp')[0];
                $expectedSig = hash_hmac('sha256', $this->merchantCode.$timestamp, $this->apiKey);
                $this->assertEquals($expectedSig, $request->header('x-duitku-signature')[0]);

                // Verify payload
                $data = $request->data();
                $this->assertEquals(75000, $data['paymentAmount']);
                $this->assertStringStartsWith('PAY-', $data['merchantOrderId']);
                $this->assertEquals('SP', $data['paymentMethod']);

                return Http::response([
                    'statusCode' => '00',
                    'statusMessage' => 'SUCCESS',
                    'reference' => 'DK-REF-998877',
                    'paymentUrl' => 'https://sandbox.duitku.com/payment/998877',
                    'vaNumber' => '1234567890',
                    'qrString' => '00020101021226590014ID.LINKAJA.WWW...',
                ], 200);
            },
        ]);

        $sale = $this->createAndFinalizeSale(75000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 75000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'duitku',
            ], $this->cashier);

            return $this->paymentService->processPayment($p, ['payment_method' => 'SP']);
        });

        $this->assertEquals(Payment::STATUS_PROCESSING, $payment->status);
        $this->assertEquals('DK-REF-998877', $payment->provider_payment_id);

        /** @var PaymentAttempt $attempt */
        $attempt = $this->runForTenant(fn () => $payment->attempts()->first());
        $this->assertEquals('DK-REF-998877', $attempt->provider_reference);
        $this->assertEquals('DUITKU_INVOICE_CREATED', $attempt->response_code);
        $this->assertEquals('https://sandbox.duitku.com/payment/998877', $attempt->response_payload['payment_url']);
        $this->assertEquals('00020101021226590014ID.LINKAJA.WWW...', $attempt->response_payload['qr_string']);
    }

    public function test_create_invoice_handles_production_endpoint(): void
    {
        $provider = new DuitkuPaymentProvider($this->merchantCode, $this->apiKey, true);

        Http::fake([
            DuitkuPaymentProvider::CREATE_INVOICE_PRODUCTION_URL => Http::response([
                'statusCode' => '00',
                'statusMessage' => 'SUCCESS',
                'reference' => 'DK-PROD-REF',
            ], 200),
        ]);

        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        $res = $provider->initiatePayment($payment);

        $this->assertEquals(Payment::STATUS_PROCESSING, $res['status']);
        $this->assertEquals('DK-PROD-REF', $res['provider_reference']);
    }

    public function test_create_invoice_handles_api_rejection(): void
    {
        Http::fake([
            DuitkuPaymentProvider::CREATE_INVOICE_SANDBOX_URL => Http::response([
                'statusCode' => '99',
                'statusMessage' => 'Payment method not available for merchant',
            ], 400),
        ]);

        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'duitku',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_FAILED, $payment->status);
        /** @var PaymentAttempt $attempt */
        $attempt = $this->runForTenant(fn () => $payment->attempts()->first());
        $this->assertEquals(PaymentAttempt::STATUS_FAILED, $attempt->status);
        $this->assertEquals('99', $attempt->response_code);
    }

    public function test_create_invoice_handles_network_timeout_without_generating_new_order_id(): void
    {
        Http::fake([
            DuitkuPaymentProvider::CREATE_INVOICE_SANDBOX_URL => function () {
                throw new ConnectionException('Connection timed out after 15000ms');
            },
        ]);

        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'duitku',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // Unknown network outcome must remain in processing to prevent double charging
        $this->assertEquals(Payment::STATUS_PROCESSING, $payment->status);
        /** @var PaymentAttempt $attempt */
        $attempt = $this->runForTenant(fn () => $payment->attempts()->first());
        $this->assertEquals(PaymentAttempt::STATUS_PROCESSING, $attempt->status);
        $this->assertEquals('NETWORK_TIMEOUT', $attempt->response_code);
    }

    public function test_get_payment_method_verifies_signature_and_parses_methods(): void
    {
        Http::fake([
            DuitkuPaymentProvider::PAYMENT_METHOD_SANDBOX_URL => function (Request $request) {
                $data = $request->data();
                $expectedSig = hash_hmac('sha256', $this->merchantCode.'10000'.$data['datetime'], $this->apiKey);
                $this->assertEquals($expectedSig, $data['signature']);

                return Http::response([
                    'paymentFee' => [
                        ['paymentMethod' => 'SP', 'paymentName' => 'ShopeePay QRIS'],
                        ['paymentMethod' => 'BC', 'paymentName' => 'BCA Virtual Account'],
                        ['paymentMethod' => 'M2', 'paymentName' => 'Mandiri Virtual Account'],
                    ],
                ], 200);
            },
        ]);

        $provider = new DuitkuPaymentProvider($this->merchantCode, $this->apiKey, false);
        $res = $provider->verifyConnection(['merchant_code' => $this->merchantCode, 'api_key' => $this->apiKey]);

        $this->assertTrue($res['verified']);
        $this->assertNull($res['error_code']);
        $this->assertEquals(['SP', 'BC', 'M2'], $res['capabilities']['supported_methods']);
    }

    public function test_duitku_callback_succeeds_with_valid_signature_and_matching_amount(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        $amount = '50000';
        $orderId = $payment->reference_number;
        $signature = hash_hmac('sha256', $this->merchantCode.$amount.$orderId, $this->apiKey);

        $response = $this->post('/api/v1/payments/webhooks/duitku', [
            'merchantCode' => $this->merchantCode,
            'amount' => $amount,
            'merchantOrderId' => $orderId,
            'paymentCode' => 'SP',
            'resultCode' => '00',
            'reference' => 'DK-REF-12345',
            'signature' => $signature,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'processed');

        $this->runForTenant(fn () => $payment->refresh());
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertNotNull($payment->succeeded_at);
    }

    public function test_duitku_callback_rejects_invalid_signature(): void
    {
        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        $response = $this->postJson('/api/v1/payments/webhooks/duitku', [
            'merchantCode' => $this->merchantCode,
            'amount' => '50000',
            'merchantOrderId' => $payment->reference_number,
            'resultCode' => '00',
            'reference' => 'DK-REF-TAMPERED',
            'signature' => 'invalid-tampered-signature',
        ]);

        $response->assertStatus(400);

        $this->runForTenant(fn () => $payment->refresh());
        $this->assertEquals(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_duitku_callback_rejects_amount_mismatch(): void
    {
        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        // Callback with manipulated amount 30000
        $tamperedAmount = '30000';
        $signature = hash_hmac('sha256', $this->merchantCode.$tamperedAmount.$payment->reference_number, $this->apiKey);

        $response = $this->postJson('/api/v1/payments/webhooks/duitku', [
            'merchantCode' => $this->merchantCode,
            'amount' => $tamperedAmount,
            'merchantOrderId' => $payment->reference_number,
            'resultCode' => '00',
            'reference' => 'DK-REF-AMOUNT-MISMATCH',
            'signature' => $signature,
        ]);

        $response->assertStatus(400);

        $this->runForTenant(fn () => $payment->refresh());
        $this->assertEquals(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_duitku_duplicate_callback_is_ignored_idempotently(): void
    {
        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        $amount = '50000';
        $orderId = $payment->reference_number;
        $signature = hash_hmac('sha256', $this->merchantCode.$amount.$orderId, $this->apiKey);

        $payload = [
            'merchantCode' => $this->merchantCode,
            'amount' => $amount,
            'merchantOrderId' => $orderId,
            'resultCode' => '00',
            'reference' => 'DK-REF-DUP-1',
            'signature' => $signature,
        ];

        // First callback
        $res1 = $this->post('/api/v1/payments/webhooks/duitku', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.status', 'processed');

        // Replay identical callback
        $res2 = $this->post('/api/v1/payments/webhooks/duitku', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'duplicate_ignored');
    }

    public function test_cross_tenant_webhook_injection_is_strictly_blocked(): void
    {
        // Tenant A creates a payment
        $saleA = $this->createAndFinalizeSale(50000);
        $paymentA = $this->runForTenant(fn () => $this->paymentService->createPayment($saleA, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        // Tenant B has a separate connection
        $tenantB = $this->createTenant('Tenant B Coffee');
        $merchantCodeB = 'D99999';
        $apiKeyB = 'secret-key-tenant-b';

        app(TenantManager::class)->runForTenant($tenantB['tenant'], function () use ($tenantB, $merchantCodeB, $apiKeyB) {
            TenantPaymentConnection::create([
                'tenant_id' => $tenantB['tenant']->id,
                'provider' => 'duitku',
                'environment' => 'sandbox',
                'status' => TenantPaymentConnection::STATUS_ACTIVE,
                'merchant_identifier' => $merchantCodeB,
                'encrypted_credentials' => ['api_key' => $apiKeyB],
            ]);
        });

        // Tenant B's credentials send a webhook claiming Tenant A's orderId
        $signatureB = hash_hmac('sha256', $merchantCodeB.'50000'.$paymentA->reference_number, $apiKeyB);

        $response = $this->postJson('/api/v1/payments/webhooks/duitku', [
            'merchantCode' => $merchantCodeB,
            'amount' => '50000',
            'merchantOrderId' => $paymentA->reference_number,
            'resultCode' => '00',
            'reference' => 'DK-CROSS-TENANT',
            'signature' => $signatureB,
        ]);

        // Must reject cross-tenant mismatch
        $response->assertStatus(400);

        $this->runForTenant(fn () => $paymentA->refresh());
        $this->assertEquals(Payment::STATUS_PENDING, $paymentA->status);
    }

    public function test_duitku_status_query_synchronizes_success(): void
    {
        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        Http::fake([
            DuitkuPaymentProvider::STATUS_SANDBOX_URL => function (Request $request) use ($payment) {
                $data = $request->data();
                $expectedSig = hash_hmac('sha256', $this->merchantCode.$payment->reference_number, $this->apiKey);
                $this->assertEquals($expectedSig, $data['signature']);

                return Http::response([
                    'merchantOrderId' => $payment->reference_number,
                    'reference' => 'DK-SYNC-REF-1',
                    'amount' => '50000',
                    'statusCode' => '00',
                    'statusMessage' => 'SUCCESS',
                ], 200);
            },
        ]);

        $synced = $this->runForTenant(fn () => $this->paymentService->syncPaymentStatus($payment));

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $synced->status);
        $this->assertEquals('DK-SYNC-REF-1', $synced->provider_payment_id);
    }

    public function test_duitku_status_query_rejects_amount_mismatch(): void
    {
        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(fn () => $this->paymentService->createPayment($sale, [
            'amount_minor' => 50000,
            'method' => Payment::METHOD_QRIS,
            'provider' => 'duitku',
        ], $this->cashier));

        Http::fake([
            DuitkuPaymentProvider::STATUS_SANDBOX_URL => Http::response([
                'merchantOrderId' => $payment->reference_number,
                'reference' => 'DK-SYNC-REF-MISMATCH',
                'amount' => '25000', // Mismatch!
                'statusCode' => '00',
            ], 200),
        ]);

        $synced = $this->runForTenant(fn () => $this->paymentService->syncPaymentStatus($payment));

        // Must not be promoted
        $this->assertEquals(Payment::STATUS_PENDING, $synced->status);
    }

    public function test_duitku_refund_throws_unsupported_exception_by_default(): void
    {
        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'duitku',
            ], $this->cashier);
            $p->update(['status' => Payment::STATUS_SUCCEEDED]);

            return $p;
        });

        $this->expectException(PaymentRefundNotSupportedException::class);

        $this->runForTenant(function () use ($payment) {
            $this->paymentService->refundPayment($payment, 50000, 'Customer return', $this->cashier);
        });
    }
}
