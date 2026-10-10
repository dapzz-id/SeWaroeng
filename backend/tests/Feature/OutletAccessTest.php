<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Outlet;
use Tests\TenantTestCase;

class OutletAccessTest extends TenantTestCase
{
    public function test_cashier_can_access_assigned_outlet(): void
    {
        $tenantData = $this->createTenant('Resto Padang');
        $outlet1 = $tenantData['outlet'];

        $cashier = $this->addMemberToTenant(
            $tenantData['tenant'],
            'cashier',
            'Kasir Outlet 1',
            [(string) $outlet1->id]
        );

        $response = $this->authenticateTenantUser($cashier['user'], $tenantData['tenant'])
            ->getJson("/api/v1/tenant/outlets/{$outlet1->id}/verify");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'outlet_id' => (string) $outlet1->id,
                'authorized' => true,
            ],
        ]);
    }

    public function test_cashier_cannot_access_unassigned_outlet(): void
    {
        $tenantData = $this->createTenant('Resto Padang');
        $outlet1 = $tenantData['outlet'];

        $outlet2 = app(TenantManager::class)->runForTenant($tenantData['tenant'], function () use ($tenantData) {
            return Outlet::create([
                'tenant_id' => $tenantData['tenant']->id,
                'name' => 'Resto Padang - Cabang 2',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);
        });

        // Cashier assigned ONLY to Outlet 1
        $cashier = $this->addMemberToTenant(
            $tenantData['tenant'],
            'cashier',
            'Kasir Outlet 1',
            [(string) $outlet1->id]
        );

        $response = $this->authenticateTenantUser($cashier['user'], $tenantData['tenant'])
            ->getJson("/api/v1/tenant/outlets/{$outlet2->id}/verify");

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'OUTLET_ACCESS_DENIED',
        ]);
    }

    public function test_owner_can_access_all_outlets(): void
    {
        $tenantData = $this->createTenant('Resto Padang');

        $outlet2 = app(TenantManager::class)->runForTenant($tenantData['tenant'], function () use ($tenantData) {
            return Outlet::create([
                'tenant_id' => $tenantData['tenant']->id,
                'name' => 'Resto Padang - Cabang 2',
                'code' => 'OUT-02',
                'is_active' => true,
            ]);
        });

        $response = $this->authenticateTenantUser($tenantData['owner'], $tenantData['tenant'])
            ->getJson("/api/v1/tenant/outlets/{$outlet2->id}/verify");

        $response->assertStatus(200);
        $response->assertJsonPath('data.authorized', true);
    }

    public function test_cannot_access_outlet_belonging_to_another_tenant(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $outletB = $tenantB['outlet'];

        // Tenant A owner tries to access Outlet B
        $response = $this->authenticateTenantUser($tenantA['owner'], $tenantA['tenant'])
            ->getJson("/api/v1/tenant/outlets/{$outletB->id}/verify");

        // Outlet does not belong to Tenant A -> 404
        $response->assertStatus(404);
        $response->assertJson([
            'code' => 'OUTLET_NOT_FOUND',
        ]);
    }
}
