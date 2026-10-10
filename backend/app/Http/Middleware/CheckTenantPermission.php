<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $tenantContext = app(TenantContext::class);

        if (! $tenantContext->hasPermission($permission)) {
            return new JsonResponse([
                'message' => "Forbidden. Missing required permission: [{$permission}].",
                'code' => 'FORBIDDEN_PERMISSION',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
