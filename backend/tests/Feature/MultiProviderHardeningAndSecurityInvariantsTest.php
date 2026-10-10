<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\UnsupportedPaymentProviderException;
use App\Models\PaymentProviderDefinition;
use App\Models\TenantPaymentConnection;
use App\Models\User;
use App\Services\Payment\PaymentProviderAvailabilityResolver;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Payment\PaymentService;
use App\Services\Payment\Providers\DuitkuPaymentProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class MultiProviderHardeningAndSecurityInvariantsTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantA;

    protected array $tenantB;

    protected User $superadmin;

    protected User $cashierA;

    protected User $ownerA;

    protected PaymentProviderManager $providerManager;

    protected PaymentService $paymentService;

    protected PaymentProviderAvailabilityResolver $availabilityResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->createTenant('Tenant Alpha Waroeng');
        $this->tenantB = $this->createTenant('Tenant Beta Waroeng');

        $cashierData = $this->addMemberToTenant($this->tenantA['tenant'], 'cashier', 'Kasir A', [$this->tenantA['outlet']->id]);
        $this->cashierA = $cashierData['user'];

        $this->ownerA = $this->tenantA['owner'];

        $this->superadmin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $this->providerManager = app(PaymentProviderManager::class);
        $this->paymentService = app(PaymentService::class);
        $this->availabilityResolver = app(PaymentProviderAvailabilityResolver::class);
    }

    /**
     * Requirement 2: Provider Adapter Registry Must Be Code-Authoritative
     */
    public function test_provider_adapter_registry_is_code_authoritative(): void
    {
        // 1. Verify code-authoritative allowlisted adapters
        $allowlist = PaymentProviderManager::getAllowlistedAdapters();
        $this->assertArrayHasKey('midtrans', $allowlist);
        $this->assertArrayHasKey('duitku', $allowlist);
        $this->assertArrayHasKey('manual', $allowlist);
        $this->assertArrayHasKey('mock', $allowlist);

        // 2. Tampering with database adapter_class does NOT affect resolver
        PaymentProviderDefinition::query()->where('provider', 'duitku')->update([
            'adapter_class' => 'App\\Models\\User', // malicious arbitrary class injection attempt
        ]);

        $resolved = $this->providerManager->resolve('duitku');
        $this->assertInstanceOf(DuitkuPaymentProvider::class, $resolved);
        $this->assertNotInstanceOf(User::class, $resolved);

        // 3. Unregistered provider cannot be resolved
        $this->expectException(UnsupportedPaymentProviderException::class);
        $this->providerManager->resolve('malicious_external_gateway');
    }

    /**
     * Requirement 3: Merchant Identifier Uniqueness
     */
    public function test_merchant_identifier_uniqueness_enforced_by_database(): void
    {
        $tenantAId = (string) $this->tenantA['tenant']->id;
        $tenantBId = (string) $this->tenantB['tenant']->id;

        // 1. Tenant A + Duitku + sandbox + merchantCode X = allowed
        $resA = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantAId}/payment-connections/duitku", [
                'merchant_identifier' => 'MERCHANT-X',
                'environment' => 'sandbox',
                'status' => 'active',
                'credentials' => ['api_key' => 'secret-key-1'],
            ]);
        $resA->assertStatus(201);

        // 2. Tenant B + Duitku + sandbox + merchantCode X = rejected with conflict
        $resB = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantBId}/payment-connections/duitku", [
                'merchant_identifier' => 'MERCHANT-X',
                'environment' => 'sandbox',
                'status' => 'active',
                'credentials' => ['api_key' => 'secret-key-2'],
            ]);
        $resB->assertStatus(409);
        $this->assertEquals('MERCHANT_IDENTIFIER_CONFLICT', $resB->json('code'));

        // 3. Tenant A + Duitku + production + merchantCode X = allowed (different environment)
        $resAProd = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantAId}/payment-connections/duitku", [
                'merchant_identifier' => 'MERCHANT-X',
                'environment' => 'production',
                'status' => 'active',
                'credentials' => ['api_key' => 'secret-key-3'],
            ]);
        $resAProd->assertStatus(201);

        // 4. Tenant A + Midtrans + sandbox + merchantCode X = allowed (different provider)
        $resAMidtrans = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantAId}/payment-connections/midtrans", [
                'merchant_identifier' => 'MERCHANT-X',
                'environment' => 'sandbox',
                'status' => 'active',
                'credentials' => ['server_key' => 'SB-Mid-server-x'],
            ]);
        $resAMidtrans->assertStatus(201);
    }

    /**
     * Requirement 4: Only One Default Payment Provider (PostgreSQL Partial Unique Index)
     */
    public function test_only_one_default_payment_provider_per_tenant_and_environment(): void
    {
        $tenantId = (string) $this->tenantA['tenant']->id;

        // 1. Create connection with is_default = true
        $res1 = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/duitku", [
                'merchant_identifier' => 'M-DUITKU-1',
                'environment' => 'sandbox',
                'status' => 'active',
                'is_default' => true,
                'credentials' => ['api_key' => 'key-1'],
            ]);
        $res1->assertStatus(201);

        // Verify duitku is default
        $conn1 = app(TenantManager::class)->runForTenant($this->tenantA['tenant'], function () use ($tenantId) {
            return TenantPaymentConnection::where('tenant_id', $tenantId)
                ->where('provider', 'duitku')
                ->first();
        });
        $this->assertNotNull($conn1);
        $this->assertTrue((bool) $conn1->is_default);

        // 2. Switch default provider to midtrans
        $res2 = $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/midtrans", [
                'merchant_identifier' => 'M-MIDTRANS-1',
                'environment' => 'sandbox',
                'status' => 'active',
                'is_default' => true,
                'credentials' => ['server_key' => 'SB-Mid-key-1'],
            ]);
        $res2->assertStatus(201);

        // Verify atomic switch: Midtrans is default, Duitku is NO LONGER default
        $conn1Fresh = app(TenantManager::class)->runForTenant($this->tenantA['tenant'], function () use ($tenantId) {
            return TenantPaymentConnection::where('tenant_id', $tenantId)
                ->where('provider', 'duitku')
                ->first();
        });
        $conn2Fresh = app(TenantManager::class)->runForTenant($this->tenantA['tenant'], function () use ($tenantId) {
            return TenantPaymentConnection::where('tenant_id', $tenantId)
                ->where('provider', 'midtrans')
                ->first();
        });

        $this->assertFalse((bool) $conn1Fresh->is_default);
        $this->assertTrue((bool) $conn2Fresh->is_default);

        // 3. Database-level partial unique index verification:
        // Trying to force a second is_default = true directly via DB statement throws UniqueConstraintViolationException
        DB::statement("SELECT set_config('app.is_platform_context', 'true', true)");
        try {
            DB::statement('SAVEPOINT sp_default_test');
            try {
                DB::statement("
                    UPDATE tenant_payment_connections 
                    SET is_default = true 
                    WHERE id = '{$conn1->id}'
                ");
                $this->fail('Expected unique constraint violation on duplicate default connection');
            } catch (UniqueConstraintViolationException $e) {
                DB::statement('ROLLBACK TO SAVEPOINT sp_default_test');
                $this->assertStringContainsString('uq_tenant_env_default_connection', $e->getMessage());
            }
        } finally {
            DB::statement("SELECT set_config('app.is_platform_context', '', true)");
        }
    }

    /**
     * Requirement 5: Credential Rotation Lifecycle
     */
    public function test_credential_rotation_invalidates_verification(): void
    {
        $tenantId = (string) $this->tenantA['tenant']->id;

        // 1. Create and verify connection
        $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/duitku", [
                'merchant_identifier' => 'M-ROTATE-1',
                'environment' => 'sandbox',
                'status' => 'active',
                'credentials' => ['api_key' => 'initial-secret-1111'],
            ])->assertStatus(201);

        // Mock verify
        $conn = app(TenantManager::class)->runForTenant($this->tenantA['tenant'], function () {
            $c = TenantPaymentConnection::where('merchant_identifier', 'M-ROTATE-1')->first();
            $c->update([
                'verification_status' => TenantPaymentConnection::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'is_default' => true,
            ]);

            return $c;
        });

        $connVerified = app(TenantManager::class)->runForTenant($this->tenantA['tenant'], fn () => $conn->fresh());
        $this->assertEquals(TenantPaymentConnection::VERIFICATION_VERIFIED, $connVerified->verification_status);
        $this->assertTrue($connVerified->is_default);

        // 2. Rotate credentials -> verification status drops to unverified, verified_at is cleared, is_default demoted
        $this->actingAs($this->superadmin)
            ->putJson("/api/v1/platform/tenants/{$tenantId}/payment-connections/duitku", [
                'merchant_identifier' => 'M-ROTATE-1',
                'environment' => 'sandbox',
                'status' => 'active',
                'credentials' => ['api_key' => 'rotated-new-secret-2222'],
            ])->assertStatus(200);

        $connFresh = app(TenantManager::class)->runForTenant($this->tenantA['tenant'], function () use ($conn) {
            return $conn->fresh();
        });

        $this->assertEquals(TenantPaymentConnection::VERIFICATION_UNVERIFIED, $connFresh->verification_status);
        $this->assertNull($connFresh->verified_at);
        $this->assertFalse($connFresh->is_default);

        // Verify old secret is not exposed in API response
        $masked = $connFresh->masked_credentials['api_key'];
        $this->assertStringNotContainsString('initial-secret-1111', $masked);
        $this->assertStringNotContainsString('rotated-new-secret-2222', $masked);
        $this->assertStringEndsWith('2222', $masked);
    }

    /**
     * Requirement 6: Effective Provider Availability
     */
    public function test_effective_provider_availability_rules(): void
    {
        $tenantId = (string) $this->tenantA['tenant']->id;

        // 1. Manual / cash is always available
        $this->assertTrue($this->availabilityResolver->isProviderAvailable('manual', $tenantId));
        $this->assertTrue($this->availabilityResolver->isProviderAvailable('cash', $tenantId));

        // 2. Platform provider disabled -> not available
        PaymentProviderDefinition::query()->where('provider', 'duitku')->update(['is_enabled' => false]);
        $checkDisabled = $this->availabilityResolver->checkAvailability('duitku', $tenantId);
        $this->assertFalse($checkDisabled['available']);
        $this->assertEquals(PaymentProviderAvailabilityResolver::REASON_PLATFORM_DISABLED, $checkDisabled['reason']);

        // Re-enable
        PaymentProviderDefinition::query()->where('provider', 'duitku')->update(['is_enabled' => true]);

        // 3. Connection missing -> not available
        $tenantBId = (string) $this->tenantB['tenant']->id;
        $checkMissing = $this->availabilityResolver->checkAvailability('duitku', $tenantBId);
        $this->assertFalse($checkMissing['available']);
        $this->assertEquals(PaymentProviderAvailabilityResolver::REASON_CONNECTION_NOT_FOUND, $checkMissing['reason']);

        // 4. Connection inactive -> not available
        $conn = app(TenantManager::class)->runForTenant($this->tenantB['tenant'], function () {
            return TenantPaymentConnection::create([
                'tenant_id' => $this->tenantB['tenant']->id,
                'provider' => 'duitku',
                'environment' => 'sandbox',
                'status' => TenantPaymentConnection::STATUS_INACTIVE,
                'merchant_identifier' => 'M-INACTIVE',
                'encrypted_credentials' => ['api_key' => 'secret-inactive'],
                'verification_status' => TenantPaymentConnection::VERIFICATION_VERIFIED,
            ]);
        });

        $checkInactive = $this->availabilityResolver->checkAvailability('duitku', $tenantBId);
        $this->assertFalse($checkInactive['available']);
        $this->assertEquals(PaymentProviderAvailabilityResolver::REASON_CONNECTION_INACTIVE, $checkInactive['reason']);

        // 5. Connection unverified -> not available
        app(TenantManager::class)->runForTenant($this->tenantB['tenant'], function () use ($conn) {
            $conn->update([
                'status' => TenantPaymentConnection::STATUS_ACTIVE,
                'verification_status' => TenantPaymentConnection::VERIFICATION_UNVERIFIED,
            ]);
        });

        $checkUnverified = $this->availabilityResolver->checkAvailability('duitku', $tenantBId);
        $this->assertFalse($checkUnverified['available']);
        $this->assertEquals(PaymentProviderAvailabilityResolver::REASON_CONNECTION_UNVERIFIED, $checkUnverified['reason']);

        // 6. Active + verified -> available!
        app(TenantManager::class)->runForTenant($this->tenantB['tenant'], function () use ($conn) {
            $conn->update([
                'verification_status' => TenantPaymentConnection::VERIFICATION_VERIFIED,
            ]);
        });

        $checkVerified = $this->availabilityResolver->checkAvailability('duitku', $tenantBId);
        $this->assertTrue($checkVerified['available']);
        $this->assertEquals(PaymentProviderAvailabilityResolver::REASON_AVAILABLE, $checkVerified['reason']);
    }

    /**
     * Requirement 9: Duitku Webhook Routing Hardening & Fail-Closed
     */
    public function test_duitku_webhook_routing_fails_closed_on_unknown_or_wrong_environment(): void
    {
        // 1. Unknown merchant code -> 400
        $resUnknown = $this->postJson('/api/v1/payments/webhooks/duitku', [
            'merchantCode' => 'NON-EXISTENT-CODE',
            'amount' => '10000',
            'merchantOrderId' => 'PAY-20261005-UNKNOWN',
            'signature' => 'some-signature',
        ]);
        $resUnknown->assertStatus(400);

        // 2. Production merchant code sent when sandbox expected -> 400
        app(TenantManager::class)->runForTenant($this->tenantA['tenant'], function () {
            TenantPaymentConnection::create([
                'tenant_id' => $this->tenantA['tenant']->id,
                'provider' => 'duitku',
                'environment' => 'production',
                'status' => TenantPaymentConnection::STATUS_ACTIVE,
                'merchant_identifier' => 'M-PROD-ONLY',
                'encrypted_credentials' => ['api_key' => 'prod-secret'],
            ]);
        });

        $resWrongEnv = $this->postJson('/api/v1/payments/webhooks/duitku', [
            'merchantCode' => 'M-PROD-ONLY',
            'amount' => '10000',
            'merchantOrderId' => 'PAY-20261005-PROD',
            'signature' => 'some-signature',
        ]);
        $resWrongEnv->assertStatus(400);
    }

    /**
     * Requirement 10: Superadmin Platform Boundary & RLS Context Cleanup
     */
    public function test_superadmin_platform_boundary_and_context_cleanup(): void
    {
        $tenantId = (string) $this->tenantA['tenant']->id;

        // 1. Cashier attempting to access platform API -> 403
        $this->actingAs($this->cashierA)
            ->getJson('/api/v1/platform/payment-providers')
            ->assertStatus(403);

        // 2. Tenant owner attempting to access platform API -> 403
        $this->actingAs($this->ownerA)
            ->getJson('/api/v1/platform/payment-providers')
            ->assertStatus(403);

        // 3. Spoofed X-Tenant-ID header does not elevate permissions -> 403
        $this->actingAs($this->cashierA)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson('/api/v1/platform/payment-providers')
            ->assertStatus(403);

        // 4. Platform context is always cleaned up after request
        $this->actingAs($this->superadmin)
            ->getJson('/api/v1/platform/payment-providers')
            ->assertStatus(200);

        $contextVal = DB::selectOne("SELECT current_setting('app.is_platform_context', true) as val")->val;
        $this->assertEmpty($contextVal);
    }
}
