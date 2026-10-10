<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class BusinessProfileAndOutletManagementTest extends TenantTestCase
{
    use RefreshDatabase;

    public function test_authorized_member_can_view_business_profile_with_localization_and_address(): void
    {
        $owner = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Kopi SeWaroeng Megah',
            'slug' => 'kopi-sewaroeng-megah',
            'business_type' => 'fnb',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        // Update profile with full details
        $this->actingAs($owner)->patchJson("/api/v1/tenants/{$tenantId}", [
            'phone' => '081234567890',
            'email' => 'contact@kopi-megah.com',
            'description' => 'Specialty coffee roasting and cafe',
            'address_line' => 'Jl. Sudirman No. 45',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'postal_code' => '12190',
            'country_code' => 'ID',
            'latitude' => -6.21462,
            'longitude' => 106.84513,
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
        ])->assertStatus(200);

        // Fetch detail
        $res = $this->actingAs($owner)->getJson("/api/v1/tenants/{$tenantId}");
        $res->assertStatus(200)
            ->assertJsonPath('data.name', 'Kopi SeWaroeng Megah')
            ->assertJsonPath('data.phone', '+6281234567890')
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonPath('data.timezone', 'Asia/Jakarta')
            ->assertJsonPath('data.city', 'Jakarta Selatan')
            ->assertJsonPath('data.latitude', -6.21462)
            ->assertJsonPath('data.longitude', 106.84513);
    }

    public function test_unauthorized_user_cannot_view_or_update_business(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Waroeng Rahasia',
            'slug' => 'waroeng-rahasia-x',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        // Stranger cannot view
        $this->actingAs($stranger)->getJson("/api/v1/tenants/{$tenantId}")
            ->assertStatus(403);

        // Stranger cannot update
        $this->actingAs($stranger)->patchJson("/api/v1/tenants/{$tenantId}", [
            'name' => 'Hacked Waroeng',
        ])->assertStatus(403);
    }

    public function test_non_owner_without_permission_cannot_update_business(): void
    {
        $owner = User::factory()->create();
        $cashier = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Waroeng Bersama',
            'slug' => 'waroeng-bersama',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        $cashierRole = Role::where('slug', 'cashier')->first();
        UserTenantMembership::create([
            'user_id' => $cashier->id,
            'tenant_id' => $tenantId,
            'role_id' => $cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        $this->actingAs($cashier)->patchJson("/api/v1/tenants/{$tenantId}", [
            'name' => 'Cashier Updated Name',
        ])->assertStatus(403);
    }

    public function test_invalid_timezone_and_currency_are_rejected(): void
    {
        $owner = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Waroeng Validasi',
            'slug' => 'waroeng-validasi',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        // Invalid timezone
        $this->actingAs($owner)->patchJson("/api/v1/tenants/{$tenantId}", [
            'timezone' => 'Invalid/Fake_Zone',
        ])->assertStatus(422);

        // Invalid currency
        $this->actingAs($owner)->patchJson("/api/v1/tenants/{$tenantId}", [
            'currency' => 'FAKE',
        ])->assertStatus(422);
    }

    public function test_owner_can_create_outlet_with_operating_hours_and_coordinates(): void
    {
        $owner = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Resto Sedap',
            'slug' => 'resto-sedap',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        $operatingHours = [
            'monday' => ['closed' => false, 'open_time' => '08:00', 'close_time' => '22:00'],
            'tuesday' => ['closed' => false, 'open_time' => '08:00', 'close_time' => '22:00'],
            'wednesday' => ['closed' => false, 'open_time' => '08:00', 'close_time' => '22:00'],
            'thursday' => ['closed' => false, 'open_time' => '08:00', 'close_time' => '22:00'],
            'friday' => ['closed' => false, 'open_time' => '08:00', 'close_time' => '23:00'],
            'saturday' => ['closed' => false, 'open_time' => '08:00', 'close_time' => '23:00'],
            'sunday' => ['closed' => true],
        ];

        $res = $this->actingAs($owner)->postJson('/api/v1/outlets', [
            'name' => 'Resto Sedap - Cabang Senopati',
            'code' => 'SNPT',
            'address_line' => 'Jl. Senopati No. 88',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'postal_code' => '12190',
            'phone_number' => '081299887766',
            'latitude' => -6.23012,
            'longitude' => 106.81234,
            'timezone' => 'Asia/Jakarta',
            'operating_hours' => $operatingHours,
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.name', 'Resto Sedap - Cabang Senopati')
            ->assertJsonPath('data.code', 'SNPT')
            ->assertJsonPath('data.phone_number', '+6281299887766')
            ->assertJsonPath('data.operating_hours.sunday.closed', true)
            ->assertJsonPath('data.operating_hours.monday.open_time', '08:00');

        $outletId = $res->json('data.id');

        // Verify show outlet detail
        $showRes = $this->actingAs($owner)->getJson("/api/v1/outlets/{$outletId}", [
            'X-Tenant-ID' => $tenantId,
        ]);

        $showRes->assertStatus(200)
            ->assertJsonPath('data.latitude', -6.23012)
            ->assertJsonPath('data.effective_timezone', 'Asia/Jakarta');
    }

    public function test_invalid_operating_hours_rejected(): void
    {
        $owner = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Resto Jam Aneh',
            'slug' => 'resto-jam-aneh',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        // Invalid time format (e.g. 25:99)
        $this->actingAs($owner)->postJson('/api/v1/outlets', [
            'name' => 'Cabang Rusak',
            'code' => 'RUSAK',
            'operating_hours' => [
                'monday' => ['closed' => false, 'open_time' => '25:99', 'close_time' => '22:00'],
            ],
        ], [
            'X-Tenant-ID' => $tenantId,
        ])->assertStatus(422);
    }

    public function test_outlet_status_can_be_activated_and_deactivated_with_audit_trail(): void
    {
        $owner = User::factory()->create();

        $createRes = $this->actingAs($owner)->postJson('/api/v1/tenants', [
            'name' => 'Waroeng Status',
            'slug' => 'waroeng-status',
        ]);
        $tenantId = $createRes->json('data.tenant.id');

        $outletRes = $this->actingAs($owner)->postJson('/api/v1/outlets', [
            'name' => 'Cabang Utama',
            'code' => 'UTAMA',
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);
        $outletId = $outletRes->json('data.id');

        // Deactivate outlet
        $updateRes = $this->actingAs($owner)->patchJson("/api/v1/outlets/{$outletId}", [
            'status' => 'inactive',
        ], [
            'X-Tenant-ID' => $tenantId,
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');

        app(TenantManager::class)->runForTenant($tenantId, function () use ($outletId) {
            $this->assertDatabaseHas('outlets', [
                'id' => $outletId,
                'status' => 'inactive',
                'is_active' => false,
            ]);
        });

        // Audit log verified
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenantId,
            'entity_id' => $outletId,
            'action' => 'outlet_status_changed',
        ]);

        // Default outlet listing excludes inactive outlet
        $listRes = $this->actingAs($owner)->getJson('/api/v1/outlets', [
            'X-Tenant-ID' => $tenantId,
        ]);
        $listRes->assertStatus(200);
        $this->assertEmpty(collect($listRes->json('data'))->where('id', $outletId));

        // Listing with include_inactive=1 includes it
        $listAllRes = $this->actingAs($owner)->getJson('/api/v1/outlets?include_inactive=1', [
            'X-Tenant-ID' => $tenantId,
        ]);
        $listAllRes->assertStatus(200);
        $this->assertNotEmpty(collect($listAllRes->json('data'))->where('id', $outletId));
    }

    public function test_cross_tenant_outlet_update_is_forbidden(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $resA = $this->actingAs($ownerA)->postJson('/api/v1/tenants', [
            'name' => 'Bisnis A',
            'slug' => 'bisnis-a',
        ]);
        $tenantIdA = $resA->json('data.tenant.id');

        $resB = $this->actingAs($ownerB)->postJson('/api/v1/tenants', [
            'name' => 'Bisnis B',
            'slug' => 'bisnis-b',
        ]);
        $tenantIdB = $resB->json('data.tenant.id');

        // Create outlet in Tenant A
        $outletA = $this->actingAs($ownerA)->postJson('/api/v1/outlets', [
            'name' => 'Outlet A',
            'code' => 'OUT-A',
        ], [
            'X-Tenant-ID' => $tenantIdA,
        ])->json('data.id');

        // Owner B attempts to update Outlet A
        $attempt = $this->actingAs($ownerB)->patchJson("/api/v1/outlets/{$outletA}", [
            'name' => 'Hacked Outlet A',
        ], [
            'X-Tenant-ID' => $tenantIdB,
        ]);

        $attempt->assertStatus(404);
    }
}
