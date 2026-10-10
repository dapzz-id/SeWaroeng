<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\Subscription\SubscriptionService;
use App\Services\Subscription\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionEntitlementService $entitlementService,
        protected SubscriptionUsageService $usageService,
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * Get current tenant subscription overview.
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $subscription = $this->entitlementService->getSubscription($tenant);

        if (! $subscription) {
            return ApiResponse::error(
                message: 'No subscription found for this business.',
                code: 'SUBSCRIPTION_NOT_FOUND',
                status: 404
            );
        }

        $now = Carbon::now();
        $isTrial = $subscription->isTrial();
        $trialRemainingDays = ($isTrial && $subscription->trial_ends_at)
            ? max(0, $now->diffInDays($subscription->trial_ends_at, false))
            : 0;

        return ApiResponse::success(data: [
            'id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'status' => $subscription->status,
            'is_operational' => $subscription->isOperational(),
            'is_trial' => $isTrial,
            'trial_remaining_days' => (int) $trialRemainingDays,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'starts_at' => $subscription->starts_at->toIso8601String(),
            'current_period_start' => $subscription->current_period_start->toIso8601String(),
            'current_period_end' => $subscription->current_period_end->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'plan' => [
                'id' => $subscription->plan->id,
                'code' => $subscription->plan->code,
                'name' => $subscription->plan->name,
                'description' => $subscription->plan->description,
                'price_minor' => $subscription->plan->price_minor,
                'currency' => $subscription->plan->currency,
                'billing_interval' => $subscription->plan->billing_interval,
            ],
            'add_ons' => $subscription->addOnAssignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'code' => $assignment->addOn->code,
                'name' => $assignment->addOn->name,
                'quantity' => $assignment->quantity,
                'status' => $assignment->status,
            ]),
        ]);
    }

    /**
     * Get current authoritative usage metrics vs plan limits.
     */
    public function usage(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();

        $dimensions = [
            SubscriptionUsageService::DIMENSION_MAX_OUTLETS,
            SubscriptionUsageService::DIMENSION_MAX_USERS,
            SubscriptionUsageService::DIMENSION_MAX_PRODUCTS,
            SubscriptionUsageService::DIMENSION_MAX_MONTHLY_TRANSACTIONS,
        ];

        $usageData = [];
        foreach ($dimensions as $dimension) {
            $limit = $this->entitlementService->limit($tenant, $dimension);
            $usage = $this->entitlementService->usage($tenant, $dimension);
            $remaining = $this->entitlementService->remaining($tenant, $dimension);

            $usageData[$dimension] = [
                'limit' => $limit, // null means unlimited
                'current' => $usage,
                'remaining' => $remaining,
                'is_unlimited' => $limit === null,
            ];
        }

        return ApiResponse::success(data: $usageData);
    }

    /**
     * Get active entitlements and capability flags.
     */
    public function entitlements(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();

        $features = [
            'pos',
            'inventory',
            'customer_crm',
            'finance',
            'dashboard',
            'offline_pos',
            'multi_outlet',
            'advanced_reports',
            'whatsapp',
            'ai_copilot',
        ];

        $entitlementsMap = [];
        foreach ($features as $feature) {
            $entitlementsMap[$feature] = $this->entitlementService->can($tenant, $feature);
        }

        return ApiResponse::success(data: [
            'features' => $entitlementsMap,
        ]);
    }

    /**
     * Cancel tenant subscription.
     */
    public function cancel(Request $request): JsonResponse
    {
        $context = app(TenantContext::class);
        if (! $context->isOwner()) {
            return ApiResponse::error(
                message: 'Forbidden. Only the business owner can cancel the subscription.',
                code: 'FORBIDDEN_OWNER_REQUIRED',
                status: 403
            );
        }

        $subscription = $this->subscriptionService->cancel($context->tenant(), $request->user());

        return ApiResponse::success(
            data: [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
                'accessible_until' => $subscription->current_period_end->toIso8601String(),
            ],
            message: 'Subscription successfully cancelled. Access remains active until the end of the billing period.'
        );
    }
}
