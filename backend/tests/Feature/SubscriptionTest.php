<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\SubscriptionQuotaExceededException;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\Subscription\SubscriptionService;
use App\Services\Subscription\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class SubscriptionTest extends TenantTestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a platform superadmin user.
     */
    protected function createSuperadmin(): User
    {
        return User::factory()->create([
            'is_superadmin' => true,
            'is_active' => true,
        ]);
    }

    public function test_new_tenant_automatically_receives_trial_subscription_on_free_plan(): void
    {
        $tenantData = $this->createTenant('Warung Trial Baru');
        $tenant = $tenantData['tenant'];

        $subscription = app(TenantManager::class)->runForTenant($tenant, function () use ($tenant) {
            return Subscription::where('tenant_id', $tenant->id)->first();
        });

        $this->assertNotNull($subscription);
        $this->assertEquals(Subscription::STATUS_TRIALING, $subscription->status);
        $this->assertEquals(SubscriptionPlan::CODE_FREE, $subscription->plan->code);
        $this->assertTrue($subscription->isTrial());
        $this->assertTrue($subscription->isOperational());
        $this->assertNotNull($subscription->trial_ends_at);

        // Verify trial event recorded
        $event = app(TenantManager::class)->runForTenant($tenant, function () use ($tenant) {
            return SubscriptionEvent::where('tenant_id', $tenant->id)->first();
        });
        $this->assertNotNull($event);
        $this->assertEquals(SubscriptionEvent::EVENT_TRIAL_STARTED, $event->event_type);
    }

    public function test_tenant_can_view_subscription_overview_usage_and_entitlements(): void
    {
        $tenantData = $this->createTenant('Warung Kopi Jaya');
        $tenant = $tenantData['tenant'];
        $owner = $tenantData['owner'];

        // 1. Overview
        $resOverview = $this->actingAs($owner)->getJson('/api/v1/subscription', [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);
        $resOverview->assertStatus(200)
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.plan.code', 'free')
            ->assertJsonPath('data.is_trial', true);

        // 2. Usage
        $resUsage = $this->actingAs($owner)->getJson('/api/v1/subscription/usage', [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);
        $resUsage->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'max_outlets' => ['limit', 'current', 'remaining', 'is_unlimited'],
                    'max_users' => ['limit', 'current', 'remaining', 'is_unlimited'],
                    'max_products' => ['limit', 'current', 'remaining', 'is_unlimited'],
                    'max_monthly_transactions' => ['limit', 'current', 'remaining', 'is_unlimited'],
                ],
            ]);
        $this->assertEquals(1, $resUsage->json('data.max_outlets.current')); // Outlet 1 created by helper

        // 3. Entitlements
        $resEntitlements = $this->actingAs($owner)->getJson('/api/v1/subscription/entitlements', [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);
        $resEntitlements->assertStatus(200)
            ->assertJsonPath('data.features.pos', true)
            ->assertJsonPath('data.features.offline_pos', true)
            ->assertJsonPath('data.features.whatsapp', false);
    }

    public function test_superadmin_can_list_create_and_update_subscription_plans(): void
    {
        $superadmin = $this->createSuperadmin();

        // 1. List plans
        $listRes = $this->actingAs($superadmin)->getJson('/api/v1/platform/subscription-plans');
        $listRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(4, count($listRes->json('data')));

        // 2. Create custom enterprise plan
        $createRes = $this->actingAs($superadmin)->postJson('/api/v1/platform/subscription-plans', [
            'code' => 'enterprise_custom',
            'name' => 'Enterprise Custom',
            'description' => 'Dedicated custom tier for multi-brand chains.',
            'price_minor' => 99900000,
            'currency' => 'IDR',
            'is_active' => true,
            'sort_order' => 10,
            'entitlements' => [
                ['feature_key' => 'pos', 'is_enabled' => true, 'quota_limit' => null],
                ['feature_key' => 'multi_outlet', 'is_enabled' => true, 'quota_limit' => null],
                ['feature_key' => 'max_outlets', 'is_enabled' => true, 'quota_limit' => 50],
            ],
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('data.code', 'enterprise_custom')
            ->assertJsonPath('data.name', 'Enterprise Custom');

        $planId = $createRes->json('data.id');

        // 3. Update plan
        $updateRes = $this->actingAs($superadmin)->patchJson("/api/v1/platform/subscription-plans/{$planId}", [
            'name' => 'Enterprise Custom v2',
            'price_minor' => 120000000,
        ]);
        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Enterprise Custom v2');
    }

    public function test_tenant_cannot_access_platform_subscription_routes(): void
    {
        $tenantData = $this->createTenant('Warung Hack Target');
        $owner = $tenantData['owner'];

        $res = $this->actingAs($owner)->getJson('/api/v1/platform/subscription-plans');
        $res->assertStatus(403)
            ->assertJsonPath('code', 'SUPERADMIN_REQUIRED');

        $resCreate = $this->actingAs($owner)->postJson('/api/v1/platform/subscription-plans', [
            'code' => 'forged_plan',
            'name' => 'Forged Plan',
            'price_minor' => 0,
        ]);
        $resCreate->assertStatus(403)
            ->assertJsonPath('code', 'SUPERADMIN_REQUIRED');
    }

    public function test_upgrade_and_downgrade_lifecycle_and_audit(): void
    {
        $tenantData = $this->createTenant('Warung Scale Up');
        $tenant = $tenantData['tenant'];
        $owner = $tenantData['owner'];

        $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->firstOrFail();
        $subscriptionService = app(SubscriptionService::class);

        // 1. Upgrade to Business
        $upgraded = $subscriptionService->upgrade($tenant, $businessPlan, $owner);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $upgraded->status);
        $this->assertEquals('business', $upgraded->plan->code);

        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $businessPlan) {
            $this->assertDatabaseHas('subscription_events', [
                'tenant_id' => $tenant->id,
                'event_type' => SubscriptionEvent::EVENT_PLAN_UPGRADED,
                'to_plan_id' => $businessPlan->id,
            ]);

            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $tenant->id,
                'action' => 'subscription.upgraded',
            ]);
        });

        // 2. Downgrade to Starter (Preserves existing data!)
        $starterPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_STARTER)->firstOrFail();
        $downgraded = $subscriptionService->downgrade($tenant, $starterPlan, $owner);
        $this->assertEquals(Subscription::STATUS_ACTIVE, $downgraded->status);
        $this->assertEquals('starter', $downgraded->plan->code);

        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $starterPlan) {
            $this->assertDatabaseHas('subscription_events', [
                'tenant_id' => $tenant->id,
                'event_type' => SubscriptionEvent::EVENT_PLAN_DOWNGRADED,
                'to_plan_id' => $starterPlan->id,
            ]);
        });
    }

    public function test_tenant_owner_can_cancel_subscription_and_cashier_cannot(): void
    {
        $tenantData = $this->createTenant('Warung Cancel Test');
        $tenant = $tenantData['tenant'];
        $owner = $tenantData['owner'];

        // Create Cashier user
        $cashier = User::factory()->create();
        $cashierRole = Role::where('slug', 'cashier')->firstOrFail();
        UserTenantMembership::create([
            'user_id' => $cashier->id,
            'tenant_id' => $tenant->id,
            'role_id' => $cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        // Cashier tries to cancel -> 403 Forbidden
        $resCashier = $this->actingAs($cashier)->postJson('/api/v1/subscription/cancel', [], [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);
        $resCashier->assertStatus(403);

        // Owner cancels -> 200 OK
        $resOwner = $this->actingAs($owner)->postJson('/api/v1/subscription/cancel', [], [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);
        $resOwner->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $subscription = app(TenantManager::class)->runForTenant($tenant, function () use ($tenant) {
            return Subscription::where('tenant_id', $tenant->id)->first();
        });
        $this->assertEquals(Subscription::STATUS_CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertTrue($subscription->isOperational()); // Still accessible until end of period
    }

    public function test_expired_subscription_blocks_operational_features_and_quota_evaluation(): void
    {
        $tenantData = $this->createTenant('Warung Expired');
        $tenant = $tenantData['tenant'];
        $owner = $tenantData['owner'];

        // Mark subscription expired
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant) {
            $subscription = Subscription::where('tenant_id', $tenant->id)->first();
            $subscription->update([
                'status' => Subscription::STATUS_EXPIRED,
                'current_period_end' => Carbon::now()->subDay(),
                'trial_ends_at' => Carbon::now()->subDay(),
            ]);
        });

        app(SubscriptionEntitlementService::class)->clearCache($tenant);

        $this->assertFalse(app(SubscriptionEntitlementService::class)->can($tenant, 'pos'));

        // Attempting to create outlet with expired subscription throws 403 SUBSCRIPTION_INACTIVE
        $res = $this->actingAs($owner)->postJson('/api/v1/outlets', [
            'name' => 'Outlet Cabang Gagal',
            'code' => 'FAIL01',
        ], [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);

        $res->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
    }

    public function test_add_on_assignment_unlocks_capability_and_increases_quota(): void
    {
        $tenantData = $this->createTenant('Warung Addon Test');
        $tenant = $tenantData['tenant'];

        $entitlementService = app(SubscriptionEntitlementService::class);
        $subscriptionService = app(SubscriptionService::class);

        // In free plan, whatsapp feature is disabled
        $this->assertFalse($entitlementService->can($tenant, 'whatsapp'));
        $baseOutletLimit = $entitlementService->limit($tenant, 'max_outlets');

        // Assign WhatsApp Add-on
        $whatsAppAddon = SubscriptionAddOn::where('code', 'addon.whatsapp')->firstOrFail();
        $subscriptionService->assignAddOn($tenant, $whatsAppAddon, 1);

        $this->assertTrue($entitlementService->can($tenant, 'whatsapp'));

        // Assign Additional Outlet Add-on (+1)
        $outletAddon = SubscriptionAddOn::where('code', 'addon.additional_outlet')->firstOrFail();
        $subscriptionService->assignAddOn($tenant, $outletAddon, 2); // +2 outlets

        $newOutletLimit = $entitlementService->limit($tenant, 'max_outlets');
        $this->assertEquals($baseOutletLimit + 2, $newOutletLimit);
    }

    public function test_quota_exceeded_rejects_resource_creation(): void
    {
        $tenantData = $this->createTenant('Warung Limit Test');
        $tenant = $tenantData['tenant'];
        $owner = $tenantData['owner'];

        // Starter plan has max_outlets = 1
        $starterPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_STARTER)->firstOrFail();
        app(SubscriptionService::class)->upgrade($tenant, $starterPlan, $owner);

        // Tenant already has 1 outlet (created in createTenant). Attempting to create 2nd outlet must be rejected!
        $res = $this->actingAs($owner)->postJson('/api/v1/outlets', [
            'name' => 'Outlet Melebihi Kuota',
            'code' => 'OVER01',
        ], [
            'X-Tenant-ID' => (string) $tenant->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'SUBSCRIPTION_QUOTA_EXCEEDED');
    }

    public function test_concurrency_protection_on_last_quota_slot(): void
    {
        $tenantData = $this->createTenant('Warung Concurrency');
        $tenant = $tenantData['tenant'];

        // Free plan limit is 5 outlets. Current usage is 1.
        // We set current usage to 4 by creating 3 more outlets directly within tenant context
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant) {
            for ($i = 2; $i <= 4; $i++) {
                Outlet::create([
                    'tenant_id' => $tenant->id,
                    'name' => "Outlet {$i}",
                    'code' => "OUT-{$i}",
                    'status' => 'active',
                    'is_active' => true,
                ]);
            }
        });

        $entitlementService = app(SubscriptionEntitlementService::class);
        $this->assertEquals(4, $entitlementService->usage($tenant, 'max_outlets'));
        $this->assertEquals(5, $entitlementService->limit($tenant, 'max_outlets'));
        $this->assertEquals(1, $entitlementService->remaining($tenant, 'max_outlets'));

        // Simulate two sequential attempts competing for the final remaining 5th slot
        // Slot 5:
        $success = false;
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $entitlementService, &$success) {
            DB::transaction(function () use ($tenant, $entitlementService, &$success) {
                Tenant::where('id', $tenant->id)->lockForUpdate()->first();
                $entitlementService->assertQuotaAvailable($tenant, 'max_outlets', 1);
                Outlet::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Outlet 5',
                    'code' => 'OUT-5',
                    'status' => 'active',
                    'is_active' => true,
                ]);
                $success = true;
            });
        });
        $this->assertTrue($success);

        // Slot 6 (attempted concurrently or immediately after):
        $this->expectException(SubscriptionQuotaExceededException::class);
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $entitlementService) {
            DB::transaction(function () use ($tenant, $entitlementService) {
                Tenant::where('id', $tenant->id)->lockForUpdate()->first();
                $entitlementService->assertQuotaAvailable($tenant, 'max_outlets', 1);
                Outlet::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Outlet 6',
                    'code' => 'OUT-6',
                    'status' => 'active',
                    'is_active' => true,
                ]);
            });
        });
    }

    public function test_cross_tenant_subscription_isolation_via_rls(): void
    {
        $tenantAData = $this->createTenant('Warung Alpha');
        $tenantBData = $this->createTenant('Warung Beta');

        $tenantA = $tenantAData['tenant'];
        $tenantB = $tenantBData['tenant'];
        $ownerA = $tenantAData['owner'];

        $subB = app(TenantManager::class)->runForTenant($tenantB, function () use ($tenantB) {
            return Subscription::where('tenant_id', $tenantB->id)->firstOrFail();
        });
        $this->assertNotNull($subB);

        // Owner A tries to access subscription using Tenant B context -> denied by RLS/TenantContext
        $res = $this->actingAs($ownerA)->getJson('/api/v1/subscription', [
            'X-Tenant-ID' => (string) $tenantB->id,
        ]);
        $res->assertStatus(403);
    }

    public function test_subscription_usage_only_counts_authoritative_finalized_sales(): void
    {
        $tenantData = $this->createTenant('Warung Usage Test');
        $tenant = $tenantData['tenant'];
        $outlet = $tenantData['outlet'];
        $owner = $tenantData['owner'];

        $usageService = app(SubscriptionUsageService::class);

        // Initial monthly transactions count is 0
        $this->assertEquals(0, $usageService->getUsage($tenant, 'max_monthly_transactions'));

        // 1. Create Open Sale -> does not count
        $openSale = app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $outlet, $owner) {
            return Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'cashier_user_id' => $owner->id,
                'reference_number' => 'SALE-OPEN-01',
                'status' => Sale::STATUS_OPEN,
                'opened_at' => now(),
                'subtotal_minor' => 10000,
                'total_minor' => 10000,
            ]);
        });
        $this->assertEquals(0, $usageService->getUsage($tenant, 'max_monthly_transactions'));

        // 2. Create Cancelled Sale -> does not count
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $outlet, $owner) {
            return Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'cashier_user_id' => $owner->id,
                'reference_number' => 'SALE-CANCELLED-01',
                'status' => Sale::STATUS_CANCELLED,
                'opened_at' => now(),
                'cancelled_at' => now(),
                'subtotal_minor' => 15000,
                'total_minor' => 15000,
            ]);
        });
        $this->assertEquals(0, $usageService->getUsage($tenant, 'max_monthly_transactions'));

        // 3. Create Sale Finalized last month -> does not count in current month
        app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $outlet, $owner) {
            return Sale::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'cashier_user_id' => $owner->id,
                'reference_number' => 'SALE-PAST-MONTH',
                'status' => Sale::STATUS_FINALIZED,
                'opened_at' => now()->subMonth(),
                'finalized_at' => now()->subMonth(),
                'subtotal_minor' => 20000,
                'total_minor' => 20000,
            ]);
        });
        $this->assertEquals(0, $usageService->getUsage($tenant, 'max_monthly_transactions'));

        // 4. Finalize the Open Sale -> increments to exactly 1
        app(TenantManager::class)->runForTenant($tenant, function () use ($openSale) {
            $openSale->update([
                'status' => Sale::STATUS_FINALIZED,
                'finalized_at' => now(),
            ]);
        });
        $this->assertEquals(1, $usageService->getUsage($tenant, 'max_monthly_transactions'));

        // 5. Idempotent check: querying usage again returns exactly 1 (no double counting)
        $this->assertEquals(1, $usageService->getUsage($tenant, 'max_monthly_transactions'));
    }
}
