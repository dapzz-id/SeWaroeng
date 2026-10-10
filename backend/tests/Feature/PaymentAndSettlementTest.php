<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Exceptions\PaymentAmountExceedsOutstandingException;
use App\Exceptions\PaymentInvalidStateException;
use App\Exceptions\PaymentRefundExceedsPaidException;
use App\Exceptions\PaymentSaleNotFinalizedException;
use App\Models\Category;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Payment;
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
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PaymentAndSettlementTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $manager;

    protected User $staff;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Barokah Payment');
        $this->outlet = $this->tenantData['outlet'];

        // Create Cashier
        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Budi Kasir', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        // Create Manager
        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Dewi Manajer', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        // Create Staff (no payment permissions)
        $staffData = $this->addMemberToTenant($this->tenantData['tenant'], 'staff', 'Agus Staff', [$this->outlet->id]);
        $this->staff = $staffData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);

        // Setup kitchen location & configure default POS location
        $this->kitchenLocation = $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Main POS Kitchen',
                'code' => 'KIT-01',
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

    /**
     * Helper to create and finalize a sale with a given total.
     */
    protected function createAndFinalizeSale(int $unitPriceMinor = 50000, float $qty = 1): Sale
    {
        return $this->runForTenant(function () use ($unitPriceMinor, $qty) {
            $category = Category::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Minuman',
                'slug' => 'minuman-'.Str::random(5),
            ]);

            $product = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $category->id,
                'name' => 'Kopi Tubruk '.Str::random(4),
                'slug' => 'kopi-tubruk-'.Str::random(5),
                'product_type' => 'standard',
                'status' => 'active',
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $product->id,
                'sku' => 'SKU-'.strtoupper(Str::random(6)),
                'name' => 'Regular',
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

            // Raw material item
            $bean = InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'sku' => 'BEAN-'.strtoupper(Str::random(5)),
                'name' => 'Biji Kopi Robusta',
                'type' => 'raw_material',
                'uom' => 'g',
                'is_active' => true,
                'allow_negative_stock' => false,
            ]);

            InventoryItemLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'inventory_item_id' => $bean->id,
                'location_id' => $this->kitchenLocation->id,
                'on_hand' => 10000.0,
                'reserved' => 0.0,
            ]);

            InventoryCostState::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'inventory_item_id' => $bean->id,
                'location_id' => $this->kitchenLocation->id,
                'total_quantity' => 10000.0,
                'total_cost_minor' => 1000000,
                'average_cost_minor' => 100,
            ]);

            $recipe = Recipe::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_variant_id' => $variant->id,
                'code' => 'REC-KOPI-'.strtoupper(Str::random(4)),
                'name' => 'Resep Kopi',
                'owner_type' => 'product_variant',
                'owner_id' => $variant->id,
                'created_by' => $this->tenantData['owner']->id,
            ]);

            $recipeVersion = RecipeVersion::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_id' => $recipe->id,
                'version_number' => 1,
                'status' => 'published',
                'yield_quantity' => 1,
                'yield_unit' => 'portion',
                'created_by' => $this->tenantData['owner']->id,
                'effective_from' => now()->subDay(),
            ]);

            RecipeComponent::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'recipe_version_id' => $recipeVersion->id,
                'inventory_item_id' => $bean->id,
                'quantity' => 15.0,
                'unit' => 'g',
            ]);

            $sale = $this->posService->createSale(
                tenantId: $this->tenantData['tenant']->id,
                outletId: $this->outlet->id,
                cashierUserId: $this->cashier->id,
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
                userId: $this->cashier->id,
                tenantId: $this->tenantData['tenant']->id
            );
        });
    }

    public function test_cash_payment_against_finalized_sale_succeeds_immediately(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $sale = $this->createAndFinalizeSale(50000, 1);
        $this->assertEquals(50000, $sale->total_minor);
        $this->assertEquals('finalized', $sale->status);

        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'pay-cash-test-1',
            ])
            ->postJson('/api/v1/payments', [
                'sale_id' => $sale->id,
                'amount_minor' => 50000,
                'method' => 'cash',
            ]);

        $response->assertStatus(201);
        $paymentId = $response->json('data.id');

        $payment = $this->runForTenant(fn () => Payment::find($paymentId));
        $this->assertEquals(Payment::STATUS_PENDING, $payment->status);

        // Process payment
        $processResponse = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'process-cash-test-1',
            ])
            ->postJson("/api/v1/payments/{$paymentId}/process");

        $processResponse->assertStatus(200);
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $processResponse->json('data.status'));

        // Verify Sale and Payment integrity
        $freshSale = $this->runForTenant(fn () => $sale->fresh());
        $this->assertEquals(Sale::STATUS_FINALIZED, $freshSale->status); // Sale status remains finalized!
        $this->assertEquals(50000, $this->runForTenant(fn () => $freshSale->getPaidAmountMinor()));
        $this->assertEquals(0, $this->runForTenant(fn () => $freshSale->getOutstandingAmountMinor()));

        $summary = $this->runForTenant(fn () => $this->paymentService->getSalePaymentSummary($freshSale));
        $this->assertEquals('paid', $summary['settlement_status']);
        $this->assertEquals(0, $summary['outstanding_minor']);
        $this->assertEquals(50000, $summary['paid_minor']);

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_payment_against_open_sale_is_rejected(): void
    {
        $sale = $this->runForTenant(function () {
            return Sale::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'reference_number' => 'SALE-TEST-OPEN',
                'status' => Sale::STATUS_OPEN,
                'cashier_user_id' => $this->cashier->id,
                'total_minor' => 50000,
            ]);
        });

        $this->expectException(PaymentSaleNotFinalizedException::class);

        $this->runForTenant(function () use ($sale) {
            $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);
        });
    }

    public function test_payment_against_cancelled_sale_is_rejected(): void
    {
        $sale = $this->runForTenant(function () {
            return Sale::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'reference_number' => 'SALE-TEST-CANCELLED',
                'status' => Sale::STATUS_CANCELLED,
                'cashier_user_id' => $this->cashier->id,
                'total_minor' => 50000,
            ]);
        });

        $this->expectException(PaymentSaleNotFinalizedException::class);

        $this->runForTenant(function () use ($sale) {
            $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);
        });
    }

    public function test_overpayment_is_rejected(): void
    {
        $sale = $this->createAndFinalizeSale(50000, 1);

        $this->expectException(PaymentAmountExceedsOutstandingException::class);

        $this->runForTenant(function () use ($sale) {
            $this->paymentService->createPayment($sale, [
                'amount_minor' => 60000, // Exceeds 50000
                'method' => 'cash',
            ], $this->cashier);
        });
    }

    public function test_split_payment_with_cash_and_qris(): void
    {
        $sale = $this->createAndFinalizeSale(100000, 1);
        $this->assertEquals(100000, $sale->getOutstandingAmountMinor());

        // Payment 1: Cash Rp40.000
        $payment1 = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 40000,
                'method' => 'cash',
                'provider' => 'manual',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment1->status);
        $this->assertEquals(60000, $this->runForTenant(fn () => $sale->fresh()->getOutstandingAmountMinor()));

        // Payment 2: QRIS Rp60.000 (Mock provider)
        $payment2 = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 60000,
                'method' => 'qris',
                'provider' => 'mock',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment2->status);

        $freshSale = $this->runForTenant(fn () => $sale->fresh());
        $this->assertEquals(100000, $this->runForTenant(fn () => $freshSale->getPaidAmountMinor()));
        $this->assertEquals(0, $this->runForTenant(fn () => $freshSale->getOutstandingAmountMinor()));

        $summary = $this->runForTenant(fn () => $this->paymentService->getSalePaymentSummary($freshSale));
        $this->assertEquals('paid', $summary['settlement_status']);
        $this->assertEquals(2, $summary['payments_count']);
    }

    public function test_concurrent_payment_attempts_prevent_overpayment(): void
    {
        $sale = $this->createAndFinalizeSale(100000, 1);

        // Attempt A creates payment for Rp70.000 and processes it
        $this->runForTenant(function () use ($sale) {
            $p1 = $this->paymentService->createPayment($sale, [
                'amount_minor' => 70000,
                'method' => 'cash',
            ], $this->cashier);
            $this->paymentService->processPayment($p1);
        });

        // Attempt B for Rp70.000 must fail because only Rp30.000 is outstanding
        $this->expectException(PaymentAmountExceedsOutstandingException::class);

        $this->runForTenant(function () use ($sale) {
            $this->paymentService->createPayment($sale, [
                'amount_minor' => 70000,
                'method' => 'cash',
            ], $this->cashier);
        });
    }

    public function test_mock_qris_async_processing_and_webhook_transition(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $sale = $this->createAndFinalizeSale(85000, 1);

        // Create async QRIS payment
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 85000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_PROCESSING, $payment->status);
        $this->assertNotNull($payment->provider_payment_id);

        // Deliver webhook with valid signature
        $secret = config('services.mock_payment.webhook_secret', 'mock-webhook-secret');
        $payload = [
            'event_id' => 'evt_'.Str::random(10),
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 85000,
        ];
        $rawJson = json_encode($payload);
        $signature = hash_hmac('sha256', $rawJson, $secret);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            content: $rawJson
        );

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.processed'));

        // Check payment transitioned to succeeded
        $freshPayment = $this->runForTenant(fn () => $payment->fresh());
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $freshPayment->status);
        $this->assertEquals(0, $this->runForTenant(fn () => $sale->fresh()->getOutstandingAmountMinor()));

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $payload = [
            'event_id' => 'evt_'.Str::random(10),
            'provider_payment_id' => 'MOCK-123',
            'status' => 'succeeded',
        ];
        $rawJson = json_encode($payload);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => 'invalid-signature-value',
            ],
            content: $rawJson
        );

        $response->assertStatus(400);
        $this->assertEquals('WEBHOOK_SIGNATURE_INVALID', $response->json('code'));
    }

    public function test_webhook_duplicate_event_is_idempotent(): void
    {
        $sale = $this->createAndFinalizeSale(75000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 75000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $secret = config('services.mock_payment.webhook_secret', 'mock-webhook-secret');
        $payload = [
            'event_id' => 'evt_idempotent_123',
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 75000,
        ];
        $rawJson = json_encode($payload);
        $signature = hash_hmac('sha256', $rawJson, $secret);

        // First delivery
        $res1 = $this->call(
            method: 'POST',
            uri: '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            content: $rawJson
        );
        $res1->assertStatus(200);
        $this->assertEquals('processed', $res1->json('data.status'));

        // Second delivery (replay with same event_id)
        $res2 = $this->call(
            method: 'POST',
            uri: '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            content: $rawJson
        );
        $res2->assertStatus(200);
        $this->assertEquals('duplicate_ignored', $res2->json('data.status'));

        $this->assertEquals(1, PaymentWebhookEvent::where('external_event_id', 'evt_idempotent_123')->count());
    }

    public function test_webhook_out_of_order_monotonic_guard(): void
    {
        $sale = $this->createAndFinalizeSale(50000, 1);

        // Payment already succeeded
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'qris',
                'provider' => 'mock',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->status);

        // Delayed webhook arriving late claiming 'processing'
        $secret = config('services.mock_payment.webhook_secret', 'mock-webhook-secret');
        $payload = [
            'event_id' => 'evt_late_'.Str::random(6),
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_PROCESSING,
        ];
        $rawJson = json_encode($payload);
        $signature = hash_hmac('sha256', $rawJson, $secret);

        $res = $this->call(
            method: 'POST',
            uri: '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            content: $rawJson
        );

        $res->assertStatus(200);
        $this->assertEquals('out_of_order_ignored', $res->json('data.status'));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $this->runForTenant(fn () => $payment->fresh()->status)); // Protected from regressing
    }

    public function test_refund_full_and_partial_semantics(): void
    {
        Event::fake([PaymentRefunded::class]);

        $sale = $this->createAndFinalizeSale(100000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => 'cash',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(100000, $this->runForTenant(fn () => $payment->fresh()->refundableAmountMinor()));

        // Partial Refund Rp30.000
        $refund1 = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment, 30000, 'Customer returned 1 item', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $refund1->status);
        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $this->runForTenant(fn () => $payment->fresh()->status));
        $this->assertEquals(70000, $this->runForTenant(fn () => $payment->fresh()->refundableAmountMinor()));
        $this->assertEquals(30000, $this->runForTenant(fn () => $sale->fresh()->getOutstandingAmountMinor()));

        // Second Refund Rp70.000 (Remaining balance)
        $refund2 = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->refundPayment($payment->fresh(), 70000, 'Customer returned remaining items', $this->manager);
        });

        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $refund2->status);
        $this->assertEquals(Payment::STATUS_REFUNDED, $this->runForTenant(fn () => $payment->fresh()->status));
        $this->assertEquals(0, $this->runForTenant(fn () => $payment->fresh()->refundableAmountMinor()));
        $this->assertEquals(100000, $this->runForTenant(fn () => $sale->fresh()->getOutstandingAmountMinor()));

        // Third refund must fail because refundable is 0
        $this->expectException(PaymentRefundExceedsPaidException::class);
        $this->runForTenant(function () use ($payment) {
            $this->paymentService->refundPayment($payment->fresh(), 1000, 'Extra refund', $this->manager);
        });
    }

    public function test_refund_cannot_exceed_paid_amount(): void
    {
        $sale = $this->createAndFinalizeSale(50000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->expectException(PaymentRefundExceedsPaidException::class);
        $this->runForTenant(function () use ($payment) {
            $this->paymentService->refundPayment($payment, 60000, 'Invalid over-refund', $this->manager);
        });
    }

    public function test_cancelled_payment_lifecycle(): void
    {
        $sale = $this->createAndFinalizeSale(50000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);
        });

        $cancelled = $this->runForTenant(function () use ($payment) {
            return $this->paymentService->cancelPayment($payment, 'Customer changed payment method');
        });

        $this->assertEquals(Payment::STATUS_CANCELLED, $cancelled->status);

        // Cannot cancel succeeded payment
        $succeededPayment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->expectException(PaymentInvalidStateException::class);
        $this->runForTenant(function () use ($succeededPayment) {
            $this->paymentService->cancelPayment($succeededPayment);
        });
    }

    public function test_tenant_isolation_and_rls_on_payments(): void
    {
        // Tenant A creates a payment
        $saleA = $this->createAndFinalizeSale(50000, 1);
        $paymentA = $this->runForTenant(function () use ($saleA) {
            $p = $this->paymentService->createPayment($saleA, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // Tenant B setup
        $tenantB = $this->createTenant('Tenant B Cafe');
        $managerBData = $this->addMemberToTenant($tenantB['tenant'], 'manager', 'Manager B', [$tenantB['outlet']->id]);
        $managerB = $managerBData['user'];

        // Tenant B cashier attempts to query Tenant A's payment
        $response = $this->actingAs($managerB)
            ->withHeaders(['X-Tenant-ID' => $tenantB['tenant']->id])
            ->getJson("/api/v1/payments/{$paymentA->id}");

        $response->assertStatus(404);
    }

    public function test_rbac_permissions_cashier_vs_manager(): void
    {
        $sale = $this->createAndFinalizeSale(50000, 1);

        // Cashier can create payment
        $resCreate = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'rbac-cashier-create-1',
            ])
            ->postJson('/api/v1/payments', [
                'sale_id' => $sale->id,
                'amount_minor' => 50000,
                'method' => 'cash',
            ]);
        $resCreate->assertStatus(201);
        $paymentId = $resCreate->json('data.id');

        // Cashier can process payment
        $resProcess = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'rbac-cashier-process-1',
            ])
            ->postJson("/api/v1/payments/{$paymentId}/process");
        $resProcess->assertStatus(200);

        // Cashier CANNOT refund payment (Forbidden 403)
        $resRefundCashier = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'rbac-cashier-refund-1',
            ])
            ->postJson("/api/v1/payments/{$paymentId}/refund", [
                'amount_minor' => 10000,
                'reason' => 'Unauthorized refund attempt',
            ]);
        $resRefundCashier->assertStatus(403);

        // Manager CAN refund payment (Status 201)
        $resRefundManager = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'rbac-manager-refund-1',
            ])
            ->postJson("/api/v1/payments/{$paymentId}/refund", [
                'amount_minor' => 10000,
                'reason' => 'Authorized manager refund',
            ]);
        $resRefundManager->assertStatus(201);
    }
}
