<?php

use App\Services\Payment\Providers\DuitkuPaymentProvider;
use App\Services\Payment\Providers\MidtransPaymentProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates:
     *   1. payment_provider_definitions: Platform-level provider registry
     *   2. tenant_payment_connections: Tenant-aware gateway connections with encrypted credentials
     *   3. RLS policy on tenant_payment_connections
     */
    public function up(): void
    {
        // 1. Payment Provider Definitions (Platform Registry)
        Schema::create('payment_provider_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50)->unique();
            $table->string('display_name', 100);
            $table->boolean('is_enabled')->default(true);
            $table->string('adapter_class');
            $table->jsonb('supported_methods')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
        });

        // 2. Tenant Payment Connections (Tenant Gateway Configurations)
        Schema::create('tenant_payment_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('environment', 20)->default('sandbox'); // sandbox, production
            $table->string('status', 30)->default('active'); // active, inactive
            $table->boolean('is_default')->default(false);
            $table->string('merchant_identifier', 100);
            $table->text('encrypted_credentials');
            $table->jsonb('configuration')->nullable();
            $table->string('verification_status', 30)->default('unverified'); // unverified, verified, failed
            $table->jsonb('capability_status')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->string('last_error_message', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'environment'], 'uq_tenant_provider_env');
            $table->index(['tenant_id', 'status']);
            $table->index(['provider', 'merchant_identifier']);
        });

        // 3. PostgreSQL Row-Level Security (RLS) on tenant_payment_connections
        DB::statement('ALTER TABLE tenant_payment_connections ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE tenant_payment_connections FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY tenant_payment_connections_isolation_policy ON tenant_payment_connections
                FOR ALL
                USING (
                    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid
                    OR NULLIF(current_setting('app.is_platform_context', true), '') = 'true'
                );
        ");

        // Seed initial platform provider definitions
        DB::table('payment_provider_definitions')->insert([
            [
                'id' => (string) Str::uuid(),
                'provider' => 'midtrans',
                'display_name' => 'Midtrans Payment Gateway',
                'is_enabled' => true,
                'adapter_class' => MidtransPaymentProvider::class,
                'supported_methods' => json_encode(['qris', 'bank_transfer', 'card', 'e_wallet']),
                'capabilities' => json_encode([
                    'refund' => true,
                    'status_inquiry' => true,
                    'webhook_signature' => 'HMAC-SHA512',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'provider' => 'duitku',
                'display_name' => 'Duitku Payment Gateway',
                'is_enabled' => true,
                'adapter_class' => DuitkuPaymentProvider::class,
                'supported_methods' => json_encode(['qris', 'bank_transfer', 'e_wallet', 'retail']),
                'capabilities' => json_encode([
                    'refund' => false,
                    'status_inquiry' => true,
                    'webhook_signature' => 'HMAC-SHA256',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_payment_connections_isolation_policy ON tenant_payment_connections;');
        Schema::dropIfExists('tenant_payment_connections');
        Schema::dropIfExists('payment_provider_definitions');
    }
};
