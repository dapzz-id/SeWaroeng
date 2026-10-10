<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Events\PaymentFailed;
use App\Exceptions\InsufficientLoyaltyPointsException;
use App\Models\Customer;
use App\Models\InventoryLocation;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\LoyaltyVoucher;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use App\Services\Subscription\SubscriptionEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class LoyaltyTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $owner;

    protected User $manager;

    protected User $cashier;

    protected Outlet $outlet;

    protected LoyaltyService $loyaltyService;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Berkah Test');
        $tenant = $this->tenantData['tenant'];
        $this->outlet = $this->tenantData['outlet'];
        $this->owner = $this->tenantData['owner'];

        $this->runForTenant(function () use ($tenant) {
            InventoryLocation::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $this->outlet->id,
                'name' => 'Main Outlet Counter',
                'code' => 'LOC-COUNTER',
                'type' => 'outlet',
                'is_active' => true,
            ]);
        });

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manajer Siti');
        $this->manager = $managerData['user'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Budi', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $this->loyaltyService = app(LoyaltyService::class);
        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    protected function createTestCustomer(string $name = 'Pelanggan Setia'): Customer
    {
        return $this->runForTenant(function () use ($name) {
            return Customer::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => $name,
                'phone' => '081234567890',
                'email' => 'customer@waroeng.test',
                'is_active' => true,
            ]);
        });
    }

    protected function createProductAndVariant(int $priceMinor = 5000000): ProductVariant
    {
        return $this->runForTenant(function () use ($priceMinor) {
            $product = Product::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'name' => 'Kopi Susu Gula Aren',
                'is_active' => true,
            ]);

            $variant = ProductVariant::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_id' => $product->id,
                'name' => 'Regular',
                'sku' => 'KOP-REG-'.uniqid(),
                'is_active' => true,
            ]);

            $variant->prices()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'currency' => 'IDR',
                'amount' => $priceMinor,
                'is_active' => true,
            ]);

            return $variant;
        });
    }

    // ==========================================
    // 1. PROGRAM & ENTITLEMENT
    // ==========================================

    public function test_loyalty_program_initialization_and_configuration(): void
    {
        $response = $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->getJson('/api/v1/loyalty/program');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.points_per_currency', 1);

        // Update program configuration
        $updateRes = $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->putJson('/api/v1/loyalty/program', [
                'name' => 'Program Loyalitas Utama',
                'points_per_currency' => 2,
                'minimum_earn_amount_minor' => 2000000, // Rp 20.000
                'rounding_policy' => 'floor',
            ]);

        $updateRes->assertStatus(200);

        $this->runForTenant(function () {
            $program = LoyaltyProgram::where('tenant_id', $this->tenantData['tenant']->id)->first();
            $this->assertEquals('Program Loyalitas Utama', $program->name);
            $this->assertEquals(2, $program->points_per_currency);
            $this->assertEquals(2000000, $program->minimum_earn_amount_minor);
        });
    }

    public function test_tenant_without_loyalty_entitlement_is_rejected(): void
    {
        // Disable loyalty entitlement for this tenant's plan
        $this->runForTenant(function () {
            $subscription = app(SubscriptionEntitlementService::class)->getSubscription($this->tenantData['tenant']);
            $entitlement = $subscription->plan->entitlements()->where('feature_key', 'loyalty')->first();
            if ($entitlement) {
                $entitlement->update(['is_enabled' => false]);
            }
            app(SubscriptionEntitlementService::class)->clearCache($this->tenantData['tenant']);
        });

        // 403 Forbidden
        $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->getJson('/api/v1/loyalty/program')
            ->assertStatus(403);
    }

    // ==========================================
    // 2. ACCOUNT & TENANT ISOLATION
    // ==========================================

    public function test_customer_account_creation_and_crm_integration(): void
    {
        $customer = $this->createTestCustomer('Dewi Sartika');

        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->getJson("/api/v1/loyalty/customers/{$customer->id}/account");

        $res->assertStatus(200)
            ->assertJsonPath('data.current_balance', 0)
            ->assertJsonPath('data.current_tier.name', 'Bronze');

        // Customer 360 summary includes loyalty data
        $summaryRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->getJson("/api/v1/customers/{$customer->id}/summary");

        $summaryRes->assertStatus(200)
            ->assertJsonPath('data.loyalty.has_account', true)
            ->assertJsonPath('data.loyalty.tier', 'Bronze')
            ->assertJsonPath('data.loyalty.current_points', 0);
    }

    public function test_tenant_isolation_on_loyalty_account_and_ledgers(): void
    {
        $customerA = $this->createTestCustomer('Customer Tenant A');

        // Second tenant
        $tenantBData = $this->createTenant('Waroeng Tenant B');
        $customerB = app(TenantManager::class)->runForTenant($tenantBData['tenant'], function () use ($tenantBData) {
            return Customer::create([
                'tenant_id' => $tenantBData['tenant']->id,
                'name' => 'Customer Tenant B',
                'phone' => '089999999999',
                'is_active' => true,
            ]);
        });

        // Tenant A user cannot access Tenant B's customer account (ModelNotFoundException -> 404)
        $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->getJson("/api/v1/loyalty/customers/{$customerB->id}/account")
            ->assertStatus(404);
    }

    // ==========================================
    // 3. POINT EARNING & IDEMPOTENCY
    // ==========================================

    public function test_points_earned_upon_finalized_and_paid_sale(): void
    {
        $customer = $this->createTestCustomer('Budi Gunawan');
        $variant = $this->createProductAndVariant(5000000); // Rp 50.000

        // Create sale with customer
        $sale = $this->runForTenant(function () use ($customer, $variant) {
            $s = Sale::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->cashier->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-TEST-001',
                'status' => Sale::STATUS_OPEN,
                'opened_at' => now(),
                'subtotal_minor' => 5000000,
                'total_minor' => 5000000,
            ]);

            $s->items()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Kopi Susu',
                'variant_name_snapshot' => 'Regular',
                'quantity' => 1,
                'unit_price_minor' => 5000000,
                'subtotal_minor' => 5000000,
                'total_minor' => 5000000,
            ]);

            // Finalize sale
            $s->status = Sale::STATUS_FINALIZED;
            $s->finalized_at = now();
            $s->save();

            // Succeeded Payment
            $s->payments()->create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'reference_number' => 'PAY-TEST-001',
                'provider' => 'cash',
                'method' => 'cash',
                'status' => Payment::STATUS_SUCCEEDED,
                'amount_minor' => 5000000,
                'paid_at' => now(),
            ]);

            return $s;
        });

        // Award points
        $ledger = $this->loyaltyService->awardPointsForSale($sale);

        $this->assertNotNull($ledger);
        // Default: Rp 1.000 = 1 pt. Rp 50.000 = 50 pts.
        $this->assertEquals(50, $ledger->points);
        $this->assertEquals(50, $ledger->balance_after);
        $this->assertEquals(LoyaltyPointLedger::TYPE_PURCHASE, $ledger->type);

        $account = $this->loyaltyService->getOrCreateAccount($this->tenantData['tenant']->id, $customer->id);
        $this->assertEquals(50, $account->current_balance);
        $this->assertEquals(50, $account->lifetime_earned);

        // IDEMPOTENCY: Calling awardPointsForSale again on same sale must NOT award duplicate points
        $secondLedger = $this->loyaltyService->awardPointsForSale($sale);
        $this->assertEquals($ledger->id, $secondLedger->id);

        $this->runForTenant(function () use ($account) {
            $account->refresh();
        });
        $this->assertEquals(50, $account->current_balance);

        $ledgerCount = $this->runForTenant(function () use ($customer) {
            return LoyaltyPointLedger::where('tenant_id', $this->tenantData['tenant']->id)
                ->where('customer_id', $customer->id)
                ->count();
        });
        $this->assertEquals(1, $ledgerCount);
    }

    public function test_unfinalized_or_unpaid_sale_does_not_earn_points(): void
    {
        $customer = $this->createTestCustomer('Unpaid Customer');

        $sale = $this->runForTenant(function () use ($customer) {
            return Sale::create([
                'tenant_id' => $this->tenantData['tenant']->id,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->cashier->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-UNPAID-001',
                'status' => Sale::STATUS_OPEN, // still open
                'subtotal_minor' => 5000000,
                'total_minor' => 5000000,
            ]);
        });

        $ledger = $this->loyaltyService->awardPointsForSale($sale);
        $this->assertNull($ledger);

        $account = $this->loyaltyService->getOrCreateAccount($this->tenantData['tenant']->id, $customer->id);
        $this->assertEquals(0, $account->current_balance);
    }

    // ==========================================
    // 4. TIER PROGRESSION
    // ==========================================

    public function test_tier_progression_and_multiplier_calculation(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Customer Tier Test');

        // Create Silver tier (min 100 points, 1.2x multiplier = 12000 bps)
        $this->runForTenant(function () use ($tenantId) {
            $program = $this->loyaltyService->getOrCreateProgram($tenantId);
            LoyaltyTier::create([
                'tenant_id' => $tenantId,
                'loyalty_program_id' => $program->id,
                'name' => 'Silver',
                'rank' => 2,
                'min_points_earned' => 100,
                'multiplier_bps' => 12000, // 1.2x
                'is_active' => true,
            ]);
        });

        // Add 120 points via manual adjustment
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 120, 'Promosi pembukaan');

        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customer->id);
        $this->assertEquals(120, $account->current_balance);
        $this->assertEquals('Silver', $account->tier->name);

        // Next sale: Rp 100.000 (100 base points). With 1.2x multiplier -> 120 points!
        $sale = $this->runForTenant(function () use ($tenantId, $customer) {
            $s = Sale::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->cashier->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-TIER-001',
                'status' => Sale::STATUS_FINALIZED,
                'finalized_at' => now(),
                'subtotal_minor' => 10000000,
                'total_minor' => 10000000,
            ]);

            $s->payments()->create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'reference_number' => 'PAY-TIER-001',
                'provider' => 'cash',
                'method' => 'cash',
                'status' => Payment::STATUS_SUCCEEDED,
                'amount_minor' => 10000000,
                'paid_at' => now(),
            ]);

            return $s;
        });

        $ledger = $this->loyaltyService->awardPointsForSale($sale);
        $this->assertEquals(120, $ledger->points); // 100 * 1.2 = 120 points
        $this->assertEquals(240, $ledger->balance_after);
    }

    // ==========================================
    // 5. REDEMPTION, VOUCHERS & ATOMICITY
    // ==========================================

    public function test_reward_redemption_and_voucher_issuance(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Redemption Customer');

        // Grant 200 points
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 200, 'Initial points');

        // Create reward: Diskon Rp 10.000 for 100 points
        $reward = $this->runForTenant(function () use ($tenantId) {
            $program = $this->loyaltyService->getOrCreateProgram($tenantId);

            return LoyaltyReward::create([
                'tenant_id' => $tenantId,
                'loyalty_program_id' => $program->id,
                'name' => 'Diskon Rp 10.000',
                'points_cost' => 100,
                'reward_type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
                'discount_amount_minor' => 1000000,
                'min_purchase_minor' => 2000000,
                'validity_days' => 14,
                'is_active' => true,
            ]);
        });

        // Redeem via API
        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/loyalty/customers/{$customer->id}/redeem", [
                'reward_id' => $reward->id,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.discount_amount_minor', 1000000);

        $voucherCode = $res->json('data.code');
        $this->assertNotEmpty($voucherCode);

        // Account balance deducted
        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customer->id);
        $this->assertEquals(100, $account->current_balance);
        $this->assertEquals(100, $account->lifetime_redeemed);

        // Ledger row created
        $redemptionLedger = $this->runForTenant(function () use ($tenantId, $customer) {
            return LoyaltyPointLedger::where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->where('type', LoyaltyPointLedger::TYPE_REDEMPTION)
                ->first();
        });

        $this->assertNotNull($redemptionLedger);
        $this->assertEquals(-100, $redemptionLedger->points);
        $this->assertEquals(100, $redemptionLedger->balance_after);
    }

    public function test_redemption_fails_when_points_are_insufficient(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Broke Customer');

        // Customer has 50 points
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 50, 'Low balance');

        $reward = $this->runForTenant(function () use ($tenantId) {
            $program = $this->loyaltyService->getOrCreateProgram($tenantId);

            return LoyaltyReward::create([
                'tenant_id' => $tenantId,
                'loyalty_program_id' => $program->id,
                'name' => 'Reward Mahal',
                'points_cost' => 100,
                'reward_type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
                'discount_amount_minor' => 1000000,
                'is_active' => true,
            ]);
        });

        // Attempt redemption -> 422
        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/loyalty/customers/{$customer->id}/redeem", [
                'reward_id' => $reward->id,
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_LOYALTY_POINTS');

        // No points deducted, no voucher created
        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customer->id);
        $this->assertEquals(50, $account->current_balance);

        $voucherCount = $this->runForTenant(function () use ($tenantId, $customer) {
            return LoyaltyVoucher::where('tenant_id', $tenantId)->where('customer_id', $customer->id)->count();
        });
        $this->assertEquals(0, $voucherCount);
    }

    // ==========================================
    // 6. CONCURRENCY SAFETY (REQUIREMENT 29)
    // ==========================================

    public function test_concurrent_redemption_race_condition_safety(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Concurrency Test Customer');

        // Customer points = 500
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 500, 'Starting 500 pts');

        // Reward cost = 500
        $reward = $this->runForTenant(function () use ($tenantId) {
            $program = $this->loyaltyService->getOrCreateProgram($tenantId);

            return LoyaltyReward::create([
                'tenant_id' => $tenantId,
                'loyalty_program_id' => $program->id,
                'name' => 'Reward 500 Poin',
                'points_cost' => 500,
                'reward_type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
                'discount_amount_minor' => 5000000,
                'is_active' => true,
            ]);
        });

        // Simulate 10 sequential/concurrent redemption attempts
        $successCount = 0;
        $failCount = 0;

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->loyaltyService->redeemReward($tenantId, $customer->id, $reward->id);
                $successCount++;
            } catch (InsufficientLoyaltyPointsException $e) {
                $failCount++;
            }
        }

        // EXACTLY 1 succeeds, 9 fail safely
        $this->assertEquals(1, $successCount);
        $this->assertEquals(9, $failCount);

        // Final balance = 0, no negative points!
        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customer->id);
        $this->assertEquals(0, $account->current_balance);
        $this->assertEquals(500, $account->lifetime_redeemed);

        $voucherCount = $this->runForTenant(function () use ($tenantId, $customer) {
            return LoyaltyVoucher::where('tenant_id', $tenantId)->where('customer_id', $customer->id)->count();
        });
        $this->assertEquals(1, $voucherCount);

        $ledgerCount = $this->runForTenant(function () use ($tenantId, $customer) {
            return LoyaltyPointLedger::where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->where('type', LoyaltyPointLedger::TYPE_REDEMPTION)
                ->count();
        });
        $this->assertEquals(1, $ledgerCount);
    }

    // ==========================================
    // 7. IMMUTABLE POINT LEDGER & RECONCILIATION
    // ==========================================

    public function test_ledger_immutability_and_balance_reconciliation(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Immutable Test Customer');

        $ledger = $this->loyaltyService->adjustPoints($tenantId, $customer->id, 100, 'Initial deposit');

        // Direct Eloquent update must THROW RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Loyalty point ledger is immutable');
        $ledger->points = 999;
        $ledger->save();
    }

    public function test_ledger_cannot_be_deleted(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Delete Test Customer');

        $ledger = $this->loyaltyService->adjustPoints($tenantId, $customer->id, 100, 'Deposit for delete');

        // Direct Eloquent delete must THROW RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Loyalty point ledger is immutable');
        $ledger->delete();
    }

    public function test_balance_reconciliation_audit_invariant(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Audit Customer');

        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 100, 'Deposit 1');
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 50, 'Deposit 2');
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, -30, 'Adjustment minus');

        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customer->id);

        $reconcileRes = $this->loyaltyService->reconcileAccountBalance($tenantId, $account->id);

        // Invariant: current_balance = SUM(all posted ledger entries)
        $this->assertTrue($reconcileRes['is_reconciled']);
        $this->assertEquals(120, $reconcileRes['cached_balance']);
        $this->assertEquals(120, $reconcileRes['ledger_sum']);
        $this->assertEquals(0, $reconcileRes['difference']);
    }

    // ==========================================
    // 8. POS VOUCHER USAGE & PRICE AUTHORITY
    // ==========================================

    public function test_server_authoritative_voucher_discount_application_in_pos(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('POS Shopper');
        $variant = $this->createProductAndVariant(10000000); // Rp 100.000

        // Give points and redeem 20% discount voucher
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 100, 'Bonus points');

        $reward = $this->runForTenant(function () use ($tenantId) {
            $program = $this->loyaltyService->getOrCreateProgram($tenantId);

            return LoyaltyReward::create([
                'tenant_id' => $tenantId,
                'loyalty_program_id' => $program->id,
                'name' => 'Diskon 20%',
                'points_cost' => 50,
                'reward_type' => LoyaltyReward::TYPE_DISCOUNT_PERCENT,
                'discount_percent' => 20,
                'min_purchase_minor' => 5000000, // Rp 50.000
                'is_active' => true,
            ]);
        });

        $voucher = $this->loyaltyService->redeemReward($tenantId, $customer->id, $reward->id);

        // Create open sale with subtotal Rp 100.000
        $sale = $this->runForTenant(function () use ($tenantId, $customer, $variant) {
            $s = Sale::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'cashier_user_id' => $this->cashier->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-VCH-001',
                'status' => Sale::STATUS_OPEN,
                'subtotal_minor' => 10000000,
                'total_minor' => 10000000,
            ]);

            $s->items()->create([
                'tenant_id' => $tenantId,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Kopi Regular',
                'variant_name_snapshot' => 'Regular',
                'quantity' => 1,
                'unit_price_minor' => 10000000,
                'subtotal_minor' => 10000000,
                'total_minor' => 10000000,
            ]);

            return $s;
        });

        // Cashier applies voucher to open sale
        $applyRes = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/pos/sales/{$sale->id}/apply-voucher", [
                'voucher_code' => $voucher->code,
            ]);

        $applyRes->assertStatus(200);

        $this->runForTenant(function () use ($sale, $voucher) {
            $sale->refresh();
            // Server calculates 20% of 100.000 = Rp 20.000 discount (2.000.000 minor)
            $this->assertEquals(2000000, $sale->discount_minor);
            $this->assertEquals(8000000, $sale->total_minor);

            $voucher->refresh();
            $this->assertEquals($sale->id, $voucher->used_sale_id);
            $this->assertEquals(LoyaltyVoucher::STATUS_RESERVED, $voucher->status);
        });
    }

    // ==========================================
    // 9. RBAC AUTHORIZATION
    // ==========================================

    public function test_cashier_cannot_arbitrarily_adjust_points_without_permission(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('RBAC Test Customer');

        // Cashier attempts manual adjustment -> 403 Forbidden
        $res = $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/loyalty/customers/{$customer->id}/adjust", [
                'points' => 9999,
                'reason' => 'Unauthorized bonus',
            ]);

        $res->assertStatus(403);

        // Owner CAN adjust points -> 200 OK
        $ownerRes = $this->authenticateTenantUser($this->owner, $this->tenantData['tenant'])
            ->postJson("/api/v1/loyalty/customers/{$customer->id}/adjust", [
                'points' => 50,
                'reason' => 'Authorized bonus',
            ]);

        $ownerRes->assertStatus(200);
        $account = $this->loyaltyService->getOrCreateAccount($tenantId, $customer->id);
        $this->assertEquals(50, $account->current_balance);
    }

    // ==========================================
    // 10. VOUCHER PAYMENT LIFECYCLE & FAILURE RECOVERY
    // ==========================================

    public function test_voucher_payment_failure_does_not_permanently_consume_voucher(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Voucher Lifecycle Customer');

        // 1. Give customer points and redeem a reward
        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 200, 'Test credit');
        $reward = $this->runForTenant(fn () => LoyaltyReward::create([
            'tenant_id' => $tenantId,
            'loyalty_program_id' => $this->loyaltyService->getOrCreateProgram($tenantId)->id,
            'name' => 'Potongan Rp 15.000',
            'points_cost' => 100,
            'reward_type' => 'discount_fixed',
            'discount_amount_minor' => 1500000,
            'min_purchase_minor' => 3000000,
            'validity_days' => 14,
            'is_active' => true,
        ]));

        $voucher = $this->loyaltyService->redeemReward($tenantId, $customer->id, $reward->id);
        $this->assertEquals(LoyaltyVoucher::STATUS_ACTIVE, $voucher->status);

        // 2. Create open sale and apply voucher
        $variant = $this->createProductAndVariant(5000000);
        $sale = $this->runForTenant(function () use ($tenantId, $customer, $variant) {
            $s = Sale::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-VCH-FAIL-'.rand(100, 999),
                'status' => Sale::STATUS_OPEN,
                'opened_at' => now(),
                'cashier_user_id' => $this->cashier->id,
                'subtotal_minor' => 5000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 5000000,
            ]);

            $s->items()->create([
                'tenant_id' => $tenantId,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Nasi Goreng Spesial',
                'variant_name_snapshot' => 'Regular',
                'quantity' => 1,
                'unit_price_minor' => 5000000,
                'subtotal_minor' => 5000000,
                'total_minor' => 5000000,
            ]);

            return $s;
        });

        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/pos/sales/{$sale->id}/apply-voucher", [
                'voucher_code' => $voucher->code,
            ])
            ->assertStatus(200);

        $this->runForTenant(function () use ($sale, $voucher, $tenantId) {
            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_RESERVED, $voucher->status);
            $this->assertEquals($sale->id, $voucher->used_sale_id);

            // 3. Finalize sale without paying
            $this->posService->finalizeSale($sale->id, [], $this->cashier->id, $tenantId);

            // Invariant check: sale finalized, but voucher is NOT consumed yet because payment is pending!
            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_RESERVED, $voucher->status);
            $this->assertNull($voucher->used_at);
        });

        // 4. Simulate payment attempt that fails
        $payment = $this->runForTenant(function () use ($tenantId, $sale) {
            return Payment::create([
                'tenant_id' => $tenantId,
                'sale_id' => $sale->id,
                'reference_number' => 'PAY-FAIL-'.rand(100, 999),
                'amount_minor' => $sale->total_minor,
                'currency' => 'IDR',
                'method' => 'qris',
                'provider' => 'manual',
                'status' => Payment::STATUS_FAILED,
            ]);
        });

        // Fire payment failed event within tenant context
        $this->runForTenant(function () use ($payment) {
            PaymentFailed::dispatch($payment, 'Customer cancelled QR payment');
        });

        // Invariant check: Voucher MUST NOT be permanently consumed!
        $this->runForTenant(function () use ($voucher, $sale, $tenantId) {
            $voucher->refresh();
            $this->assertNotEquals(LoyaltyVoucher::STATUS_USED, $voucher->status);
            $this->assertEquals(LoyaltyVoucher::STATUS_RESERVED, $voucher->status);

            // 5. Cancel the unpaid sale -> voucher reservation released back to active!
            $this->posService->cancelSale($sale->id, 'Payment failed, customer leaving', $this->cashier->id, $tenantId);

            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_ACTIVE, $voucher->status);
            $this->assertNull($voucher->used_sale_id);
        });

        // 6. Customer can re-use the exact same voucher on a new sale!
        $newSale = $this->runForTenant(function () use ($tenantId, $customer, $variant) {
            $s = Sale::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-VCH-RETRY-'.rand(100, 999),
                'status' => Sale::STATUS_OPEN,
                'opened_at' => now(),
                'cashier_user_id' => $this->cashier->id,
                'subtotal_minor' => 5000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 5000000,
            ]);

            $s->items()->create([
                'tenant_id' => $tenantId,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Nasi Goreng Spesial',
                'variant_name_snapshot' => 'Regular',
                'quantity' => 1,
                'unit_price_minor' => 5000000,
                'subtotal_minor' => 5000000,
                'total_minor' => 5000000,
            ]);

            return $s;
        });

        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/pos/sales/{$newSale->id}/apply-voucher", [
                'voucher_code' => $voucher->code,
            ])
            ->assertStatus(200);

        // Finalize and process successful payment
        $this->runForTenant(function () use ($newSale, $voucher, $tenantId) {
            $this->posService->finalizeSale($newSale->id, [], $this->cashier->id, $tenantId);
            $p = $this->paymentService->createPayment($newSale->fresh(), [
                'amount_minor' => $newSale->fresh()->total_minor,
                'method' => 'cash',
            ], $this->cashier);
            $this->paymentService->processPayment($p);

            // Invariant check: Voucher is now USED exactly once!
            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_USED, $voucher->status);
            $this->assertNotNull($voucher->used_at);
            $this->assertEquals($newSale->id, $voucher->used_sale_id);
        });
    }

    public function test_payment_refund_does_not_reinstate_consumed_voucher(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Refund Policy Customer');

        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 200, 'Test balance');
        $reward = $this->runForTenant(fn () => LoyaltyReward::create([
            'tenant_id' => $tenantId,
            'loyalty_program_id' => $this->loyaltyService->getOrCreateProgram($tenantId)->id,
            'name' => 'Diskon Rp 10.000',
            'points_cost' => 50,
            'reward_type' => 'discount_fixed',
            'discount_amount_minor' => 1000000,
            'min_purchase_minor' => 2000000,
            'validity_days' => 14,
            'is_active' => true,
        ]));

        $voucher = $this->loyaltyService->redeemReward($tenantId, $customer->id, $reward->id);
        $variant = $this->createProductAndVariant(3000000);

        $sale = $this->runForTenant(function () use ($tenantId, $customer, $variant) {
            $s = Sale::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-REFUND-'.rand(100, 999),
                'status' => Sale::STATUS_OPEN,
                'opened_at' => now(),
                'cashier_user_id' => $this->cashier->id,
                'subtotal_minor' => 3000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 3000000,
            ]);

            $s->items()->create([
                'tenant_id' => $tenantId,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Kopi Susu Gula Aren',
                'variant_name_snapshot' => 'Regular',
                'quantity' => 1,
                'unit_price_minor' => 3000000,
                'subtotal_minor' => 3000000,
                'total_minor' => 3000000,
            ]);

            return $s;
        });

        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/pos/sales/{$sale->id}/apply-voucher", [
                'voucher_code' => $voucher->code,
            ])
            ->assertStatus(200);

        $this->runForTenant(function () use ($sale, $voucher, $tenantId) {
            $this->posService->finalizeSale($sale->id, [], $this->cashier->id, $tenantId);
            $p = $this->paymentService->createPayment($sale->fresh(), [
                'amount_minor' => $sale->fresh()->total_minor,
                'method' => 'cash',
            ], $this->cashier);
            $payment = $this->paymentService->processPayment($p);

            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_USED, $voucher->status);

            // Refund the cash payment
            $this->paymentService->refundPayment(
                $payment,
                $payment->amount_minor,
                'Customer changed mind',
                $this->manager
            );

            // Invariant check: Financial money is refunded, but promotional loyalty voucher remains used!
            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_USED, $voucher->status);
        });
    }

    public function test_voucher_can_be_removed_from_open_sale(): void
    {
        $tenantId = $this->tenantData['tenant']->id;
        $customer = $this->createTestCustomer('Remove Voucher Customer');

        $this->loyaltyService->adjustPoints($tenantId, $customer->id, 200, 'Test balance');
        $reward = $this->runForTenant(fn () => LoyaltyReward::create([
            'tenant_id' => $tenantId,
            'loyalty_program_id' => $this->loyaltyService->getOrCreateProgram($tenantId)->id,
            'name' => 'Diskon Rp 5.000',
            'points_cost' => 50,
            'reward_type' => 'discount_fixed',
            'discount_amount_minor' => 500000,
            'min_purchase_minor' => 1000000,
            'validity_days' => 7,
            'is_active' => true,
        ]));

        $voucher = $this->loyaltyService->redeemReward($tenantId, $customer->id, $reward->id);
        $variant = $this->createProductAndVariant(2000000);

        $sale = $this->runForTenant(function () use ($tenantId, $customer, $variant) {
            $s = Sale::create([
                'tenant_id' => $tenantId,
                'outlet_id' => $this->outlet->id,
                'customer_id' => $customer->id,
                'reference_number' => 'SALE-RM-VCH-'.rand(100, 999),
                'status' => Sale::STATUS_OPEN,
                'opened_at' => now(),
                'cashier_user_id' => $this->cashier->id,
                'subtotal_minor' => 2000000,
                'tax_minor' => 0,
                'discount_minor' => 0,
                'total_minor' => 2000000,
            ]);

            $s->items()->create([
                'tenant_id' => $tenantId,
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => 'Teh Tarik',
                'variant_name_snapshot' => 'Regular',
                'quantity' => 1,
                'unit_price_minor' => 2000000,
                'subtotal_minor' => 2000000,
                'total_minor' => 2000000,
            ]);

            return $s;
        });

        // 1. Apply voucher
        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->postJson("/api/v1/pos/sales/{$sale->id}/apply-voucher", [
                'voucher_code' => $voucher->code,
            ])
            ->assertStatus(200);

        $this->runForTenant(function () use ($voucher) {
            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_RESERVED, $voucher->status);
        });

        // 2. Remove voucher before finalize
        $this->authenticateTenantUser($this->cashier, $this->tenantData['tenant'])
            ->deleteJson("/api/v1/pos/sales/{$sale->id}/voucher")
            ->assertStatus(200);

        $this->runForTenant(function () use ($sale, $voucher) {
            $sale->refresh();
            $this->assertEquals(0, $sale->discount_minor);
            $this->assertEquals(2000000, $sale->total_minor);

            $voucher->refresh();
            $this->assertEquals(LoyaltyVoucher::STATUS_ACTIVE, $voucher->status);
            $this->assertNull($voucher->used_sale_id);
        });
    }

    public function test_full_loyalty_http_api_routes_coverage(): void
    {
        $tenant = $this->tenantData['tenant'];
        $customer = $this->createTestCustomer('API Route Test Customer');

        // GET /api/v1/loyalty/program
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/loyalty/program')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Program Loyalitas');

        // PUT /api/v1/loyalty/program (Manager / Owner)
        $this->authenticateTenantUser($this->manager, $tenant)
            ->putJson('/api/v1/loyalty/program', [
                'name' => 'Program Waroeng Hebat',
                'points_per_currency' => 2,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Program Waroeng Hebat');

        // GET /api/v1/loyalty/tiers
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/loyalty/tiers')
            ->assertStatus(200);

        // POST /api/v1/loyalty/tiers
        $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/loyalty/tiers', [
                'name' => 'Diamond',
                'rank' => 4,
                'min_points_earned' => 5000,
                'multiplier_bps' => 20000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Diamond');

        // GET /api/v1/loyalty/rewards
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/loyalty/rewards')
            ->assertStatus(200);

        // POST /api/v1/loyalty/rewards
        $rewardRes = $this->authenticateTenantUser($this->manager, $tenant)
            ->postJson('/api/v1/loyalty/rewards', [
                'name' => 'Diskon Spesial HTTP',
                'points_cost' => 50,
                'reward_type' => 'discount_fixed',
                'discount_amount_minor' => 1000000,
                'min_purchase_minor' => 2000000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Diskon Spesial HTTP');

        $rewardId = $rewardRes->json('data.id');

        // GET /api/v1/loyalty/customers/{customer}/account
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/loyalty/customers/{$customer->id}/account")
            ->assertStatus(200)
            ->assertJsonPath('data.current_balance', 0);

        // POST /api/v1/loyalty/customers/{customer}/adjust (Owner)
        $this->authenticateTenantUser($this->owner, $tenant)
            ->postJson("/api/v1/loyalty/customers/{$customer->id}/adjust", [
                'points' => 100,
                'reason' => 'Welcome bonus HTTP test',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.balance_after', 100);

        // GET /api/v1/loyalty/customers/{customer}/ledger
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/loyalty/customers/{$customer->id}/ledger")
            ->assertStatus(200)
            ->assertJsonPath('data.0.type', 'adjustment');

        // POST /api/v1/loyalty/customers/{customer}/redeem
        $redeemRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson("/api/v1/loyalty/customers/{$customer->id}/redeem", [
                'reward_id' => $rewardId,
            ])
            ->assertStatus(201);

        $voucherCode = $redeemRes->json('data.code');

        // GET /api/v1/loyalty/customers/{customer}/vouchers
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson("/api/v1/loyalty/customers/{$customer->id}/vouchers")
            ->assertStatus(200)
            ->assertJsonPath('data.0.code', $voucherCode);

        // POST /api/v1/loyalty/vouchers/validate
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/loyalty/vouchers/validate', [
                'code' => $voucherCode,
                'subtotal_minor' => 3000000,
                'customer_id' => $customer->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.code', $voucherCode);

        // GET /api/v1/loyalty/reconcile/{account} (Manager)
        $account = $this->loyaltyService->getOrCreateAccount($tenant->id, $customer->id);
        $this->authenticateTenantUser($this->manager, $tenant)
            ->getJson("/api/v1/loyalty/reconcile/{$account->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', true);
    }
}
