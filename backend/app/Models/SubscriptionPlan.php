<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory, HasUuidV7;

    public const CODE_FREE = 'free';

    public const CODE_STARTER = 'starter';

    public const CODE_BUSINESS = 'business';

    public const CODE_MULTI_OUTLET = 'multi_outlet';

    protected $table = 'subscription_plans';

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_minor',
        'currency',
        'billing_interval',
        'is_active',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'price_minor' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionPlanEntitlement::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function getEntitlement(string $featureKey): ?SubscriptionPlanEntitlement
    {
        return $this->entitlements->firstWhere('feature_key', $featureKey);
    }

    public function isFeatureEnabled(string $featureKey): bool
    {
        $entitlement = $this->getEntitlement($featureKey);

        return $entitlement ? $entitlement->is_enabled : false;
    }

    public function getQuotaLimit(string $dimension): ?int
    {
        $entitlement = $this->getEntitlement($dimension);

        return $entitlement ? $entitlement->quota_limit : null;
    }
}
