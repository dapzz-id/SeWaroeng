<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\AuditLog;
use App\Models\PaymentProviderDefinition;
use App\Models\TenantPaymentConnection;
use App\Models\User;
use App\Services\Payment\Providers\MidtransPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

class SuperadminPaymentProviderTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $owner;

    protected User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Waroeng Superadmin Cafe');
        $this->owner = $this->tenantData['owner'];

        $cashierData = $this->addMemberToTenant($this->tenantData['tenant'], 'cashier', 'Kasir User');
        $this->cashier = $cashierData['user'];

        // Create platform Superadmin user
        $this->superadmin = User::create([
            'name' => 'Platform Superadmin',
            'email' => 'superadmin@sewaroeng.platform',
            'password' => 'supersecret123',
            'is_active' => true,
            'is_superadmin' => true,
        ]);
    }

    public function test_cashier_cannot_access_platform_payment_providers(): void
    {
        $response = $this->actingAs($this->cashier)
            ->getJson('/api/v1/platform/payment-providers');

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'SUPERADMIN_REQUIRED');
    }

    public function test_tenant_owner_cannot_manage_platform_payment_providers(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/platform/payment-providers');

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'SUPERADMIN_REQUIRED');
    }

    public function test_superadmin_can_list_platform_payment_providers(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->getJson('/api/v1/platform/payment-providers');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $providers = array_column($data, 'provider');
        $this->assertContains('midtrans', $providers);
        $this->assertContains('duitku', $providers);
    }

    public function test_superadmin_can_enable_and_disable_provider(): void
    {
        // Disable Midtrans globally
        $resDisable = $this->actingAs($this->superadmin)
            ->postJson('/api/v1/platform/payment-providers/midtrans/disable');

        $resDisable->assertStatus(200);
        $this->assertFalse(PaymentProviderDefinition::where('provider', 'midtrans')->value('is_enabled'));

        // Enable Midtrans globally
        $resEnable = $this->actingAs($this->superadmin)
            ->postJson('/api/v1/platform/payment-providers/midtrans/enable');

        $resEnable->assertStatus(200);
        $this->assertTrue(PaymentProviderDefinition::where('provider', 'midtrans')->value('is_enabled'));

        // Verify audit log recorded
        $log = AuditLog::where('action', 'payment_provider_enabled')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->superadmin->id, $log->actor_user_id);
    }

    public function test_superadmin_can_create_and_update_tenant_payment_connection(): void
    {
        $tenantId = (string) $this->tenantData['tenant']->id;

        // Create connection
        $resCreate = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/duitku", [
                'merchant_identifier' => 'D98765',
                'environment' => 'sandbox',
                'status' => 'active',
                'is_default' => true,
                'credentials' => [
                    'api_key' => 'super-secret-api-key-9999',
                ],
            ]);

        $resCreate->assertStatus(201);

        // Verify secrets are masked in API response
        $maskedKey = $resCreate->json('data.masked_credentials.api_key');
        $this->assertNotNull($maskedKey);
        $this->assertStringNotContainsString('super-secret-api-key-9999', $maskedKey);
        $this->assertStringEndsWith('9999', $maskedKey);

        // Verify encrypted at rest in database
        $rawConnection = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () use ($tenantId) {
            return TenantPaymentConnection::where('tenant_id', $tenantId)
                ->where('provider', 'duitku')
                ->first();
        });

        $this->assertNotNull($rawConnection);
        $this->assertEquals('D98765', $rawConnection->merchant_identifier);
        $this->assertEquals('super-secret-api-key-9999', $rawConnection->encrypted_credentials['api_key']);

        // Verify audit log masks secrets
        $auditLog = AuditLog::where('action', 'tenant_payment_connection_created')->latest('id')->first();
        $this->assertNotNull($auditLog);
        $logJson = json_encode($auditLog->after);
        $this->assertStringNotContainsString('super-secret-api-key-9999', $logJson);
    }

    public function test_superadmin_can_verify_tenant_payment_connection(): void
    {
        $tenantId = (string) $this->tenantData['tenant']->id;

        // Setup connection inside tenant boundary
        $connection = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () use ($tenantId) {
            return TenantPaymentConnection::create([
                'tenant_id' => $tenantId,
                'provider' => 'midtrans',
                'environment' => 'sandbox',
                'status' => 'active',
                'merchant_identifier' => 'M123456',
                'encrypted_credentials' => [
                    'server_key' => 'SB-Mid-server-test-1234',
                ],
                'verification_status' => 'unverified',
            ]);
        });

        // Mock Midtrans ping response
        Http::fake([
            MidtransPaymentProvider::SANDBOX_URL.'/v2/sewaroeng-verify-ping/status' => Http::response([
                'status_code' => '404',
                'status_message' => 'Transaction not found',
            ], 404),
        ]);

        $resVerify = $this->actingAs($this->superadmin)
            ->postJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/midtrans/verify");

        $resVerify->assertStatus(200);
        $resVerify->assertJsonPath('data.verification.verified', true);

        app(TenantManager::class)->runForTenant($this->tenantData['tenant'], fn () => $connection->refresh());
        $this->assertEquals('verified', $connection->verification_status);
        $this->assertNotNull($connection->verified_at);
        $this->assertNull($connection->last_error_code);
    }

    public function test_superadmin_verification_records_failure_safely(): void
    {
        $tenantId = (string) $this->tenantData['tenant']->id;

        $connection = app(TenantManager::class)->runForTenant($this->tenantData['tenant'], function () use ($tenantId) {
            return TenantPaymentConnection::create([
                'tenant_id' => $tenantId,
                'provider' => 'midtrans',
                'environment' => 'sandbox',
                'status' => 'active',
                'merchant_identifier' => 'M123456',
                'encrypted_credentials' => [
                    'server_key' => 'SB-Mid-server-invalid-key',
                ],
                'verification_status' => 'unverified',
            ]);
        });

        // Mock 401 Unauthorized
        Http::fake([
            MidtransPaymentProvider::SANDBOX_URL.'/v2/sewaroeng-verify-ping/status' => Http::response([
                'status_code' => '401',
                'status_message' => 'Access denied',
            ], 401),
        ]);

        $resVerify = $this->actingAs($this->superadmin)
            ->postJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/midtrans/verify");

        $resVerify->assertStatus(200);
        $resVerify->assertJsonPath('data.verification.verified', false);

        app(TenantManager::class)->runForTenant($this->tenantData['tenant'], fn () => $connection->refresh());
        $this->assertEquals('failed', $connection->verification_status);
        $this->assertEquals('INVALID_CREDENTIALS', $connection->last_error_code);
    }
}
