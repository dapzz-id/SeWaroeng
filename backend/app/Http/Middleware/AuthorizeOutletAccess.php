<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Models\Outlet;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeOutletAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $paramName = 'outletId'): Response
    {
        $tenantContext = app(TenantContext::class);

        // Resolve requested outlet ID from route parameter, header, or payload
        $outletId = $request->route($paramName)
            ?? $request->route('outletId')
            ?? $request->route('outlet')
            ?? $request->header('X-Outlet-ID')
            ?? $request->input('outlet_id');

        if ($outletId instanceof Outlet) {
            $outletId = (string) $outletId->id;
        }

        if (! $outletId) {
            return $next($request);
        }

        // 1. Verify outlet belongs to current tenant (Tenancy Boundary)
        $outlet = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $tenantContext->id())
            ->where('id', $outletId)
            ->first();

        if (! $outlet) {
            return new JsonResponse([
                'message' => 'Outlet not found or does not belong to this tenant.',
                'code' => 'OUTLET_NOT_FOUND',
            ], Response::HTTP_NOT_FOUND);
        }

        // 2. Verify user has assigned access to this specific outlet (RBAC Boundary)
        if (! $tenantContext->hasOutletAccess($outletId)) {
            return new JsonResponse([
                'message' => 'User is not authorized to access this outlet.',
                'code' => 'OUTLET_ACCESS_DENIED',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
