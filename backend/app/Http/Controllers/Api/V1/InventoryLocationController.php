<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\Outlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLocationController extends Controller
{
    /**
     * List inventory locations for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = InventoryLocation::where('tenant_id', $tenantId)->with('outlet:id,name,code');

        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($outletId = $request->query('outlet_id')) {
            $query->where('outlet_id', $outletId);
        }

        $locations = $query->orderBy('name')->get();

        return ApiResponse::success(data: $locations->map(fn ($loc) => [
            'id' => $loc->id,
            'tenant_id' => $loc->tenant_id,
            'outlet_id' => $loc->outlet_id,
            'outlet_name' => $loc->outlet?->name,
            'name' => $loc->name,
            'code' => $loc->code,
            'type' => $loc->type,
            'is_active' => $loc->is_active,
            'created_at' => $loc->created_at?->toIso8601String(),
        ]));
    }

    /**
     * Show inventory location detail.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $location = InventoryLocation::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with('outlet:id,name,code')
            ->firstOrFail();

        return ApiResponse::success(data: [
            'id' => $location->id,
            'tenant_id' => $location->tenant_id,
            'outlet_id' => $location->outlet_id,
            'outlet_name' => $location->outlet?->name,
            'name' => $location->name,
            'code' => $location->code,
            'type' => $location->type,
            'is_active' => $location->is_active,
            'created_at' => $location->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Create an inventory location.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'outlet_id' => 'nullable|uuid',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50',
            'type' => 'required|string|in:outlet,warehouse,kitchen,storage,central_warehouse,other',
            'is_active' => 'nullable|boolean',
        ]);

        if (! empty($validated['outlet_id'])) {
            $outlet = Outlet::where('id', $validated['outlet_id'])
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $outlet) {
                return ApiResponse::error(
                    message: 'Outlet not found or belongs to another tenant.',
                    code: 'OUTLET_CROSS_TENANT',
                    status: 422
                );
            }
        }

        $codeExists = InventoryLocation::where('tenant_id', $tenantId)
            ->where('code', $validated['code'])
            ->exists();

        if ($codeExists) {
            return ApiResponse::error(
                message: 'An inventory location with this code already exists.',
                code: 'LOCATION_CODE_DUPLICATE',
                status: 422
            );
        }

        $location = InventoryLocation::create([
            'tenant_id' => $tenantId,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'type' => $validated['type'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'inventory_location_created',
            'entity_type' => InventoryLocation::class,
            'entity_id' => $location->id,
            'after' => $location->toArray(),
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $location->id,
                'tenant_id' => $location->tenant_id,
                'outlet_id' => $location->outlet_id,
                'name' => $location->name,
                'code' => $location->code,
                'type' => $location->type,
                'is_active' => $location->is_active,
            ],
            message: 'Inventory location created successfully.',
            status: 201
        );
    }
}
