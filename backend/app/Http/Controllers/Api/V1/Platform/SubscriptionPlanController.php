<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanEntitlement;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * List all platform subscription plans with their entitlements.
     */
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::with('entitlements')
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(data: $plans);
    }

    /**
     * Create a new subscription plan (Platform Superadmin only).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:subscription_plans,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'price_minor' => 'required|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'billing_interval' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'entitlements' => 'nullable|array',
            'entitlements.*.feature_key' => 'required_with:entitlements|string|max:100',
            'entitlements.*.is_enabled' => 'required_with:entitlements|boolean',
            'entitlements.*.quota_limit' => 'nullable|integer',
        ]);

        $plan = DB::transaction(function () use ($validated, $request) {
            $entitlements = $validated['entitlements'] ?? [];
            unset($validated['entitlements']);

            $newPlan = SubscriptionPlan::create($validated);

            foreach ($entitlements as $e) {
                SubscriptionPlanEntitlement::create([
                    'plan_id' => $newPlan->id,
                    'feature_key' => $e['feature_key'],
                    'is_enabled' => $e['is_enabled'],
                    'quota_limit' => $e['quota_limit'] ?? null,
                ]);
            }

            AuditLog::create([
                'tenant_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'platform.subscription_plan.created',
                'entity_type' => SubscriptionPlan::class,
                'entity_id' => $newPlan->id,
                'before' => null,
                'after' => $newPlan->toArray(),
                'created_at' => now(),
            ]);

            return $newPlan->load('entitlements');
        });

        return ApiResponse::success(data: $plan, status: 201);
    }

    /**
     * Update an existing subscription plan.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'price_minor' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $before = $plan->toArray();
        $plan->update($validated);

        AuditLog::create([
            'tenant_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'platform.subscription_plan.updated',
            'entity_type' => SubscriptionPlan::class,
            'entity_id' => $plan->id,
            'before' => $before,
            'after' => $plan->fresh()->toArray(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(data: $plan->fresh('entitlements'));
    }

    /**
     * List tenant subscriptions across the platform.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $subscriptions = Subscription::with(['plan', 'tenant'])
            ->latest('created_at')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::success(data: $subscriptions);
    }

    /**
     * Assign a plan to a tenant (Superadmin administrative override).
     */
    public function assign(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $validated = $request->validate([
            'plan_id' => 'required|uuid|exists:subscription_plans,id',
            'transition_type' => 'nullable|string|in:upgrade,downgrade',
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);
        $transition = $validated['transition_type'] ?? 'upgrade';

        if ($transition === 'downgrade') {
            $subscription = $this->subscriptionService->downgrade($tenant, $plan, $request->user());
        } else {
            $subscription = $this->subscriptionService->upgrade($tenant, $plan, $request->user());
        }

        return ApiResponse::success(
            data: $subscription,
            message: "Successfully assigned {$plan->name} to tenant {$tenant->name}."
        );
    }
}
