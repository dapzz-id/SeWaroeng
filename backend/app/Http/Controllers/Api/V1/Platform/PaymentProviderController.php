<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\PaymentProviderDefinition;
use App\Models\Tenant;
use App\Models\TenantPaymentConnection;
use App\Services\Payment\PaymentProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PaymentProviderController extends Controller
{
    public function __construct(
        protected PaymentProviderManager $providerManager
    ) {}

    /**
     * List all platform payment provider definitions.
     */
    public function index(): JsonResponse
    {
        $definitions = PaymentProviderDefinition::query()
            ->orderBy('provider')
            ->get();

        return ApiResponse::success(data: $definitions);
    }

    /**
     * Get specific platform provider definition.
     */
    public function show(string $provider): JsonResponse
    {
        $definition = PaymentProviderDefinition::query()
            ->where('provider', strtolower($provider))
            ->firstOrFail();

        return ApiResponse::success(data: $definition);
    }

    /**
     * Enable provider globally.
     */
    public function enable(Request $request, string $provider): JsonResponse
    {
        $definition = PaymentProviderDefinition::query()
            ->where('provider', strtolower($provider))
            ->firstOrFail();

        $before = $definition->toArray();
        $definition->update(['is_enabled' => true]);

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'payment_provider_enabled',
            'entity_type' => PaymentProviderDefinition::class,
            'entity_id' => $definition->id,
            'before' => $before,
            'after' => $definition->toArray(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: $definition,
            message: "Payment provider '{$provider}' enabled globally."
        );
    }

    /**
     * Disable provider globally.
     */
    public function disable(Request $request, string $provider): JsonResponse
    {
        $definition = PaymentProviderDefinition::query()
            ->where('provider', strtolower($provider))
            ->firstOrFail();

        $before = $definition->toArray();
        $definition->update(['is_enabled' => false]);

        AuditLog::create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'payment_provider_disabled',
            'entity_type' => PaymentProviderDefinition::class,
            'entity_id' => $definition->id,
            'before' => $before,
            'after' => $definition->toArray(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: $definition,
            message: "Payment provider '{$provider}' disabled globally."
        );
    }

    /**
     * List payment connections for a specific tenant.
     */
    public function connections(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $connections = TenantPaymentConnection::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('provider')
            ->get();

        return ApiResponse::success(data: $connections);
    }

    /**
     * Create or update a tenant payment gateway connection.
     */
    public function updateConnection(Request $request, string $tenantId, string $provider): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $providerKey = strtolower(trim($provider));

        $validated = $request->validate([
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'is_default' => ['nullable', 'boolean'],
            'merchant_identifier' => ['required', 'string', 'max:100'],
            'credentials' => ['required', 'array'],
            'configuration' => ['nullable', 'array'],
            'capability_status' => ['nullable', 'array'],
        ]);

        $environment = $validated['environment'] ?? 'sandbox';

        /** @var TenantPaymentConnection|null $connection */
        $connection = TenantPaymentConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $providerKey)
            ->where('environment', $environment)
            ->first();

        $isCreate = ! $connection;
        $before = $connection ? $connection->toArray() : null;

        $targetStatus = $validated['status'] ?? ($connection?->status ?? TenantPaymentConnection::STATUS_ACTIVE);
        $wantsDefault = isset($validated['is_default']) ? (bool) $validated['is_default'] : (bool) ($connection?->is_default ?? false);

        // Invariant: Inactive connection cannot be default
        if ($targetStatus === TenantPaymentConnection::STATUS_INACTIVE) {
            $wantsDefault = false;
        }

        try {
            DB::transaction(function () use (
                $tenant,
                $providerKey,
                $environment,
                $targetStatus,
                $wantsDefault,
                $validated,
                $request,
                $isCreate,
                &$connection
            ) {
                // If setting as default, atomically unset other defaults for this tenant and environment
                if ($wantsDefault) {
                    TenantPaymentConnection::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('environment', $environment)
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
                }

                if ($isCreate) {
                    $connection = TenantPaymentConnection::create([
                        'tenant_id' => $tenant->id,
                        'provider' => $providerKey,
                        'environment' => $environment,
                        'status' => $targetStatus,
                        'is_default' => $wantsDefault,
                        'merchant_identifier' => $validated['merchant_identifier'],
                        'encrypted_credentials' => $validated['credentials'],
                        'configuration' => $validated['configuration'] ?? null,
                        'capability_status' => $validated['capability_status'] ?? null,
                        'verification_status' => TenantPaymentConnection::VERIFICATION_UNVERIFIED,
                        'created_by' => $request->user()?->id,
                    ]);
                } else {
                    $connection->update([
                        'status' => $targetStatus,
                        'is_default' => $wantsDefault,
                        'merchant_identifier' => $validated['merchant_identifier'],
                        'encrypted_credentials' => $validated['credentials'],
                        'configuration' => $validated['configuration'] ?? $connection->configuration,
                        'capability_status' => $validated['capability_status'] ?? $connection->capability_status,
                        'updated_by' => $request->user()?->id,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'uq_provider_env_merchant_id')) {
                return ApiResponse::error(
                    message: "Merchant identifier '{$validated['merchant_identifier']}' is already registered for this provider and environment.",
                    code: 'MERCHANT_IDENTIFIER_CONFLICT',
                    status: Response::HTTP_CONFLICT
                );
            }

            if (str_contains($e->getMessage(), 'uq_tenant_env_default_connection')) {
                return ApiResponse::error(
                    message: 'Only one default payment provider is permitted per environment.',
                    code: 'DUPLICATE_DEFAULT_PROVIDER',
                    status: Response::HTTP_CONFLICT
                );
            }

            throw $e;
        }

        // Audit without raw secret material
        $maskedCreds = [];
        foreach ($validated['credentials'] as $k => $v) {
            $maskedCreds[$k] = TenantPaymentConnection::maskSecret((string) $v);
        }

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $request->user()?->id,
            'action' => $isCreate ? 'tenant_payment_connection_created' : 'tenant_payment_connection_updated',
            'entity_type' => TenantPaymentConnection::class,
            'entity_id' => $connection->id,
            'before' => $before,
            'after' => array_merge($connection->toArray(), [
                'masked_credentials' => $maskedCreds,
            ]),
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: $connection,
            message: "Tenant payment connection for '{$providerKey}' saved successfully.",
            status: $isCreate ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    /**
     * Perform safe non-destructive verification against provider gateway.
     */
    public function verifyConnection(Request $request, string $tenantId, string $provider): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $providerKey = strtolower(trim($provider));

        /** @var TenantPaymentConnection $connection */
        $connection = TenantPaymentConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $providerKey)
            ->firstOrFail();

        $providerInstance = $this->providerManager->resolve($providerKey);
        $creds = $connection->encrypted_credentials ?? [];

        $verification = $providerInstance->verifyConnection($creds, $connection->environment);

        if ($verification['verified']) {
            $connection->update([
                'verification_status' => TenantPaymentConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'capability_status' => $verification['capabilities'] ?? $connection->capability_status,
                'updated_by' => $request->user()?->id,
            ]);
        } else {
            $connection->update([
                'verification_status' => TenantPaymentConnection::VERIFICATION_FAILED,
                'last_error_code' => $verification['error_code'] ?? 'UNKNOWN_ERROR',
                'last_error_message' => $verification['error_message'] ?? 'Verification failed',
                'updated_by' => $request->user()?->id,
            ]);
        }

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $request->user()?->id,
            'action' => 'tenant_payment_connection_verified',
            'entity_type' => TenantPaymentConnection::class,
            'entity_id' => $connection->id,
            'after' => [
                'provider' => $providerKey,
                'verification_status' => $connection->verification_status,
                'last_error_code' => $connection->last_error_code,
            ],
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: [
                'connection' => $connection,
                'verification' => $verification,
            ],
            message: $verification['verified']
                ? "Connection to '{$providerKey}' verified successfully."
                : 'Connection verification failed: '.($verification['error_message'] ?? 'Error')
        );
    }
}
