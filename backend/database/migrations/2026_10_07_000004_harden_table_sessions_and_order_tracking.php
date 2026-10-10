<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enforces database-level active table session invariant and adds hashed public order tracking.
     */
    public function up(): void
    {
        // 1. Database-level invariant: A physical table cannot have more than one active session simultaneously
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX uq_table_sessions_active_table ON table_sessions (table_id) WHERE status = 'active';");
        } else {
            // Fallback for non-postgres drivers in unit tests if needed
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->index(['table_id', 'status'], 'idx_table_sessions_table_status');
            });
        }

        // 2. Add cryptographically secure public order tracking token hash to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_tracking_token_hash', 64)->nullable()->after('idempotency_key');
            $table->index(['tenant_id', 'table_id', 'public_tracking_token_hash'], 'idx_orders_tracking_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uq_table_sessions_active_table;');
        } else {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->dropIndex('idx_table_sessions_table_status');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_tracking_hash');
            $table->dropColumn('public_tracking_token_hash');
        });
    }
};
