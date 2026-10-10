<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

class CustomerManagementTest extends TenantTestCase
{
    use RefreshDatabase;

    protected array $tenantData;

    protected User $cashier;

    protected User $manager;

    protected User $staff;

    protected Outlet $outlet;

    protected PosService $posService;

    protected PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantData = $this->createTenant('Warung Customer Test');
        $tenant = $this->tenantData['tenant'];
        $this->outlet = $this->tenantData['outlet'];

        $cashierData = $this->addMemberToTenant($tenant, 'cashier', 'Kasir Budi', [$this->outlet->id]);
        $this->cashier = $cashierData['user'];

        $managerData = $this->addMemberToTenant($tenant, 'manager', 'Manager Siti');
        $this->manager = $managerData['user'];

        $staffData = $this->addMemberToTenant($tenant, 'staff', 'Staff Joko');
        $this->staff = $staffData['user'];

        $this->posService = app(PosService::class);
        $this->paymentService = app(PaymentService::class);
    }

    protected function runForTenant(callable $callback): mixed
    {
        return app(TenantManager::class)->runForTenant($this->tenantData['tenant'], $callback);
    }

    public function test_merchant_can_create_customer_with_phone_and_email(): void
    {
        $tenant = $this->tenantData['tenant'];

        $response = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'John Doe',
                'phone' => '081234567890',
                'email' => 'John.Doe@Example.COM ',
                'notes' => 'Pelanggan VIP',
                'marketing_opt_in' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'John Doe')
            ->assertJsonPath('data.phone', '+6281234567890')
            ->assertJsonPath('data.email', 'john.doe@example.com')
            ->assertJsonPath('data.marketing_opt_in', true);

        $customerId = $response->json('data.id');

        $this->runForTenant(function () use ($customerId, $tenant) {
            $this->assertDatabaseHas('customers', [
                'id' => $customerId,
                'tenant_id' => $tenant->id,
                'name' => 'John Doe',
                'phone' => '+6281234567890',
                'email' => 'john.doe@example.com',
                'marketing_opt_in' => true,
            ]);
        });

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'customer_created',
            'entity_type' => Customer::class,
            'entity_id' => $customerId,
        ]);
    }

    public function test_create_customer_without_phone_and_email_succeeds_name_only(): void
    {
        $tenant = $this->tenantData['tenant'];

        $response = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'Pelanggan Walk-In Biasa',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Pelanggan Walk-In Biasa')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null);
    }

    public function test_duplicate_phone_in_same_tenant_fails_deterministically(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Create initial customer
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'Pelanggan Pertama',
                'phone' => '+6281234567890',
            ])->assertStatus(201);

        // Attempt duplicate with differently formatted but identical phone
        $response = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'Pelanggan Kedua',
                'phone' => '0812-3456-7890',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_duplicate_phone_in_different_tenant_succeeds_tenant_isolated(): void
    {
        $tenantA = $this->tenantData['tenant'];
        $tenantBData = $this->createTenant('Warung Sebelah B');
        $tenantB = $tenantBData['tenant'];
        $cashierB = $tenantBData['owner'];

        // Create customer in Tenant A
        $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson('/api/v1/customers', [
                'name' => 'Budi Warung A',
                'phone' => '+6281234567890',
            ])->assertStatus(201);

        // Same phone in Tenant B must succeed!
        $responseB = $this->authenticateTenantUser($cashierB, $tenantB)
            ->postJson('/api/v1/customers', [
                'name' => 'Budi Warung B',
                'phone' => '081234567890',
            ]);

        $responseB->assertStatus(201)
            ->assertJsonPath('data.phone', '+6281234567890');
    }

    public function test_duplicate_email_in_same_tenant_fails(): void
    {
        $tenant = $this->tenantData['tenant'];

        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'User One',
                'email' => 'client@sewaroeng.test',
            ])->assertStatus(201);

        $response = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'User Two',
                'email' => ' CLIENT@sewaroeng.test ',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_customer_list_supports_pagination_and_search(): void
    {
        $tenant = $this->tenantData['tenant'];

        $auth = $this->authenticateTenantUser($this->cashier, $tenant);
        $auth->postJson('/api/v1/customers', ['name' => 'Ahmad Dahlan', 'phone' => '081111111111']);
        $auth->postJson('/api/v1/customers', ['name' => 'Budi Santoso', 'phone' => '082222222222', 'email' => 'budi@test.com']);
        $auth->postJson('/api/v1/customers', ['name' => 'Citra Dewi', 'phone' => '083333333333']);

        // Search by name
        $resName = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/customers?search=Santoso');
        $resName->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Budi Santoso');

        // Search by phone
        $resPhone = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/customers?search=0811');
        $resPhone->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ahmad Dahlan');

        // Search by email
        $resEmail = $this->authenticateTenantUser($this->cashier, $tenant)
            ->getJson('/api/v1/customers?search=budi@test');
        $resEmail->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Budi Santoso');
    }

    public function test_customer_update_normalizes_and_audits_changes(): void
    {
        $tenant = $this->tenantData['tenant'];

        $createRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'Old Name',
                'phone' => '081200000000',
            ])->assertStatus(201);

        $customerId = $createRes->json('data.id');

        $updateRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->putJson("/api/v1/customers/{$customerId}", [
                'name' => 'Updated Name',
                'phone' => '081299999999',
                'notes' => 'Catatan baru',
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.phone', '+6281299999999')
            ->assertJsonPath('data.notes', 'Catatan baru');

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'customer_updated',
            'entity_type' => Customer::class,
            'entity_id' => $customerId,
        ]);
    }

    public function test_customer_deactivation_is_audited(): void
    {
        $tenant = $this->tenantData['tenant'];

        $createRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', [
                'name' => 'Customer To Deactivate',
            ])->assertStatus(201);

        $customerId = $createRes->json('data.id');

        $deactivateRes = $this->authenticateTenantUser($this->cashier, $tenant)
            ->patchJson("/api/v1/customers/{$customerId}", [
                'is_active' => false,
            ]);

        $deactivateRes->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'customer_deactivated',
            'entity_type' => Customer::class,
            'entity_id' => $customerId,
        ]);
    }

    public function test_tenant_a_cannot_access_tenant_b_customers(): void
    {
        $tenantA = $this->tenantData['tenant'];
        $tenantBData = $this->createTenant('Tenant Rival B');
        $tenantB = $tenantBData['tenant'];
        $cashierB = $tenantBData['owner'];

        $createRes = $this->authenticateTenantUser($this->cashier, $tenantA)
            ->postJson('/api/v1/customers', [
                'name' => 'Customer A Private',
                'phone' => '081211112222',
            ])->assertStatus(201);

        $customerAId = $createRes->json('data.id');

        // Tenant B tries to get customer A
        $this->authenticateTenantUser($cashierB, $tenantB)
            ->getJson("/api/v1/customers/{$customerAId}")
            ->assertStatus(404);

        // Tenant B tries to update customer A
        $this->authenticateTenantUser($cashierB, $tenantB)
            ->putJson("/api/v1/customers/{$customerAId}", [
                'name' => 'Hacked Name',
            ])->assertStatus(404);
    }

    public function test_rbac_permissions_for_customer_operations(): void
    {
        $tenant = $this->tenantData['tenant'];

        // Cashier has customer.create and customer.view permissions
        $this->authenticateTenantUser($this->cashier, $tenant)
            ->postJson('/api/v1/customers', ['name' => 'Cashier Customer'])
            ->assertStatus(201);

        // Staff does NOT have customer.create permission
        $this->authenticateTenantUser($this->staff, $tenant)
            ->postJson('/api/v1/customers', ['name' => 'Staff Illegal Customer'])
            ->assertStatus(403);
    }

    public function test_customer_segmentation_rules(): void
    {
        // 1. Prospect: 0 purchases
        $this->assertEquals(Customer::SEGMENT_PROSPECT, Customer::determineSegment(0, 0, null));

        // 2. New: 1 purchase within 30 days
        $this->assertEquals(Customer::SEGMENT_NEW, Customer::determineSegment(1, 5000000, now()));

        // 3. Returning: 2+ purchases within 30 days
        $this->assertEquals(Customer::SEGMENT_RETURNING, Customer::determineSegment(2, 5000000, now()));

        // 4. High Value: spend >= Rp 1.000.000 (100.000.000 minor)
        $this->assertEquals(Customer::SEGMENT_HIGH_VALUE, Customer::determineSegment(3, 150000000, now()));

        // 5. Inactive: last purchase older than 30 days
        $oldDate = now()->subDays(35);
        $this->assertEquals(Customer::SEGMENT_INACTIVE, Customer::determineSegment(2, 5000000, $oldDate));
    }
}
