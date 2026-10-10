<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Price;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceController extends Controller
{
    /**
     * List price records for variants or outlets.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $query = Price::where('tenant_id', $tenantId)->with(['variant.product:id,name', 'outlet:id,name,code']);

        if ($variantId = $request->query('variant_id')) {
            $query->where('variant_id', $variantId);
        }

        if ($request->has('outlet_id')) {
            $outletId = $request->query('outlet_id');
            if ($outletId === 'null' || $outletId === '') {
                $query->whereNull('outlet_id');
            } else {
                if (! $tenantContext->hasOutletAccess($outletId)) {
                    return ApiResponse::error(
                        message: 'User is not authorized to access this outlet.',
                        code: 'OUTLET_ACCESS_DENIED',
                        status: 403
                    );
                }
                $query->where('outlet_id', $outletId);
            }
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            $query->where(function ($q) use ($assigned) {
                $q->whereIn('outlet_id', $assigned)->orWhereNull('outlet_id');
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $prices = $query->orderByDesc('effective_from')->get();

        return ApiResponse::success(data: $prices->map(fn ($p) => [
            'id' => $p->id,
            'tenant_id' => $p->tenant_id,
            'variant_id' => $p->variant_id,
            'variant_name' => $p->variant?->name,
            'product_name' => $p->variant?->product?->name,
            'outlet_id' => $p->outlet_id,
            'outlet_name' => $p->outlet?->name,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'effective_from' => $p->effective_from?->toIso8601String(),
            'effective_until' => $p->effective_until?->toIso8601String(),
            'is_active' => $p->is_active,
        ]));
    }

    /**
     * Resolve effective price for a variant at an optional outlet.
     * Throws or returns PRICE_NOT_CONFIGURED if no active price exists.
     */
    public function resolve(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'variant_id' => 'required|uuid',
            'outlet_id' => 'nullable|uuid',
        ]);

        $variant = ProductVariant::where('id', $validated['variant_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $variant) {
            return ApiResponse::error(
                message: 'Variant not found or belongs to another business.',
                code: 'VARIANT_CROSS_TENANT',
                status: 404
            );
        }

        $tenantContext = app(TenantContext::class);
        if (! empty($validated['outlet_id']) && ! $tenantContext->hasOutletAccess($validated['outlet_id'])) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        $price = $variant->resolvePrice($validated['outlet_id'] ?? null);

        if (! $price) {
            return ApiResponse::error(
                message: 'No active price configured for this variant.',
                code: 'PRICE_NOT_CONFIGURED',
                status: 422
            );
        }

        return ApiResponse::success(data: [
            'id' => $price->id,
            'variant_id' => $price->variant_id,
            'outlet_id' => $price->outlet_id,
            'amount' => $price->amount,
            'currency' => $price->currency,
            'effective_from' => $price->effective_from?->toIso8601String(),
            'effective_until' => $price->effective_until?->toIso8601String(),
        ]);
    }

    /**
     * Create a new price entry (tenant default or outlet-specific).
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $tenantId = (string) $tenant->id;
        $businessCurrency = $tenant->currency ?: 'IDR';

        $validated = $request->validate([
            'variant_id' => 'required|uuid',
            'outlet_id' => 'nullable|uuid',
            'amount' => 'required|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'effective_from' => 'nullable|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'nullable|boolean',
        ]);

        // 1. Verify variant belongs to current tenant
        $variant = ProductVariant::where('id', $validated['variant_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $variant) {
            return ApiResponse::error(
                message: 'Variant not found or belongs to another business.',
                code: 'VARIANT_CROSS_TENANT',
                status: 422
            );
        }

        // 2. Verify outlet belongs to current tenant and user has access
        $tenantContext = app(TenantContext::class);
        if (! empty($validated['outlet_id'])) {
            $outlet = Outlet::where('id', $validated['outlet_id'])
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $outlet) {
                return ApiResponse::error(
                    message: 'Outlet not found or belongs to another business.',
                    code: 'OUTLET_CROSS_TENANT',
                    status: 422
                );
            }

            if (! $tenantContext->hasOutletAccess($validated['outlet_id'])) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: 403
                );
            }
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            return ApiResponse::error(
                message: 'Non-owner users cannot set tenant-wide base prices without an assigned outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        // 3. Verify currency matches tenant currency
        $priceCurrency = ! empty($validated['currency'])
            ? strtoupper(trim($validated['currency']))
            : $businessCurrency;

        if ($priceCurrency !== $businessCurrency) {
            return ApiResponse::error(
                message: "Price currency [{$priceCurrency}] must match business currency [{$businessCurrency}].",
                code: 'PRICE_CURRENCY_MISMATCH',
                status: 422
            );
        }

        try {
            $price = Price::create([
                'tenant_id' => $tenantId,
                'variant_id' => $variant->id,
                'outlet_id' => $validated['outlet_id'] ?? null,
                'amount' => (int) $validated['amount'],
                'currency' => $priceCurrency,
                'effective_from' => ! empty($validated['effective_from']) ? $validated['effective_from'] : now(),
                'effective_until' => $validated['effective_until'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $e) {
            // Check for PostgreSQL exclusion constraint violation (error code 23P01)
            if (str_contains($e->getMessage(), 'no_overlapping') || str_contains($e->getMessage(), 'exclusion constraint')) {
                return ApiResponse::error(
                    message: 'An active price for this variant and outlet scope already overlaps with the specified effective period.',
                    code: 'PRICE_OVERLAPPING',
                    status: 422
                );
            }
            throw $e;
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => 'price_created',
            'entity_type' => Price::class,
            'entity_id' => $price->id,
            'before' => null,
            'after' => [
                'variant_id' => $price->variant_id,
                'outlet_id' => $price->outlet_id,
                'amount' => $price->amount,
                'currency' => $price->currency,
                'effective_from' => $price->effective_from?->toIso8601String(),
                'effective_until' => $price->effective_until?->toIso8601String(),
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $price->id,
                'tenant_id' => $price->tenant_id,
                'variant_id' => $price->variant_id,
                'outlet_id' => $price->outlet_id,
                'amount' => $price->amount,
                'currency' => $price->currency,
                'is_active' => $price->is_active,
                'effective_from' => $price->effective_from?->toIso8601String(),
                'effective_until' => $price->effective_until?->toIso8601String(),
            ],
            message: 'Price created successfully.',
            status: 201
        );
    }

    /**
     * Update or deactivate an existing price entry.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $price = Price::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $tenantContext = app(TenantContext::class);
        if ($price->outlet_id) {
            if (! $tenantContext->hasOutletAccess($price->outlet_id)) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: 403
                );
            }
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            return ApiResponse::error(
                message: 'Non-owner users cannot update tenant-wide base prices.',
                code: 'OUTLET_ACCESS_DENIED',
                status: 403
            );
        }

        $validated = $request->validate([
            'amount' => 'sometimes|integer|min:0',
            'effective_until' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $before = [
            'amount' => $price->amount,
            'is_active' => $price->is_active,
            'effective_until' => $price->effective_until?->toIso8601String(),
        ];

        if (isset($validated['amount'])) {
            $price->amount = (int) $validated['amount'];
        }
        if (array_key_exists('effective_until', $validated)) {
            $price->effective_until = $validated['effective_until'];
        }

        $deactivated = false;
        if (isset($validated['is_active'])) {
            if ($price->is_active && ! $validated['is_active']) {
                $deactivated = true;
            }
            $price->is_active = $validated['is_active'];
        }

        try {
            $price->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'no_overlapping') || str_contains($e->getMessage(), 'exclusion constraint')) {
                return ApiResponse::error(
                    message: 'Updating this price causes an overlap with another active price record.',
                    code: 'PRICE_OVERLAPPING',
                    status: 422
                );
            }
            throw $e;
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $request->user()->id,
            'action' => $deactivated ? 'price_deactivated' : 'price_updated',
            'entity_type' => Price::class,
            'entity_id' => $price->id,
            'before' => $before,
            'after' => [
                'amount' => $price->amount,
                'is_active' => $price->is_active,
                'effective_until' => $price->effective_until?->toIso8601String(),
            ],
            'request_id' => $request->header('X-Request-ID'),
        ]);

        return ApiResponse::success(
            data: [
                'id' => $price->id,
                'variant_id' => $price->variant_id,
                'outlet_id' => $price->outlet_id,
                'amount' => $price->amount,
                'currency' => $price->currency,
                'is_active' => $price->is_active,
            ],
            message: 'Price updated successfully.'
        );
    }
}
