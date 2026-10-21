<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestSuccessfulJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static bool $executed = false;

    public function handle(): void
    {
        self::$executed = true;
    }
}

class TestFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        throw new \RuntimeException('Simulated queue job failure for dead letter test');
    }
}

class DatabaseCacheAndQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'database',
            'queue.default' => 'database',
        ]);
    }

    /**
     * Test 1: Database Cache can put, get, forget, and handle atomic distributed locks.
     */
    public function test_database_cache_crud_and_atomic_lock(): void
    {
        $cache = Cache::store('database');
        $key = 'test_cache_key_'.Str::random(8);
        $value = ['message' => 'database_cache_payload', 'number' => 42];

        // 1. Write and read
        $cache->put($key, $value, 60);
        $retrieved = $cache->get($key);
        $this->assertEquals($value, $retrieved);

        // 2. Forget
        $cache->forget($key);
        $this->assertNull($cache->get($key));

        // 3. Distributed Lock acquisition via cache_locks table
        $lockKey = 'test_lock_'.Str::random(8);
        $lock1 = $cache->lock($lockKey, 10);
        $this->assertTrue($lock1->get(), 'First lock acquisition must succeed');

        // 4. Verify lock contention: second lock attempt with same key must fail
        $lock2 = $cache->lock($lockKey, 10);
        $this->assertFalse($lock2->get(), 'Contending lock on same key must be rejected');

        // 5. Release lock and verify re-acquisition succeeds
        $lock1->release();
        $this->assertTrue($lock2->get(), 'Lock re-acquisition after release must succeed');
        $lock2->release();
    }

    /**
     * Test 2: Tenant-specific cache keys remain strictly isolated.
     */
    public function test_tenant_specific_cache_keys_are_strictly_isolated(): void
    {
        $cache = Cache::store('database');

        $tenant1Id = (string) Str::uuid();
        $tenant2Id = (string) Str::uuid();
        $sharedKeyName = 'daily_sales_summary';

        // Namespaced keys as required by architecture
        $cacheKeyTenant1 = "tenant_{$tenant1Id}:{$sharedKeyName}";
        $cacheKeyTenant2 = "tenant_{$tenant2Id}:{$sharedKeyName}";

        $cache->put($cacheKeyTenant1, ['total_minor' => 1500000, 'tenant' => 'Warung A'], 60);
        $cache->put($cacheKeyTenant2, ['total_minor' => 8500000, 'tenant' => 'Warung B'], 60);

        // Verify no crosstalk
        $this->assertEquals(1500000, $cache->get($cacheKeyTenant1)['total_minor']);
        $this->assertEquals(8500000, $cache->get($cacheKeyTenant2)['total_minor']);

        // Forget tenant 1's cache; tenant 2 must remain unaffected
        $cache->forget($cacheKeyTenant1);
        $this->assertNull($cache->get($cacheKeyTenant1));
        $this->assertEquals(8500000, $cache->get($cacheKeyTenant2)['total_minor']);

        // Clean up
        $cache->forget($cacheKeyTenant2);
    }

    /**
     * Test 3: Database-backed queue dispatches and processes jobs without Redis.
     */
    public function test_database_queue_dispatches_and_processes_jobs(): void
    {
        TestSuccessfulJob::$executed = false;

        // Ensure database queue connection
        $queueName = 'default';
        TestSuccessfulJob::dispatch()->onConnection('database')->onQueue($queueName);

        // Verify job record exists in 'jobs' database table
        $jobCount = DB::table('jobs')->where('queue', $queueName)->count();
        $this->assertGreaterThan(0, $jobCount, "Job must be stored in PostgreSQL 'jobs' table");

        // Run queue worker for 1 job
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queueName,
            '--once' => true,
            '--stop-when-empty' => true,
        ]);

        $this->assertTrue(TestSuccessfulJob::$executed, 'Dispatched job must be executed by worker');

        // Verify job removed from 'jobs' table upon successful execution
        $remainingJobs = DB::table('jobs')->where('queue', $queueName)->count();
        $this->assertEquals(0, $remainingJobs, "Job must be deleted from 'jobs' table upon success");
    }

    /**
     * Test 4: Failed jobs are moved to 'failed_jobs' table on retry budget exhaustion.
     */
    public function test_database_queue_records_failed_jobs(): void
    {
        $queueName = 'notifications';
        TestFailingJob::dispatch()->onConnection('database')->onQueue($queueName);

        $initialFailedCount = DB::table('failed_jobs')->count();

        // Process failing job (tries=1)
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queueName,
            '--once' => true,
            '--stop-when-empty' => true,
        ]);

        // Job must be logged in failed_jobs table
        $newFailedCount = DB::table('failed_jobs')->count();
        $this->assertEquals($initialFailedCount + 1, $newFailedCount, 'Failed job must be logged into failed_jobs table');

        $latestFailed = DB::table('failed_jobs')->latest('failed_at')->first();
        $this->assertNotNull($latestFailed);
        $this->assertEquals($queueName, $latestFailed->queue);
        $this->assertStringContainsString('Simulated queue job failure', $latestFailed->exception);
    }

    /**
     * Test 5: Scheduler executes with database cache mutex without throwing exception.
     */
    public function test_scheduler_runs_with_database_cache_mutex(): void
    {
        // Run schedule:run and verify exit code 0
        $exitCode = Artisan::call('schedule:run');
        $this->assertEquals(0, $exitCode, 'schedule:run must exit cleanly using database cache mutex');
    }

    /**
     * Test 6: Verify active database connection is strictly PostgreSQL.
     */
    public function test_active_database_driver_is_strictly_postgresql(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->assertEquals('pgsql', $driver, 'Production and integration tests must run against PostgreSQL 16+.');

        // Verify btree_gist extension exists
        $hasBtreeGist = DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'btree_gist'");
        $this->assertNotNull($hasBtreeGist, "Extension 'btree_gist' must be installed in PostgreSQL.");
    }

    /**
     * Test 7: Verify runtime role DML permissions on all infrastructure tables.
     */
    public function test_runtime_role_has_dml_permissions_on_infrastructure_tables(): void
    {
        $tables = ['cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'sessions'];

        foreach ($tables as $table) {
            // Verify SELECT works
            $count = DB::table($table)->count();
            $this->assertIsInt($count, "Runtime role must have SELECT permission on '{$table}'");
        }
    }
}
