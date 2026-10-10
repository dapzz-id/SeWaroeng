<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;

class ManualSubscriptionBillingProvider implements SubscriptionBillingProviderInterface
{
    public function getProviderKey(): string
    {
        return 'manual';
    }

    public function createCustomer(Tenant $tenant): string
    {
        return 'cust_manual_'.$tenant->id;
    }

    public function createSubscription(Tenant $tenant, SubscriptionPlan $plan, array $options = []): array
    {
        return [
            'provider' => 'manual',
            'external_id' => 'sub_manual_'.uniqid(),
            'status' => 'active',
        ];
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        return true;
    }

    public function resumeSubscription(Subscription $subscription): bool
    {
        return true;
    }
}
