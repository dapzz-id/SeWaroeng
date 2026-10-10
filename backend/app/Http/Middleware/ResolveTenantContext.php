<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\TenantManager;
use App\Models\UserTenantMembership;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(
        protected TenantManager $tenantManager
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse([
                'message' => 'Unauthenticated.',
                'code' => 'UNAUTHENTICATED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->is_active === false) {
            return new JsonResponse([
                'message' => 'Account is deactivated. Please contact support.',
                'code' => 'ACCOUNT_DEACTIVATED',
            ], Response::HTTP_FORBIDDEN);
        }

        $requestedTenantId = $request->header('X-Tenant-ID');

        if ($requestedTenantId) {
            // Find authoritative membership
            $membership = UserTenantMembership::with(['tenant', 'role.permissions'])
                ->where('user_id', $user->id)
                ->where('tenant_id', $requestedTenantId)
                ->where('status', 'active')
                ->first();

            if (! $membership || ! $membership->tenant || ! $membership->tenant->is_active) {
                return new JsonResponse([
                    'message' => 'Access denied to the requested tenant or tenant is suspended.',
                    'code' => 'TENANT_ACCESS_DENIED',
                ], Response::HTTP_FORBIDDEN);
            }
        } else {
            // No X-Tenant-ID provided: auto-resolve if user has exactly one active membership
            $memberships = UserTenantMembership::with(['tenant', 'role.permissions'])
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
                ->get();

            if ($memberships->isEmpty()) {
                return new JsonResponse([
                    'message' => 'User does not belong to any active tenant.',
                    'code' => 'NO_ACTIVE_TENANT',
                ], Response::HTTP_FORBIDDEN);
            }

            if ($memberships->count() > 1) {
                return new JsonResponse([
                    'message' => 'Multiple tenant memberships found. Please specify X-Tenant-ID header.',
                    'code' => 'TENANT_SELECTION_REQUIRED',
                    'tenants' => $memberships->map(fn ($m) => [
                        'id' => $m->tenant_id,
                        'name' => $m->tenant->name,
                        'role' => $m->role->slug,
                    ]),
                ], Response::HTTP_BAD_REQUEST);
            }

            $membership = $memberships->first();
        }

        // Establish tenant context authoritatively
        $context = new TenantContext($membership->tenant, $membership);
        $this->tenantManager->setContext($context);

        try {
            return $next($request);
        } finally {
            // Ensure no connection pollution occurs
            $this->tenantManager->clearContext();
        }
    }
}
