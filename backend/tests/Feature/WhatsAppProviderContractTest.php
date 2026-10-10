<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
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
        // 1. Configured valid version on connection
        $this->connection->configuration = ['api_version' => 'v22.0'];
        $this->assertEquals('v22.0', $this->provider->resolveApiVersion($this->connection));

        // 2. Uses server config when not on connection
        $this->connection->configuration = [];
        config(['services.whatsapp.meta.graph_version' => 'v20.0']);
        $this->assertEquals('v20.0', $this->provider->resolveApiVersion($this->connection));

        // 3. Rejects unsupported magic string or expired version
        $this->connection->configuration = ['api_version' => 'v99.9'];
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->resolveApiVersion($this->connection);
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
            textBody: 'Nota Pembelian'
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
            textBody: 'Nota Pembelian'
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
            textBody: 'Nota Pembelian'
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
            textBody: 'Nota'
        );

        $result = $this->provider->sendMessage($this->connection, $payload);

        $this->assertFalse($result->success);

        // Ensure raw response does not contain the unredacted token key or secret
        $jsonDump = json_encode($result->rawResponse);
        $this->assertStringNotContainsString('EAAG_SECRET_TOKEN_DO_NOT_LEAK', $jsonDump);
    }
}
