<?php

namespace App\Services\Subscription;

use App\Domain\Tenant\TenantManager;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use App\Models\SubscriptionAddOnAssignment;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(
        protected SubscriptionEntitlementService $entitlementService,
        protected SubscriptionBillingProviderInterface $billingProvider,
        protected TenantManager $tenantManager
    ) {}

    /**
     * Initializes a new trial subscription for a tenant (usually upon merchant registration).
     */
    public function createTrialSubscription(Tenant $tenant, ?SubscriptionPlan $plan = null, int $trialDays = 14): Subscription
    {
        $plan = $plan ?? SubscriptionPlan::where('code', SubscriptionPlan::CODE_FREE)->firstOrFail();

        return DB::transaction(function () use ($tenant, $plan, $trialDays) {
            try {
                DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenant->id]);
            } catch (\Throwable) {
            }

            $now = Carbon::now();
            $trialEnd = $now->copy()->addDays($trialDays);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_TRIALING,
                'starts_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $trialEnd,
                'trial_ends_at' => $trialEnd,
                'billing_provider' => $this->billingProvider->getProviderKey(),
                'metadata' => [
                    'trial_duration_days' => $trialDays,
                ],
            ]);

            SubscriptionEvent::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'event_type' => SubscriptionEvent::EVENT_TRIAL_STARTED,
                'user_id' => null,
                'from_plan_id' => null,
                'to_plan_id' => $plan->id,
                'payload' => [
                    'trial_days' => $trialDays,
                    'trial_ends_at' => $trialEnd->toIso8601String(),
                ],
            ]);

            $this->entitlementService->clearCache($tenant);

            return $subscription;
        });
    }

    /**
     * Upgrades tenant to a higher plan.
     */
    public function upgrade(Tenant $tenant, SubscriptionPlan $newPlan, ?User $actor = null): Subscription
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $newPlan, $actor) {
            return DB::transaction(function () use ($tenant, $newPlan, $actor) {
                $subscription = Subscription::where('tenant_id', $tenant->id)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldPlanId = $subscription->plan_id;
                $now = Carbon::now();

                $subscription->update([
                    'plan_id' => $newPlan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'current_period_start' => $now,
                    'current_period_end' => $now->copy()->addMonth(),
                    'cancelled_at' => null,
                    'ended_at' => null,
                ]);

                SubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => SubscriptionEvent::EVENT_PLAN_UPGRADED,
                    'user_id' => $actor?->id,
                    'from_plan_id' => $oldPlanId,
                    'to_plan_id' => $newPlan->id,
                    'payload' => [
                        'from_plan' => $oldPlanId,
                        'to_plan' => $newPlan->id,
                        'period_start' => $now->toIso8601String(),
                        'period_end' => $subscription->current_period_end->toIso8601String(),
                    ],
                ]);

                AuditLog::create([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $actor?->id,
                    'action' => 'subscription.upgraded',
                    'entity_type' => Subscription::class,
                    'entity_id' => $subscription->id,
                    'before' => ['plan_id' => $oldPlanId],
                    'after' => ['plan_id' => $newPlan->id, 'status' => $subscription->status],
                    'created_at' => now(),
                ]);

                $this->entitlementService->clearCache($tenant);

                return $subscription->fresh(['plan.entitlements', 'addOnAssignments.addOn']);
            });
        });
    }

    /**
     * Downgrades tenant to a lower plan.
     * Preserves all existing business resources and data without destructive mutations.
     */
    public function downgrade(Tenant $tenant, SubscriptionPlan $newPlan, ?User $actor = null): Subscription
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $newPlan, $actor) {
            return DB::transaction(function () use ($tenant, $newPlan, $actor) {
                $subscription = Subscription::where('tenant_id', $tenant->id)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldPlanId = $subscription->plan_id;
                $now = Carbon::now();

                $subscription->update([
                    'plan_id' => $newPlan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'current_period_start' => $now,
                    'current_period_end' => $now->copy()->addMonth(),
                    'cancelled_at' => null,
                    'ended_at' => null,
                ]);

                SubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => SubscriptionEvent::EVENT_PLAN_DOWNGRADED,
                    'user_id' => $actor?->id,
                    'from_plan_id' => $oldPlanId,
                    'to_plan_id' => $newPlan->id,
                    'payload' => [
                        'from_plan' => $oldPlanId,
                        'to_plan' => $newPlan->id,
                        'preserves_existing_resources' => true,
                    ],
                ]);

                AuditLog::create([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $actor?->id,
                    'action' => 'subscription.downgraded',
                    'entity_type' => Subscription::class,
                    'entity_id' => $subscription->id,
                    'before' => ['plan_id' => $oldPlanId],
                    'after' => ['plan_id' => $newPlan->id, 'status' => $subscription->status],
                    'created_at' => now(),
                ]);

                $this->entitlementService->clearCache($tenant);

                return $subscription->fresh(['plan.entitlements', 'addOnAssignments.addOn']);
            });
        });
    }

    /**
     * Cancels an active subscription.
     * Retains access until current_period_end.
     */
    public function cancel(Tenant $tenant, ?User $actor = null): Subscription
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $actor) {
            return DB::transaction(function () use ($tenant, $actor) {
                $subscription = Subscription::where('tenant_id', $tenant->id)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($subscription->status === Subscription::STATUS_CANCELLED) {
                    return $subscription;
                }

                $now = Carbon::now();
                $subscription->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'cancelled_at' => $now,
                ]);

                SubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => SubscriptionEvent::EVENT_SUBSCRIPTION_CANCELLED,
                    'user_id' => $actor?->id,
                    'from_plan_id' => $subscription->plan_id,
                    'to_plan_id' => $subscription->plan_id,
                    'payload' => [
                        'cancelled_at' => $now->toIso8601String(),
                        'accessible_until' => $subscription->current_period_end->toIso8601String(),
                    ],
                ]);

                AuditLog::create([
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $actor?->id,
                    'action' => 'subscription.cancelled',
                    'entity_type' => Subscription::class,
                    'entity_id' => $subscription->id,
                    'before' => ['status' => Subscription::STATUS_ACTIVE],
                    'after' => ['status' => Subscription::STATUS_CANCELLED, 'cancelled_at' => $now],
                    'created_at' => now(),
                ]);

                $this->entitlementService->clearCache($tenant);

                return $subscription->fresh(['plan.entitlements', 'addOnAssignments.addOn']);
            });
        });
    }

    /**
     * Renews an existing subscription for an additional billing cycle.
     */
    public function renew(Tenant $tenant, ?User $actor = null): Subscription
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $actor) {
            return DB::transaction(function () use ($tenant, $actor) {
                $subscription = Subscription::where('tenant_id', $tenant->id)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                $now = Carbon::now();
                $newPeriodStart = $subscription->current_period_end->isFuture() ? $subscription->current_period_end : $now;
                $newPeriodEnd = $newPeriodStart->copy()->addMonth();

                $subscription->update([
                    'status' => Subscription::STATUS_ACTIVE,
                    'current_period_start' => $newPeriodStart,
                    'current_period_end' => $newPeriodEnd,
                    'cancelled_at' => null,
                    'ended_at' => null,
                ]);

                SubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => SubscriptionEvent::EVENT_SUBSCRIPTION_RENEWED,
                    'user_id' => $actor?->id,
                    'from_plan_id' => $subscription->plan_id,
                    'to_plan_id' => $subscription->plan_id,
                    'payload' => [
                        'renewed_at' => $now->toIso8601String(),
                        'period_start' => $newPeriodStart->toIso8601String(),
                        'period_end' => $newPeriodEnd->toIso8601String(),
                    ],
                ]);

                $this->entitlementService->clearCache($tenant);

                return $subscription->fresh(['plan.entitlements', 'addOnAssignments.addOn']);
            });
        });
    }

    /**
     * Assigns an add-on to the active subscription.
     */
    public function assignAddOn(Tenant $tenant, SubscriptionAddOn $addOn, int $quantity = 1, ?User $actor = null): SubscriptionAddOnAssignment
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $addOn, $quantity, $actor) {
            return DB::transaction(function () use ($tenant, $addOn, $quantity, $actor) {
                $subscription = Subscription::where('tenant_id', $tenant->id)
                    ->latest('created_at')
                    ->firstOrFail();

                $assignment = SubscriptionAddOnAssignment::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $subscription->id,
                        'add_on_id' => $addOn->id,
                    ],
                    [
                        'quantity' => $quantity,
                        'status' => SubscriptionAddOnAssignment::STATUS_ACTIVE,
                        'starts_at' => Carbon::now(),
                    ]
                );

                SubscriptionEvent::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'event_type' => SubscriptionEvent::EVENT_ADDON_ASSIGNED,
                    'user_id' => $actor?->id,
                    'from_plan_id' => $subscription->plan_id,
                    'to_plan_id' => $subscription->plan_id,
                    'payload' => [
                        'add_on_code' => $addOn->code,
                        'quantity' => $quantity,
                    ],
                ]);

                $this->entitlementService->clearCache($tenant);

                return $assignment;
            });
        });
    }
}
