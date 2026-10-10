<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\Services\TenantOnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\UserTenantMembership;
use App\Support\PhoneNormalizer;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantController extends Controller
{
    /**
     * Create a new business and first outlet (Onboarding).
     */
    public function store(Request $request, TenantOnboardingService $onboardingService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => ['nullable', 'string', 'min:2', 'max:100'],
            'business_type' => ['nullable', 'string', Rule::in(['fnb', 'retail', 'service', 'production', 'online_seller'])],
            'outlet' => ['nullable', 'array'],
            'outlet.name' => ['nullable', 'string', 'max:255'],
            'outlet.code' => ['nullable', 'string', 'max:50'],
            'outlet_name' => ['nullable', 'string', 'max:255'],
            'outlet_code' => ['nullable', 'string', 'max:50'],
        ]);

        $outletName = $validated['outlet']['name'] ?? $validated['outlet_name'] ?? null;
        $outletCode = $validated['outlet']['code'] ?? $validated['outlet_code'] ?? null;

        $user = $request->user();
        $correlationId = $request->header('X-Correlation-ID', $request->header('X-Request-ID'));

        $result = $onboardingService->onboard(
            user: $user,
            data: [
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? null,
                'business_type' => $validated['business_type'] ?? 'fnb',
                'outlet_name' => $outletName,
                'outlet_code' => $outletCode,
            ],
            requestId: $correlationId,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        $tenant = $result['tenant'];
        $outlet = $result['outlet'];
        $membership = $result['membership'];

        return ApiResponse::success(
            data: [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'business_type' => $tenant->business_type,
                    'status' => $tenant->status,
                    'currency' => $tenant->currency,
                    'timezone' => $tenant->timezone,
                ],
                'outlet' => [
                    'id' => $outlet->id,
                    'name' => $outlet->name,
                    'code' => $outlet->code,
                    'status' => $outlet->status,
                ],
                'membership' => [
                    'id' => $membership->id,
                    'role' => 'owner',
                    'status' => $membership->status,
                    'outlet_access' => $membership->outlet_access,
                ],
            ],
            message: 'Business and initial outlet created successfully.',
            status: 201
        );
    }

    /**
     * List all businesses where the authenticated user has an active membership.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = UserTenantMembership::with(['tenant', 'role'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->get();

        $businesses = $memberships->map(function ($m) {
            $t = $m->tenant;

            return [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'business_type' => $t->business_type,
                'status' => $t->status,
                'currency' => $t->currency,
                'timezone' => $t->timezone,
                'role' => $m->role?->slug ?? 'member',
            ];
        });

        return ApiResponse::success(data: $businesses);
    }

    /**
     * Get details of a single business for which the caller is an active member.
     */
    public function show(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();

        $membership = UserTenantMembership::with(['tenant', 'role'])
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (! $membership || ! $membership->tenant) {
            return ApiResponse::error(
                message: 'Access denied to the requested business or business does not exist.',
                code: 'TENANT_ACCESS_DENIED',
                status: 403
            );
        }

        $tenant = $membership->tenant;
        if (! $tenant->is_active || $tenant->status !== 'active') {
            return ApiResponse::error(
                message: 'This business is suspended or archived.',
                code: 'BUSINESS_SUSPENDED',
                status: 403
            );
        }

        // Outlets query: owners see all active outlets, others see permitted outlets
        $isOwner = $membership->role?->slug === 'owner';
        $outletsQuery = $tenant->outlets()->where('status', 'active');
        if (! $isOwner && $membership->outlet_access !== 'all') {
            $permittedIds = $membership->assigned_outlet_ids ?? [];
            $outletsQuery->whereIn('id', $permittedIds);
        }
        $outlets = $outletsQuery->get([
            'id', 'tenant_id', 'name', 'code', 'status', 'address', 'address_line',
            'city', 'province', 'postal_code', 'phone_number', 'latitude', 'longitude',
            'timezone', 'operating_hours',
        ]);

        return ApiResponse::success(data: [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'business_type' => $tenant->business_type,
            'status' => $tenant->status,
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'description' => $tenant->description,
            'address_line' => $tenant->address_line,
            'city' => $tenant->city,
            'province' => $tenant->province,
            'postal_code' => $tenant->postal_code,
            'country_code' => $tenant->country_code,
            'latitude' => $tenant->latitude,
            'longitude' => $tenant->longitude,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
            'role' => $membership->role?->slug,
            'membership' => [
                'id' => $membership->id,
                'role' => $membership->role?->slug,
                'outlet_access' => $membership->outlet_access,
            ],
            'outlets' => $outlets,
        ]);
    }

    /**
     * Update business profile details (Owner or business.update permission).
     */
    public function update(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();

        $membership = UserTenantMembership::with(['tenant', 'role'])
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (! $membership || ! $membership->tenant) {
            return ApiResponse::error(
                message: 'Access denied to the requested business.',
                code: 'TENANT_ACCESS_DENIED',
                status: 403
            );
        }

        $canUpdate = ($membership->role?->slug === 'owner') || $membership->hasPermission('business.update');
        if (! $canUpdate) {
            return ApiResponse::error(
                message: 'Forbidden. You do not have permission to update this business.',
                code: 'FORBIDDEN_PERMISSION',
                status: 403
            );
        }

        $tenant = $membership->tenant;
        if ($tenant->status !== 'active' && ! $request->has('status')) {
            return ApiResponse::error(
                message: 'Cannot update a suspended or archived business.',
                code: 'BUSINESS_SUSPENDED',
                status: 403
            );
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'business_type' => ['sometimes', 'required', 'string', Rule::in(['fnb', 'retail', 'service', 'production', 'online_seller'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', Rule::in(['IDR', 'USD', 'SGD', 'MYR', 'EUR', 'GBP', 'JPY', 'AUD'])],
            'timezone' => ['sometimes', 'required', 'string', 'max:50', Rule::in(DateTimeZone::listIdentifiers())],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'suspended', 'archived'])],
            'slug' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
        ]);

        // Status change is restricted strictly to Owner
        if (isset($validated['status']) && $membership->role?->slug !== 'owner') {
            return ApiResponse::error(
                message: 'Only the business owner can change business status.',
                code: 'FORBIDDEN_PERMISSION',
                status: 403
            );
        }

        // Slug change validation
        if (isset($validated['slug'])) {
            $normalizedSlug = TenantOnboardingService::normalizeSlug($validated['slug']);
            if ($normalizedSlug !== $tenant->slug) {
                if (Tenant::where('slug', $normalizedSlug)->where('id', '!=', $tenant->id)->exists()) {
                    throw ValidationException::withMessages([
                        'slug' => ['This business slug is already taken.'],
                    ]);
                }
                $validated['slug'] = $normalizedSlug;
            }
        }

        // Normalize phone number
        if (! empty($validated['phone'])) {
            $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);
        }

        // Keep is_active synchronized with status
        if (isset($validated['status'])) {
            $validated['is_active'] = ($validated['status'] === 'active');
        }

        // Security: Whitelist allowed fields to prevent mass assignment of owner/membership/security fields
        $allowedFields = [
            'name', 'slug', 'business_type', 'phone', 'email', 'description',
            'address_line', 'city', 'province', 'postal_code', 'country_code',
            'latitude', 'longitude', 'currency', 'timezone', 'status', 'is_active',
        ];
        $updateData = array_intersect_key($validated, array_flip($allowedFields));

        $before = array_intersect_key($tenant->toArray(), $updateData);
        $tenant->update($updateData);
        $after = array_intersect_key($tenant->fresh()->toArray(), $updateData);

        // Determine audit event action
        $action = isset($validated['status']) && $before['status'] !== $after['status']
            ? 'business_status_changed'
            : 'business_updated';

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $user->id,
            'action' => $action,
            'entity_type' => Tenant::class,
            'entity_id' => $tenant->id,
            'before' => $before,
            'after' => $after,
            'request_id' => $request->header('X-Correlation-ID', $request->header('X-Request-ID')),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'business_type' => $tenant->business_type,
                'status' => $tenant->status,
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'description' => $tenant->description,
                'address_line' => $tenant->address_line,
                'city' => $tenant->city,
                'province' => $tenant->province,
                'postal_code' => $tenant->postal_code,
                'country_code' => $tenant->country_code,
                'latitude' => $tenant->latitude,
                'longitude' => $tenant->longitude,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
            ],
            message: 'Business profile updated successfully.'
        );
    }
}
