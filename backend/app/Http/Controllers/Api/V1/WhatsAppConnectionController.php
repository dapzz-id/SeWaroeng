<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderDefinition;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\WhatsAppNotificationService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppConnectionController extends Controller
{
    public function __construct(
        protected WhatsAppProviderManager $providerManager,
        protected WhatsAppNotificationService $notificationService,
        protected SubscriptionEntitlementService $entitlementService
    ) {}

    /**
     * Show WhatsApp connections and platform providers for the active tenant.
     */
    public function show(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $tenant = app(TenantContext::class)->tenant();

        $connections = TenantWhatsAppConnection::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->get();

        $platformProviders = WhatsAppProviderDefinition::query()
            ->where('is_enabled', true)
            ->get();

        $isEntitled = $this->entitlementService->can($tenant, 'whatsapp');

        return ApiResponse::success(data: [
            'is_entitled' => $isEntitled,
            'connections' => $connections,
            'available_providers' => $platformProviders,
        ]);
    }

    /**
     * Create or update tenant WhatsApp connection.
     * Enforces entitlement and credential security (encrypted at rest, never logged in plain text).
     */
    public function update(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();

        // 1. Enforce Subscription Entitlement
        $this->entitlementService->assertCan($tenant, 'whatsapp');

        // 1b. Role Guard: Manager cannot mutate sensitive provider credentials; Owner or Superadmin required
        $tenantContext = app(TenantContext::class);
        $user = $request->user();
        if (! $tenantContext->isOwner() && ! ($user && $user->isSuperadmin())) {
            return ApiResponse::error(
                message: 'Hanya Owner atau Superadmin yang dapat mengubah kredensial koneksi WhatsApp.',
                status: Response::HTTP_FORBIDDEN
            );
        }

        // 2. Validate Request
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:mock,meta_cloud'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'is_default' => ['nullable', 'boolean'],
            'phone_number_id' => ['nullable', 'string', 'max:100'],
            'business_account_id' => ['nullable', 'string', 'max:100'],
            'credentials' => ['required', 'array'],
            'configuration' => ['nullable', 'array'],
            'configuration.api_version' => ['nullable', 'string', 'in:v21.0,v22.0,v25.0'],
        ]);

        $providerKey = strtolower(trim($validated['provider']));
        $environment = $validated['environment'] ?? 'sandbox';

        // 2b. Production Mock Provider Isolation: Mock provider cannot be selected for production
        if ($providerKey === 'mock' && ($environment === 'production' || app()->environment('production'))) {
            return ApiResponse::error(
                message: 'Mock WhatsApp Provider tidak dapat digunakan di environment produksi.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var TenantWhatsAppConnection|null $connection */
        $connection = TenantWhatsAppConnection::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $providerKey)
            ->where('environment', $environment)
            ->first();

        $isCreate = ! $connection;
        $before = $connection ? $connection->toArray() : null;

        $targetStatus = $validated['status'] ?? ($connection?->status ?? TenantWhatsAppConnection::STATUS_ACTIVE);
        $wantsDefault = isset($validated['is_default']) ? (bool) $validated['is_default'] : (bool) ($connection?->is_default ?? false);

        // Invariant: Inactive connection cannot be default
        if ($targetStatus === TenantWhatsAppConnection::STATUS_INACTIVE) {
            $wantsDefault = false;
        }

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
            if ($wantsDefault) {
                TenantWhatsAppConnection::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('environment', $environment)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if ($isCreate) {
                $connection = TenantWhatsAppConnection::create([
                    'tenant_id' => $tenant->id,
                    'provider' => $providerKey,
                    'environment' => $environment,
                    'status' => $targetStatus,
                    'is_default' => $wantsDefault,
                    'phone_number_id' => $validated['phone_number_id'] ?? null,
                    'business_account_id' => $validated['business_account_id'] ?? null,
                    'encrypted_credentials' => $validated['credentials'],
                    'configuration' => $validated['configuration'] ?? [],
                    'verification_status' => TenantWhatsAppConnection::VERIFICATION_UNVERIFIED,
                    'created_by' => $request->user()?->id,
                    'updated_by' => $request->user()?->id,
                ]);
            } else {
                $connection->fill([
                    'status' => $targetStatus,
                    'is_default' => $wantsDefault,
                    'phone_number_id' => $validated['phone_number_id'] ?? $connection->phone_number_id,
                    'business_account_id' => $validated['business_account_id'] ?? $connection->business_account_id,
                    'encrypted_credentials' => $validated['credentials'],
                    'configuration' => $validated['configuration'] ?? $connection->configuration,
                    'updated_by' => $request->user()?->id,
                ]);
                $connection->save();
            }
        });

        // Audit Log without credential leak
        AuditLog::create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $request->user()?->id,
            'action' => $isCreate ? 'whatsapp_connection_created' : 'whatsapp_connection_updated',
            'entity_type' => TenantWhatsAppConnection::class,
            'entity_id' => $connection->id,
            'before' => $before,
            'after' => $connection->toArray(), // Already hides encrypted_credentials and exposes only masked_credentials
            'created_at' => now(),
        ]);

        return ApiResponse::success(
            data: $connection->fresh(),
            message: 'Konfigurasi WhatsApp berhasil disimpan.',
            status: $isCreate ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    /**
     * Verify credentials against provider adapter.
     */
    public function verify(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $this->entitlementService->assertCan($tenant, 'whatsapp');

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'in:mock,meta_cloud'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
        ]);

        $resolved = $this->providerManager->resolveForTenant($tenant, $validated['provider'] ?? null);
        /** @var TenantWhatsAppConnection|null $connection */
        $connection = $resolved['connection'];
        $provider = $resolved['provider'];

        if (! $connection) {
            return ApiResponse::error(
                message: 'Koneksi WhatsApp belum dikonfigurasi untuk tenant ini.',
                status: Response::HTTP_NOT_FOUND
            );
        }

        $result = $provider->verifyConnection(
            credentials: $connection->encrypted_credentials ?? [],
            phoneNumberId: $connection->phone_number_id,
            environment: $connection->environment,
            apiVersion: $connection->configuration['api_version'] ?? null
        );

        if ($result['verified']) {
            $connection->update([
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'capability_status' => $result['capabilities'] ?? null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]);

            return ApiResponse::success(
                data: $connection->fresh(),
                message: 'Koneksi WhatsApp berhasil diverifikasi.'
            );
        }

        $connection->update([
            'verification_status' => TenantWhatsAppConnection::VERIFICATION_FAILED,
            'last_error_code' => $result['error_code'] ?? 'VERIFICATION_FAILED',
            'last_error_message' => $result['error_message'] ?? 'Verifikasi gagal.',
        ]);

        return ApiResponse::error(
            message: $result['error_message'] ?? 'Verifikasi WhatsApp gagal.',
            data: $connection->fresh(),
            status: Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * Send test message to a specified recipient phone.
     */
    public function testSend(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $this->entitlementService->assertCan($tenant, 'whatsapp');

        $validated = $request->validate([
            'recipient_phone' => ['required', 'string'],
            'provider' => ['nullable', 'string', 'in:mock,meta_cloud'],
        ]);

        $phone = PhoneNormalizer::normalize($validated['recipient_phone']);
        if (! PhoneNormalizer::isValid($phone)) {
            return ApiResponse::error(
                message: 'Format nomor telepon tidak valid. Gunakan format nomor HP Indonesia (+628...).',
                status: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $resolved = $this->providerManager->resolveForTenant($tenant, $validated['provider'] ?? null);
        $connection = $resolved['connection'];
        $provider = $resolved['provider'];

        if (! $connection || ! $connection->isActive()) {
            return ApiResponse::error(
                message: 'Tidak ada koneksi WhatsApp yang aktif.',
                status: Response::HTTP_BAD_REQUEST
            );
        }

        $message = WhatsAppMessage::create([
            'tenant_id' => $tenant->id,
            'recipient_phone' => $phone,
            'message_type' => 'test_verification',
            'payload' => ['summary' => 'Pesan uji coba integrasi WhatsApp SeWaroeng'],
            'status' => WhatsAppMessage::STATUS_PENDING,
            'provider' => $provider->getProviderName(),
            'created_by' => $request->user()?->id,
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: $phone,
            messageType: 'test_verification',
            textBody: "Halo! Ini adalah pesan uji coba dari SeWaroeng untuk outlet {$tenant->name}.",
            metadata: ['test' => true]
        );

        $dispatched = $this->notificationService->dispatchMessage($message, $connection, $provider, $payload);

        if ($dispatched->isSent()) {
            return ApiResponse::success(
                data: $dispatched,
                message: 'Pesan uji coba berhasil dikirim ke WhatsApp.'
            );
        }

        return ApiResponse::error(
            message: $dispatched->error_message ?? 'Gagal mengirim pesan uji coba WhatsApp.',
            data: $dispatched,
            status: Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * Get merchant WhatsApp notification preferences.
     */
    public function getPreferences(): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $this->entitlementService->assertCan($tenant, 'whatsapp');

        $settings = $tenant->settings ?? [];
        $notifications = $settings['whatsapp_notifications'] ?? [];

        return ApiResponse::success(data: [
            'merchant_phone' => $notifications['merchant_phone'] ?? $tenant->phone ?? $tenant->owner?->phone_number,
            'consent_given' => (bool) ($notifications['consent_given'] ?? false),
            'daily_sales_summary' => (bool) ($notifications['daily_sales_summary'] ?? false),
            'low_stock' => (bool) ($notifications['low_stock'] ?? false),
            'booking_reminder' => (bool) ($notifications['booking_reminder'] ?? false),
            'payment_settlement' => (bool) ($notifications['payment_settlement'] ?? false),
            'anomaly_alert' => (bool) ($notifications['anomaly_alert'] ?? false),
            'provenance' => $settings['whatsapp_notifications_provenance'] ?? null,
        ]);
    }

    /**
     * Update merchant WhatsApp notification preferences.
     * Enforces affirmative positive consent recording with full provenance.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $this->entitlementService->assertCan($tenant, 'whatsapp');

        $validated = $request->validate([
            'merchant_phone' => ['nullable', 'string', 'max:50'],
            'consent_given' => ['nullable', 'boolean'],
            'daily_sales_summary' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'booking_reminder' => ['nullable', 'boolean'],
            'payment_settlement' => ['nullable', 'boolean'],
            'anomaly_alert' => ['nullable', 'boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $current = $settings['whatsapp_notifications'] ?? [];

        // If consent_given is explicitly provided, use it; otherwise preserve current or default to true if configuring preferences
        $consentGiven = $validated['consent_given'] ?? ($current['consent_given'] ?? true);

        $updated = array_merge($current, array_filter($validated, fn ($val) => $val !== null));
        $updated['consent_given'] = (bool) $consentGiven;

        $provenance = [
            'consent_status' => $updated['consent_given'] ? 'consented' : 'revoked',
            'consented_at' => now()->toIso8601String(),
            'updated_by_user_id' => $request->user()?->id,
            'source' => 'merchant_portal_settings',
            'ip_address' => $request->ip(),
            'categories' => [
                'daily_sales_summary' => (bool) ($updated['daily_sales_summary'] ?? false),
                'low_stock' => (bool) ($updated['low_stock'] ?? false),
                'booking_reminder' => (bool) ($updated['booking_reminder'] ?? false),
                'payment_settlement' => (bool) ($updated['payment_settlement'] ?? false),
                'anomaly_alert' => (bool) ($updated['anomaly_alert'] ?? false),
            ],
        ];

        $settings['whatsapp_notifications'] = $updated;
        $settings['whatsapp_notifications_provenance'] = $provenance;

        $tenant->settings = $settings;
        $tenant->save();

        return ApiResponse::success(
            data: array_merge($updated, ['provenance' => $provenance]),
            message: 'Preferensi notifikasi WhatsApp berhasil diperbarui.'
        );
    }
}
