<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * User registration endpoint.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone_number' => 'nullable|string|max:30|unique:users,phone_number',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],
            'device_name' => 'nullable|string|max:100',
            'device_id' => 'nullable|string|max:64',
            'platform' => 'nullable|string|max:30',
        ], [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));
        $normalizedPhone = null;

        if (! empty($validated['phone_number'])) {
            $normalizedPhone = PhoneNormalizer::normalize($validated['phone_number']);
            if (! PhoneNormalizer::isValid($normalizedPhone)) {
                throw ValidationException::withMessages([
                    'phone_number' => ['The provided phone number is not a valid Indonesian mobile number.'],
                ]);
            }

            // Ensure uniqueness with normalized value
            if (User::where('phone_number', $normalizedPhone)->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => ['The phone number has already been taken.'],
                ]);
            }
        }

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => $normalizedEmail,
            'phone_number' => $normalizedPhone,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $deviceName = $validated['device_name'] ?? 'Default Device';
        $token = $user->createToken(name: $deviceName, abilities: ['*']);

        $tokenModel = $token->accessToken;
        $tokenModel->device_id = $validated['device_id'] ?? null;
        $tokenModel->platform = $validated['platform'] ?? 'unknown';
        $tokenModel->ip_address = $request->ip();
        $tokenModel->save();

        AuditLog::create([
            'tenant_id' => null,
            'actor_user_id' => $user->id,
            'action' => 'auth.registered',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'before' => null,
            'after' => [
                'user_id' => $user->id,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'device_id' => $tokenModel->device_id,
            ],
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'is_active' => $user->is_active,
                ],
                'session' => [
                    'id' => $tokenModel->id,
                    'device_id' => $tokenModel->device_id,
                    'device_name' => $tokenModel->name,
                    'platform' => $tokenModel->platform,
                    'expires_at' => $tokenModel->expires_at,
                ],
                'token' => $token->plainTextToken,
            ],
            message: 'User registered successfully.',
            status: 201
        );
    }

    /**
     * User authentication endpoint with brute-force rate limiting.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'identity' => 'nullable|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
            'device_id' => 'nullable|string|max:64',
            'platform' => 'nullable|string|max:30',
        ]);

        $rawIdentity = $request->input('identity')
            ?? $request->input('email')
            ?? $request->input('phone_number');

        if (empty($rawIdentity)) {
            throw ValidationException::withMessages([
                'identity' => ['Please provide an email or phone number.'],
            ]);
        }

        // Apply rate limit key: IP + normalized identity
        $rateLimitKey = 'login:'.Str::lower($rawIdentity).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return ApiResponse::error(
                message: "Too many login attempts. Please try again in {$seconds} seconds.",
                code: 'RATE_LIMIT_EXCEEDED',
                status: 429
            );
        }

        // Determine if identity is email or phone
        $user = null;
        if (str_contains($rawIdentity, '@')) {
            $user = User::where('email', strtolower(trim($rawIdentity)))->first();
        } else {
            $normalizedPhone = PhoneNormalizer::normalize($rawIdentity);
            $user = User::where('phone_number', $normalizedPhone)->first();
        }

        // Generic authentication failure to prevent account enumeration
        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($rateLimitKey, self::DECAY_SECONDS);

            AuditLog::create([
                'tenant_id' => null,
                'actor_user_id' => null,
                'action' => 'auth.login_failed',
                'entity_type' => 'user',
                'entity_id' => $user?->id,
                'before' => null,
                'after' => ['attempted_identity' => Str::mask($rawIdentity, '*', 3, -3)],
                'request_id' => $request->attributes->get('request_id'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return ApiResponse::error(
                message: 'Invalid credentials.',
                code: 'INVALID_CREDENTIALS',
                status: 401
            );
        }

        // Check account active state
        if ($user->is_active === false) {
            return ApiResponse::error(
                message: 'Account is deactivated. Please contact support.',
                code: 'ACCOUNT_DEACTIVATED',
                status: 403
            );
        }

        // Authentication succeeded: clear rate limiter
        RateLimiter::clear($rateLimitKey);

        $deviceName = $request->input('device_name', 'Default Device');
        $token = $user->createToken(name: $deviceName, abilities: ['*']);

        $tokenModel = $token->accessToken;
        $tokenModel->device_id = $request->input('device_id');
        $tokenModel->platform = $request->input('platform', 'unknown');
        $tokenModel->ip_address = $request->ip();
        $tokenModel->save();

        AuditLog::create([
            'tenant_id' => null,
            'actor_user_id' => $user->id,
            'action' => 'auth.login',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'before' => null,
            'after' => [
                'device_id' => $tokenModel->device_id,
                'device_name' => $tokenModel->name,
                'platform' => $tokenModel->platform,
            ],
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'is_active' => $user->is_active,
                ],
                'session' => [
                    'id' => $tokenModel->id,
                    'device_id' => $tokenModel->device_id,
                    'device_name' => $tokenModel->name,
                    'platform' => $tokenModel->platform,
                    'expires_at' => $tokenModel->expires_at,
                ],
                'token' => $token->plainTextToken,
                'tenants' => $user->memberships()->with(['tenant', 'role'])->get()->map(fn ($m) => [
                    'id' => $m->tenant_id,
                    'name' => $m->tenant?->name,
                    'role' => $m->role?->slug,
                    'status' => $m->status,
                ]),
            ],
            message: 'Authenticated successfully.'
        );
    }

    /**
     * User logout endpoint (revokes current session token).
     */
    public function logout(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();

        if ($currentToken) {
            AuditLog::create([
                'tenant_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'auth.logout',
                'entity_type' => 'personal_access_token',
                'entity_id' => $currentToken->id,
                'before' => ['session_id' => $currentToken->id, 'device_id' => $currentToken->device_id],
                'after' => null,
                'request_id' => $request->attributes->get('request_id'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            $currentToken->delete();
        }

        return ApiResponse::success(
            message: 'Token revoked successfully.'
        );
    }

    /**
     * Get current authenticated user profile and active session.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        return ApiResponse::success(
            data: [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'is_active' => $user->is_active,
                ],
                'session' => $currentToken ? [
                    'id' => $currentToken->id,
                    'device_id' => $currentToken->device_id,
                    'device_name' => $currentToken->name,
                    'platform' => $currentToken->platform,
                    'expires_at' => $currentToken->expires_at,
                ] : null,
            ]
        );
    }

    /**
     * List all active sessions/devices for the authenticated user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->id;

        $sessions = $user->tokens()
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device_name' => $token->name,
                'device_id' => $token->device_id,
                'platform' => $token->platform,
                'ip_address' => $token->ip_address,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'is_current' => $token->id === $currentId,
            ]);

        return ApiResponse::success(data: $sessions);
    }

    /**
     * Revoke a specific session/device token.
     */
    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();

        $token = $user->tokens()->where('id', $sessionId)->first();

        if (! $token) {
            return ApiResponse::error(
                message: 'Session not found.',
                code: 'SESSION_NOT_FOUND',
                status: 404
            );
        }

        AuditLog::create([
            'tenant_id' => null,
            'actor_user_id' => $user->id,
            'action' => 'auth.token_revoked',
            'entity_type' => 'personal_access_token',
            'entity_id' => $token->id,
            'before' => ['session_id' => $token->id, 'device_id' => $token->device_id],
            'after' => null,
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        $token->delete();

        return ApiResponse::success(message: 'Session revoked successfully.');
    }
}
