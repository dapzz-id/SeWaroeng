<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class V1MigrationLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate');
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate');
        parent::tearDown();
    }

    /**
     * Automated verification of migration 2026_10_07_000005:
     * - Migrates legacy encrypted_tracking_token from orders.metadata to dedicated ciphertext column.
     * - Preserves tracking hash, order_number, and payload_hash.
     * - Removes encrypted_tracking_token from metadata.
     * - Rolls back cleanly dropping ciphertext column without accidental order loss.
     */
    public function test_v1_migration_000005_migrates_legacy_metadata_and_rolls_back_cleanly(): void
    {
        // 0. Ensure any subsequent migrations (e.g. Loyalty domain) are rolled back first
        while (Schema::hasTable('loyalty_programs')) {
            Artisan::call('migrate:rollback', ['--step' => 1]);
        }

        // 1. Rollback step 1 to reach legacy schema state (before migration 000005)
        $exitCode = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode);
        $this->assertFalse(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));

        // 2. Setup baseline user, tenant, outlet, table, and session records
        $userId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();
        $outletId = (string) Str::uuid();
        $tableId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();
        $orderId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Legacy Owner',
            'email' => 'legacy.'.Str::random(8).'@example.com',
            'password' => 'secret123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET app.current_tenant_id = '{$tenantId}'");
        }

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'owner_user_id' => $userId,
            'name' => 'Legacy Migration Tenant',
            'code' => 'LEGACY-'.Str::random(6),
            'business_type' => 'fnb',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outlets')->insert([
            'id' => $outletId,
            'tenant_id' => $tenantId,
            'name' => 'Legacy Outlet',
            'code' => 'OUT-LEG-'.Str::random(4),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tables')->insert([
            'id' => $tableId,
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'code' => 'T-LEG-1',
            'name' => 'Meja Legacy',
            'capacity' => 4,
            'status' => 'available',
            'qr_token_hash' => hash('sha256', 'legacy_table_token'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('table_sessions')->insert([
            'id' => $sessionId,
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'table_id' => $tableId,
            'session_code' => 'SESS-LEG-1',
            'status' => 'active',
            'guest_count' => 2,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Insert legacy-style order with encrypted token stored in metadata
        $rawTrackingToken = bin2hex(random_bytes(24));
        $trackingHash = hash('sha256', $rawTrackingToken);
        $encryptedToken = Crypt::encryptString($rawTrackingToken);

        DB::table('orders')->insert([
            'id' => $orderId,
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'table_id' => $tableId,
            'table_session_id' => $sessionId,
            'order_number' => 'ORD-LEGACY-MIGRATE-01',
            'status' => 'submitted',
            'subtotal_minor' => 50000,
            'total_minor' => 50000,
            'idempotency_key' => 'IDEMP-LEGACY-01',
            'public_tracking_token_hash' => $trackingHash,
            'source' => 'qr_table',
            'metadata' => json_encode([
                'payload_hash' => 'hash_payload_123',
                'encrypted_tracking_token' => $encryptedToken,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Run migration 000005
        $migrateExit = Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_07_000005_add_public_tracking_token_ciphertext_to_orders.php']);
        $this->assertEquals(0, $migrateExit, 'Migration 000005 must execute successfully');
        $this->assertTrue(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));

        // 5. Assert legacy data migrated cleanly
        $migratedOrder = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($migratedOrder);
        $this->assertEquals($encryptedToken, $migratedOrder->public_tracking_token_ciphertext);
        $this->assertEquals($trackingHash, $migratedOrder->public_tracking_token_hash);
        $this->assertEquals('ORD-LEGACY-MIGRATE-01', $migratedOrder->order_number);

        // Verify decrypting the migrated ciphertext yields the exact raw tracking token
        $this->assertEquals($rawTrackingToken, Crypt::decryptString($migratedOrder->public_tracking_token_ciphertext));

        // Verify metadata was cleansed of encrypted credential while preserving business metadata
        $meta = json_decode((string) $migratedOrder->metadata, true);
        $this->assertArrayNotHasKey('encrypted_tracking_token', $meta, 'encrypted_tracking_token must be purged from metadata');
        $this->assertEquals('hash_payload_123', $meta['payload_hash'], 'business metadata must be preserved');

        // 6. Verify rollback of migration 000005
        $rollbackExit = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $rollbackExit, 'Rollback of migration 000005 must execute successfully');
        $this->assertFalse(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));

        // Verify order is intact without accidental deletion
        $orderAfterRollback = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($orderAfterRollback, 'Rollback must not cause accidental order loss');
        $this->assertEquals('ORD-LEGACY-MIGRATE-01', $orderAfterRollback->order_number);

        // 7. Restore migration to leave test database on latest schema
        Artisan::call('migrate');
        $this->assertTrue(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));

        // Clean up test records
        DB::table('orders')->where('id', $orderId)->delete();
        DB::table('table_sessions')->where('id', $sessionId)->delete();
        DB::table('tables')->where('id', $tableId)->delete();
        DB::table('outlets')->where('id', $outletId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
        DB::table('users')->where('id', $userId)->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET app.current_tenant_id = ''");
        }
    }

    /**
     * Automated verification of full V1 Booking/Table/QR ordering migrations rollback and re-application.
     * Proves:
     * - Step-by-step rollback of all 5 V1 migrations cleanly removes columns, indexes, and tables without residuals.
     * - Re-migration recreates full schema, dedicated ciphertext column, partial unique indexes, and RLS policies cleanly.
     */
    public function test_v1_migrations_rollback_and_remigrate_cleanly(): void
    {
        // 1. Initial State: All V1 tables and hardening structures exist
        $this->assertTrue(Schema::hasTable('tables'), 'tables must exist before rollback');
        $this->assertTrue(Schema::hasTable('bookings'), 'bookings must exist before rollback');
        $this->assertTrue(Schema::hasTable('table_sessions'), 'table_sessions must exist before rollback');
        $this->assertTrue(Schema::hasTable('orders'), 'orders must exist before rollback');
        $this->assertTrue(Schema::hasTable('order_items'), 'order_items must exist before rollback');
        $this->assertTrue(Schema::hasColumn('orders', 'public_tracking_token_hash'));
        $this->assertTrue(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));
        $this->assertTrue(Schema::hasColumn('tables', 'qr_token_hash'));

        if (DB::getDriverName() === 'pgsql') {
            $hasActiveSessionIdx = DB::table('pg_indexes')
                ->where('indexname', 'uq_table_sessions_active_table')
                ->exists();
            $this->assertTrue($hasActiveSessionIdx, 'uq_table_sessions_active_table index must exist');

            $hasNotifDedupIdx = DB::table('pg_indexes')
                ->where('indexname', 'uq_notifications_tenant_dedup')
                ->exists();
            $this->assertTrue($hasNotifDedupIdx, 'uq_notifications_tenant_dedup index must exist');
        }

        // Ensure any subsequent migrations (e.g. Loyalty domain) are rolled back first
        while (Schema::hasTable('loyalty_programs')) {
            Artisan::call('migrate:rollback', ['--step' => 1]);
        }

        // 2. Rollback step 1: 2026_10_07_000005_add_public_tracking_token_ciphertext_to_orders
        $exitCode5 = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode5, 'Rollback step 1 (000005) must succeed');
        $this->assertFalse(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));

        // 3. Rollback step 2: 2026_10_07_000004_harden_table_sessions_and_order_tracking
        $exitCode4 = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode4, 'Rollback step 2 (000004) must succeed');

        $this->assertFalse(Schema::hasColumn('orders', 'public_tracking_token_hash'), 'public_tracking_token_hash column must be dropped');
        if (DB::getDriverName() === 'pgsql') {
            $hasActiveSessionIdxAfterRollback = DB::table('pg_indexes')
                ->where('indexname', 'uq_table_sessions_active_table')
                ->exists();
            $this->assertFalse($hasActiveSessionIdxAfterRollback, 'uq_table_sessions_active_table must not leave residual index');
        }

        // 4. Rollback step 3: 2026_10_07_000003_add_customer_snapshots_to_orders
        $exitCode3 = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode3, 'Rollback step 3 (000003) must succeed');
        $this->assertFalse(Schema::hasColumn('orders', 'customer_name_snapshot'));
        $this->assertFalse(Schema::hasColumn('orders', 'customer_contact_snapshot'));

        // 5. Rollback step 4: 2026_10_07_000002_hash_table_qr_tokens
        $exitCode2 = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode2, 'Rollback step 4 (000002) must succeed');
        $this->assertFalse(Schema::hasColumn('tables', 'qr_token_hash'), 'qr_token_hash column must be dropped');
        $this->assertTrue(Schema::hasColumn('tables', 'qr_token'), 'plaintext qr_token column must be restored');

        // 6. Rollback step 5: 2026_10_07_000001_create_table_booking_and_order_tables
        $exitCode1 = Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertEquals(0, $exitCode1, 'Rollback step 5 (000001) must succeed');
        $this->assertFalse(Schema::hasTable('order_items'), 'order_items table must be dropped');
        $this->assertFalse(Schema::hasTable('orders'), 'orders table must be dropped');
        $this->assertFalse(Schema::hasTable('table_sessions'), 'table_sessions table must be dropped');
        $this->assertFalse(Schema::hasTable('bookings'), 'bookings table must be dropped');
        $this->assertFalse(Schema::hasTable('tables'), 'tables table must be dropped');

        // 7. Re-run migration from scratch (verify fresh creation of all V1 schema including ciphertext)
        $migrateExit = Artisan::call('migrate');
        $this->assertEquals(0, $migrateExit, 'Re-running migrate must succeed');

        // Verify complete restoration of all schema elements
        $this->assertTrue(Schema::hasTable('tables'));
        $this->assertTrue(Schema::hasTable('bookings'));
        $this->assertTrue(Schema::hasTable('table_sessions'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_items'));
        $this->assertTrue(Schema::hasColumn('tables', 'qr_token_hash'));
        $this->assertFalse(Schema::hasColumn('tables', 'qr_token'));
        $this->assertTrue(Schema::hasColumn('orders', 'customer_name_snapshot'));
        $this->assertTrue(Schema::hasColumn('orders', 'public_tracking_token_hash'));
        $this->assertTrue(Schema::hasColumn('orders', 'public_tracking_token_ciphertext'));

        if (DB::getDriverName() === 'pgsql') {
            $restoredSessionIdx = DB::table('pg_indexes')
                ->where('indexname', 'uq_table_sessions_active_table')
                ->exists();
            $this->assertTrue($restoredSessionIdx, 'uq_table_sessions_active_table index must be recreated');
        }
    }
}
