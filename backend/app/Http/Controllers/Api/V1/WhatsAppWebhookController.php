<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TenantWhatsAppConnection;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppProviderManager $providerManager,
        protected WhatsAppNotificationService $notificationService,
        protected TenantManager $tenantManager
    ) {}

    /**
     * Resolve connection in platform context to satisfy RLS for incoming public webhooks.
     */
    protected function resolveConnection(string $tenantId, string $provider): ?TenantWhatsAppConnection
    {
        try {
            DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
            try {
                return TenantWhatsAppConnection::query()
                    ->where('tenant_id', $tenantId)
                    ->where('provider', strtolower(trim($provider)))
                    ->first();
            } finally {
                DB::statement("SELECT set_config('app.is_platform_context', '', true)");
            }
        } catch (\Throwable) {
            return TenantWhatsAppConnection::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', strtolower(trim($provider)))
                ->first();
        }
    }

    /**
     * Resolve connection by phone number ID across tenants in platform context.
     */
    protected function resolveConnectionByPhoneNumber(string $phoneNumberId, string $provider): ?TenantWhatsAppConnection
    {
        try {
            DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
            try {
                return TenantWhatsAppConnection::query()
                    ->where('phone_number_id', $phoneNumberId)
                    ->where('provider', strtolower(trim($provider)))
                    ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                    ->first();
            } finally {
                DB::statement("SELECT set_config('app.is_platform_context', '', true)");
            }
        } catch (\Throwable) {
            return TenantWhatsAppConnection::query()
                ->where('phone_number_id', $phoneNumberId)
                ->where('provider', strtolower(trim($provider)))
                ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE)
                ->first();
        }
    }

    /**
     * Handle Meta WhatsApp Webhook GET challenge verification (tenant-scoped endpoint).
     */
    public function verifyChallenge(Request $request, string $tenantId, string $provider): HttpResponse|JsonResponse
    {
        $providerInstance = $this->providerManager->resolve($provider);
        $connection = $this->resolveConnection($tenantId, $provider);

        if (! $connection) {
            return response('Tenant connection not found', Response::HTTP_NOT_FOUND);
        }

        if ($providerInstance->verifyWebhookSignature($request, $connection)) {
            $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

            return response((string) $challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * Handle delivery status notification POST callback from provider (tenant-scoped endpoint).
     * Enforces raw body HMAC-SHA256 verification and cross-tenant evidence isolation.
     */
    public function handleWebhook(Request $request, string $tenantId, string $provider): JsonResponse
    {
        $providerInstance = $this->providerManager->resolve($provider);
        $connection = $this->resolveConnection($tenantId, $provider);

        if (! $connection) {
            return ApiResponse::error(
                message: 'Tenant connection not found for webhook callback.',
                status: Response::HTTP_NOT_FOUND
            );
        }

        // 1. Constant-time raw body cryptographic signature verification
        if (! $providerInstance->verifyWebhookSignature($request, $connection)) {
            return ApiResponse::error(
                message: 'Invalid webhook signature or token.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        $events = $providerInstance->parseWebhookEvents($request);
        $processed = 0;

        $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $provider, $connection, $events, &$processed) {
            foreach ($events as $event) {
                // 2. Cross-Tenant Evidence & Path Tampering Verification:
                // If payload specifies a phone_number_id, it must match the tenant's connection phone_number_id!
                if ($event->phoneNumberId && $connection->phone_number_id && $event->phoneNumberId !== $connection->phone_number_id) {
                    Log::warning("Cross-tenant evidence rejected: payload phone_number_id '{$event->phoneNumberId}' does not match tenant connection '{$connection->phone_number_id}'.");

                    continue;
                }

                $updated = $this->notificationService->handleWebhookEvent($tenantId, $provider, $event);
                if ($updated) {
                    $processed++;
                }
            }
        });

        return ApiResponse::success(
            data: ['processed_events' => $processed],
            message: 'Webhook processed successfully.'
        );
    }

    /**
     * Handle Meta Webhook GET challenge verification (platform-scoped endpoint).
     */
    public function verifyPlatformChallenge(Request $request, string $provider): HttpResponse|JsonResponse
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $verifyToken = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $expectedToken = config('services.whatsapp.meta.verify_token');

        if ($mode === 'subscribe' && ! empty($expectedToken) && hash_equals($expectedToken, (string) $verifyToken)) {
            $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

            return response((string) $challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * Handle delivery status notification POST callback (platform-scoped endpoint).
     * Maps verified provider evidence (phone_number_id) to the correct tenant.
     */
    public function handlePlatformWebhook(Request $request, string $provider): JsonResponse
    {
        // 1. Verify Platform Signature using Platform App Secret
        $signatureHeader = $request->header('X-Hub-Signature-256');
        $platformSecret = config('services.whatsapp.meta.app_secret');

        if (empty($signatureHeader) || empty($platformSecret) || ! str_starts_with($signatureHeader, 'sha256=')) {
            return ApiResponse::error(
                message: 'Invalid platform webhook signature.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        $expectedHash = hash_hmac('sha256', $request->getContent(), $platformSecret);
        $providedHash = substr($signatureHeader, 7);

        if (! hash_equals($expectedHash, $providedHash)) {
            return ApiResponse::error(
                message: 'Invalid platform webhook signature.',
                status: Response::HTTP_UNAUTHORIZED
            );
        }

        $providerInstance = $this->providerManager->resolve($provider);
        $events = $providerInstance->parseWebhookEvents($request);
        $processed = 0;

        foreach ($events as $event) {
            if (! $event->phoneNumberId) {
                continue;
            }

            $connection = $this->resolveConnectionByPhoneNumber($event->phoneNumberId, $provider);
            if (! $connection) {
                Log::warning("Platform webhook: No active tenant connection found for phone_number_id '{$event->phoneNumberId}'.");

                continue;
            }

            $this->tenantManager->runForTenant($connection->tenant_id, function () use ($connection, $provider, $event, &$processed) {
                $updated = $this->notificationService->handleWebhookEvent($connection->tenant_id, $provider, $event);
                if ($updated) {
                    $processed++;
                }
            });
        }

        return ApiResponse::success(
            data: ['processed_events' => $processed],
            message: 'Platform webhook processed successfully.'
        );
    }
}
