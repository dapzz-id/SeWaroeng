<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class BusinessOnboardingTest extends TenantTestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_onboard_business_and_becomes_owner(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Waroeng Kopi Sedap',
            'slug' => 'waroeng-kopi-sedap',
            'business_type' => 'fnb',
            'outlet' => [
                'name' => 'Waroeng Kopi Sedap - Sudirman',
                'code' => 'SDRMN',
            ],
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.tenant.name', 'Waroeng Kopi Sedap')
            ->assertJsonPath('data.tenant.slug', 'waroeng-kopi-sedap')
            ->assertJsonPath('data.tenant.status', 'active')
            ->assertJsonPath('data.outlet.name', 'Waroeng Kopi Sedap - Sudirman')
            ->assertJsonPath('data.outlet.code', 'SDRMN')
            ->assertJsonPath('data.membership.role', 'owner');

        $tenantId = $response->json('data.tenant.id');
        $outletId = $response->json('data.outlet.id');

        // Verify Database Persistence
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'name' => 'Waroeng Kopi Sedap',
            'slug' => 'waroeng-kopi-sedap',
            'owner_user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('outlets', [
            'id' => $outletId,
            'tenant_id' => $tenantId,
            'name' => 'Waroeng Kopi Sedap - Sudirman',
            'code' => 'SDRMN',
            'status' => 'active',
        ]);

        $ownerRole = Role::where('slug', 'owner')->first();
        $this->assertDatabaseHas('user_tenant_memberships', [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $user->id,
            'action' => 'business_created',
        ]);
    }

    public function test_unauthenticated_user_cannot_create_business(): void
    {
        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'Hacker Waroeng',
            'slug' => 'hacker-waroeng',
        ]);

        $response->assertStatus(401);
    }

    public function test_server_authoritative_ignores_client_supplied_security_fields(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $cashierRole = Role::where('slug', 'cashier')->first();

        $response = $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Warung Jujur',
            'slug' => 'warung-jujur',
            // Attacker attempts to forge owner and role
            'owner_user_id' => $otherUser->id,
            'role_id' => $cashierRole->id,
            'role' => 'cashier',
            'permissions' => ['all'],
        ]);

        $response->assertStatus(201);
        $tenantId = $response->json('data.tenant.id');

        // Verified: The owner MUST be the authenticated user, and role MUST be owner
        $tenant = Tenant::findOrFail($tenantId);
        $this->assertEquals($user->id, $tenant->owner_user_id);

        $membership = UserTenantMembership::where('tenant_id', $tenantId)->first();
        $this->assertEquals($user->id, $membership->user_id);
        $this->assertEquals('owner', $membership->role->slug);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // First creation succeeds
        $this->actingAs($user1)->postJson('/api/v1/tenants', [
            'name' => 'Toko Barokah',
            'slug' => 'toko-barokah',
        ])->assertStatus(201);

        // Second creation with exact same slug fails
        $response = $this->actingAs($user2)->postJson('/api/v1/tenants', [
            'name' => 'Toko Barokah Lain',
            'slug' => 'toko-barokah',
        ]);

        $response->assertStatus(422);
    }

    public function test_reserved_slug_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'System Waroeng',
            'slug' => 'admin',
        ]);

        $response->assertStatus(422);
    }

    public function test_onboarding_idempotency_prevents_duplicate_business_creation(): void
    {
        $user = User::factory()->create();
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'name' => 'Kopi Mantap',
            'slug' => 'kopi-mantap',
        ];

        // Request 1
        $res1 = $this->actingAs($user)->postJson('/api/v1/tenants', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $res1->assertStatus(201);
        $tenantId = $res1->json('data.tenant.id');

        // Request 2 with same idempotency key (simulated network retry)
        $res2 = $this->actingAs($user)->postJson('/api/v1/tenants', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $res2->assertStatus(201);
        $this->assertEquals($tenantId, $res2->json('data.tenant.id'));

        // Assert exactly one tenant was created
        $this->assertEquals(1, Tenant::where('slug', 'kopi-mantap')->count());
    }

    public function test_idempotency_key_with_different_payload_is_rejected_as_conflict(): void
    {
        $user = User::factory()->create();
        $idempotencyKey = (string) Str::uuid();

        // Request 1
        $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Kopi A',
            'slug' => 'kopi-a',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ])->assertStatus(201);

        // Request 2 with same key but different payload
        $res2 = $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Kopi B Different',
            'slug' => 'kopi-b',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $res2->assertStatus(409);
    }

    public function test_user_can_list_their_active_businesses(): void
    {
        $user = User::factory()->create();

        // Onboard business 1
        $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Bisnis Satu',
            'slug' => 'bisnis-satu',
        ])->assertStatus(201);

        // Onboard business 2
        $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Bisnis Dua',
            'slug' => 'bisnis-dua',
        ])->assertStatus(201);

        $res = $this->actingAs($user)->getJson('/api/v1/tenants');
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals('bisnis-satu', $data[0]['slug']);
        $this->assertEquals('bisnis-dua', $data[1]['slug']);
    }

    public function test_user_cannot_access_unauthorized_business_detail(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $res = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Waroeng Rahasia',
            'slug' => 'waroeng-rahasia',
        ]);
        $tenantId = $res->json('data.tenant.id');

        // Stranger attempts to view business detail
        $strangerRes = $this->actingAs($stranger)->getJson("/api/v1/tenants/{$tenantId}");
        $strangerRes->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_ACCESS_DENIED');
    }

    public function test_owner_can_create_and_manage_additional_outlets(): void
    {
        $user = User::factory()->create();

        // 1. Onboard business
        $res = $this->actingAs($user)->postJson('/api/v1/tenants', [
            'name' => 'Resto Nusantara',
            'slug' => 'resto-nusantara',
        ]);
        $tenantId = $res->json('data.tenant.id');

        // 2. Create second outlet
        $outletRes = $this->actingAs($user)->postJson('/api/v1/outlets', [
            'name' => 'Resto Nusantara - Cabang Barat',
            'code' => 'BARAT',
            'address' => 'Jl. Kebon Jeruk No. 10',
            'phone_number' => '0215551234',
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);

        $outletRes->assertStatus(201)
            ->assertJsonPath('data.name', 'Resto Nusantara - Cabang Barat')
            ->assertJsonPath('data.code', 'BARAT');
        $outletId = $outletRes->json('data.id');

        // 3. Duplicate code in same tenant is rejected
        $dupRes = $this->actingAs($user)->postJson('/api/v1/outlets', [
            'name' => 'Resto Nusantara - Cabang Barat 2',
            'code' => 'BARAT',
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);
        $dupRes->assertStatus(422)
            ->assertJsonPath('code', 'OUTLET_CODE_TAKEN');

        // 4. Update outlet
        $updateRes = $this->actingAs($user)->patchJson("/api/v1/outlets/{$outletId}", [
            'name' => 'Resto Nusantara - Cabang Barat Baru',
            'status' => 'active',
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Resto Nusantara - Cabang Barat Baru');
    }

    public function test_non_owner_cannot_create_outlets(): void
    {
        $owner = User::factory()->create();
        $cashier = User::factory()->create();

        $res = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Kafe Santai',
            'slug' => 'kafe-santai',
        ]);
        $tenantId = $res->json('data.tenant.id');

        // Add cashier to tenant
        $cashierRole = Role::where('slug', 'cashier')->first();
        UserTenantMembership::create([
            'user_id' => $cashier->id,
            'tenant_id' => $tenantId,
            'role_id' => $cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        // Cashier attempts to create outlet
        $attempt = $this->actingAs($cashier)->postJson('/api/v1/outlets', [
            'name' => 'Ilegal Outlet',
            'code' => 'ILEGAL',
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);

        $attempt->assertStatus(403);
    }
}
