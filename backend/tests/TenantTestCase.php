<?php

namespace Tests;

use App\Domain\Tenant\TenantManager;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Creates a test tenant with an owner user.
     */
    protected function createTenant(string $name = 'Test Warung', ?string $code = null): array
    {
        $code = $code ?? 'WARUNG-'.substr(md5(uniqid()), 0, 6);

        $owner = User::create([
            'name' => "Owner of {$name}",
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'name' => $name,
            'code' => $code,
            'business_type' => 'fnb',
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ]);

        $ownerRole = Role::where('slug', 'owner')->firstOrFail();

        $membership = UserTenantMembership::create([
            'user_id' => $owner->id,
            'tenant_id' => $tenant->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'outlet_access' => 'all',
        ]);

        $outlet = app(TenantManager::class)->runForTenant($tenant, function () use ($tenant, $name) {
            $createdOutlet = Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => "{$name} - Outlet 1",
                'code' => 'OUT-01',
                'is_active' => true,
            ]);

            app(SubscriptionService::class)->createTrialSubscription($tenant);

            return $createdOutlet;
        });

        return [
            'tenant' => $tenant,
            'owner' => $owner,
            'membership' => $membership,
            'outlet' => $outlet,
        ];
    }

    /**
     * Adds a user with a specific role and outlet assignment to a tenant.
     */
    protected function addMemberToTenant(Tenant $tenant, string $roleSlug, string $name, array $assignedOutletIds = []): array
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();

        $membership = UserTenantMembership::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'status' => 'active',
            'outlet_access' => empty($assignedOutletIds) ? 'all' : 'specific',
            'assigned_outlet_ids' => empty($assignedOutletIds) ? null : $assignedOutletIds,
        ]);

        return [
            'user' => $user,
            'membership' => $membership,
            'role' => $role,
        ];
    }

    /**
     * Simulates authenticating as a specific tenant user with Sanctum and tenant context header.
     */
    protected function authenticateTenantUser(User $user, Tenant $tenant): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this->withHeader('X-Tenant-ID', (string) $tenant->id);
    }
}
