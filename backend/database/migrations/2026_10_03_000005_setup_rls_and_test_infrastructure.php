<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Enable RLS on `outlets` table
        DB::statement('ALTER TABLE outlets ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE outlets FORCE ROW LEVEL SECURITY;');
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies WHERE tablename = 'outlets' AND policyname = 'outlets_tenant_isolation_policy'
                ) THEN
                    CREATE POLICY outlets_tenant_isolation_policy ON outlets
                        FOR ALL
                        USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                        WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
                END IF;
            END $$;
        ");

        // 2. Create tenant_test_records table to systematically verify RLS fail-closed, transaction isolation, and leak tests
        Schema::create('tenant_test_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('secret_data')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
        });

        DB::statement('ALTER TABLE tenant_test_records ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE tenant_test_records FORCE ROW LEVEL SECURITY;');
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies WHERE tablename = 'tenant_test_records' AND policyname = 'tenant_test_records_isolation_policy'
                ) THEN
                    CREATE POLICY tenant_test_records_isolation_policy ON tenant_test_records
                        FOR ALL
                        USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                        WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
                END IF;
            END $$;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_test_records_isolation_policy ON tenant_test_records;');
        Schema::dropIfExists('tenant_test_records');

        DB::statement('DROP POLICY IF EXISTS outlets_tenant_isolation_policy ON outlets;');
        DB::statement('ALTER TABLE outlets DISABLE ROW LEVEL SECURITY;');
    }
};
