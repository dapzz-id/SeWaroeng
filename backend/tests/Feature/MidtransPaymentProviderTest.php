<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentSucceeded;
use App\Exceptions\PaymentRefundNotSupportedException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\PaymentWebhookEvent;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Payment\Providers\MidtransPaymentProvider;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class MidtransPaymentProviderTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $manager;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected string $serverKey = 'SB-Mid-server-test-key-12345';

    protected string $clientKey = 'SB-Mid-client-test-key-12345';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => $this->serverKey,
            'services.midtrans.client_key' => $this->clientKey,
            'services.midtrans.merchant_id' => 'G123456789',
            'services.midtrans.is_production' => false,
            'services.midtrans.base_url' => MidtransPaymentProvider::SANDBOX_URL,
        ]);

        $this->tenantData = $this->createTenant('Waroeng Midtrans Coffee');
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Kasir Midtrans', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Manajer Midtrans', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);

        $this->kitchenLocation = $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Midtrans Kitchen',
                'code' => 'KIT-MID',
                'type' => 'kitchen',
                'is_active' => true,
            ]);

            $this->outlet->update(['default_pos_location_id' => $loc->id]);

            return $loc;
        });
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    protected function createAndFinalizeSale(
        int $unitPriceMinor = 50000,
        float $qty = 1,
        ?array $customTenantData = null,
        ?User $customUser = null,
        ?InventoryLocation $customLocation = null
    ): Sale {
        $tData = $customTenantData ?? $this->tenantData;
        $user = $customUser ?? $this->cashier;
        $loc = $customLocation ?? $this->kitchenLocation;

        return app(TenantManager::class)->runForTenant($tData['tenant'], function () use ($unitPriceMinor, $qty, $tData, $user, $loc) {
            $category = Category::create([
                'tenant_id' => $tData['tenant']->id,
                'name' => 'Minuman',
                'slug' => 'minuman-'.Str::random(5),
            ]);

            $product = Product::create([
                'tenant_id' => $tData['tenant']->id,
                'category_id' => $category->id,
                'name' => 'Kopi Tubruk '.Str::random(4),
                'slug' => 'kopi-tubruk-'.Str::random(5),
                'product_type' => 'standard',
                'status' => 'active',
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $tData['tenant']->id,
                'product_id' => $product->id,
                'sku' => 'SKU-'.strtoupper(Str::random(6)),
                'name' => 'Regular',
            ]);

            Price::create([
                'tenant_id' => $tData['tenant']->id,
                'variant_id' => $variant->id,
                'outlet_id' => null,
                'amount' => $unitPriceMinor,
                'currency' => 'IDR',
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            $bean = InventoryItem::create([
                'tenant_id' => $tData['tenant']->id,
                'sku' => 'BEAN-'.strtoupper(Str::random(5)),
                'name' => 'Biji Kopi Robusta',
                'type' => 'raw_material',
                'uom' => 'g',
                'is_active' => true,
                'allow_negative_stock' => false,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $tData['tenant']->id,
                'inventory_item_id' => $bean->id,
                'location_id' => $loc->id,
                'on_hand' => 10000.0,
                'reserved' => 0.0,
            ]);

            InventoryCostState::create([
                'tenant_id' => $tData['tenant']->id,
                'inventory_item_id' => $bean->id,
                'location_id' => $loc->id,
                'total_quantity' => 10000.0,
                'total_cost_minor' => 1000000,
                'average_cost_minor' => 100,
            ]);

            $recipe = Recipe::create([
                'tenant_id' => $tData['tenant']->id,
                'product_variant_id' => $variant->id,
                'code' => 'REC-KOPI-'.strtoupper(Str::random(4)),
                'name' => 'Resep Kopi',
                'owner_type' => 'product_variant',
                'owner_id' => $variant->id,
                'created_by' => $tData['owner']->id,
            ]);

            $recipeVersion = RecipeVersion::create([
                'tenant_id' => $tData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'status' => 'published',
                'yield_quantity' => 1,
                'yield_unit' => 'portion',
                'created_by' => $tData['owner']->id,
                'effective_from' => now()->subDay(),
            ]);

            RecipeComponent::create([
                'tenant_id' => $tData['tenant']->id,
                'recipe_version_id' => $recipeVersion->id,
                'inventory_item_id' => $bean->id,
                'quantity' => 15.0,
                'unit' => 'g',
            ]);

            $sale = $this->posService->createSale(
                tenantId: $tData['tenant']->id,
                outletId: $tData['outlet']->id,
                cashierUserId: $user->id,
                items: [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => $qty,
                    ],
                ]
            );

            return $this->posService->finalizeSale(
                sale: $sale,
                options: [],
                userId: $user->id,
                tenantId: $tData['tenant']->id
            );
        });
    }

    public function test_midtrans_initiation_with_qris_returns_processing_and_qr_string(): void
    {
        $sale = $this->createAndFinalizeSale(75000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => '0225134c-633d-4c3e-9669-70dc982701f6',
                'order_id' => 'temp', // will match dynamically
                'gross_amount' => '75000.00',
                'currency' => 'IDR',
                'payment_type' => 'qris',
                'transaction_time' => '2026-10-05 16:30:00',
                'transaction_status' => 'pending',
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/0225134c-633d-4c3e-9669-70dc982701f6/qr-code',
                    ],
                ],
                'qr_string' => '00020101021226540014ID.LINKAJA.WWW011893600911002220194402150000000000000005204581253033605802ID5911SeWaroeng6007Jakarta61051234062070703A0163047D3A',
                'expiry_time' => '2026-10-05 16:45:00',
            ], 201),
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 75000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $processed = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->processPayment($payment);
        });

        $this->assertEquals(Payment::STATUS_PROCESSING, $processed->status);
        $this->assertEquals('0225134c-633d-4c3e-9669-70dc982701f6', $processed->provider_payment_id);
        $this->assertNotNull($processed->metadata['provider_result']['qr_string'] ?? null);
        $this->assertEquals('qris', $processed->metadata['provider_result']['payment_type']);

        $attempts = $this->runForTenant(fn () => $processed->attempts()->get());
        $this->assertCount(1, $attempts);
        $this->assertEquals(PaymentAttempt::STATUS_PROCESSING, $attempts[0]->status);
        $this->assertEquals('0225134c-633d-4c3e-9669-70dc982701f6', $attempts[0]->provider_reference);

        // Verify outgoing request headers & payload to Midtrans
        Http::assertSent(function ($request) use ($payment) {
            return $request->url() === 'https://api.sandbox.midtrans.com/v2/charge'
                && $request['payment_type'] === 'qris'
                && $request['transaction_details']['order_id'] === $payment->reference_number
                && $request['transaction_details']['gross_amount'] === 75000
                && $request->hasHeader('Authorization');
        });
    }

    public function test_midtrans_initiation_synchronous_success(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $sale = $this->createAndFinalizeSale(50000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, transaction is settled',
                'transaction_id' => 'sync-success-uuid-1234',
                'order_id' => 'temp',
                'gross_amount' => '50000.00',
                'currency' => 'IDR',
                'payment_type' => 'qris',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $processed = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->processPayment($payment);
        });

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $processed->status);
        $this->assertEquals('sync-success-uuid-1234', $processed->provider_payment_id);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_midtrans_initiation_provider_decline_fails_safely(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '400',
                'status_message' => 'Validation error: invalid merchant account or inactive QRIS',
            ], 400),
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $processed = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->processPayment($payment);
        });

        $this->assertEquals(Payment::STATUS_FAILED, $processed->status);
        $this->assertStringContainsString('Validation error', $processed->metadata['failure_reason']);

        $attempts = $this->runForTenant(fn () => $processed->attempts()->get());
        $this->assertEquals(PaymentAttempt::STATUS_FAILED, $attempts[0]->status);
    }

    public function test_midtrans_initiation_network_timeout_preserves_processing_state(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
            },
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $processed = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->processPayment($payment);
        });

        // Crucial invariant (Section 18): Network timeout is an UNKNOWN outcome, MUST NOT be marked failed
        $this->assertEquals(Payment::STATUS_PROCESSING, $processed->status);
        $attempts = $this->runForTenant(fn () => $processed->attempts()->get());
        $this->assertEquals(PaymentAttempt::STATUS_PROCESSING, $attempts[0]->status);
        $this->assertEquals('NETWORK_TIMEOUT', $attempts[0]->response_code);
    }

    public function test_midtrans_webhook_valid_signature_settles_payment(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'transaction_id' => 'tx-midtrans-valid-sig-1',
                'transaction_status' => 'pending',
                'gross_amount' => '100000.00',
            ], 201),
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_PROCESSING, $payment->status);

        // Prepare Midtrans Webhook Notification
        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-midtrans-valid-sig-1',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'payment_type' => 'qris',
            'currency' => 'IDR',
            'transaction_time' => '2026-10-05 16:35:00',
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.processed', true);
        $response->assertJsonPath('data.status', 'processed');

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloaded->status);
        $this->assertNotNull($reloaded->succeeded_at);

        $attempts = $this->runForTenant(fn () => $reloaded->attempts()->get());
        $this->assertEquals(PaymentAttempt::STATUS_SUCCEEDED, $attempts[0]->status);
    }

    public function test_midtrans_webhook_invalid_signature_is_rejected(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $payload = [
            'order_id' => $payment->reference_number,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'signature_key' => 'tampered_invalid_signature_hash_value',
            'transaction_id' => 'tx-forged-sig',
            'transaction_status' => 'settlement',
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(400);
        $response->assertJsonPath('code', 'WEBHOOK_SIGNATURE_INVALID');

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_PENDING, $reloaded->status);
    }

    public function test_midtrans_webhook_amount_mismatch_is_rejected_with_400(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'transaction_id' => 'tx-midtrans-amt-mismatch',
                'transaction_status' => 'pending',
                'gross_amount' => '100000.00',
            ], 201),
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // Attacker creates a webhook with amount 50000.00 instead of 100000.00
        $orderId = $payment->reference_number;
        $statusCode = '200';
        $mismatchedAmount = '50000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$mismatchedAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $mismatchedAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-midtrans-amt-mismatch',
            'transaction_status' => 'settlement',
            'currency' => 'IDR',
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(400);

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_PROCESSING, $reloaded->status);

        // Verify webhook event recorded error
        $event = PaymentWebhookEvent::where('provider', 'midtrans')
            ->where('external_event_id', 'tx-midtrans-amt-mismatch-200-settlement')
            ->first();
        $this->assertNotNull($event);
        $this->assertEquals(PaymentWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertEquals('PAYMENT_WEBHOOK_AMOUNT_MISMATCH', $event->error_code);
    }

    public function test_midtrans_webhook_currency_mismatch_is_rejected_with_400(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-midtrans-curr-mismatch',
            'transaction_status' => 'settlement',
            'currency' => 'USD', // mismatched
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(400);

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_PENDING, $reloaded->status);
    }

    public function test_midtrans_webhook_idempotency_ignores_duplicate_delivery(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-midtrans-idempotency-1',
            'transaction_status' => 'settlement',
            'currency' => 'IDR',
        ];

        // 1st delivery
        $res1 = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.status', 'processed');

        // 2nd delivery (duplicate retry from Midtrans)
        $res2 = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'duplicate_ignored');
    }

    public function test_midtrans_webhook_out_of_order_preserves_monotonic_terminal_state(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        // Late arriving 'pending' webhook event after payment has already succeeded
        $orderId = $payment->reference_number;
        $statusCode = '201';
        $grossAmount = '50000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-midtrans-out-of-order',
            'transaction_status' => 'pending',
            'currency' => 'IDR',
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'out_of_order_ignored');

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloaded->status);
    }

    public function test_midtrans_status_query_synchronizes_state(): void
    {
        $sale = $this->createAndFinalizeSale(60000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'transaction_id' => 'tx-query-sync-123',
                'transaction_status' => 'pending',
            ], 201),
            'https://api.sandbox.midtrans.com/v2/*/status' => function ($request) {
                // Extract order_id from URL
                $parts = explode('/', parse_url($request->url(), PHP_URL_PATH));
                $orderId = $parts[count($parts) - 2] ?? 'ORDER';

                return Http::response([
                    'status_code' => '200',
                    'transaction_id' => 'tx-query-sync-123',
                    'order_id' => $orderId,
                    'gross_amount' => '60000.00',
                    'currency' => 'IDR',
                    'transaction_status' => 'settlement',
                    'fraud_status' => 'accept',
                ], 200);
            },
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 60000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_PROCESSING, $payment->status);

        // Call sync-status API endpoint
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenantData['tenant']->id)
            ->postJson("/api/v1/payments/{$payment->id}/sync-status");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED);

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloaded->status);
    }

    public function test_midtrans_status_query_cannot_demote_succeeded_payment(): void
    {
        $sale = $this->createAndFinalizeSale(60000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 60000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'status_code' => '200',
                'transaction_status' => 'pending',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $synced = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->syncPaymentStatus($payment);
        });

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $synced->status);
    }

    public function test_midtrans_refund_full_and_partial_execution(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, refund is accepted',
                'transaction_id' => 'tx-refund-test-1',
                'refund_charge_id' => 'MID-REFUND-CHARGE-999',
                'refund_amount' => '40000.00',
                'bank_confirmed_at' => now()->toIso8601String(),
            ], 200),
        ]);

        // Partial Refund 40000
        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 40000, 'Customer changed order', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $refund->status);
        $this->assertEquals('MID-REFUND-CHARGE-999', $refund->provider_reference);

        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $reloaded->status);
        $refundable = $this->runForTenant(fn () => $reloaded->refundableAmountMinor());
        $this->assertEquals(60000, $refundable);

        // Full remaining refund 60000
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, refund is accepted',
                'refund_charge_id' => 'MID-REFUND-CHARGE-1000',
                'refund_amount' => '60000.00',
                'bank_confirmed_at' => now()->toIso8601String(),
            ], 200),
        ]);

        $refund2 = $this->runForTenant(function () use ($reloaded) {
            return $this->paymentService->refundPayment($reloaded, 60000, 'Remaining refund', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $refund2->status);

        $fullyRefunded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_REFUNDED, $fullyRefunded->status);
        $remainingRefundable = $this->runForTenant(fn () => $fullyRefunded->refundableAmountMinor());
        $this->assertEquals(0, $remainingRefundable);
    }

    public function test_midtrans_refund_rejected_by_gateway_fails_safely(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '412',
                'status_message' => 'Merchant balance insufficient for refund',
            ], 412),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 50000, 'Failed refund attempt', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_FAILED, $refund->status);

        // Payment status must remain succeeded
        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloaded->status);
    }

    public function test_midtrans_security_secrets_are_never_exposed_or_logged(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'transaction_id' => 'tx-secret-check-1',
                'transaction_status' => 'pending',
                'qr_string' => 'mock-qr',
            ], 201),
        ]);

        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenantData['tenant']->id)
            ->postJson('/api/v1/payments', [
                'sale_id' => $sale->id,
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ]);

        $response->assertStatus(201);
        $paymentId = $response->json('data.id');

        $processResponse = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenantData['tenant']->id)
            ->postJson("/api/v1/payments/{$paymentId}/process");

        $processResponse->assertStatus(200);

        // 1. Secrets must not be returned in API JSON response
        $rawJson = $processResponse->getContent();
        $this->assertStringNotContainsString($this->serverKey, $rawJson);
        $this->assertStringNotContainsString($this->clientKey, $rawJson);

        // 2. Secrets must not be stored in PaymentAttempt response_payload
        $attempts = $this->runForTenant(fn () => PaymentAttempt::where('payment_id', $paymentId)->get());
        foreach ($attempts as $attempt) {
            $payloadStr = json_encode($attempt->response_payload ?? []);
            $this->assertStringNotContainsString($this->serverKey, $payloadStr);
            $this->assertStringNotContainsString($this->clientKey, $payloadStr);
        }

        // 3. Secrets must not be stored in AuditLog
        $auditLogs = $this->runForTenant(fn () => AuditLog::where('entity_id', $paymentId)->get());
        foreach ($auditLogs as $log) {
            $logStr = json_encode($log->toArray());
            $this->assertStringNotContainsString($this->serverKey, $logStr);
            $this->assertStringNotContainsString($this->clientKey, $logStr);
        }
    }

    public function test_midtrans_webhook_cannot_be_injected_with_arbitrary_tenant(): void
    {
        // Second tenant
        $secondTenantData = $this->createTenant('Second Waroeng Injected');
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-tenant-inject-1',
            'transaction_status' => 'settlement',
            'currency' => 'IDR',
        ];

        // An attacker tries to send X-Tenant-ID header with Second Tenant's ID
        $response = $this->withHeader('X-Tenant-ID', $secondTenantData['tenant']->id)
            ->postJson('/api/v1/payments/webhooks/midtrans', $payload);

        $response->assertStatus(200);

        // Payment MUST belong to First Tenant and settle under First Tenant
        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals($this->tenantData['tenant']->id, $reloaded->tenant_id);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloaded->status);

        // Second tenant should NOT have this payment
        $secondPayment = app(TenantManager::class)->runForTenant($secondTenantData['tenant'], function () use ($payment) {
            return Payment::find($payment->id);
        });
        $this->assertNull($secondPayment);
    }

    public function test_midtrans_initiation_rejects_unsupported_currency(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        // Test in-memory attribute to verify adapter guard without violating DB constraint
        $payment->currency = 'USD';

        $provider = new MidtransPaymentProvider;
        $result = $provider->initiatePayment($payment);

        $this->assertEquals(Payment::STATUS_FAILED, $result['status']);
        $this->assertEquals('UNSUPPORTED_CURRENCY', $result['response_code']);
    }

    public function test_midtrans_initiation_bank_transfer_va_payload(): void
    {
        $sale = $this->createAndFinalizeSale(150000);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Success, Bank Transfer transaction is created',
                'transaction_id' => 'tx-va-bca-12345',
                'payment_type' => 'bank_transfer',
                'transaction_status' => 'pending',
                'va_numbers' => [
                    [
                        'bank' => 'bca',
                        'va_number' => '12345678901234',
                    ],
                ],
            ], 201),
        ]);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 150000,
                'method' => Payment::METHOD_BANK_TRANSFER,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $processed = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->processPayment($payment, ['bank' => 'bca']);
        });

        $this->assertEquals(Payment::STATUS_PROCESSING, $processed->status);
        $this->assertEquals('tx-va-bca-12345', $processed->provider_payment_id);
        $this->assertEquals('12345678901234', $processed->metadata['provider_result']['va_numbers'][0]['va_number']);

        Http::assertSent(function ($request) use ($payment) {
            return $request['payment_type'] === 'bank_transfer'
                && $request['bank_transfer']['bank'] === 'bca'
                && $request['transaction_details']['order_id'] === $payment->reference_number;
        });
    }

    public function test_midtrans_status_query_handles_timeout_non_destructively(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_PROCESSING]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => function () {
                throw new ConnectionException('Operation timed out');
            },
        ]);

        $synced = $this->runForTenant(fn () => $this->paymentService->syncPaymentStatus($payment));

        // State remains non-destructive
        $this->assertEquals(Payment::STATUS_PROCESSING, $synced->status);
    }

    public function test_midtrans_cannot_mutate_another_provider_payment(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        // Created under 'mock' provider
        $mockPayment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'mock',
            ], $this->cashier);
        });

        $orderId = $mockPayment->reference_number;
        $statusCode = '200';
        $grossAmount = '50000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-cross-provider',
            'transaction_status' => 'settlement',
            'currency' => 'IDR',
        ];

        // Midtrans webhook arrives for a mock payment
        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'payment_not_found');

        // Mock payment remains unaffected
        $reloaded = $this->runForTenant(fn () => Payment::find($mockPayment->id));
        $this->assertEquals(Payment::STATUS_PENDING, $reloaded->status);
    }

    public function test_midtrans_refund_request_accepted_becomes_processing(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        // Midtrans Direct Refund API returns HTTP 200 without bank_confirmed_at
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, refund request is accepted',
                'transaction_id' => 'tx-refund-accepted-1',
                'refund_charge_id' => 'MID-CH-12345',
                'refund_amount' => '40000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 40000, 'Customer order change', $this->manager);
        });

        // Refund request accepted -> processing
        $this->assertEquals(PaymentRefund::STATUS_PROCESSING, $refund->status);
        $this->assertEquals('MID-CH-12345', $refund->provider_reference);

        // Payment status must not be refunded yet
        $reloaded = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloaded->status);
        $this->assertEquals(100000, $reloaded->amount_minor); // Immutable
    }

    public function test_midtrans_refund_notification_without_bank_confirmation_stays_processing(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, refund request is accepted',
                'refund_charge_id' => 'MID-CH-55555',
                'refund_amount' => '40000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 40000, 'Partial return', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_PROCESSING, $refund->status);

        // Webhook arrives with refund event but WITHOUT bank_confirmed_at
        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-partial-refund-unconfirmed',
            'transaction_status' => 'partial_refund',
            'currency' => 'IDR',
            'refund_key' => $refund->reference_number,
            'refund_amount' => '40000.00',
            'refund_charge_id' => 'MID-CH-55555',
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);

        $reloadedRefund = $this->runForTenant(fn () => PaymentRefund::find($refund->id));
        $this->assertEquals(PaymentRefund::STATUS_PROCESSING, $reloadedRefund->status);
        $this->assertNull($reloadedRefund->bank_confirmed_at);

        $reloadedPayment = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloadedPayment->status);
    }

    public function test_midtrans_refund_notification_with_bank_confirmed_at_completes_refund(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, refund request is accepted',
                'refund_charge_id' => 'MID-CH-77777',
                'refund_amount' => '40000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 40000, 'Partial return', $this->manager);
        });

        // Webhook arrives WITH bank_confirmed_at
        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);
        $bankConfirmedTime = '2026-10-05 17:45:00';

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-partial-refund-confirmed',
            'transaction_status' => 'partial_refund',
            'currency' => 'IDR',
            'refund_key' => $refund->reference_number,
            'refund_amount' => '40000.00',
            'refund_charge_id' => 'MID-CH-77777',
            'bank_confirmed_at' => $bankConfirmedTime,
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);

        $reloadedRefund = $this->runForTenant(fn () => PaymentRefund::find($refund->id));
        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $reloadedRefund->status);
        $this->assertNotNull($reloadedRefund->bank_confirmed_at);
        $this->assertNotNull($reloadedRefund->completed_at);

        $reloadedPayment = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $reloadedPayment->status);
        $refundable = $this->runForTenant(fn () => $reloadedPayment->refundableAmountMinor());
        $this->assertEquals(60000, $refundable);
    }

    public function test_midtrans_full_refund_marks_payment_refunded(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'status_message' => 'Success, refund request is accepted',
                'refund_charge_id' => 'MID-CH-FULL',
                'refund_amount' => '100000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 100000, 'Full cancellation', $this->manager);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-full-refund',
            'transaction_status' => 'refund',
            'currency' => 'IDR',
            'refund_key' => $refund->reference_number,
            'refund_amount' => '100000.00',
            'refund_charge_id' => 'MID-CH-FULL',
            'bank_confirmed_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);

        $reloadedPayment = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_REFUNDED, $reloadedPayment->status);
        $this->assertEquals(0, $this->runForTenant(fn () => $reloadedPayment->refundableAmountMinor()));
        $this->assertEquals(100000, $reloadedPayment->amount_minor); // Immutable
    }

    public function test_midtrans_partial_refund_marks_payment_partially_refunded(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'refund_charge_id' => 'MID-CH-PARTIAL',
                'refund_amount' => '30000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 30000, 'Partial refund 30k', $this->manager);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-partial-refund-30k',
            'transaction_status' => 'partial_refund',
            'currency' => 'IDR',
            'refund_key' => $refund->reference_number,
            'refund_amount' => '30000.00',
            'refund_charge_id' => 'MID-CH-PARTIAL',
            'bank_confirmed_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);

        $reloadedPayment = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $reloadedPayment->status);
        $this->assertEquals(70000, $this->runForTenant(fn () => $reloadedPayment->refundableAmountMinor()));
        $this->assertEquals(100000, $reloadedPayment->amount_minor); // Immutable
    }

    public function test_midtrans_duplicate_refund_notification_is_ignored(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'refund_charge_id' => 'MID-CH-DUP',
                'refund_amount' => '40000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 40000, 'Duplicate test', $this->manager);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-refund-dup',
            'transaction_status' => 'partial_refund',
            'currency' => 'IDR',
            'refund_key' => $refund->reference_number,
            'refund_amount' => '40000.00',
            'bank_confirmed_at' => now()->toIso8601String(),
        ];

        // 1st delivery
        $res1 = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.status', 'processed');

        // 2nd delivery (duplicate replay)
        $res2 = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'duplicate_ignored');
    }

    public function test_midtrans_refund_amount_mismatch_is_rejected(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'refund_charge_id' => 'MID-CH-MISMATCH',
                'refund_amount' => '40000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 40000, 'Tamper test', $this->manager);
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        // Webhook claims refund amount is 99999 instead of 40000
        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-refund-mismatch',
            'transaction_status' => 'partial_refund',
            'currency' => 'IDR',
            'refund_key' => $refund->reference_number,
            'refund_amount' => '99999.00',
            'bank_confirmed_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(400);

        // State remains processing, not completed
        $reloadedRefund = $this->runForTenant(fn () => PaymentRefund::find($refund->id));
        $this->assertEquals(PaymentRefund::STATUS_PROCESSING, $reloadedRefund->status);
    }

    public function test_midtrans_wrong_refund_key_fails_safely(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        $orderId = $payment->reference_number;
        $statusCode = '200';
        $grossAmount = '100000.00';
        $signatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        $payload = [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signatureKey,
            'transaction_id' => 'tx-wrong-ref-key',
            'transaction_status' => 'partial_refund',
            'currency' => 'IDR',
            'refund_key' => 'REF-UNKNOWN-999999',
            'refund_amount' => '40000.00',
            'bank_confirmed_at' => now()->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/payments/webhooks/midtrans', $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'refund_not_found');

        $reloadedPayment = $this->runForTenant(fn () => Payment::find($payment->id));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $reloadedPayment->status);
    }

    public function test_midtrans_refund_unsupported_payment_method_throws_exception(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        // Bank Transfer (Virtual Account) payment
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_BANK_TRANSFER,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        $this->expectException(PaymentRefundNotSupportedException::class);

        $this->runForTenant(function () use ($payment) {
            $this->paymentService->refundPayment($payment, 50000, 'Attempt VA refund', $this->manager);
        });
    }

    public function test_midtrans_refund_network_timeout_preserves_processing_and_idempotency_key(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => function () {
                throw new ConnectionException('Midtrans refund gateway connection timeout');
            },
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 50000, 'Timeout refund attempt', $this->manager);
        });

        // State remains processing
        $this->assertEquals(PaymentRefund::STATUS_PROCESSING, $refund->status);
        $this->assertEquals('REFUND_NETWORK_TIMEOUT', $refund->metadata['response_code']);
        $this->assertNotNull($refund->reference_number); // Idempotency key retained for later reconciliation
    }

    public function test_midtrans_status_query_identifier_order_id_vs_transaction_id(): void
    {
        $provider = app(MidtransPaymentProvider::class);

        $sale = $this->createAndFinalizeSale(50000);
        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        $payment->provider_payment_id = 'tx-midtrans-id-999';

        // 1. Default: order_id
        $this->assertEquals($payment->reference_number, $provider->resolveStatusIdentifier($payment));

        // 2. Explicit use_transaction_id option
        $this->assertEquals('tx-midtrans-id-999', $provider->resolveStatusIdentifier($payment, ['use_transaction_id' => true]));

        // 3. DANA e-wallet requires transaction_id
        $this->assertEquals('tx-midtrans-id-999', $provider->resolveStatusIdentifier($payment, ['payment_type' => 'dana']));

        // 4. BI SNAP requires transaction_id
        $this->assertEquals('tx-midtrans-id-999', $provider->resolveStatusIdentifier($payment, ['is_snap' => true]));
    }

    public function test_midtrans_status_query_amount_mismatch_does_not_mutate_payment(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        // Status query returns gross_amount of 90000 instead of 50000
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'status_code' => '200',
                'order_id' => $payment->reference_number,
                'gross_amount' => '90000.00',
                'currency' => 'IDR',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $synced = $this->runForTenant(fn () => $this->paymentService->syncPaymentStatus($payment));

        // Must remain pending/processing, NOT mutated to succeeded
        $this->assertEquals(Payment::STATUS_PENDING, $synced->status);
    }

    public function test_midtrans_status_query_currency_mismatch_does_not_mutate_payment(): void
    {
        $sale = $this->createAndFinalizeSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);
        });

        // Status query returns currency USD instead of IDR
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'status_code' => '200',
                'order_id' => $payment->reference_number,
                'gross_amount' => '50000.00',
                'currency' => 'USD',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $synced = $this->runForTenant(fn () => $this->paymentService->syncPaymentStatus($payment));

        $this->assertEquals(Payment::STATUS_PENDING, $synced->status);
    }

    public function test_midtrans_status_query_reconciles_processing_refund(): void
    {
        $sale = $this->createAndFinalizeSale(100000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => Payment::METHOD_QRIS,
                'provider' => 'midtrans',
            ], $this->cashier);

            $p->update(['status' => Payment::STATUS_SUCCEEDED, 'succeeded_at' => now()]);

            return $p;
        });

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/refund' => Http::response([
                'status_code' => '200',
                'refund_charge_id' => 'MID-CH-SYNC-REF',
                'refund_amount' => '50000.00',
            ], 200),
        ]);

        $refund = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 50000, 'Refund to be synced', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_PROCESSING, $refund->status);

        // Now query status returns refund confirmation with bank_confirmed_at
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'status_code' => '200',
                'order_id' => $payment->reference_number,
                'gross_amount' => '100000.00',
                'currency' => 'IDR',
                'transaction_status' => 'partial_refund',
                'fraud_status' => 'accept',
                'refunds' => [
                    [
                        'refund_key' => $refund->reference_number,
                        'refund_charge_id' => 'MID-CH-SYNC-REF',
                        'refund_amount' => '50000.00',
                        'bank_confirmed_at' => now()->toIso8601String(),
                    ],
                ],
            ], 200),
        ]);

        $synced = $this->runForTenant(fn () => $this->paymentService->syncPaymentStatus($payment));

        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $synced->status);
        $reloadedRefund = $this->runForTenant(fn () => PaymentRefund::find($refund->id));
        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $reloadedRefund->status);
        $this->assertNotNull($reloadedRefund->bank_confirmed_at);
    }
}
