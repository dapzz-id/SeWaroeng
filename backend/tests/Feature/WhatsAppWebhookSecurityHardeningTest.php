<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppNotificationService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class WhatsAppWebhookSecurityHardeningTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected TenantWhatsAppConnection $connectionA;

    protected TenantWhatsAppConnection $connectionB;

    protected string $platformAppSecret = 'test_platform_secret_key_abcdef123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
        config(['services.whatsapp.meta.app_secret' => $this->platformAppSecret]);
        config(['services.whatsapp.meta.verify_token' => 'meta_hub_verify_token_xyz']);

        // Create Tenant A
        $dataA = $this->createTenant('Tenant A Waroeng');
        $this->tenantA = $dataA['tenant'];

        // Create Tenant B
        $dataB = $this->createTenant('Tenant B Waroeng');
        $this->tenantB = $dataB['tenant'];

        $this->connectionA = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenantA->id,
                'provider' => 'meta',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '1111111111',
                'waba_id' => 'WABA_AAA',
                'encrypted_credentials' => [
                    'access_token' => 'meta_token_A',
                    'app_secret' => 'tenant_a_app_secret_999',
                ],
                'configuration' => ['receipt_template_name' => 'transactional_receipt'],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });

        $this->connectionB = app(TenantManager::class)->runForTenant($this->tenantB, function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenantB->id,
                'provider' => 'meta',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '2222222222',
                'waba_id' => 'WABA_BBB',
                'encrypted_credentials' => [
                    'access_token' => 'meta_token_B',
                    'app_secret' => 'tenant_b_app_secret_888',
                ],
                'configuration' => ['receipt_template_name' => 'transactional_receipt'],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });

        app(SubscriptionEntitlementService::class)->clearCache($this->tenantA);
        app(SubscriptionEntitlementService::class)->clearCache($this->tenantB);
    }

    protected function createSignedPlatformPayload(array $payload, ?string $secret = null): array
    {
        $rawJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $secret = $secret ?? $this->platformAppSecret;
        $signature = 'sha256='.hash_hmac('sha256', $rawJson, $secret);

        return ['raw' => $rawJson, 'signature' => $signature];
    }

    public function test_valid_platform_webhook_signature_processes_delivery_event(): void
    {
        // Setup existing message in Tenant A
        $message = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return WhatsAppMessage::create([
                'tenant_id' => $this->tenantA->id,
                'recipient_phone' => '+6281211112222',
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_SENT,
                'provider' => 'meta',
                'provider_message_id' => 'wamid.HBgLMTExMTExMTExM',
                'idempotency_key' => 'receipt:sale:001',
                'attempts' => 1,
            ]);
        });

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_AAA',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '123456789',
                                    'phone_number_id' => '1111111111',
                                ],
                                'statuses' => [
                                    [
                                        'id' => 'wamid.HBgLMTExMTExMTExM',
                                        'status' => 'delivered',
                                        'timestamp' => '1710000000',
                                        'recipient_id' => '6281211112222',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $signed = $this->createSignedPlatformPayload($payload);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/whatsapp/meta',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signed['signature'],
            ],
            content: $signed['raw']
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.processed_events', 1);

        $fresh = app(TenantManager::class)->runForTenant($this->tenantA, function () use ($message) {
            return $message->fresh();
        });
        $this->assertEquals(WhatsAppMessage::STATUS_DELIVERED, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_invalid_platform_signature_is_rejected_with_401(): void
    {
        $payload = ['object' => 'whatsapp_business_account'];
        $rawJson = json_encode($payload);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/whatsapp/meta',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid_hash_signature_123456',
            ],
            content: $rawJson
        );

        $response->assertStatus(401);
    }

    public function test_altered_raw_payload_fails_signature_verification(): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => []];
        $signed = $this->createSignedPlatformPayload($payload);

        // Tamper with raw body after signature generation
        $tamperedRaw = json_encode(['object' => 'whatsapp_business_account', 'entry' => ['tampered' => true]]);

        $response = $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/whatsapp/meta',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signed['signature'],
            ],
            content: $tamperedRaw
        );

        $response->assertStatus(401);
    }

    public function test_tenant_path_tampering_with_mismatched_phone_number_id_is_rejected(): void
    {
        // Tenant A scoped endpoint receives webhook claiming phone_number_id of Tenant B
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA_AAA',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '123456789',
                                    'phone_number_id' => '2222222222', // Mismatch! Belongs to Tenant B
                                ],
                                'statuses' => [
                                    [
                                        'id' => 'wamid.TAMPERED_001',
                                        'status' => 'delivered',
                                        'timestamp' => '1710000000',
                                        'recipient_id' => '6281211112222',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Signed with Tenant A app_secret
        $rawJson = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $rawJson, 'tenant_a_app_secret_999');

        $response = $this->call(
            method: 'POST',
            uri: "/api/v1/webhooks/whatsapp/{$this->tenantA->id}/meta",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            content: $rawJson
        );

        $response->assertStatus(200);
        // Processed count must be 0 because phone_number_id was rejected due to cross-tenant evidence mismatch!
        $response->assertJsonPath('data.processed_events', 0);
    }

    public function test_cross_tenant_message_mutation_is_strictly_rejected(): void
    {
        // Message belongs to Tenant B
        $messageB = app(TenantManager::class)->runForTenant($this->tenantB, function () {
            return WhatsAppMessage::create([
                'tenant_id' => $this->tenantB->id,
                'recipient_phone' => '+6281987654321',
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_SENT,
                'provider' => 'meta',
                'provider_message_id' => 'wamid.TENANT_B_EXCLUSIVE_MSG',
                'idempotency_key' => 'receipt:sale:tenant_b_001',
                'attempts' => 1,
            ]);
        });

        // Webhook handler called with Tenant A context attempting to update Tenant B message
        $service = app(WhatsAppNotificationService::class);
        $event = new WhatsAppWebhookEvent(
            providerMessageId: 'wamid.TENANT_B_EXCLUSIVE_MSG',
            status: 'read',
            timestamp: now()->toIso8601String(),
            phoneNumberId: '1111111111'
        );

        $result = $service->handleWebhookEvent($this->tenantA->id, 'meta', $event);

        $this->assertNull($result, 'Service must reject event targeting a message outside the tenant');
        $statusB = app(TenantManager::class)->runForTenant($this->tenantB, function () use ($messageB) {
            return $messageB->fresh()->status;
        });
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $statusB, 'Tenant B message must remain unmodified');
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $message = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return WhatsAppMessage::create([
                'tenant_id' => $this->tenantA->id,
                'recipient_phone' => '+6281233334444',
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_SENT,
                'provider' => 'meta',
                'provider_message_id' => 'wamid.IDEMPOTENT_TEST_01',
                'idempotency_key' => 'receipt:sale:idemp_01',
                'attempts' => 1,
            ]);
        });

        $service = app(WhatsAppNotificationService::class);
        $event = new WhatsAppWebhookEvent(
            providerMessageId: 'wamid.IDEMPOTENT_TEST_01',
            status: 'delivered',
            timestamp: now()->toIso8601String(),
            phoneNumberId: '1111111111'
        );

        app(TenantManager::class)->runForTenant($this->tenantA, function () use ($service, $event) {
            // First delivery
            $first = $service->handleWebhookEvent($this->tenantA->id, 'meta', $event);
            $this->assertNotNull($first);
            $this->assertEquals(WhatsAppMessage::STATUS_DELIVERED, $first->status);

            // Duplicate delivery (idempotent replay)
            $second = $service->handleWebhookEvent($this->tenantA->id, 'meta', $event);
            $this->assertNotNull($second);
            $this->assertEquals(WhatsAppMessage::STATUS_DELIVERED, $second->status);
        });
    }

    public function test_out_of_order_status_does_not_downgrade_state(): void
    {
        $message = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return WhatsAppMessage::create([
                'tenant_id' => $this->tenantA->id,
                'recipient_phone' => '+6281233335555',
                'message_type' => WhatsAppMessage::TYPE_TRANSACTIONAL_RECEIPT,
                'status' => WhatsAppMessage::STATUS_SENT,
                'provider' => 'meta',
                'provider_message_id' => 'wamid.MONOTONIC_TEST_01',
                'idempotency_key' => 'receipt:sale:mono_01',
                'attempts' => 1,
            ]);
        });

        $service = app(WhatsAppNotificationService::class);

        app(TenantManager::class)->runForTenant($this->tenantA, function () use ($service, $message) {
            // Advance to READ
            $readEvent = new WhatsAppWebhookEvent(
                providerMessageId: 'wamid.MONOTONIC_TEST_01',
                status: 'read',
                timestamp: now()->toIso8601String(),
                phoneNumberId: '1111111111'
            );
            $service->handleWebhookEvent($this->tenantA->id, 'meta', $readEvent);
            $this->assertEquals(WhatsAppMessage::STATUS_READ, $message->fresh()->status);

            // Delayed out-of-order 'sent' or 'delivered' callback must NOT downgrade 'read' status!
            $lateSentEvent = new WhatsAppWebhookEvent(
                providerMessageId: 'wamid.MONOTONIC_TEST_01',
                status: 'sent',
                timestamp: now()->subMinutes(5)->toIso8601String(),
                phoneNumberId: '1111111111'
            );
            $service->handleWebhookEvent($this->tenantA->id, 'meta', $lateSentEvent);
            $this->assertEquals(WhatsAppMessage::STATUS_READ, $message->fresh()->status, 'Status must not downgrade from read to sent');

            $lateDeliveredEvent = new WhatsAppWebhookEvent(
                providerMessageId: 'wamid.MONOTONIC_TEST_01',
                status: 'delivered',
                timestamp: now()->subMinutes(3)->toIso8601String(),
                phoneNumberId: '1111111111'
            );
            $service->handleWebhookEvent($this->tenantA->id, 'meta', $lateDeliveredEvent);
            $this->assertEquals(WhatsAppMessage::STATUS_READ, $message->fresh()->status, 'Status must not downgrade from read to delivered');
        });
    }

    public function test_get_webhook_challenge_verification_succeeds_with_valid_token(): void
    {
        $response = $this->get('/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.challenge=test_challenge_code_123&hub.verify_token=meta_hub_verify_token_xyz');

        $response->assertStatus(200);
        $this->assertEquals('test_challenge_code_123', $response->getContent());
    }

    public function test_get_webhook_challenge_verification_fails_with_invalid_token(): void
    {
        $response = $this->get('/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.challenge=test_challenge_code_123&hub.verify_token=wrong_token');

        $response->assertStatus(403);
    }
}
