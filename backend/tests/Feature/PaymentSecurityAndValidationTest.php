<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentSucceeded;
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
use App\Services\Payment\Providers\ManualCashPaymentProvider;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PaymentSecurityAndValidationTest extends TenantTestCase
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

    protected string $webhookSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Security Payment');
        $this->outlet = $this->tenantData['outlet'];

        // Create Cashier
        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Budi Kasir', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        // Create Manager
        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Dewi Manajer', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        // Create Staff
        $staffData = $this->addMemberToTenant($this->tenantData['tenant'], 'staff', 'Agus Staff', [$this->outlet->id]);
        $this->staff = $staffData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
        $this->webhookSecret = config('services.mock_payment.webhook_secret', 'mock-webhook-secret');

        // Setup kitchen location & configure default POS location
        $this->kitchenLocation = $this->runForTenant(function () {
            $loc = InventoryLocation::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Main Security Kitchen',
                'code' => 'KIT-SEC',
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
        int $unitPriceMinor = 100000,
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

    protected function signPayload(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->webhookSecret);
    }

    /**
     * Requirement 1 & 13: Webhook Amount Validation & Tampering Test Matrix
     */
    public function test_webhook_amount_tampering_matrix_cases(): void
    {
        $sale = $this->createAndFinalizeSale(100000, 1);

        // Case A: Exact match (Rp100.000 vs Rp100.000) -> Accepted
        $paymentA = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $payloadA = [
            'event_id' => 'evt_case_a_'.Str::random(6),
            'provider_payment_id' => $paymentA->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 100000,
            'currency' => 'IDR',
        ];
        $resA = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadA),
            ],
            content: json_encode($payloadA)
        );
        $resA->assertStatus(200);
        $this->assertEquals('processed', $resA->json('data.status'));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $this->runForTenant(fn () => $paymentA->fresh()->status));

        // Create new sale for Case B
        $saleB = $this->createAndFinalizeSale(100000, 1);
        $paymentB = $this->runForTenant(function () use ($saleB) {
            $p = $this->paymentService->createPayment($saleB, [
                'amount_minor' => 100000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // Case B: Undershoot (Rp99.999 vs Rp100.000) -> Rejected
        $payloadB = [
            'event_id' => 'evt_case_b_'.Str::random(6),
            'provider_payment_id' => $paymentB->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 99999,
            'currency' => 'IDR',
        ];
        $resB = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadB),
            ],
            content: json_encode($payloadB)
        );
        $resB->assertStatus(400);
        $this->assertEquals('PAYMENT_WEBHOOK_AMOUNT_MISMATCH', $resB->json('code'));
        $this->assertEquals(Payment::STATUS_PROCESSING, $this->runForTenant(fn () => $paymentB->fresh()->status));

        // Case C: Overshoot (Rp100.001 vs Rp100.000) -> Rejected
        $payloadC = [
            'event_id' => 'evt_case_c_'.Str::random(6),
            'provider_payment_id' => $paymentB->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 100001,
            'currency' => 'IDR',
        ];
        $resC = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadC),
            ],
            content: json_encode($payloadC)
        );
        $resC->assertStatus(400);
        $this->assertEquals('PAYMENT_WEBHOOK_AMOUNT_MISMATCH', $resC->json('code'));
        $this->assertEquals(Payment::STATUS_PROCESSING, $this->runForTenant(fn () => $paymentB->fresh()->status));

        // Case D: Missing amount on success webhook -> Rejected
        $payloadD = [
            'event_id' => 'evt_case_d_'.Str::random(6),
            'provider_payment_id' => $paymentB->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            // 'amount_minor' deliberately omitted
            'currency' => 'IDR',
        ];
        $resD = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadD),
            ],
            content: json_encode($payloadD)
        );
        $resD->assertStatus(400);
        $this->assertEquals('PAYMENT_WEBHOOK_AMOUNT_MISMATCH', $resD->json('code'));
        $this->assertEquals(Payment::STATUS_PROCESSING, $this->runForTenant(fn () => $paymentB->fresh()->status));

        // Case E: Currency mismatch (IDR vs USD) -> Rejected with PAYMENT_WEBHOOK_CURRENCY_MISMATCH
        $payloadE = [
            'event_id' => 'evt_case_e_'.Str::random(6),
            'provider_payment_id' => $paymentB->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 100000,
            'currency' => 'USD',
        ];
        $resE = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadE),
            ],
            content: json_encode($payloadE)
        );
        $resE->assertStatus(400);
        $this->assertEquals('PAYMENT_WEBHOOK_CURRENCY_MISMATCH', $resE->json('code'));
        $this->assertEquals(Payment::STATUS_PROCESSING, $this->runForTenant(fn () => $paymentB->fresh()->status));
    }

    /**
     * Requirement 6: Webhook Idempotency Lifecycle with Amount Mismatch & Retry
     */
    public function test_webhook_idempotency_lifecycle_with_amount_mismatch_and_retry(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $sale = $this->createAndFinalizeSale(100000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // 1. Mismatched amount first delivery -> rejected (HTTP 400, PAYMENT_WEBHOOK_AMOUNT_MISMATCH)
        $mismatchedPayload = [
            'event_id' => 'evt_tampered_101',
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 50000, // Mismatch
        ];
        $res1 = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($mismatchedPayload),
            ],
            content: json_encode($mismatchedPayload)
        );
        $res1->assertStatus(400);
        $this->assertEquals('PAYMENT_WEBHOOK_AMOUNT_MISMATCH', $res1->json('code'));

        // 2. Retry SAME mismatched event -> deterministic rejection
        $res2 = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($mismatchedPayload),
            ],
            content: json_encode($mismatchedPayload)
        );
        $res2->assertStatus(400);
        $this->assertEquals('PAYMENT_WEBHOOK_AMOUNT_MISMATCH', $res2->json('code'));
        $this->assertEquals(Payment::STATUS_PROCESSING, $this->runForTenant(fn () => $payment->fresh()->status));

        // 3. Corrected provider event with new external_event_id -> valid
        $correctedPayload = [
            'event_id' => 'evt_corrected_102',
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 100000,
        ];
        $res3 = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($correctedPayload),
            ],
            content: json_encode($correctedPayload)
        );
        $res3->assertStatus(200);
        $this->assertEquals('processed', $res3->json('data.status'));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $this->runForTenant(fn () => $payment->fresh()->status));

        // 4. Duplicate replay of valid event -> duplicate_ignored
        $res4 = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($correctedPayload),
            ],
            content: json_encode($correctedPayload)
        );
        $res4->assertStatus(200);
        $this->assertEquals('duplicate_ignored', $res4->json('data.status'));

        // PaymentSucceeded event emitted exactly once
        Event::assertDispatched(PaymentSucceeded::class, 1);
    }

    /**
     * Requirement 7: Monotonic State Transition Guards
     */
    public function test_webhook_state_transition_guards_enforce_monotonic_rules(): void
    {
        $sale = $this->createAndFinalizeSale(100000, 1);

        // 1. Succeeded payment:
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => 'qris',
                'provider' => 'mock',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->status);

        // Attempt succeeded -> failed via mock webhook
        $payloadSucceededToFailed = [
            'event_id' => 'evt_guard_'.Str::random(6),
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_FAILED,
            'amount_minor' => 100000,
        ];
        $res = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadSucceededToFailed),
            ],
            content: json_encode($payloadSucceededToFailed)
        );
        $res->assertStatus(200);
        $this->assertEquals('out_of_order_ignored', $res->json('data.status'));
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $this->runForTenant(fn () => $payment->fresh()->status));

        // 2. Refunded payment:
        $this->runForTenant(function () use ($payment) {
            $this->paymentService->refundPayment($payment, 100000, 'Customer full return', $this->manager);
        });
        $this->assertEquals(Payment::STATUS_REFUNDED, $this->runForTenant(fn () => $payment->fresh()->status));

        // Attempt refunded -> succeeded
        $payloadRefundedToSucceeded = [
            'event_id' => 'evt_guard_'.Str::random(6),
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 100000,
        ];
        $resRefunded = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadRefundedToSucceeded),
            ],
            content: json_encode($payloadRefundedToSucceeded)
        );
        $resRefunded->assertStatus(200);
        $this->assertEquals('out_of_order_ignored', $resRefunded->json('data.status'));
        $this->assertEquals(Payment::STATUS_REFUNDED, $this->runForTenant(fn () => $payment->fresh()->status));

        // Attempt refunded -> processing
        $payloadRefundedToProcessing = [
            'event_id' => 'evt_guard_'.Str::random(6),
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_PROCESSING,
            'amount_minor' => 100000,
        ];
        $resProc = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payloadRefundedToProcessing),
            ],
            content: json_encode($payloadRefundedToProcessing)
        );
        $resProc->assertStatus(200);
        $this->assertEquals('out_of_order_ignored', $resProc->json('data.status'));
        $this->assertEquals(Payment::STATUS_REFUNDED, $this->runForTenant(fn () => $payment->fresh()->status));
    }

    /**
     * Requirement 8: Cash Provider Webhook Rejection
     */
    public function test_cash_provider_webhook_rejection(): void
    {
        $cashProvider = new ManualCashPaymentProvider;
        $mockRequest = Request::create('/api/v1/payments/webhooks/manual', 'POST');

        // 1. verifyWebhookSignature() must return false
        $this->assertFalse($cashProvider->verifyWebhookSignature($mockRequest));

        // 2. Webhook endpoint cannot convert a cash payment
        $sale = $this->createAndFinalizeSale(50000, 1);
        $cashPayment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
                'provider' => 'manual',
            ], $this->cashier);
        });

        $this->assertEquals(Payment::STATUS_PENDING, $cashPayment->status);

        $payload = [
            'payment_id' => $cashPayment->id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 50000,
        ];

        $resManual = $this->call(
            'POST',
            '/api/v1/payments/webhooks/manual',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );
        $resManual->assertStatus(400);
        $this->assertEquals('WEBHOOK_SIGNATURE_INVALID', $resManual->json('code'));

        $resCash = $this->call(
            'POST',
            '/api/v1/payments/webhooks/cash',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );
        $resCash->assertStatus(400);
        $this->assertEquals('WEBHOOK_SIGNATURE_INVALID', $resCash->json('code'));

        // Payment remains pending, not mutated by unauthenticated webhook
        $this->assertEquals(Payment::STATUS_PENDING, $this->runForTenant(fn () => $cashPayment->fresh()->status));
    }

    /**
     * Requirement 9: Unsupported Provider Behavior
     */
    public function test_unsupported_provider_behavior(): void
    {
        $unsupportedProviders = ['qris', 'card', 'bank_transfer', 'e_wallet'];
        $sale = $this->createAndFinalizeSale(100000, 1);

        foreach ($unsupportedProviders as $providerName) {
            $payment = $this->runForTenant(function () use ($sale, $providerName) {
                return $this->paymentService->createPayment($sale, [
                    'amount_minor' => 20000,
                    'method' => $providerName,
                    'provider' => $providerName,
                ], $this->cashier);
            });

            // HTTP API call to process payment
            $res = $this->actingAs($this->cashier)
                ->withHeaders([
                    'X-Tenant-ID' => $this->tenantData['tenant']->id,
                    'Idempotency-Key' => "unsupported-{$providerName}-1",
                ])
                ->postJson("/api/v1/payments/{$payment->id}/process");

            $res->assertStatus(400);
            $this->assertEquals('UNSUPPORTED_PAYMENT_PROVIDER', $res->json('code'));

            // Verify No Payment mutation
            $freshPayment = $this->runForTenant(fn () => $payment->fresh());
            $this->assertEquals(Payment::STATUS_PENDING, $freshPayment->status);

            // Verify No PaymentAttempt success
            $this->assertEquals(0, $freshPayment->attempts()->where('status', PaymentAttempt::STATUS_SUCCEEDED)->count());

            // Verify No settlement mutation
            $summary = $this->runForTenant(fn () => $this->paymentService->getSalePaymentSummary($sale->fresh()));
            $this->assertEquals(0, $summary['paid_minor']);
            $this->assertEquals('unpaid', $summary['settlement_status']);
        }
    }

    /**
     * Requirement 10: Cashier Cancel RBAC
     */
    public function test_cashier_cancel_rbac(): void
    {
        $sale = $this->createAndFinalizeSale(50000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            return $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'cash',
            ], $this->cashier);
        });

        // 1. Cashier attempts cancel -> 403 Forbidden
        $resCashier = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'cancel-cashier-1',
            ])
            ->postJson("/api/v1/payments/{$payment->id}/cancel", [
                'reason' => 'Cashier attempt cancel',
            ]);
        $resCashier->assertStatus(403);
        $this->assertEquals(Payment::STATUS_PENDING, $this->runForTenant(fn () => $payment->fresh()->status));

        // 2. Manager attempts cancel -> 200 OK
        $resManager = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'cancel-manager-1',
            ])
            ->postJson("/api/v1/payments/{$payment->id}/cancel", [
                'reason' => 'Manager authorized cancel',
            ]);
        $resManager->assertStatus(200);
        $this->assertEquals(Payment::STATUS_CANCELLED, $this->runForTenant(fn () => $payment->fresh()->status));
    }

    /**
     * Requirement 11: Settlement Partially Refunded to Fully Refunded
     */
    public function test_settlement_partially_refunded_to_fully_refunded(): void
    {
        $sale = $this->createAndFinalizeSale(100000, 1);

        // Successful payment: Rp100.000
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => 'cash',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $this->assertEquals(Payment::STATUS_SUCCEEDED, $payment->status);
        $summaryInitial = $this->runForTenant(fn () => $this->paymentService->getSalePaymentSummary($sale->fresh()));
        $this->assertEquals('paid', $summaryInitial['settlement_status']);

        // Refund: Rp30.000
        $this->runForTenant(function () use ($payment) {
            $this->paymentService->refundPayment($payment, 30000, 'Customer return partial', $this->manager);
        });

        $freshPayment1 = $this->runForTenant(fn () => $payment->fresh());
        $summary1 = $this->runForTenant(fn () => $this->paymentService->getSalePaymentSummary($sale->fresh()));

        $this->assertEquals(Payment::STATUS_PARTIALLY_REFUNDED, $freshPayment1->status);
        $this->assertEquals('partially_refunded', $summary1['settlement_status']);
        $this->assertEquals(70000, $summary1['net_paid_minor']);

        // Then refund: Rp70.000
        $this->runForTenant(function () use ($freshPayment1) {
            $this->paymentService->refundPayment($freshPayment1, 70000, 'Customer return remainder', $this->manager);
        });

        $freshPayment2 = $this->runForTenant(fn () => $payment->fresh());
        $summary2 = $this->runForTenant(fn () => $this->paymentService->getSalePaymentSummary($sale->fresh()));

        $this->assertEquals(Payment::STATUS_REFUNDED, $freshPayment2->status);
        $this->assertEquals('refunded', $summary2['settlement_status']);
        $this->assertEquals(0, $summary2['net_paid_minor']);
    }

    /**
     * Requirement 12: Refund Idempotency and Overshoot Protection
     */
    public function test_refund_idempotency_and_overshoot(): void
    {
        $sale = $this->createAndFinalizeSale(100000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 100000,
                'method' => 'cash',
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // 1. Initial refund
        $res1 = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'idemp-refund-key-1',
            ])
            ->postJson("/api/v1/payments/{$payment->id}/refund", [
                'amount_minor' => 30000,
                'reason' => 'First partial refund',
            ]);
        $res1->assertStatus(201);
        $refundId1 = $res1->json('data.id');

        // 2. Duplicate same request -> replay without duplicate record
        $res2 = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'idemp-refund-key-1',
            ])
            ->postJson("/api/v1/payments/{$payment->id}/refund", [
                'amount_minor' => 30000,
                'reason' => 'First partial refund',
            ]);
        $res2->assertStatus(201);
        $this->assertEquals($refundId1, $res2->json('data.id'));
        $this->assertEquals(1, $this->runForTenant(fn () => PaymentRefund::where('payment_id', $payment->id)->count()));

        // 3. Same key with different request payload -> 409 Conflict
        $res3 = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'idemp-refund-key-1',
            ])
            ->postJson("/api/v1/payments/{$payment->id}/refund", [
                'amount_minor' => 40000,
                'reason' => 'Tampered payload with same key',
            ]);
        $res3->assertStatus(409);
        $this->assertEquals('IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD', $res3->json('code'));

        // 4. Refund overshoot remains rejected
        $resOvershoot = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => 'idemp-refund-overshoot',
            ])
            ->postJson("/api/v1/payments/{$payment->id}/refund", [
                'amount_minor' => 90000, // Remaining refundable is 70.000
                'reason' => 'Overshoot refund',
            ]);
        $resOvershoot->assertStatus(422);
        $this->assertEquals('PAYMENT_REFUND_EXCEEDS_PAID', $resOvershoot->json('code'));
    }

    /**
     * Requirement 4 & 5: Cross-Tenant Webhook Injection & Platform Context Security
     */
    public function test_cross_tenant_webhook_injection_and_platform_context_security(): void
    {
        // Tenant A: Payment A
        $saleA = $this->createAndFinalizeSale(50000, 1);
        $paymentA = $this->runForTenant(function () use ($saleA) {
            $p = $this->paymentService->createPayment($saleA, [
                'amount_minor' => 50000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        // Tenant B: Payment B
        $tenantB = $this->createTenant('Tenant B Cafe');
        $managerBData = $this->addMemberToTenant($tenantB['tenant'], 'manager', 'Manager B', [$tenantB['outlet']->id]);
        $managerB = $managerBData['user'];

        $locB = app(TenantManager::class)->runForTenant($tenantB['tenant'], function () use ($tenantB) {
            $loc = InventoryLocation::create([
                'tenant_id' => $tenantB['tenant']->id,
                'outlet_id' => $tenantB['outlet']->id,
                'name' => 'Kitchen B',
                'code' => 'KIT-B',
                'type' => 'kitchen',
                'is_active' => true,
            ]);
            $tenantB['outlet']->update(['default_pos_location_id' => $loc->id]);

            return $loc;
        });

        $saleB = $this->createAndFinalizeSale(60000, 1, $tenantB, $managerB, $locB);
        $paymentB = app(TenantManager::class)->runForTenant($tenantB['tenant'], function () use ($saleB, $managerB) {
            $pB = $this->paymentService->createPayment($saleB, [
                'amount_minor' => 60000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $managerB);

            return $this->paymentService->processPayment($pB);
        });

        // Attacker attempts to forge X-Tenant-ID: Tenant A on webhook targeting Payment B
        $attackerPayload = [
            'event_id' => 'evt_cross_tenant_1',
            'provider_payment_id' => $paymentB->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 60000,
        ];

        $res = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($attackerPayload),
                'HTTP_X_TENANT_ID' => $this->tenantData['tenant']->id, // Injected header
            ],
            content: json_encode($attackerPayload)
        );
        $res->assertStatus(200);

        // Verification:
        // 1. Payment A in Tenant A is NOT mutated
        $this->assertEquals(Payment::STATUS_PROCESSING, $this->runForTenant(fn () => $paymentA->fresh()->status));

        // 2. Payment B in Tenant B is transitioned inside Tenant B's boundary
        $freshPB = app(TenantManager::class)->runForTenant($tenantB['tenant'], fn () => $paymentB->fresh());
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $freshPB->status);

        // 3. Tenant A cannot see Payment B
        $resQueryB = $this->actingAs($this->manager)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->getJson("/api/v1/payments/{$paymentB->id}");
        $resQueryB->assertStatus(404);

        // 4. Regression test: platform context is cleared in finally
        $platformContextSetting = DB::selectOne("SELECT NULLIF(current_setting('app.is_platform_context', true), '') as ctx");
        $this->assertNull($platformContextSetting->ctx);
    }

    /**
     * Requirement 14 & 15: Payment Attempt Integrity and Historical Immutability
     */
    public function test_payment_attempt_integrity_and_historical_immutability(): void
    {
        $sale = $this->createAndFinalizeSale(80000, 1);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 80000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $initialAttempt = $this->runForTenant(fn () => $payment->attempts()->first());
        $this->assertNotNull($initialAttempt);
        $this->assertEquals(PaymentAttempt::STATUS_PROCESSING, $initialAttempt->status);

        // Process webhook
        $payload = [
            'event_id' => 'evt_attempt_test_1',
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 80000,
            'response_code' => 'QRIS_PAID',
        ];

        $res = $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payload),
            ],
            content: json_encode($payload)
        );
        $res->assertStatus(200);

        // Verify Attempt Integrity
        $freshAttempt = $this->runForTenant(fn () => $initialAttempt->fresh());
        $this->assertEquals(PaymentAttempt::STATUS_SUCCEEDED, $freshAttempt->status);
        $this->assertNotNull($freshAttempt->completed_at);
        $this->assertEquals('QRIS_PAID', $freshAttempt->response_code);

        // Verify Historical Immutability of Payment
        $freshPayment = $this->runForTenant(fn () => $payment->fresh());
        $this->assertEquals(80000, $freshPayment->amount_minor);
        $this->assertEquals(Payment::CURRENCY_IDR, $freshPayment->currency);
        $this->assertEquals('qris', $freshPayment->method);
        $this->assertEquals('mock', $freshPayment->provider);
        $this->assertEquals($payment->provider_payment_id, $freshPayment->provider_payment_id);
    }

    /**
     * Requirement 16: Sensitive Data Redaction
     */
    public function test_sensitive_data_redaction(): void
    {
        $sensitiveData = [
            'card_number' => '4111222233334444',
            'pan' => '1234567890123456',
            'cvv' => '123',
            'api_secret' => 'super_secret_key',
            'private_key' => '-----BEGIN PRIVATE KEY-----...',
            'safe_field' => 'visible_reference_123',
        ];

        $sanitized = AuditLog::sanitizePayload($sensitiveData);

        $this->assertEquals('[REDACTED]', $sanitized['card_number']);
        $this->assertEquals('[REDACTED]', $sanitized['pan']);
        $this->assertEquals('[REDACTED]', $sanitized['cvv']);
        $this->assertEquals('[REDACTED]', $sanitized['api_secret']);
        $this->assertEquals('[REDACTED]', $sanitized['private_key']);
        $this->assertEquals('visible_reference_123', $sanitized['safe_field']);

        // Verify webhook event payload storage is redacted
        $sale = $this->createAndFinalizeSale(50000, 1);
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'amount_minor' => 50000,
                'method' => 'qris',
                'provider' => 'mock',
                'metadata' => ['simulate_async' => true],
            ], $this->cashier);

            return $this->paymentService->processPayment($p);
        });

        $payload = array_merge($sensitiveData, [
            'event_id' => 'evt_sensitive_'.Str::random(6),
            'provider_payment_id' => $payment->provider_payment_id,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => 50000,
        ]);

        $this->call(
            'POST',
            '/api/v1/payments/webhooks/mock',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => $this->signPayload($payload),
            ],
            content: json_encode($payload)
        );

        $webhookEvent = PaymentWebhookEvent::where('external_event_id', $payload['event_id'])->first();
        $this->assertNotNull($webhookEvent);
        $this->assertEquals('[REDACTED]', $webhookEvent->payload['card_number']);
        $this->assertEquals('[REDACTED]', $webhookEvent->payload['cvv']);
    }
}
