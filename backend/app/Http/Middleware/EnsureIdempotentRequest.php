<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantManager;
use App\Models\IdempotencyRecord;
use Closure;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Hardened Middleware providing durable effectively-once semantics via Idempotency-Key headers.
 * Uses dual-layer architecture: Redis L1 lock/cache + PostgreSQL durable idempotency_records.
 * Complies with ADR-0002, ADR-0004 & Phase 0C specifications.
 */
class EnsureIdempotentRequest
{
    private const TTL_SECONDS = 86400; // 24 hours

    private const LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only evaluate mutating HTTP verbs
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey) {
            return $next($request);
        }

        $tenantId = app(TenantManager::class)->currentTenantId();
        $userId = $request->user()?->id;

        // Calculate cryptographic fingerprint of the request payload and route
        $rawPayload = json_encode($request->all()) ?: '';
        $requestFingerprint = hash('sha256', "{$request->method()}:{$request->path()}:{$rawPayload}");

        $cachePrefix = $tenantId ?? 'global';
        $cacheKey = "idempotency:record:{$cachePrefix}:{$idempotencyKey}";
        $lockKey = "idempotency:lock:{$cachePrefix}:{$idempotencyKey}";

        // 1. Fast path: Check L1 Redis Cache
        $cached = Cache::get($cacheKey);
        if ($cached) {
            if ($cached['fingerprint'] !== $requestFingerprint) {
                return new JsonResponse([
                    'message' => 'Idempotency key has already been used with a different request payload.',
                    'code' => 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                ], Response::HTTP_CONFLICT);
            }

            $response = new JsonResponse(
                data: json_decode($cached['content'], true) ?? $cached['content'],
                status: $cached['status']
            );
            $response->header('X-Idempotent-Replay', 'true');

            return $response;
        }

        // 2. Acquire Redis distributed lock
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS);

        if (! $lock->get()) {
            return new JsonResponse([
                'message' => 'A request with this Idempotency-Key is currently being processed. Please retry shortly.',
                'code' => 'REQUEST_IN_PROGRESS',
            ], Response::HTTP_CONFLICT, ['Retry-After' => 2]);
        }

        $durableRecord = null;

        try {
            // 3. Query L2 Durable PostgreSQL Table
            $query = IdempotencyRecord::where('idempotency_key', $idempotencyKey);
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->whereNull('tenant_id');
            }

            $existingRecord = $query->first();

            if ($existingRecord) {
                // Check for payload mismatch (tampering / key collision with different payload)
                if ($existingRecord->request_fingerprint !== $requestFingerprint) {
                    return new JsonResponse([
                        'message' => 'Idempotency key has already been used with a different request payload.',
                        'code' => 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                    ], Response::HTTP_CONFLICT);
                }

                if ($existingRecord->isCompleted()) {
                    // Seed L1 cache for subsequent requests
                    Cache::put($cacheKey, [
                        'status' => $existingRecord->response_status,
                        'content' => $existingRecord->response_body,
                        'fingerprint' => $requestFingerprint,
                    ], now()->addSeconds(self::TTL_SECONDS));

                    $response = new JsonResponse(
                        data: json_decode($existingRecord->response_body, true) ?? $existingRecord->response_body,
                        status: $existingRecord->response_status
                    );
                    $response->header('X-Idempotent-Replay', 'true');

                    return $response;
                }

                if ($existingRecord->isProcessing()) {
                    return new JsonResponse([
                        'message' => 'A request with this Idempotency-Key is currently being processed. Please retry shortly.',
                        'code' => 'REQUEST_IN_PROGRESS',
                    ], Response::HTTP_CONFLICT, ['Retry-After' => 2]);
                }

                // If record was previously marked failed, allow retry by reusing record
                $durableRecord = $existingRecord;
                $durableRecord->update([
                    'status' => IdempotencyRecord::STATUS_PROCESSING,
                    'request_fingerprint' => $requestFingerprint,
                    'user_id' => $userId,
                    'expires_at' => now()->addSeconds(self::TTL_SECONDS),
                ]);
            } else {
                // 4. Create new durable record in 'processing' state
                try {
                    $durableRecord = IdempotencyRecord::create([
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $requestFingerprint,
                        'status' => IdempotencyRecord::STATUS_PROCESSING,
                        'expires_at' => now()->addSeconds(self::TTL_SECONDS),
                    ]);
                } catch (QueryException $qe) {
                    // Race condition caught by PostgreSQL unique constraint
                    return new JsonResponse([
                        'message' => 'A request with this Idempotency-Key is currently being processed. Please retry shortly.',
                        'code' => 'REQUEST_IN_PROGRESS',
                    ], Response::HTTP_CONFLICT, ['Retry-After' => 2]);
                }
            }

            // 5. Execute downstream request / business logic
            try {
                $response = $next($request);
            } catch (Throwable $t) {
                // If downstream threw an unhandled exception, mark durable record as failed
                $durableRecord->update(['status' => IdempotencyRecord::STATUS_FAILED]);
                throw $t;
            }

            // 6. Update durable record and L1 cache on valid HTTP response
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 500 && $statusCode !== 429) {
                $content = $response->getContent();

                $durableRecord->update([
                    'status' => IdempotencyRecord::STATUS_COMPLETED,
                    'response_status' => $statusCode,
                    'response_body' => $content,
                ]);

                Cache::put($cacheKey, [
                    'status' => $statusCode,
                    'content' => $content,
                    'fingerprint' => $requestFingerprint,
                ], now()->addSeconds(self::TTL_SECONDS));
            } else {
                // Temporary server errors (5xx) or rate limits (429) mark as failed to allow retry
                $durableRecord->update(['status' => IdempotencyRecord::STATUS_FAILED]);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
