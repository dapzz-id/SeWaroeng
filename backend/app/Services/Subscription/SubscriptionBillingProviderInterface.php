<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;

/**
 * Interface SubscriptionBillingProviderInterface
 *
 * Strict boundary: SeWaroeng platform charges tenant.
 * Completely separate from tenant's merchant payment gateways (Midtrans/Duitku).
 */
interface SubscriptionBillingProviderInterface
{
    /**
     * Resolves the provider key identifier (e.g., 'manual', 'mock', 'future_gateway').
     */
    public function getProviderKey(): string;

    /**
     * Creates or syncs tenant customer record on billing provider.
     */
    public function createCustomer(Tenant $tenant): string;

    /**
     * Provisions subscription on billing provider.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> Provider response metadata
     */
    public function createSubscription(Tenant $tenant, SubscriptionPlan $plan, array $options = []): array;

    /**
     * Cancels subscription on billing provider.
     */
    public function cancelSubscription(Subscription $subscription): bool;

    /**
     * Resumes or renews subscription on billing provider.
     */
    public function resumeSubscription(Subscription $subscription): bool;
}
