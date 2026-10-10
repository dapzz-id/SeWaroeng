<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            return new JsonResponse([
                'message' => 'Account is deactivated. Please contact support.',
                'code' => 'ACCOUNT_DEACTIVATED',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
