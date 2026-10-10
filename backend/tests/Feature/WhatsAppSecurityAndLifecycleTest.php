<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Outlet;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Subscription\SubscriptionEntitlementService;
use App\Services\Subscription\SubscriptionService;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class WhatsAppSecurityAndLifecycleTest extends TenantTestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Outlet $outletA;

    protected User $ownerA;

    protected User $cashierA;

    protected TenantWhatsAppConnection $connectionA;

    protected Tenant $tenantB;

    protected Outlet $outletB;

    protected User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);

        MockWhatsAppProvider::reset();

        // 1. Setup Tenant A
        $tenantAData = $this->createTenant('Waroeng A');
        $this->tenantA = $tenantAData['tenant'];
        $this->outletA = $tenantAData['outlet'];
        $this->ownerA = $tenantAData['owner'];

        $cashierData = $this->addMemberToTenant($this->tenantA, 'cashier', 'Kasir A', [$this->outletA->id]);
        $this->cashierA = $cashierData['user'];

        // Entitle Tenant A
        app(TenantManager::class)->runForTenant($this->tenantA, function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenantA->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenantA);

        // Connection for Tenant A
        $this->connectionA = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return TenantWhatsAppConnection::create([
                'tenant_id' => $this->tenantA->id,
                'provider' => 'mock',
                'environment' => 'sandbox',
                'status' => TenantWhatsAppConnection::STATUS_ACTIVE,
                'is_default' => true,
                'phone_number_id' => '1029384756',
                'encrypted_credentials' => [
                    'access_token' => 'super_secret_token_tenant_a',
                    'webhook_verify_token' => 'token_verify_secret_a',
                ],
                'configuration' => [
                    'receipt_template_name' => 'transactional_receipt',
                ],
                'verification_status' => TenantWhatsAppConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
            ]);
        });

        // 2. Setup Tenant B
        $tenantBData = $this->createTenant('Waroeng B');
        $this->tenantB = $tenantBData['tenant'];
        $this->outletB = $tenantBData['outlet'];
        $this->ownerB = $tenantBData['owner'];

        app(TenantManager::class)->runForTenant($this->tenantB, function () {
            $businessPlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_BUSINESS)->first();
            Subscription::where('tenant_id', $this->tenantB->id)->update(['plan_id' => $businessPlan->id]);
        });
        app(SubscriptionEntitlementService::class)->clearCache($this->tenantB);
    }

    public function test_credentials_are_masked_in_api_responses_and_never_leaked(): void
    {
        $response = $this->authenticateTenantUser($this->ownerA, $this->tenantA)
            ->getJson('/api/v1/whatsapp/connection');

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_entitled', true);

        // Must expose masked_credentials with secrets replaced by asterisks
        $connections = $response->json('data.connections');
        $this->assertNotEmpty($connections);
        $conn = $connections[0];

        $this->assertArrayHasKey('masked_credentials', $conn);
        $this->assertStringStartsWith('****', $conn['masked_credentials']['access_token']);
        $this->assertStringNotContainsString('super_secret_token_tenant_a', $conn['masked_credentials']['access_token']);

        // Must NOT expose raw encrypted_credentials
        $this->assertArrayNotHasKey('encrypted_credentials', $conn);
    }

    public function test_cross_tenant_cannot_view_or_mutate_other_tenant_connection(): void
    {
        // Owner B authenticated for Tenant B
        $response = $this->authenticateTenantUser($this->ownerB, $this->tenantB)
            ->getJson('/api/v1/whatsapp/connection');

        $response->assertStatus(200);
        // Tenant B has 0 connections, Tenant A's connection is completely isolated
        $connections = $response->json('data.connections');
        $this->assertEmpty($connections);

        // Owner B attempts to send update for Tenant B
        $updateResponse = $this->authenticateTenantUser($this->ownerB, $this->tenantB)
            ->postJson('/api/v1/whatsapp/connection', [
                'provider' => 'mock',
                'credentials' => [
                    'access_token' => 'tenant_b_token',
                ],
            ]);

        $updateResponse->assertStatus(201);
        $this->assertEquals($this->tenantB->id, $updateResponse->json('data.tenant_id'));

        // Tenant A's connection remains intact and unmodified
        $connA = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return $this->connectionA->fresh();
        });

        $this->assertEquals(
            'super_secret_token_tenant_a',
            $connA->encrypted_credentials['access_token']
        );
    }

    public function test_webhook_verification_meta_get_challenge_and_post_signature(): void
    {
        // 1. GET Subscription Challenge with valid token
        $getValid = $this->get('/api/v1/webhooks/whatsapp/'.$this->tenantA->id.'/mock?hub.mode=subscribe&hub.verify_token=token_verify_secret_a&hub.challenge=test_challenge_code_123');
        $getValid->assertStatus(200);
        $this->assertEquals('test_challenge_code_123', $getValid->getContent());

        // 2. GET Subscription Challenge with invalid token
        $getInvalid = $this->get('/api/v1/webhooks/whatsapp/'.$this->tenantA->id.'/mock?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=test_challenge_code_123');
        $getInvalid->assertStatus(403);

        // 3. POST Webhook with invalid signature
        $postInvalid = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenantA->id.'/mock', [
            'statuses' => [],
        ], [
            'X-Mock-Signature' => 'invalid-signature',
        ]);
        $postInvalid->assertStatus(401);

        // 4. POST Webhook with valid signature transitions message status
        $message = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            return WhatsAppMessage::create([
                'tenant_id' => $this->tenantA->id,
                'recipient_phone' => '+628123456789',
                'message_type' => 'transactional_receipt',
                'status' => WhatsAppMessage::STATUS_SENT,
                'provider' => 'mock',
                'provider_message_id' => 'mock-msg-webhook-101',
            ]);
        });

        $postValid = $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenantA->id.'/mock', [
            'statuses' => [
                [
                    'id' => 'mock-msg-webhook-101',
                    'status' => 'delivered',
                    'timestamp' => (string) now()->timestamp,
                    'recipient_id' => '628123456789',
                ],
            ],
        ], [
            'X-Mock-Signature' => 'valid-mock-signature',
        ]);

        $postValid->assertStatus(200);

        $freshMsg = app(TenantManager::class)->runForTenant($this->tenantA, fn () => $message->fresh());
        $this->assertEquals(WhatsAppMessage::STATUS_DELIVERED, $freshMsg->status);
        $this->assertNotNull($freshMsg->delivered_at);
    }

    public function test_monotonic_state_machine_prevents_out_of_order_downgrade(): void
    {
        $message = app(TenantManager::class)->runForTenant($this->tenantA, function () {
            $msg = WhatsAppMessage::create([
                'tenant_id' => $this->tenantA->id,
                'recipient_phone' => '+628123456789',
                'message_type' => 'transactional_receipt',
                'status' => WhatsAppMessage::STATUS_SENT,
                'provider' => 'mock',
                'provider_message_id' => 'mock-msg-mono-1',
            ]);
            $msg->markDelivered();
            $msg->markRead();

            return $msg;
        });

        $this->assertEquals(WhatsAppMessage::STATUS_READ, $message->status);

        // Out-of-order callback arriving late with status 'delivered'
        $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenantA->id.'/mock', [
            'statuses' => [
                [
                    'id' => 'mock-msg-mono-1',
                    'status' => 'delivered',
                ],
            ],
        ], ['X-Mock-Signature' => 'valid-mock-signature']);

        // Message remains READ, cannot downgrade
        $freshDelivered = app(TenantManager::class)->runForTenant($this->tenantA, fn () => $message->fresh());
        $this->assertEquals(WhatsAppMessage::STATUS_READ, $freshDelivered->status);

        // Out-of-order callback arriving late with status 'failed'
        $this->postJson('/api/v1/webhooks/whatsapp/'.$this->tenantA->id.'/mock', [
            'statuses' => [
                [
                    'id' => 'mock-msg-mono-1',
                    'status' => 'failed',
                    'errors' => [['code' => 500, 'title' => 'Late Error']],
                ],
            ],
        ], ['X-Mock-Signature' => 'valid-mock-signature']);

        // Message still remains READ!
        $freshFailed = app(TenantManager::class)->runForTenant($this->tenantA, fn () => $message->fresh());
        $this->assertEquals(WhatsAppMessage::STATUS_READ, $freshFailed->status);
    }

    public function test_unentitled_tenant_blocked_from_configuring_whatsapp(): void
    {
        // Create Tenant with Free Plan (whatsapp = false)
        $freeTenantData = $this->createTenant('Toko Free Plan');
        $freeTenant = $freeTenantData['tenant'];
        $freeOwner = $freeTenantData['owner'];

        $freePlan = SubscriptionPlan::where('code', SubscriptionPlan::CODE_FREE)->first();
        app(SubscriptionService::class)->createTrialSubscription($freeTenant, $freePlan);

        $response = $this->authenticateTenantUser($freeOwner, $freeTenant)
            ->postJson('/api/v1/whatsapp/connection', [
                'provider' => 'mock',
                'credentials' => [
                    'access_token' => 'free_attempt_token',
                ],
            ]);

        // Blocked with SubscriptionFeatureNotIncludedException / 403 Forbidden
        $this->assertTrue(in_array($response->status(), [402, 403], true));
    }
}
