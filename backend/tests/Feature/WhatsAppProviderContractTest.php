<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\Providers\MetaCloudWhatsAppProvider;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

class WhatsAppProviderContractTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected TenantWhatsAppConnection $connection;

    protected MetaCloudWhatsAppProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);

        $tenantData = $this->createTenant('Meta Provider Contract Waroeng');
        $this->tenant = $tenantData['tenant'];

        app(TenantManager::class)->runForTenant($this->tenant, function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenant->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenant);

        $this->connection = app(TenantManager::class)->runForTenant($this->tenant, function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenant->id,
                'provider' => 'meta_cloud',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '10987654321',
                'waba_id' => 'WABA_CONTRACT_01',
                'encrypted_credentials' => [
                    'access_token' => 'EAAG_SECRET_TOKEN_DO_NOT_LEAK',
                    'app_secret' => 'PLATFORM_SECRET_98765',
                ],
                'configuration' => [
                    'receipt_template_name' => 'transactional_receipt',
                    'api_version' => 'v21.0',
                    'templates' => [
                        'transactional_receipt' => ['status' => 'APPROVED'],
                        'deprecated_promo' => ['status' => 'REJECTED'],
                    ],
                ],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });

        $this->provider = new MetaCloudWhatsAppProvider;
    }

    public function test_api_version_is_configurable_and_rejects_unsupported_or_expired_versions(): void
    {
        // 1. Configured active version on connection (v22.0 and v25.0)
        $this->connection->configuration = ['api_version' => 'v22.0'];
        $this->assertEquals('v22.0', $this->provider->resolveApiVersion($this->connection));

        $this->connection->configuration = ['api_version' => 'v25.0'];
        $this->assertEquals('v25.0', $this->provider->resolveApiVersion($this->connection));

        // 2. Uses default active version (v25.0) when not on connection
        $this->connection->configuration = [];
        config(['services.whatsapp.meta.graph_version' => 'v25.0']);
        $this->assertEquals('v25.0', $this->provider->resolveApiVersion($this->connection));

        // 3. Rejects expired version (v20.0 lifecycle ended September 24, 2026)
        $this->connection->configuration = ['api_version' => 'v20.0'];
        try {
            $this->provider->resolveApiVersion($this->connection);
            $this->fail('Expected InvalidArgumentException for expired v20.0');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported or expired Meta Graph API version', $e->getMessage());
        }

        // 4. Rejects unverified newer version (v26.0 not certified for WhatsApp Cloud API contract)
        $this->connection->configuration = ['api_version' => 'v26.0'];
        try {
            $this->provider->resolveApiVersion($this->connection);
            $this->fail('Expected InvalidArgumentException for unverified v26.0');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported or expired Meta Graph API version', $e->getMessage());
        }

        // 5. Rejects arbitrary unsupported magic string
        $this->connection->configuration = ['api_version' => 'v99.9'];
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->resolveApiVersion($this->connection);
    }

    public function test_endpoint_construction_for_active_versions(): void
    {
        // 1. Verify v21.0 endpoint
        Http::fake([
            'https://graph.facebook.com/v21.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.V21_TEST']],
            ], 200),
            'https://graph.facebook.com/v22.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.V22_TEST']],
            ], 200),
            'https://graph.facebook.com/v25.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.V25_TEST']],
            ], 200),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['SeWaroeng', 'REC-001', 'Rp 10.000']
        );

        $templates = ['transactional_receipt' => ['status' => 'APPROVED']];

        $this->connection->configuration = ['api_version' => 'v21.0', 'templates' => $templates];
        $resultV21 = $this->provider->sendMessage($this->connection, $payload);
        $this->assertTrue($resultV21->success);
        $this->assertEquals('wamid.V21_TEST', $resultV21->providerMessageId);

        // 2. Verify v22.0 endpoint
        $this->connection->configuration = ['api_version' => 'v22.0', 'templates' => $templates];
        $resultV22 = $this->provider->sendMessage($this->connection, $payload);
        $this->assertTrue($resultV22->success);
        $this->assertEquals('wamid.V22_TEST', $resultV22->providerMessageId);

        // 3. Verify v25.0 endpoint (default active candidate)
        $this->connection->configuration = ['api_version' => 'v25.0', 'templates' => $templates];
        $resultV25 = $this->provider->sendMessage($this->connection, $payload);
        $this->assertTrue($resultV25->success);
        $this->assertEquals('wamid.V25_TEST', $resultV25->providerMessageId);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://graph.facebook.com/v21.0/10987654321/messages');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://graph.facebook.com/v22.0/10987654321/messages');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://graph.facebook.com/v25.0/10987654321/messages');
    }

    public function test_verify_connection_rejects_expired_and_unsupported_versions_without_network_call(): void
    {
        Http::fake();

        // 1. Expired v20.0
        $resultV20 = $this->provider->verifyConnection(
            credentials: ['access_token' => 'EAAG_TEST_TOKEN'],
            phoneNumberId: '10987654321',
            apiVersion: 'v20.0'
        );
        $this->assertFalse($resultV20['verified']);
        $this->assertEquals('UNSUPPORTED_API_VERSION', $resultV20['error_code']);

        // 2. Unverified v26.0
        $resultV26 = $this->provider->verifyConnection(
            credentials: ['access_token' => 'EAAG_TEST_TOKEN'],
            phoneNumberId: '10987654321',
            apiVersion: 'v26.0'
        );
        $this->assertFalse($resultV26['verified']);
        $this->assertEquals('UNSUPPORTED_API_VERSION', $resultV26['error_code']);

        // No network calls made for invalid versions!
        Http::assertNothingSent();
    }

    public function test_tenant_connection_update_endpoint_rejects_expired_and_unsupported_versions(): void
    {
        $owner = $this->tenant->users()->first();

        // 1. Attempt to configure expired v20.0
        $resExpired = $this->authenticateTenantUser($owner, $this->tenant)
            ->putJson('/api/v1/whatsapp/connection', [
                'provider' => 'meta_cloud',
                'credentials' => ['access_token' => 'token_test'],
                'configuration' => ['api_version' => 'v20.0'],
            ]);

        $resExpired->assertStatus(422);
        $resExpired->assertJsonValidationErrors(['configuration.api_version']);

        // 2. Attempt to configure unverified v26.0
        $resUnsupported = $this->authenticateTenantUser($owner, $this->tenant)
            ->putJson('/api/v1/whatsapp/connection', [
                'provider' => 'meta_cloud',
                'credentials' => ['access_token' => 'token_test'],
                'configuration' => ['api_version' => 'v26.0'],
            ]);

        $resUnsupported->assertStatus(422);
        $resUnsupported->assertJsonValidationErrors(['configuration.api_version']);

        // 3. Configure valid active versions (v22.0 and v25.0) succeeds
        $resValid = $this->authenticateTenantUser($owner, $this->tenant)
            ->putJson('/api/v1/whatsapp/connection', [
                'provider' => 'meta_cloud',
                'credentials' => ['access_token' => 'token_test'],
                'configuration' => ['api_version' => 'v22.0'],
            ]);

        $resValid->assertStatus(200);
        $this->assertEquals('v22.0', $resValid->json('data.configuration.api_version'));

        $resValidV25 = $this->authenticateTenantUser($owner, $this->tenant)
            ->putJson('/api/v1/whatsapp/connection', [
                'provider' => 'meta_cloud',
                'credentials' => ['access_token' => 'token_test'],
                'configuration' => ['api_version' => 'v25.0'],
            ]);

        $resValidV25->assertStatus(200);
        $this->assertEquals('v25.0', $resValidV25->json('data.configuration.api_version'));
    }

    public function test_approved_template_contract_constructs_compliant_meta_graph_api_payload(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '628123456789', 'wa_id' => '628123456789']],
                'messages' => [['id' => 'wamid.HBgLMTIzNDU2Nzg5']],
            ], 200),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['Waroeng Berkah', 'REC-001', 'Rp 50.000'],
            metadata: ['language_code' => 'id']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertTrue($result->success);
        $this->assertEquals('wamid.HBgLMTIzNDU2Nzg5', $result->providerMessageId);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://graph.facebook.com/v21.0/10987654321/messages'
                && $request->hasHeader('Authorization', 'Bearer EAAG_SECRET_TOKEN_DO_NOT_LEAK')
                && $data['messaging_product'] === 'whatsapp'
                && $data['to'] === '+628123456789'
                && $data['type'] === 'template'
                && $data['template']['name'] === 'transactional_receipt'
                && $data['template']['language']['code'] === 'id'
                && count($data['template']['components'][0]['parameters']) === 3
                && $data['template']['components'][0]['parameters'][0]['text'] === 'Waroeng Berkah'
                && $data['template']['components'][0]['parameters'][1]['text'] === 'REC-001'
                && $data['template']['components'][0]['parameters'][2]['text'] === 'Rp 50.000';
        });
    }

    public function test_rejected_template_aborts_dispatch_with_terminal_failure_before_network_call(): void
    {
        Http::fake();

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'marketing_promo',
            templateName: 'deprecated_promo',
            templateParameters: ['Promo Spesial']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertFalse($result->success);
        $this->assertEquals('TEMPLATE_REJECTED', $result->errorCode);
        $this->assertFalse($result->isRetryable);
        Http::assertNothingSent();
    }

    public function test_non_approved_template_states_abort_dispatch_as_terminal_failures(): void
    {
        Http::fake();

        $this->connection->configuration = [
            'api_version' => 'v21.0',
            'templates' => [
                'pending_tpl' => ['status' => 'PENDING'],
                'paused_tpl' => ['status' => 'PAUSED'],
                'disabled_tpl' => ['status' => 'APPROVED', 'enabled' => false],
                'unknown_tpl' => ['status' => 'IN_REVIEW'],
            ],
        ];

        // 1. PENDING template
        $resPending = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'pending_tpl'
        ));
        $this->assertFalse($resPending->success);
        $this->assertEquals('TEMPLATE_PENDING', $resPending->errorCode);
        $this->assertFalse($resPending->isRetryable);

        // 2. PAUSED template
        $resPaused = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'paused_tpl'
        ));
        $this->assertFalse($resPaused->success);
        $this->assertEquals('TEMPLATE_PAUSED', $resPaused->errorCode);
        $this->assertFalse($resPaused->isRetryable);

        // 3. DISABLED template
        $resDisabled = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'disabled_tpl'
        ));
        $this->assertFalse($resDisabled->success);
        $this->assertEquals('TEMPLATE_DISABLED', $resDisabled->errorCode);
        $this->assertFalse($resDisabled->isRetryable);

        // 4. UNKNOWN / IN_REVIEW status
        $resUnknown = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'unknown_tpl'
        ));
        $this->assertFalse($resUnknown->success);
        $this->assertEquals('TEMPLATE_NOT_APPROVED', $resUnknown->errorCode);
        $this->assertFalse($resUnknown->isRetryable);

        // 5. MISSING / Not configured template
        $resMissing = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'non_existent_template'
        ));
        $this->assertFalse($resMissing->success);
        $this->assertEquals('TEMPLATE_MISSING', $resMissing->errorCode);
        $this->assertFalse($resMissing->isRetryable);

        // Verify zero HTTP requests reached the network
        Http::assertNothingSent();
    }

    public function test_template_parameter_and_language_mismatch_aborts_dispatch_without_network_call(): void
    {
        Http::fake();

        $this->connection->configuration = [
            'api_version' => 'v21.0',
            'templates' => [
                'order_update' => [
                    'status' => 'APPROVED',
                    'language' => 'id',
                    'required_param_count' => 3,
                ],
            ],
        ];

        // 1. Parameter count mismatch (required 3, provided 2)
        $resParamMismatch = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'order_status_update',
            templateName: 'order_update',
            templateParameters: ['ORD-001', 'Diproses'] // only 2 params
        ));
        $this->assertFalse($resParamMismatch->success);
        $this->assertEquals('TEMPLATE_PARAM_MISMATCH', $resParamMismatch->errorCode);
        $this->assertFalse($resParamMismatch->isRetryable);

        // 2. Language mismatch (configured 'id', requested 'en')
        $resLangMismatch = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'order_status_update',
            templateName: 'order_update',
            templateParameters: ['ORD-001', 'Diproses', '10 menit'],
            metadata: ['language_code' => 'en']
        ));
        $this->assertFalse($resLangMismatch->success);
        $this->assertEquals('TEMPLATE_LANGUAGE_MISMATCH', $resLangMismatch->errorCode);
        $this->assertFalse($resLangMismatch->isRetryable);

        // Prove no HTTP requests made
        Http::assertNothingSent();
    }

    public function test_freeform_text_outside_customer_service_window_aborts_dispatch_as_terminal_failure(): void
    {
        Http::fake();

        // 1. Transactional / notification message attempted with freeform text (no template)
        $resNotifFreeform = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: null,
            textBody: 'Receipt details in free text'
        ));
        $this->assertFalse($resNotifFreeform->success);
        $this->assertEquals('FREEFORM_NOT_PERMITTED', $resNotifFreeform->errorCode);
        $this->assertFalse($resNotifFreeform->isRetryable);

        // 2. Merchant query reply where customer message was sent 25 hours ago (> 24h window)
        $resExpiredSession = $this->provider->sendMessage($this->connection, new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'merchant_query_reply',
            templateName: null,
            textBody: 'Balasan query setelah 25 jam',
            metadata: [
                'inbound_timestamp' => now()->subHours(25)->timestamp,
            ]
        ));
        $this->assertFalse($resExpiredSession->success);
        $this->assertEquals('FREEFORM_NOT_PERMITTED', $resExpiredSession->errorCode);
        $this->assertFalse($resExpiredSession->isRetryable);

        Http::assertNothingSent();
    }

    public function test_connection_timeout_classifies_as_ambiguous_timeout_and_retryable(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds with 0 bytes received');
            },
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['SeWaroeng', 'REC-TIMEOUT', 'Rp 10.000']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertFalse($result->success);
        $this->assertEquals('AMBIGUOUS_TIMEOUT', $result->errorCode);
        $this->assertTrue($result->isRetryable, 'Timeout must be marked retryable to allow queue retry budget');
    }

    public function test_rate_limited_response_is_retryable(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'User rate limit exceeded',
                    'type' => 'OAuthException',
                    'code' => 80007,
                ],
            ], 429),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['SeWaroeng', 'REC-001', 'Rp 10.000']
        );

        $result429 = $this->provider->sendMessage($this->connection, $payload);
        $this->assertFalse($result429->success);
        $this->assertTrue($result429->isRetryable);
        $this->assertEquals('META_ERR_80007', $result429->errorCode);
    }

    public function test_server_error_response_is_retryable(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Service temporarily unavailable',
                    'type' => 'MetaServerError',
                    'code' => 2,
                ],
            ], 503),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['SeWaroeng', 'REC-001', 'Rp 10.000']
        );

        $result503 = $this->provider->sendMessage($this->connection, $payload);
        $this->assertFalse($result503->success);
        $this->assertTrue($result503->isRetryable);
        $this->assertEquals('META_ERR_2', $result503->errorCode);
    }

    public function test_invalid_credentials_classifies_as_terminal_non_retryable_failure(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid OAuth access token.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 401),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['SeWaroeng', 'REC-001', 'Rp 10.000']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertFalse($result->success);
        $this->assertEquals('META_ERR_190', $result->errorCode);
        $this->assertFalse($result->isRetryable, 'Credential invalidation must not trigger retry loop');
    }

    public function test_sanitized_response_does_not_leak_access_token_or_app_secret(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error occurred for access_token EAAG_SECRET_TOKEN_DO_NOT_LEAK with app_secret PLATFORM_SECRET_98765',
                    'code' => 100,
                    'token' => 'EAAG_SECRET_TOKEN_DO_NOT_LEAK',
                ],
            ], 400),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'transactional_receipt',
            templateParameters: ['SeWaroeng', 'REC-001', 'Rp 10.000']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertFalse($result->success);

        // Ensure raw response does not contain the unredacted token key or secret
        $jsonDump = json_encode($result->rawResponse);
        $this->assertStringNotContainsString('EAAG_SECRET_TOKEN_DO_NOT_LEAK', $jsonDump);
    }

    public function test_freeform_session_contract_constructs_text_payload_with_callback_data(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/10987654321/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '628123456789', 'wa_id' => '628123456789']],
                'messages' => [['id' => 'wamid.FREEFORM_001']],
            ], 200),
        ]);

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'merchant_query_reply',
            templateName: null,
            templateParameters: [],
            textBody: 'Total omzet hari ini adalah Rp 500.000',
            metadata: ['message_id' => 'msg-opaque-12345']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertTrue($result->success);
        $this->assertEquals('wamid.FREEFORM_001', $result->providerMessageId);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://graph.facebook.com/v21.0/10987654321/messages'
                && $data['messaging_product'] === 'whatsapp'
                && $data['to'] === '+628123456789'
                && $data['type'] === 'text'
                && $data['text']['body'] === 'Total omzet hari ini adalah Rp 500.000'
                && $data['biz_opaque_callback_data'] === 'msg-opaque-12345';
        });
    }

    public function test_template_eligibility_fails_closed_for_pending_paused_disabled_rejected_and_unknown_states(): void
    {
        Http::fake();

        $this->connection->configuration = [
            'api_version' => 'v21.0',
            'templates' => [
                'tpl_pending' => ['status' => 'PENDING', 'enabled' => true],
                'tpl_paused' => ['status' => 'PAUSED', 'enabled' => true],
                'tpl_disabled_status' => ['status' => 'DISABLED', 'enabled' => true],
                'tpl_disabled_flag' => ['status' => 'APPROVED', 'enabled' => false],
                'tpl_rejected' => ['status' => 'REJECTED', 'enabled' => true],
                'tpl_unknown' => ['status' => 'IN_REVIEW', 'enabled' => true],
            ],
        ];

        $cases = [
            'tpl_pending' => 'TEMPLATE_PENDING',
            'tpl_paused' => 'TEMPLATE_PAUSED',
            'tpl_disabled_status' => 'TEMPLATE_DISABLED',
            'tpl_disabled_flag' => 'TEMPLATE_DISABLED',
            'tpl_rejected' => 'TEMPLATE_REJECTED',
            'tpl_unknown' => 'TEMPLATE_NOT_APPROVED',
        ];

        foreach ($cases as $templateName => $expectedErrorCode) {
            $payload = new WhatsAppMessagePayload(
                recipientPhone: '+628123456789',
                messageType: 'transactional_receipt',
                templateName: $templateName,
                templateParameters: ['Param 1']
            );

            $result = $this->provider->sendMessage($this->connection, $payload);

            $this->assertFalse($result->success, "Template {$templateName} must fail");
            $this->assertEquals($expectedErrorCode, $result->errorCode, "Template {$templateName} must fail with {$expectedErrorCode}");
            $this->assertFalse($result->isRetryable, "Template {$templateName} failure must be terminal (non-retryable)");
        }

        Http::assertNothingSent();
    }

    public function test_template_missing_from_configuration_fails_closed(): void
    {
        Http::fake();

        $this->connection->configuration = [
            'api_version' => 'v21.0',
            'templates' => [
                'tpl_approved' => ['status' => 'APPROVED'],
            ],
        ];

        $payload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'non_existent_template',
            templateParameters: ['Param 1']
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertFalse($result->success);
        $this->assertEquals('TEMPLATE_MISSING', $result->errorCode);
        $this->assertFalse($result->isRetryable);

        Http::assertNothingSent();
    }

    public function test_template_language_and_parameter_mismatch_fails_closed(): void
    {
        Http::fake();

        $this->connection->configuration = [
            'api_version' => 'v21.0',
            'templates' => [
                'order_update' => [
                    'status' => 'APPROVED',
                    'language' => 'id_ID',
                    'required_param_count' => 3,
                ],
            ],
        ];

        // 1. Language mismatch
        $langMismatchPayload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'order_update',
            templateParameters: ['A', 'B', 'C'],
            metadata: ['language_code' => 'en_US']
        );

        $resultLang = $this->provider->sendMessage($this->connection, $langMismatchPayload);
        $this->assertFalse($resultLang->success);
        $this->assertEquals('TEMPLATE_LANGUAGE_MISMATCH', $resultLang->errorCode);
        $this->assertFalse($resultLang->isRetryable);

        // 2. Parameter count mismatch
        $paramMismatchPayload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: 'order_update',
            templateParameters: ['A', 'B'], // provided 2, required 3
            metadata: ['language_code' => 'id_ID']
        );

        $resultParam = $this->provider->sendMessage($this->connection, $paramMismatchPayload);
        $this->assertFalse($resultParam->success);
        $this->assertEquals('TEMPLATE_PARAM_MISMATCH', $resultParam->errorCode);
        $this->assertFalse($resultParam->isRetryable);

        Http::assertNothingSent();
    }

    public function test_freeform_text_outside_24_hour_window_fails_closed(): void
    {
        Http::fake();

        // 1. Session message with inbound timestamp older than 24 hours (86400 seconds)
        $expiredPayload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'merchant_query_reply',
            templateName: null,
            templateParameters: [],
            textBody: 'Late reply after window closed',
            metadata: ['inbound_timestamp' => now()->subHours(25)->timestamp]
        );

        $resultExpired = $this->provider->sendMessage($this->connection, $expiredPayload);
        $this->assertFalse($resultExpired->success);
        $this->assertEquals('FREEFORM_NOT_PERMITTED', $resultExpired->errorCode);
        $this->assertFalse($resultExpired->isRetryable);

        // 2. Non-session message attempting free-form text without template
        $nonSessionPayload = new WhatsAppMessagePayload(
            recipientPhone: '+628123456789',
            messageType: 'transactional_receipt',
            templateName: null,
            templateParameters: [],
            textBody: 'Attempting freeform receipt'
        );

        $resultNonSession = $this->provider->sendMessage($this->connection, $nonSessionPayload);
        $this->assertFalse($resultNonSession->success);
        $this->assertEquals('FREEFORM_NOT_PERMITTED', $resultNonSession->errorCode);
        $this->assertFalse($resultNonSession->isRetryable);

        Http::assertNothingSent();
    }
}
