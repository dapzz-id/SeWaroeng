<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Invariants added:
     * 1. UNIQUE(provider, environment, merchant_identifier)
     *    Prevents ambiguous merchant code routing across tenants.
     * 2. Partial unique index on (tenant_id, environment) WHERE is_default = TRUE
     *    Guarantees at database level that at most one connection can be default per tenant & environment.
     */
    public function up(): void
    {
        // 1. Merchant identifier uniqueness per provider & environment
        DB::statement('
            ALTER TABLE tenant_payment_connections
            ADD CONSTRAINT uq_provider_env_merchant_id 
            UNIQUE (provider, environment, merchant_identifier);
        ');

        // 2. Partial unique index for one default provider per tenant and environment
        DB::statement('
            CREATE UNIQUE INDEX uq_tenant_env_default_connection 
            ON tenant_payment_connections (tenant_id, environment) 
            WHERE is_default = TRUE;
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_tenant_env_default_connection;');
        DB::statement('ALTER TABLE tenant_payment_connections DROP CONSTRAINT IF EXISTS uq_provider_env_merchant_id;');
    }
};
