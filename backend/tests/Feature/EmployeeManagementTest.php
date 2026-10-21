<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class EmployeeManagementTest extends TenantTestCase
{
    protected Tenant $tenant;

    protected Outlet $outlet;

    protected User $owner;

    protected Role $ownerRole;

    protected Role $cashierRole;

    protected Role $managerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerRole = Role::where('slug', 'owner')->firstOrFail();
        $this->managerRole = Role::where('slug', 'manager')->firstOrFail();
        $this->cashierRole = Role::where('slug', 'cashier')->firstOrFail();

        $tenantData = $this->createTenant('Waroeng Test');
        $this->tenant = $tenantData['tenant'];
        $this->owner = $tenantData['owner'];
        $this->outlet = $tenantData['outlet'];
    }

    public function test_owner_can_list_employees_in_tenant(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/v1/employees');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', $this->owner->name)
            ->assertJsonPath('data.0.role.slug', 'owner');
    }

    public function test_owner_can_add_new_cashier_employee(): void
    {
        $payload = [
            'name' => 'Siti Kasir',
            'email' => 'siti.kasir@example.com',
            'phone_number' => '081234567890',
            'password' => 'secret1234',
            'role_slug' => 'cashier',
            'outlet_access' => 'specific',
            'assigned_outlet_ids' => [$this->outlet->id],
        ];

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/employees', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Siti Kasir')
            ->assertJsonPath('data.role.slug', 'cashier')
            ->assertJsonPath('data.outlet_access', 'specific')
            ->assertJsonPath('data.permitted_outlets.0.id', $this->outlet->id);

        $this->assertDatabaseHas('users', ['email' => 'siti.kasir@example.com']);
        $this->assertDatabaseHas('user_tenant_memberships', [
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
            'status' => 'active',
        ]);
    }

    public function test_cannot_add_duplicate_employee_in_same_tenant(): void
    {
        $payload = [
            'name' => 'Owner Duplicate',
            'email' => $this->owner->email,
            'role_slug' => 'cashier',
            'outlet_access' => 'all',
        ];

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/employees', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'EMPLOYEE_ALREADY_EXISTS');
    }

    public function test_owner_can_update_employee_role_and_outlets(): void
    {
        $cashierUser = User::create([
            'name' => 'Agus Staf',
            'email' => 'agus@example.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $membership = UserTenantMembership::create([
            'user_id' => $cashierUser->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        $updatePayload = [
            'role_slug' => 'manager',
            'outlet_access' => 'specific',
            'assigned_outlet_ids' => [$this->outlet->id],
        ];

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/v1/employees/{$membership->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('data.role.slug', 'manager')
            ->assertJsonPath('data.outlet_access', 'specific');

        $this->assertDatabaseHas('user_tenant_memberships', [
            'id' => $membership->id,
            'role_id' => $this->managerRole->id,
            'outlet_access' => 'specific',
        ]);
    }

    public function test_cannot_demote_the_last_active_owner(): void
    {
        $ownerMembership = UserTenantMembership::where('user_id', $this->owner->id)
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/v1/employees/{$ownerMembership->id}", [
                'role_slug' => 'cashier',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_DEMOTE_LAST_OWNER');
    }

    public function test_cannot_delete_last_active_owner(): void
    {
        $ownerMembership = UserTenantMembership::where('user_id', $this->owner->id)
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->deleteJson("/api/v1/employees/{$ownerMembership->id}");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_REMOVE_LAST_OWNER');
    }

    public function test_owner_can_delete_staff_membership(): void
    {
        $staffUser = User::create([
            'name' => 'Doni Staf',
            'email' => 'doni@example.com',
            'password' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $membership = UserTenantMembership::create([
            'user_id' => $staffUser->id,
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cashierRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->deleteJson("/api/v1/employees/{$membership->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('user_tenant_memberships', [
            'id' => $membership->id,
        ]);
    }
}
