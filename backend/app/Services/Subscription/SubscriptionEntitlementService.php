<?php

namespace App\Services\Subscription;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\SubscriptionFeatureNotIncludedException;
use App\Exceptions\SubscriptionInactiveException;
use App\Exceptions\SubscriptionQuotaExceededException;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

class SubscriptionEntitlementService
{
    public function __construct(
        protected SubscriptionUsageService $usageService,
        protected TenantManager $tenantManager
    ) {}

    /**
     * Cache key for tenant subscription resolution.
     */
    protected function cacheKey(string $tenantId): string
    {
        return "tenant_subscription_entitlements:{$tenantId}";
    }

    /**
     * Clears cached entitlements for a given tenant.
     */
    public function clearCache(Tenant|string $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;
        Cache::forget($this->cacheKey($tenantId));
    }

    /**
     * Resolves active or trialing subscription for tenant within PostgreSQL RLS context.
     */
    public function getSubscription(Tenant $tenant): ?Subscription
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant) {
            return Cache::remember($this->cacheKey((string) $tenant->id), 300, function () use ($tenant) {
                $subscription = Subscription::with(['plan.entitlements', 'addOnAssignments.addOn'])
                    ->where('tenant_id', $tenant->id)
                    ->latest('created_at')
                    ->first();

                if (! $subscription) {
                    $freePlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_FREE)->first();
                    if ($freePlan) {
                        $subscription = app(SubscriptionService::class)->createTrialSubscription($tenant, $freePlan);
                    }
                }

                return $subscription;
            });
        });
    }

    /**
     * Checks if tenant has operational capability for a feature.
     */
    public function can(Tenant $tenant, string $featureKey): bool
    {
        $subscription = $this->getSubscription($tenant);

        if (! $subscription || ! $subscription->isOperational()) {
            return false;
        }

        $plan = $subscription->plan;
        if (! $plan) {
            return false;
        }

        // 1. Check base plan entitlement
        if ($plan->isFeatureEnabled($featureKey)) {
            return true;
        }

        // 2. Check active add-ons
        foreach ($subscription->addOnAssignments as $assignment) {
            if ($assignment->status === 'active' && $assignment->addOn) {
                if ($assignment->addOn->feature_key === $featureKey && $assignment->addOn->is_active) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolves quota limit for a given dimension (null = unlimited).
     */
    public function limit(Tenant $tenant, string $quotaDimension): ?int
    {
        $subscription = $this->getSubscription($tenant);

        if (! $subscription || ! $subscription->isOperational()) {
            return 0;
        }

        $plan = $subscription->plan;
        if (! $plan) {
            return 0;
        }

        $baseLimit = $plan->getQuotaLimit($quotaDimension);

        // If base plan provides unlimited, total is unlimited
        if ($baseLimit === null) {
            return null;
        }

        // Add additional capacity from active add-ons
        $addonIncrement = 0;
        foreach ($subscription->addOnAssignments as $assignment) {
            if ($assignment->status === 'active' && $assignment->addOn) {
                if ($assignment->addOn->feature_key === $quotaDimension && $assignment->addOn->quota_increment) {
                    $addonIncrement += ($assignment->quantity * $assignment->addOn->quota_increment);
                }
            }
        }

        return $baseLimit + $addonIncrement;
    }

    /**
     * Current usage count for a quota dimension.
     */
    public function usage(Tenant $tenant, string $quotaDimension): int
    {
        return $this->usageService->getUsage($tenant, $quotaDimension);
    }

    /**
     * Remaining quota slots available (null = unlimited).
     */
    public function remaining(Tenant $tenant, string $quotaDimension): ?int
    {
        $limit = $this->limit($tenant, $quotaDimension);

        if ($limit === null) {
            return null;
        }

        $currentUsage = $this->usage($tenant, $quotaDimension);

        return max(0, $limit - $currentUsage);
    }

    /**
     * Asserts that tenant has access to a feature, throwing exception if denied.
     *
     * @throws SubscriptionInactiveException
     * @throws SubscriptionFeatureNotIncludedException
     */
    public function assertCan(Tenant $tenant, string $featureKey): void
    {
        $subscription = $this->getSubscription($tenant);

        if (! $subscription || ! $subscription->isOperational()) {
            throw new SubscriptionInactiveException("Subscription is inactive or expired. Feature '{$featureKey}' is blocked.");
        }

        if (! $this->can($tenant, $featureKey)) {
            throw new SubscriptionFeatureNotIncludedException("Feature '{$featureKey}' is not included in current subscription plan.");
        }
    }

    /**
     * Asserts that tenant has quota capacity available, throwing exception if exceeded.
     *
     * @throws SubscriptionInactiveException
     * @throws SubscriptionQuotaExceededException
     */
    public function assertQuotaAvailable(Tenant $tenant, string $quotaDimension, int $increment = 1): void
    {
        $subscription = $this->getSubscription($tenant);

        if (! $subscription || ! $subscription->isOperational()) {
            throw new SubscriptionInactiveException("Subscription is inactive or expired. Quota validation failed for '{$quotaDimension}'.");
        }

        $limit = $this->limit($tenant, $quotaDimension);

        if ($limit === null) {
            return; // Unlimited quota
        }

        $currentUsage = $this->usage($tenant, $quotaDimension);

        if (($currentUsage + $increment) > $limit) {
            throw new SubscriptionQuotaExceededException(
                "Subscription quota limit ({$limit}) exceeded for '{$quotaDimension}'. Current usage is {$currentUsage}."
            );
        }
    }
}
