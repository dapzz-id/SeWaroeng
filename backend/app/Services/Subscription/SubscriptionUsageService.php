<?php

namespace App\Services\Subscription;

use App\Domain\Tenant\TenantManager;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\UserTenantMembership;
use Carbon\Carbon;

class SubscriptionUsageService
{
    public const DIMENSION_MAX_OUTLETS = 'max_outlets';

    public const DIMENSION_MAX_USERS = 'max_users';

    public const DIMENSION_MAX_PRODUCTS = 'max_products';

    public const DIMENSION_MAX_MONTHLY_TRANSACTIONS = 'max_monthly_transactions';

    public function __construct(
        protected TenantManager $tenantManager
    ) {}

    /**
     * Authoritatively calculates current tenant usage for a given quota dimension.
     */
    public function getUsage(Tenant $tenant, string $dimension): int
    {
        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $dimension) {
            return match ($dimension) {
                self::DIMENSION_MAX_OUTLETS => Outlet::where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->count(),

                self::DIMENSION_MAX_USERS => UserTenantMembership::where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->count(),

                self::DIMENSION_MAX_PRODUCTS => Product::where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->count(),

                self::DIMENSION_MAX_MONTHLY_TRANSACTIONS => Sale::where('tenant_id', $tenant->id)
                    ->where('status', Sale::STATUS_FINALIZED)
                    ->whereBetween('finalized_at', [
                        Carbon::now()->startOfMonth(),
                        Carbon::now()->endOfMonth(),
                    ])
                    ->count(),

                default => 0,
            };
        });
    }

    /**
     * Authoritative usage summary across all monitored dimensions.
     *
     * @return array<string, int>
     */
    public function getAllUsage(Tenant $tenant): array
    {
        return [
            self::DIMENSION_MAX_OUTLETS => $this->getUsage($tenant, self::DIMENSION_MAX_OUTLETS),
            self::DIMENSION_MAX_USERS => $this->getUsage($tenant, self::DIMENSION_MAX_USERS),
            self::DIMENSION_MAX_PRODUCTS => $this->getUsage($tenant, self::DIMENSION_MAX_PRODUCTS),
            self::DIMENSION_MAX_MONTHLY_TRANSACTIONS => $this->getUsage($tenant, self::DIMENSION_MAX_MONTHLY_TRANSACTIONS),
        ];
    }
}
