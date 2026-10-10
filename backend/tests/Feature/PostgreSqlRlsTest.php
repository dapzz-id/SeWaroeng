<?php

namespace Tests\Feature;

use App\Domain\Tenant\TenantManager;
use App\Models\TenantTestRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class PostgreSqlRlsTest extends TenantTestCase
{
    /**
     * Test A: Tenant A cannot read Tenant B data even using raw SQL.
     */
    public function test_rls_prevents_raw_sql_cross_tenant_reads(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $tenantB = $this->createTenant('Tenant Beta');

        $idA = (string) Str::uuid7();
        $idB = (string) Str::uuid7();

        DB::transaction(function () use ($tenantA, $idA) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA['tenant']->id]);
            DB::table('tenant_test_records')->insert([
                'id' => $idA,
                'tenant_id' => $tenantA['tenant']->id,
                'name' => 'Alpha Secret Note',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::transaction(function () use ($tenantB, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantB['tenant']->id]);
            DB::table('tenant_test_records')->insert([
                'id' => $idB,
                'tenant_id' => $tenantB['tenant']->id,
                'name' => 'Beta Secret Note',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Query raw SQL under Tenant A context
        $results = DB::transaction(function () use ($tenantA) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA['tenant']->id]);

            return DB::select('SELECT * FROM tenant_test_records');
        });

        $this->assertCount(1, $results);
        $this->assertEquals('Alpha Secret Note', $results[0]->name);
        $this->assertEquals((string) $tenantA['tenant']->id, $results[0]->tenant_id);
    }

    /**
     * Test B: Tenant A cannot update Tenant B data even if raw ID is targeted.
     */
    public function test_rls_prevents_cross_tenant_mutation(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $tenantB = $this->createTenant('Tenant Beta');

        $idB = (string) Str::uuid7();

        DB::transaction(function () use ($tenantB, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantB['tenant']->id]);
            DB::table('tenant_test_records')->insert([
                'id' => $idB,
                'tenant_id' => $tenantB['tenant']->id,
                'name' => 'Beta Original',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Tenant A tries to execute raw SQL UPDATE on Tenant B's record
        $affected = DB::transaction(function () use ($tenantA, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA['tenant']->id]);

            return DB::update("UPDATE tenant_test_records SET name = 'Tampered by Alpha' WHERE id = ?", [$idB]);
        });

        $this->assertEquals(0, $affected, 'RLS must reject mutation of Tenant B row under Tenant A context');

        // Verify row in DB remained unchanged
        $check = DB::transaction(function () use ($tenantB, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantB['tenant']->id]);

            return DB::selectOne('SELECT name FROM tenant_test_records WHERE id = ?', [$idB]);
        });

        $this->assertEquals('Beta Original', $check->name);
    }

    /**
     * Test C: Missing tenant context fails closed (exposes 0 rows).
     */
    public function test_rls_fails_closed_when_tenant_setting_is_missing_or_empty(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $idA = (string) Str::uuid7();

        DB::transaction(function () use ($tenantA, $idA) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA['tenant']->id]);
            DB::table('tenant_test_records')->insert([
                'id' => $idA,
                'tenant_id' => $tenantA['tenant']->id,
                'name' => 'Alpha Secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Query WITHOUT setting app.current_tenant_id
        $unscopedResults = DB::transaction(function () {
            DB::statement("SELECT set_config('app.current_tenant_id', '', true)");

            return DB::select('SELECT * FROM tenant_test_records');
        });

        $this->assertEmpty($unscopedResults, 'RLS must fail closed when tenant setting is empty');
    }

    /**
     * Test D: Tenant context from one transaction does not leak into the next.
     */
    public function test_transaction_scoped_tenant_context_does_not_leak(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $manager = app(TenantManager::class);

        // Run Tenant A via TenantManager
        $manager->runForTenant($tenantA['tenant'], function () use ($tenantA) {
            $val = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS val")->val;
            $this->assertEquals((string) $tenantA['tenant']->id, $val);
        });

        // After runForTenant completes, verify context has not leaked
        $valAfter = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS val")->val;
        $this->assertEmpty($valAfter, 'Tenant context from runForTenant leaked after execution!');
    }

    /**
     * Test E: Queue job helper runForTenant enforces strict boundary.
     */
    public function test_tenant_manager_run_for_tenant_enforces_rls_boundary(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $tenantB = $this->createTenant('Tenant Beta');

        $manager = app(TenantManager::class);

        $recordA = $manager->runForTenant($tenantA['tenant'], function () use ($tenantA) {
            return TenantTestRecord::withoutGlobalScopes()->create([
                'name' => 'Queue Processed Item Alpha',
                'tenant_id' => $tenantA['tenant']->id,
            ]);
        });

        $this->assertNotNull($recordA->id);

        // Attempting to query under Tenant B using runForTenant
        $found = $manager->runForTenant($tenantB['tenant'], function () use ($recordA) {
            return TenantTestRecord::withoutGlobalScopes()->where('id', $recordA->id)->first();
        });

        $this->assertNull($found, 'Tenant B job should not see Tenant A record under RLS');
    }

    /**
     * Test F: Tenant A cannot DELETE Tenant B record.
     */
    public function test_rls_prevents_cross_tenant_deletion(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $tenantB = $this->createTenant('Tenant Beta');

        $idB = (string) Str::uuid7();

        DB::transaction(function () use ($tenantB, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantB['tenant']->id]);
            DB::table('tenant_test_records')->insert([
                'id' => $idB,
                'tenant_id' => $tenantB['tenant']->id,
                'name' => 'Beta Record to Delete',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Tenant A tries to execute raw SQL DELETE on Tenant B's record
        $affected = DB::transaction(function () use ($tenantA, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA['tenant']->id]);

            return DB::delete('DELETE FROM tenant_test_records WHERE id = ?', [$idB]);
        });

        $this->assertEquals(0, $affected, 'RLS must reject deletion of Tenant B row under Tenant A context');

        // Verify row still exists in database
        $stillExists = DB::transaction(function () use ($tenantB, $idB) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantB['tenant']->id]);

            return DB::table('tenant_test_records')->where('id', $idB)->exists();
        });

        $this->assertTrue($stillExists, 'Record of Tenant B must still exist');
    }

    /**
     * Test G: Invalid or nonexistent UUID in app.current_tenant_id returns no rows.
     */
    public function test_rls_fails_closed_when_tenant_setting_is_invalid_or_nonexistent(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $idA = (string) Str::uuid7();

        DB::transaction(function () use ($tenantA, $idA) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [(string) $tenantA['tenant']->id]);
            DB::table('tenant_test_records')->insert([
                'id' => $idA,
                'tenant_id' => $tenantA['tenant']->id,
                'name' => 'Alpha Secret Note',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $randomUuid = (string) Str::uuid7();

        // Query with a random nonexistent tenant UUID
        $results = DB::transaction(function () use ($randomUuid) {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$randomUuid]);

            return DB::select('SELECT * FROM tenant_test_records');
        });

        $this->assertEmpty($results, 'Nonexistent tenant UUID must return 0 rows under RLS');
    }

    /**
     * Test H: Application database user cannot bypass RLS (not superuser, no bypassrls privilege).
     */
    public function test_application_database_user_cannot_bypass_rls(): void
    {
        $roleInfo = DB::selectOne('
            SELECT r.rolname, r.rolsuper, r.rolbypassrls 
            FROM pg_roles r 
            WHERE r.rolname = current_user
        ');

        $this->assertNotNull($roleInfo);
        $this->assertEquals('sewaroeng_app', $roleInfo->rolname);
        $this->assertFalse((bool) $roleInfo->rolsuper, 'Application database user MUST NOT be a PostgreSQL superuser');
        $this->assertFalse((bool) $roleInfo->rolbypassrls, 'Application database user MUST NOT possess BYPASSRLS privilege');
    }

    /**
     * Test I: Queue job throwing exception cleans up tenant context completely.
     */
    public function test_queue_worker_exception_cleans_up_context(): void
    {
        $tenantA = $this->createTenant('Tenant Alpha');
        $manager = app(TenantManager::class);

        try {
            $manager->runForTenant($tenantA['tenant'], function () {
                throw new \RuntimeException('Simulated worker crash');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Verify context is clean
        $valAfter = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS val")->val;
        $this->assertEmpty($valAfter, 'Tenant context leaked after exception in worker execution!');
        $this->assertNull($manager->currentTenantId());
    }
}
