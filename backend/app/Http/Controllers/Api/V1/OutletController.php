<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Support\OperatingHoursValidator;
use App\Support\PhoneNormalizer;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OutletController extends Controller
{
    /**
     * List all outlets in the current active tenant context.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $membership = $tenantContext->membership();

        $query = Outlet::where('tenant_id', $tenantContext->id());

        // By default show active outlets unless specifically requested by authorized user
        $includeInactive = $request->boolean('include_inactive', false);
        if (! $includeInactive) {
            $query->where('status', 'active');
        }

        // Owners see all active outlets; non-owners only see explicitly permitted outlets
        if (! $tenantContext->isOwner() && $membership->outlet_access !== 'all') {
            $permittedIds = $membership->assigned_outlet_ids ?? [];
            $query->whereIn('id', $permittedIds);
        }

        $outlets = $query->get()->map(function ($outlet) {
            return [
                'id' => $outlet->id,
                'tenant_id' => $outlet->tenant_id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'status' => $outlet->status,
                'address' => $outlet->address,
                'address_line' => $outlet->address_line,
                'city' => $outlet->city,
                'province' => $outlet->province,
                'postal_code' => $outlet->postal_code,
                'phone_number' => $outlet->phone_number,
                'latitude' => $outlet->latitude,
                'longitude' => $outlet->longitude,
                'timezone' => $outlet->timezone,
                'effective_timezone' => $outlet->effectiveTimezone(),
                'operating_hours' => $outlet->operating_hours,
            ];
        });

        return ApiResponse::success(data: $outlets);
    }

    /**
     * Display the specified outlet within the current active tenant context.
     */
    public function show(Request $request, string $outletId): JsonResponse
    {
        $tenantContext = app(TenantContext::class);

        $outlet = Outlet::where('id', $outletId)
            ->where('tenant_id', $tenantContext->id())
            ->firstOrFail();

        return ApiResponse::success(data: [
            'id' => $outlet->id,
            'tenant_id' => $outlet->tenant_id,
            'name' => $outlet->name,
            'code' => $outlet->code,
            'status' => $outlet->status,
            'address' => $outlet->address,
            'address_line' => $outlet->address_line,
            'city' => $outlet->city,
            'province' => $outlet->province,
            'postal_code' => $outlet->postal_code,
            'phone_number' => $outlet->phone_number,
            'latitude' => $outlet->latitude,
            'longitude' => $outlet->longitude,
            'timezone' => $outlet->timezone,
            'effective_timezone' => $outlet->effectiveTimezone(),
            'operating_hours' => $outlet->operating_hours,
            'settings' => $outlet->settings,
        ]);
    }

    /**
     * Create an additional outlet in the active tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);

        $canCreate = $tenantContext->isOwner() || $tenantContext->hasPermission('outlet.create');
        if (! $canCreate) {
            return ApiResponse::error(
                message: 'Forbidden. You do not have permission to create outlets.',
                code: 'FORBIDDEN_PERMISSION',
                status: 403
            );
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => ['required', 'string', 'min:2', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'timezone' => ['nullable', 'string', 'max:50', Rule::in(DateTimeZone::listIdentifiers())],
            'operating_hours' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $tenantId = $tenantContext->id();

        // Check unique code within tenant
        $exists = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->exists();

        if ($exists) {
            return ApiResponse::error(
                message: 'An outlet with this code already exists in your business.',
                code: 'OUTLET_CODE_TAKEN',
                status: 422
            );
        }

        // Validate operating hours if present
        $operatingHours = null;
        if (! empty($validated['operating_hours'])) {
            $operatingHours = OperatingHoursValidator::validate($validated['operating_hours']);
        }

        $phone = ! empty($validated['phone_number'])
            ? PhoneNormalizer::normalize($validated['phone_number'])
            : null;

        $outlet = DB::transaction(function () use ($tenantContext, $tenantId, $validated, $code, $operatingHours, $phone) {
            // Pessimistic lock on tenant to guarantee race-condition safe quota evaluation
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->first();
            if ($tenant) {
                app(SubscriptionEntitlementService::class)->assertQuotaAvailable($tenant, 'max_outlets', 1);
            }

            $createdOutlet = Outlet::create([
                'tenant_id' => $tenantId, // Derived strictly from TenantContext, never client input
                'name' => trim($validated['name']),
                'code' => $code,
                'address' => $validated['address'] ?? ($validated['address_line'] ?? null),
                'address_line' => $validated['address_line'] ?? null,
                'city' => $validated['city'] ?? null,
                'province' => $validated['province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'phone_number' => $phone,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
                'operating_hours' => $operatingHours,
                'settings' => $validated['settings'] ?? null,
                'status' => 'active',
                'is_active' => true,
            ]);

            // Attach to creator membership if owner
            if ($tenantContext->isOwner()) {
                $tenantContext->membership()->permittedOutlets()->syncWithoutDetaching([
                    $createdOutlet->id => ['id' => (string) Str::uuid()],
                ]);
            }

            return $createdOutlet;
        });

        // Record Audit Log
        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'outlet_created',
            'entity_type' => Outlet::class,
            'entity_id' => $outlet->id,
            'before' => null,
            'after' => [
                'name' => $outlet->name,
                'code' => $outlet->code,
                'status' => $outlet->status,
                'timezone' => $outlet->timezone,
            ],
            'request_id' => $request->header('X-Correlation-ID', $request->header('X-Request-ID')),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $outlet->id,
                'tenant_id' => $outlet->tenant_id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'status' => $outlet->status,
                'address' => $outlet->address,
                'address_line' => $outlet->address_line,
                'city' => $outlet->city,
                'province' => $outlet->province,
                'postal_code' => $outlet->postal_code,
                'phone_number' => $outlet->phone_number,
                'latitude' => $outlet->latitude,
                'longitude' => $outlet->longitude,
                'timezone' => $outlet->timezone,
                'effective_timezone' => $outlet->effectiveTimezone(),
                'operating_hours' => $outlet->operating_hours,
            ],
            message: 'Outlet created successfully.',
            status: 201
        );
    }

    /**
     * Update an outlet in the active tenant.
     */
    public function update(Request $request, string $outletId): JsonResponse
    {
        $tenantContext = app(TenantContext::class);

        $canUpdate = $tenantContext->isOwner() || $tenantContext->hasPermission('outlet.update');
        if (! $canUpdate) {
            return ApiResponse::error(
                message: 'Forbidden. You do not have permission to update outlets.',
                code: 'FORBIDDEN_PERMISSION',
                status: 403
            );
        }

        $outlet = Outlet::where('id', $outletId)
            ->where('tenant_id', $tenantContext->id())
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'timezone' => ['nullable', 'string', 'max:50', Rule::in(DateTimeZone::listIdentifiers())],
            'operating_hours' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ]);

        // Status change permission check
        if (isset($validated['status']) && $validated['status'] !== $outlet->status) {
            $requiredPerm = $validated['status'] === 'active' ? 'outlet.activate' : 'outlet.deactivate';
            if (! $tenantContext->isOwner() && ! $tenantContext->hasPermission($requiredPerm)) {
                return ApiResponse::error(
                    message: "Forbidden. Missing [{$requiredPerm}] permission.",
                    code: 'FORBIDDEN_PERMISSION',
                    status: 403
                );
            }
            $validated['is_active'] = ($validated['status'] === 'active');
        }

        // Normalize phone number
        if (! empty($validated['phone_number'])) {
            $validated['phone_number'] = PhoneNormalizer::normalize($validated['phone_number']);
        }

        // Validate operating hours if updated
        if (isset($validated['operating_hours']) && is_array($validated['operating_hours'])) {
            $validated['operating_hours'] = OperatingHoursValidator::validate($validated['operating_hours']);
        }

        // Whitelist allowed update fields (prevents tenant_id or code alteration)
        $allowedFields = [
            'name', 'address', 'address_line', 'city', 'province', 'postal_code',
            'phone_number', 'latitude', 'longitude', 'timezone', 'operating_hours',
            'settings', 'status', 'is_active',
        ];
        $updateData = array_intersect_key($validated, array_flip($allowedFields));

        $before = array_intersect_key($outlet->toArray(), $updateData);
        $outlet->update($updateData);
        $after = array_intersect_key($outlet->fresh()->toArray(), $updateData);

        // Determine audit event
        $action = isset($validated['status']) && $before['status'] !== $after['status']
            ? 'outlet_status_changed'
            : 'outlet_updated';

        AuditLog::create([
            'tenant_id' => $tenantContext->id(),
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => Outlet::class,
            'entity_id' => $outlet->id,
            'before' => $before,
            'after' => $after,
            'request_id' => $request->header('X-Correlation-ID', $request->header('X-Request-ID')),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $outlet->id,
                'tenant_id' => $outlet->tenant_id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'status' => $outlet->status,
                'address' => $outlet->address,
                'address_line' => $outlet->address_line,
                'city' => $outlet->city,
                'province' => $outlet->province,
                'postal_code' => $outlet->postal_code,
                'phone_number' => $outlet->phone_number,
                'latitude' => $outlet->latitude,
                'longitude' => $outlet->longitude,
                'timezone' => $outlet->timezone,
                'effective_timezone' => $outlet->effectiveTimezone(),
                'operating_hours' => $outlet->operating_hours,
            ],
            message: 'Outlet updated successfully.'
        );
    }
}
