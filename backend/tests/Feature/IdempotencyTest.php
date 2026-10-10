<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\TenantTestRecord;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class IdempotencyTest extends TenantTestCase
{
    public function test_duplicate_idempotency_key_does_not_duplicate_mutation(): void
    {
        $tenantData = $this->createTenant('Cafe Nusantara');
        $idempotencyKey = (string) Str::uuid7();
        $manager = app(TenantManager::class);

        $payload = [
            'name' => 'Single Execution Item',
            'secret_data' => 'Initial Data',
        ];

        // First Request
        $res1 = $this->authenticateTenantUser($tenantData['owner'], $tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/tenant/test-records', $payload);

        $res1->assertStatus(201);
        $recordId1 = $res1->json('data.id');

        // Verify exactly 1 record created in database under tenant context (RLS-safe)
        $count1 = $manager->runForTenant($tenantData['tenant'], function () {
            return TenantTestRecord::where('name', 'Single Execution Item')->count();
        });
        $this->assertEquals(1, $count1);

        // Repeated Request with exact same Idempotency-Key
        $res2 = $this->authenticateTenantUser($tenantData['owner'], $tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/tenant/test-records', $payload);

        $res2->assertStatus(201);
        $res2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertEquals($recordId1, $res2->json('data.id'));

        // Database still has exactly 1 record!
        $count2 = $manager->runForTenant($tenantData['tenant'], function () {
            return TenantTestRecord::where('name', 'Single Execution Item')->count();
        });
        $this->assertEquals(1, $count2);

        // Verify durable record exists in PostgreSQL idempotency_records table
        $this->assertDatabaseHas('idempotency_records', [
            'tenant_id' => $tenantData['tenant']->id,
            'idempotency_key' => $idempotencyKey,
            'status' => 'completed',
            'response_status' => 201,
        ]);
    }

    public function test_same_key_with_different_payload_is_rejected_as_conflict(): void
    {
        $tenantData = $this->createTenant('Cafe Nusantara');
        $idempotencyKey = (string) Str::uuid7();

        // First Request
        $res1 = $this->authenticateTenantUser($tenantData['owner'], $tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/tenant/test-records', [
                'name' => 'Original Payload',
            ]);
        $res1->assertStatus(201);

        // Second Request with SAME KEY but DIFFERENT PAYLOAD
        $res2 = $this->authenticateTenantUser($tenantData['owner'], $tenantData['tenant'])
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/tenant/test-records', [
                'name' => 'Tampered / Mismatched Payload',
            ]);

        $res2->assertStatus(409);
        $res2->assertJson([
            'code' => 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
        ]);
    }
}
