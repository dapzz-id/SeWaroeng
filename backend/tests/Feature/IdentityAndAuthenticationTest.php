<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class IdentityAndAuthenticationTest extends TenantTestCase
{
    public function test_user_can_register_with_valid_details(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi.santoso@example.com',
            'phone_number' => '081234567899',
            'password' => 'SeWaroeng2026!',
            'device_name' => 'Cashier POS 01',
            'device_id' => (string) Str::uuid7(),
            'platform' => 'android',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'user' => ['id', 'name', 'email', 'phone_number', 'is_active'],
                'session' => ['id', 'device_id', 'device_name', 'platform'],
                'token',
            ],
            'message',
        ]);

        // Verify normalized phone stored in database
        $this->assertDatabaseHas('users', [
            'email' => 'budi.santoso@example.com',
            'phone_number' => '+6281234567899',
            'is_active' => true,
        ]);

        // Verify password is not plaintext
        $user = User::where('email', 'budi.santoso@example.com')->first();
        $this->assertNotEquals('SeWaroeng2026!', $user->password);
        $this->assertTrue(Hash::check('SeWaroeng2026!', $user->password));

        // Verify audit log
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'auth.registered',
        ]);
    }

    public function test_registration_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'weak.password@example.com',
            'password' => 'simple', // < 8 chars, no mixed case or number
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'existing@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_with_duplicate_phone_even_differently_formatted(): void
    {
        User::create([
            'name' => 'Phone User',
            'email' => 'phone1@example.com',
            'phone_number' => '+6281234567890',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        // Attempt registering with 0812 format matching existing +62812
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Phone User 2',
            'email' => 'phone2@example.com',
            'phone_number' => '0812-3456-7890',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone_number']);
    }

    public function test_user_can_login_with_phone_number(): void
    {
        $user = User::create([
            'name' => 'Mobile Merchant',
            'email' => 'merchant@example.com',
            'phone_number' => '+6281987654321',
            'password' => Hash::make('MerchantPass123'),
            'is_active' => true,
        ]);

        // Login using Indonesian local 08 prefix
        $response = $this->postJson('/api/v1/auth/login', [
            'identity' => '081987654321',
            'password' => 'MerchantPass123',
            'device_name' => 'Samsung Tab A',
            'device_id' => '0192a543-0000-7000-8000-000000000001',
            'platform' => 'android',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.session.device_name', 'Samsung Tab A');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_invalid_credentials_returns_generic_error(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'identity' => 'nonexistent@example.com',
            'password' => 'SomePassword123',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'Invalid credentials.',
        ]);
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $user = User::create([
            'name' => 'Deactivated User',
            'email' => 'deactivated@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'ACCOUNT_DEACTIVATED',
        ]);
    }

    public function test_authenticated_user_can_access_me_and_sessions(): void
    {
        $user = User::create([
            'name' => 'Active Member',
            'email' => 'active.member@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        // Login first device
        $res1 = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => 'Password123!',
            'device_name' => 'Tablet POS',
            'device_id' => 'device-1',
        ]);
        $token1 = $res1->json('data.token');
        $sessionId1 = $res1->json('data.session.id');

        // Login second device
        $res2 = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => 'Password123!',
            'device_name' => 'Phone Manager',
            'device_id' => 'device-2',
        ]);
        $token2 = $res2->json('data.token');
        $sessionId2 = $res2->json('data.session.id');

        // Access /api/v1/auth/me from device 2
        $meRes = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/v1/auth/me');

        $meRes->assertStatus(200);
        $meRes->assertJsonPath('data.user.id', $user->id);
        $meRes->assertJsonPath('data.session.id', $sessionId2);

        // List sessions from device 2
        $sessionsRes = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/v1/auth/sessions');

        $sessionsRes->assertStatus(200);
        $sessions = $sessionsRes->json('data');
        $this->assertCount(2, $sessions);

        // Revoke device 1 from device 2
        $revokeRes = $this->withHeader('Authorization', "Bearer {$token2}")
            ->deleteJson("/api/v1/auth/sessions/{$sessionId1}");
        $revokeRes->assertStatus(200);

        // Verify device 1 token is now invalid
        $this->app['auth']->forgetGuards();
        $resDevice1 = $this->withHeader('Authorization', "Bearer {$token1}")
            ->getJson('/api/v1/auth/me');
        $resDevice1->assertStatus(401);

        // Device 2 remains authenticated!
        $this->app['auth']->forgetGuards();
        $resDevice2 = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/v1/auth/me');
        $resDevice2->assertStatus(200);
    }

    public function test_logout_revokes_current_session(): void
    {
        $user = User::create([
            'name' => 'Logout User',
            'email' => 'logout@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'identity' => $user->email,
            'password' => 'Password123!',
        ]);
        $token = $loginRes->json('data.token');

        $logoutRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');
        $logoutRes->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $subsequent = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');
        $subsequent->assertStatus(401);
    }
}
