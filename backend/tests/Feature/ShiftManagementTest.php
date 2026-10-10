<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\CashMovementCreated;
use App\Events\ShiftClosed;
use App\Events\ShiftOpened;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\InventoryCostState;
use App\Models\InventoryItem;
use App\Models\InventoryItemLocation;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\Shift\ShiftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class ShiftManagementTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $manager;

    protected User $staff;

    protected Outlet $outlet;

    protected InventoryLocation $kitchenLocation;

    protected ShiftService $shiftService;

    protected PaymentService $paymentService;

    protected PosService $posService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Shift Tenant');
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Budi Kasir', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $managerData = $this->addMemberToTenant($this->tenantData['tenant'], 'manager', 'Dewi Manajer', [$this->outlet->id]);
        $this->manager = $managerData['user'];

        $staffData = $this->addMemberToTenant($this->tenantData['tenant'], 'staff', 'Agus Staff', [$this->outlet->id]);
        $this->staff = $staffData['user'];

        $this->shiftService = app(ShiftService::class);
        $this->paymentService = app(PaymentService::class);
        $this->posService = app(PosService::class);

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

    public function test_can_open_shift_with_positive_opening_float(): void
    {
        Event::fake([ShiftOpened::class, CashMovementCreated::class]);

        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 100000,
                'notes' => 'Pagi pembukaan',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.opening_cash_minor', 100000);
        $response->assertJsonPath('data.notes', 'Pagi pembukaan');

        $this->runForTenant(function () {
            $this->assertDatabaseHas('shifts', [
                'outlet_id' => $this->outlet->id,
                'status' => 'open',
                'opening_cash_minor' => 100000,
            ]);

            // Opening float cash movement recorded
            $this->assertDatabaseHas('cash_movements', [
                'outlet_id' => $this->outlet->id,
                'type' => 'opening',
                'direction' => 'in',
                'amount_minor' => 100000,
            ]);
        });

        Event::assertDispatched(ShiftOpened::class);
    }

    public function test_cannot_open_duplicate_shift_on_same_outlet(): void
    {
        $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 50000,
            ])->assertStatus(201);

        // Second shift attempt must fail deterministically with 409
        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 100000,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'ACTIVE_SHIFT_ALREADY_EXISTS');
    }

    public function test_database_rejects_concurrent_open_shifts_via_partial_unique_index(): void
    {
        $this->runForTenant(function () {
            Shift::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'opened_by_user_id' => $this->cashier->id,
                'status' => Shift::STATUS_OPEN,
                'opened_at' => now(),
                'opening_cash_minor' => 50000,
            ]);
        });

        $this->expectException(QueryException::class);

        // Attempting to insert another 'open' shift for same outlet must throw QueryException
        $this->runForTenant(function () {
            Shift::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'opened_by_user_id' => $this->manager->id,
                'status' => Shift::STATUS_OPEN,
                'opened_at' => now(),
                'opening_cash_minor' => 100000,
            ]);
        });
    }

    public function test_cash_in_and_cash_out_movements(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));

        // Cash In
        $inResponse = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-in", [
                'amount_minor' => 50000,
                'reason' => 'Tambah modal receh',
            ]);

        $inResponse->assertStatus(201);
        $inResponse->assertJsonPath('data.amount_minor', 50000);
        $inResponse->assertJsonPath('data.type', 'cash_in');
        $inResponse->assertJsonPath('data.direction', 'in');

        // Cash Out
        $outResponse = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-out", [
                'amount_minor' => 30000,
                'reason' => 'Beli plastik bungkus',
            ]);

        $outResponse->assertStatus(201);
        $outResponse->assertJsonPath('data.amount_minor', 30000);
        $outResponse->assertJsonPath('data.type', 'cash_out');
        $outResponse->assertJsonPath('data.direction', 'out');

        // Verify summary: 100k opening + 50k in - 30k out = 120k expected
        $summaryResponse = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->getJson("/api/v1/shifts/{$shift->id}/summary");

        $summaryResponse->assertStatus(200);
        $summaryResponse->assertJsonPath('data.summary.opening_cash', 100000);
        $summaryResponse->assertJsonPath('data.summary.cash_in', 50000);
        $summaryResponse->assertJsonPath('data.summary.cash_out', 30000);
        $summaryResponse->assertJsonPath('data.summary.expected_closing', 120000);
    }

    public function test_cash_out_exceeding_drawer_balance_is_rejected(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 50000, $this->cashier));

        // Attempting to withdraw 60000 when drawer has 50000 must fail with 422
        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-out", [
                'amount_minor' => 60000,
                'reason' => 'Ambil lebih besar dari saldo',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INSUFFICIENT_DRAWER_CASH');
    }

    public function test_cash_adjustment_requires_cash_adjust_permission(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->manager));

        // Cashier has no cash.adjust permission by default
        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/adjust", [
                'amount_minor' => 5000,
                'direction' => 'out',
                'reason' => 'Koreksi hitungan fisik salah input',
            ]);

        $response->assertStatus(403);

        // Manager has cash.adjust permission
        $adjResponse = $this->actingAs($this->manager)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/adjust", [
                'amount_minor' => 5000,
                'direction' => 'out',
                'reason' => 'Koreksi hitungan fisik audit',
            ]);

        $adjResponse->assertStatus(201);
        $adjResponse->assertJsonPath('data.amount_minor', 5000);
        $adjResponse->assertJsonPath('data.type', 'adjustment');
    }

    public function test_cash_sale_creates_cash_movement_atomically(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));

        $sale = $this->createFinalizedSale(50000);

        // Process cash payment
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'method' => 'cash',
                'amount_minor' => 50000,
            ], $this->cashier);

            $this->paymentService->processPayment($p);

            return $p;
        });

        $freshPayment = $this->runForTenant(fn () => $payment->fresh());
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $freshPayment->status);

        // Assert CashMovement created
        $movement = $this->runForTenant(function () use ($shift, $payment) {
            return CashMovement::where('shift_id', $shift->id)
                ->where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->first();
        });

        $this->assertNotNull($movement);
        $this->assertEquals(CashMovement::TYPE_SALE, $movement->type);
        $this->assertEquals(CashMovement::DIRECTION_IN, $movement->direction);
        $this->assertEquals(50000, $movement->amount_minor);

        // Summary reflects sale
        $summary = $this->runForTenant(fn () => $this->shiftService->getCashSummary($shift));
        $this->assertEquals(50000, $summary['cash_sales']);
        $this->assertEquals(150000, $summary['expected_closing']);
    }

    public function test_non_cash_payment_does_not_create_cash_movement(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));

        $sale = $this->createFinalizedSale(75000);

        // Process manual QRIS payment
        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'method' => 'qris',
                'amount_minor' => 75000,
            ], $this->cashier);

            $this->paymentService->processPayment($p);

            return $p;
        });

        $freshPayment = $this->runForTenant(fn () => $payment->fresh());
        $this->assertEquals(Payment::STATUS_SUCCEEDED, $freshPayment->status);

        // Assert no cash movement created for non-cash
        $movement = $this->runForTenant(function () use ($shift, $payment) {
            return CashMovement::where('shift_id', $shift->id)
                ->where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->first();
        });

        $this->assertNull($movement);

        $summary = $this->runForTenant(fn () => $this->shiftService->getCashSummary($shift));
        $this->assertEquals(0, $summary['cash_sales']);
        $this->assertEquals(100000, $summary['expected_closing']);
    }

    public function test_cash_refund_creates_cash_out_impact(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));

        $sale = $this->createFinalizedSale(50000);
        $refund = $this->runForTenant(function () use ($sale) {
            $payment = $this->paymentService->createPayment($sale, [
                'method' => 'cash',
                'amount_minor' => 50000,
            ], $this->cashier);
            $this->paymentService->processPayment($payment);

            return $this->paymentService->refundPayment($payment, 20000, 'Customer return item', $this->cashier);
        });

        $freshRefund = $this->runForTenant(fn () => $refund->fresh());
        $this->assertEquals(PaymentRefund::STATUS_COMPLETED, $freshRefund->status);

        // Assert cash refund movement created
        $movement = $this->runForTenant(function () use ($shift, $refund) {
            return CashMovement::where('shift_id', $shift->id)
                ->where('reference_type', 'payment_refund')
                ->where('reference_id', $refund->id)
                ->first();
        });

        $this->assertNotNull($movement);
        $this->assertEquals(CashMovement::TYPE_REFUND, $movement->type);
        $this->assertEquals(CashMovement::DIRECTION_OUT, $movement->direction);
        $this->assertEquals(20000, $movement->amount_minor);

        $summary = $this->runForTenant(fn () => $this->shiftService->getCashSummary($shift));
        $this->assertEquals(50000, $summary['cash_sales']);
        $this->assertEquals(20000, $summary['cash_refund']);
        $this->assertEquals(130000, $summary['expected_closing']);
    }

    public function test_shift_closing_with_variance_calculation(): void
    {
        Event::fake([ShiftClosed::class]);

        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));

        $sale = $this->createFinalizedSale(50000);
        $this->runForTenant(function () use ($sale) {
            $payment = $this->paymentService->createPayment($sale, [
                'method' => 'cash',
                'amount_minor' => 50000,
            ], $this->cashier);
            $this->paymentService->processPayment($payment);
        });

        // Expected is 150000. Cashier counts 152000 (+2000 variance)
        $closeResponse = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/close", [
                'actual_closing_cash_minor' => 152000,
                'notes' => 'Lebih 2000 tip kasir',
            ]);

        $closeResponse->assertStatus(200);
        $closeResponse->assertJsonPath('data.shift.status', 'closed');
        $closeResponse->assertJsonPath('data.shift.closing_cash_minor', 152000);
        $closeResponse->assertJsonPath('data.shift.expected_cash_minor', 150000);
        $closeResponse->assertJsonPath('data.shift.variance_minor', 2000);

        $fresh = $this->runForTenant(fn () => $shift->fresh());
        $this->assertTrue($fresh->isClosed());
        $this->assertNotNull($fresh->closed_at);
        $this->assertEquals($this->cashier->id, $fresh->closed_by_user_id);
        $this->assertEquals(2000, $fresh->variance_minor);

        // Cannot mutate closed shift
        $afterClosedResponse = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-in", [
                'amount_minor' => 10000,
                'reason' => 'Late cash in',
            ]);

        $afterClosedResponse->assertStatus(409);
        $afterClosedResponse->assertJsonPath('code', 'SHIFT_CLOSED');

        Event::assertDispatched(ShiftClosed::class);
    }

    public function test_outlet_isolation_cashier_cannot_open_or_mutate_other_outlet_shift(): void
    {
        $outlet2 = $this->runForTenant(function () {
            return Outlet::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Outlet Cabang B',
                'code' => 'OUT-SFT-2',
                'is_active' => true,
            ]);
        });

        // Cashier is assigned only to outlet 1 (not outlet2)
        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/outlets/{$outlet2->id}/shifts/open", [
                'opening_cash_minor' => 100000,
            ]);

        $response->assertStatus(403);
    }

    public function test_idempotency_on_shift_operations(): void
    {
        $idempotencyKey = (string) Str::uuid();

        // 1. First Open Shift with Idempotency-Key
        $res1 = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 100000,
                'notes' => 'Idempotent open',
            ]);

        $res1->assertStatus(201);
        $shiftId = $res1->json('data.id');

        // 2. Replay exactly same request with same key -> Returns identical cached response
        $res2 = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 100000,
                'notes' => 'Idempotent open',
            ]);

        $res2->assertStatus(201);
        $this->assertEquals($shiftId, $res2->json('data.id'));

        // 3. Same key with different payload -> Returns 409 Mismatch
        $res3 = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenantData['tenant']->id,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->postJson("/api/v1/outlets/{$this->outlet->id}/shifts/open", [
                'opening_cash_minor' => 200000,
            ]);

        $res3->assertStatus(409);
    }

    public function test_duplicate_close_shift_fails_deterministically(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 50000, $this->cashier));

        // First close succeeds
        $res1 = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/close", [
                'actual_closing_cash_minor' => 50000,
            ]);
        $res1->assertStatus(200);

        // Second close fails with 409 SHIFT_CLOSED
        $res2 = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/close", [
                'actual_closing_cash_minor' => 50000,
            ]);
        $res2->assertStatus(409);
        $res2->assertJsonPath('code', 'SHIFT_CLOSED');
    }

    public function test_database_check_constraints_enforce_state_invariants(): void
    {
        // 1. Cannot insert closed shift with null closed_at
        $this->expectException(QueryException::class);
        $this->runForTenant(function () {
            Shift::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'opened_by_user_id' => $this->cashier->id,
                'status' => Shift::STATUS_CLOSED,
                'opened_at' => now(),
                'closed_at' => null, // Violates check constraint
                'opening_cash_minor' => 10000,
                'closing_cash_minor' => 10000,
            ]);
        });
    }

    public function test_zero_and_negative_variance_closing(): void
    {
        // Zero variance
        $shift1 = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));
        $res1 = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift1->id}/close", [
                'actual_closing_cash_minor' => 100000,
            ]);
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.shift.variance_minor', 0);

        // Negative variance (shortage)
        $shift2 = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));
        $res2 = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift2->id}/close", [
                'actual_closing_cash_minor' => 95000,
            ]);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.shift.variance_minor', -5000);
    }

    public function test_tenant_isolation_cross_tenant_access_blocked(): void
    {
        $tenantB = $this->createTenant('Tenant B Warung');
        $shiftB = app(TenantManager::class)->runForTenant($tenantB['tenant'], function () use ($tenantB) {
            return $this->shiftService->openShift($tenantB['outlet'], 50000, $tenantB['owner']);
        });

        // Cashier of Tenant A attempts to view Tenant B's shift
        $response = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->getJson("/api/v1/shifts/{$shiftB->id}/summary");

        $response->assertStatus(404);

        // Cashier of Tenant A attempts to cash-in to Tenant B's shift
        $cashInResponse = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shiftB->id}/cash-in", [
                'amount_minor' => 10000,
                'reason' => 'Malicious cross tenant cash in',
            ]);

        $cashInResponse->assertStatus(404);
    }

    public function test_cash_in_and_cash_out_reject_zero_or_negative_amounts(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 50000, $this->cashier));

        // Zero amount
        $resZero = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-in", [
                'amount_minor' => 0,
                'reason' => 'Zero amount',
            ]);
        $resZero->assertStatus(422);

        // Negative amount
        $resNeg = $this->actingAs($this->cashier)
            ->withHeaders(['X-Tenant-ID' => $this->tenantData['tenant']->id])
            ->postJson("/api/v1/shifts/{$shift->id}/cash-out", [
                'amount_minor' => -1000,
                'reason' => 'Negative amount',
            ]);
        $resNeg->assertStatus(422);
    }

    public function test_duplicate_payment_finalization_does_not_double_count_cash(): void
    {
        $shift = $this->runForTenant(fn () => $this->shiftService->openShift($this->outlet, 100000, $this->cashier));
        $sale = $this->createFinalizedSale(50000);

        $payment = $this->runForTenant(function () use ($sale) {
            $p = $this->paymentService->createPayment($sale, [
                'method' => 'cash',
                'amount_minor' => 50000,
            ], $this->cashier);
            $this->paymentService->processPayment($p);

            return $p;
        });

        // Attempt second recording of the same payment impact
        $this->runForTenant(function () use ($payment) {
            $this->shiftService->recordSaleCashPayment($payment);
        });

        // Cash movement must exist exactly once
        $count = $this->runForTenant(function () use ($shift, $payment) {
            return CashMovement::where('shift_id', $shift->id)
                ->where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->count();
        });

        $this->assertEquals(1, $count);

        $summary = $this->runForTenant(fn () => $this->shiftService->getCashSummary($shift));
        $this->assertEquals(50000, $summary['cash_sales']);
        $this->assertEquals(150000, $summary['expected_closing']);
    }

    protected function createFinalizedSale(int $amountMinor): Sale
    {
        return $this->runForTenant(function () use ($amountMinor) {
            $category = Category::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Makanan Shift',
                'slug' => 'makanan-shift-'.Str::random(5),
            ]);

            $product = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'category_id' => $category->id,
                'name' => 'Nasi Goreng Shift',
                'slug' => 'nasi-goreng-shift-'.Str::random(5),
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
                'amount' => $amountMinor,
                'currency' => 'IDR',
                'effective_from' => now()->subDay(),
                'is_active' => true,
            ]);

            $bean = InventoryItem::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'sku' => 'MAT-'.strtoupper(Str::random(5)),
                'name' => 'Beras',
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
                'code' => 'REC-NASI-'.strtoupper(Str::random(4)),
                'name' => 'Resep Nasi Goreng',
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
                        'quantity' => 1,
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
}
