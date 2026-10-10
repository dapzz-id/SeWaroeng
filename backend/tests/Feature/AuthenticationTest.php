<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

class AuthenticationTest extends TenantTestCase
{
    public function test_user_can_login_with_valid_credentials(): void
    {
        $tenantData = $this->createTenant('Cafe Sudut');
        $user = $tenantData['owner'];

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_name' => 'Cashier POS Tablet',
            'device_id' => '01924b12-9c3a-7f28-8d32-28e75a9b1c04',
            'platform' => 'android',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'token',
                'user' => ['id', 'name', 'email'],
                'tenants' => [
                    '*' => ['id', 'name', 'role', 'status'],
                ],
            ],
            'message',
        ]);

        $this->assertNotEmpty($response->json('data.token'));

        // Verify device metadata in database
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'Cashier POS Tablet',
            'device_id' => '01924b12-9c3a-7f28-8d32-28e75a9b1c04',
            'platform' => 'android',
        ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $tenantData = $this->createTenant('Cafe Sudut');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $tenantData['owner']->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'INVALID_CREDENTIALS',
        ]);
    }

    public function test_revoked_token_fails_authentication(): void
    {
        $tenantData = $this->createTenant('Cafe Sudut');
        $user = $tenantData['owner'];

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Request with valid token succeeds
        $res1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');
        $res1->assertStatus(200);

        // Revoke token via logout endpoint
        $logoutRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');
        $logoutRes->assertStatus(200);

        // Clear in-memory guard state so Sanctum re-verifies token against the database
        $this->app['auth']->forgetGuards();

        // Subsequent request with revoked token fails with 401
        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');
        $res2->assertStatus(401);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $tenantData = $this->createTenant('Cafe Inactive');
        $user = $tenantData['owner'];
        $user->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'ACCOUNT_DEACTIVATED',
        ]);
    }

    public function test_inactive_user_token_is_rejected_on_subsequent_requests(): void
    {
        $tenantData = $this->createTenant('Cafe Inactive 2');
        $user = $tenantData['owner'];

        // Login while active
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);
        $token = $loginRes->json('data.token');

        // Deactivate user afterwards
        $user->update(['is_active' => false]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', (string) $tenantData['tenant']->id)
            ->getJson('/api/v1/tenant/context');

        $res->assertStatus(403);
        $res->assertJson([
            'code' => 'ACCOUNT_DEACTIVATED',
        ]);
    }

    public function test_soft_revoked_token_fails_authentication(): void
    {
        $tenantData = $this->createTenant('Cafe Soft Revoke');
        $user = $tenantData['owner'];

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);
        $token = $loginRes->json('data.token');

        // Soft-revoke by setting revoked_at timestamp in database
        $tokenModel = $user->tokens()->first();
        $tokenModel->update(['revoked_at' => now()]);

        $this->app['auth']->forgetGuards();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');

        $res->assertStatus(401);
    }
}
