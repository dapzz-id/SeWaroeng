<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformSuperadmin
{
    /**
     * Handle an incoming request for platform-scoped superadmin operations.
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

        if (! $user->isSuperadmin()) {
            return new JsonResponse([
                'message' => 'Forbidden. Superadmin platform privileges required.',
                'code' => 'SUPERADMIN_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        // Establish controlled platform context for PostgreSQL RLS policies
        DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
        DB::statement("SELECT set_config('app.current_tenant_id', '', true)");

        try {
            return $next($request);
        } finally {
            // Guarantee reset of platform context setting even under exceptions
            DB::statement("SELECT set_config('app.is_platform_context', '', true)");
        }
    }
}
