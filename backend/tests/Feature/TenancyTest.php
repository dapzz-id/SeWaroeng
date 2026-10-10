<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Role;
use App\Models\TenantTestRecord;
use Tests\TenantTestCase;

class TenancyTest extends TenantTestCase
{
    public function test_tenant_a_cannot_access_tenant_b_records(): void
    {
        $tenantA = $this->createTenant('Warung Kopi A');
        $tenantB = $this->createTenant('Bakery Jaya B');
        $manager = app(TenantManager::class);

        // Create records for both tenants inside their respective tenant RLS contexts
        $recordA = $manager->runForTenant($tenantA['tenant'], function () use ($tenantA) {
            return TenantTestRecord::create([
                'tenant_id' => $tenantA['tenant']->id,
                'name' => 'Record Tenant A',
                'secret_data' => 'Secret A',
            ]);
        });

        $recordB = $manager->runForTenant($tenantB['tenant'], function () use ($tenantB) {
            return TenantTestRecord::create([
                'tenant_id' => $tenantB['tenant']->id,
                'name' => 'Record Tenant B',
                'secret_data' => 'Secret B',
            ]);
        });

        // Authenticate as Tenant A owner
        $response = $this->authenticateTenantUser($tenantA['owner'], $tenantA['tenant'])
            ->getJson('/api/v1/tenant/test-records');

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Record Tenant A']);
        $response->assertJsonMissing(['name' => 'Record Tenant B']);
    }

    public function test_client_cannot_forge_tenant_id_header(): void
    {
        $tenantA = $this->createTenant('Warung Kopi A');
        $tenantB = $this->createTenant('Bakery Jaya B');

        // User from Tenant A attempts to access Tenant B by manipulating X-Tenant-ID
        $response = $this->authenticateTenantUser($tenantA['owner'], $tenantB['tenant'])
            ->getJson('/api/v1/tenant/test-records');

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'TENANT_ACCESS_DENIED',
        ]);
    }

    public function test_record_created_under_tenant_automatically_assigned_tenant_id(): void
    {
        $tenantA = $this->createTenant('Warung Kopi A');
        $manager = app(TenantManager::class);

        $response = $this->authenticateTenantUser($tenantA['owner'], $tenantA['tenant'])
            ->postJson('/api/v1/tenant/test-records', [
                'name' => 'Automated Scoped Item',
                'secret_data' => 'Confidential',
            ]);

        $response->assertStatus(201);
        $recordId = $response->json('data.id');

        // Check inside tenant context honoring PostgreSQL RLS
        $persisted = $manager->runForTenant($tenantA['tenant'], function () use ($recordId, $tenantA) {
            return TenantTestRecord::where('id', $recordId)
                ->where('tenant_id', $tenantA['tenant']->id)
                ->first();
        });

        $this->assertNotNull($persisted);
        $this->assertEquals('Automated Scoped Item', $persisted->name);
        $this->assertEquals((string) $tenantA['tenant']->id, (string) $persisted->tenant_id);
    }

    public function test_multi_business_user_can_switch_context(): void
    {
        $tenantA = $this->createTenant('Warung Kopi A');
        $tenantB = $this->createTenant('Bakery Jaya B');

        // Link Tenant A owner to Tenant B as manager
        $tenantB['tenant']->memberships()->create([
            'user_id' => $tenantA['owner']->id,
            'role_id' => Role::where('slug', 'manager')->first()->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        // Access Tenant A
        $resA = $this->authenticateTenantUser($tenantA['owner'], $tenantA['tenant'])
            ->getJson('/api/v1/tenant/context');
        $resA->assertStatus(200);
        $resA->assertJsonPath('data.tenant_id', (string) $tenantA['tenant']->id);
        $resA->assertJsonPath('data.role', 'owner');

        // Access Tenant B
        $resB = $this->authenticateTenantUser($tenantA['owner'], $tenantB['tenant'])
            ->getJson('/api/v1/tenant/context');
        $resB->assertStatus(200);
        $resB->assertJsonPath('data.tenant_id', (string) $tenantB['tenant']->id);
        $resB->assertJsonPath('data.role', 'manager');
    }
}
