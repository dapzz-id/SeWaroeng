<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

class AuthorizationTest extends TenantTestCase
{
    public function test_owner_can_execute_owner_action(): void
    {
        $tenantData = $this->createTenant('Cafe Aroma');

        $response = $this->authenticateTenantUser($tenantData['owner'], $tenantData['tenant'])
            ->postJson('/api/v1/tenant/owner-only-action');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Owner action executed.']);
    }

    public function test_cashier_cannot_execute_owner_action(): void
    {
        $tenantData = $this->createTenant('Cafe Aroma');
        $cashier = $this->addMemberToTenant($tenantData['tenant'], 'cashier', 'Kasir Budi');

        $response = $this->authenticateTenantUser($cashier['user'], $tenantData['tenant'])
            ->postJson('/api/v1/tenant/owner-only-action');

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'OWNER_ONLY_ACTION',
        ]);
    }

    public function test_cashier_can_execute_pos_sell_permission(): void
    {
        $tenantData = $this->createTenant('Cafe Aroma');
        $cashier = $this->addMemberToTenant($tenantData['tenant'], 'cashier', 'Kasir Siti');

        $response = $this->authenticateTenantUser($cashier['user'], $tenantData['tenant'])
            ->postJson('/api/v1/tenant/pos-sell-action');

        $response->assertStatus(200);
    }

    public function test_cashier_cannot_execute_finance_permission(): void
    {
        $tenantData = $this->createTenant('Cafe Aroma');
        $cashier = $this->addMemberToTenant($tenantData['tenant'], 'cashier', 'Kasir Siti');

        $response = $this->authenticateTenantUser($cashier['user'], $tenantData['tenant'])
            ->postJson('/api/v1/tenant/finance-action');

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'FORBIDDEN_PERMISSION',
        ]);
    }

    public function test_accountant_can_execute_finance_but_not_pos_sell(): void
    {
        $tenantData = $this->createTenant('Cafe Aroma');
        $accountant = $this->addMemberToTenant($tenantData['tenant'], 'accountant', 'Akuntan Anton');

        // Accountant can execute finance
        $financeRes = $this->authenticateTenantUser($accountant['user'], $tenantData['tenant'])
            ->postJson('/api/v1/tenant/finance-action');
        $financeRes->assertStatus(200);

        // Accountant cannot execute pos.sell
        $posRes = $this->authenticateTenantUser($accountant['user'], $tenantData['tenant'])
            ->postJson('/api/v1/tenant/pos-sell-action');
        $posRes->assertStatus(403);
    }
}
