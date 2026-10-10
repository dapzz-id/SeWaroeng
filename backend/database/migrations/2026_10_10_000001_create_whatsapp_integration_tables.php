<?php

use App\Services\WhatsApp\Providers\MetaCloudWhatsAppProvider;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;
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
     *   1. whatsapp_provider_definitions: Platform-level WhatsApp provider registry
     *   2. tenant_whatsapp_connections: Tenant-aware WhatsApp connections with encrypted credentials
     *   3. whatsapp_messages: Normalized outbound message lifecycle tracking
     *   4. PostgreSQL Row-Level Security (RLS) on tenant_whatsapp_connections & whatsapp_messages
     */
    public function up(): void
    {
        // 1. WhatsApp Provider Definitions (Platform Registry)
        Schema::create('whatsapp_provider_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50)->unique();
            $table->string('display_name', 100);
            $table->boolean('is_enabled')->default(true);
            $table->string('adapter_class');
            $table->jsonb('capabilities')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
        });

        // 2. Tenant WhatsApp Connections (Tenant Gateway Configurations)
        Schema::create('tenant_whatsapp_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('environment', 20)->default('sandbox'); // sandbox, production
            $table->string('status', 30)->default('active'); // active, inactive
            $table->boolean('is_default')->default(false);
            $table->string('phone_number_id', 100)->nullable();
            $table->string('business_account_id', 100)->nullable();
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

            $table->unique(['tenant_id', 'provider', 'environment'], 'uq_tenant_whatsapp_prov_env');
            $table->index(['tenant_id', 'status']);
            $table->index(['provider', 'phone_number_id']);
        });

        // 3. WhatsApp Messages (Outbound Delivery Lifecycle Audit)
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('recipient_phone', 50);
            $table->string('message_type', 50); // transactional_receipt, transactional_order, loyalty_notification, marketing_promo
            $table->string('template_name', 100)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('status', 30)->default('pending'); // pending, sending, sent, delivered, read, failed
            $table->string('provider', 50);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'uq_whatsapp_messages_tenant_idempotency');
            $table->index(['tenant_id', 'status']);
            $table->index(['provider', 'provider_message_id']);
            $table->index(['tenant_id', 'recipient_phone']);
            $table->index(['tenant_id', 'created_at']);
        });

        // 4. PostgreSQL Row-Level Security (RLS) Policies
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenant_whatsapp_connections ENABLE ROW LEVEL SECURITY;');
            DB::statement('ALTER TABLE tenant_whatsapp_connections FORCE ROW LEVEL SECURITY;');
            DB::statement("
                CREATE POLICY tenant_whatsapp_connections_isolation_policy ON tenant_whatsapp_connections
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

            DB::statement('ALTER TABLE whatsapp_messages ENABLE ROW LEVEL SECURITY;');
            DB::statement('ALTER TABLE whatsapp_messages FORCE ROW LEVEL SECURITY;');
            DB::statement("
                CREATE POLICY whatsapp_messages_isolation_policy ON whatsapp_messages
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
        }

        // 5. Seed initial platform provider definitions
        DB::table('whatsapp_provider_definitions')->insert([
            [
                'id' => (string) Str::uuid(),
                'provider' => 'mock',
                'display_name' => 'Mock WhatsApp Provider (Testing)',
                'is_enabled' => true,
                'adapter_class' => MockWhatsAppProvider::class,
                'capabilities' => json_encode([
                    'template_messages' => true,
                    'text_messages' => true,
                    'delivery_status_webhooks' => true,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'provider' => 'meta_cloud',
                'display_name' => 'Meta WhatsApp Cloud API',
                'is_enabled' => true,
                'adapter_class' => MetaCloudWhatsAppProvider::class,
                'capabilities' => json_encode([
                    'template_messages' => true,
                    'text_messages' => true,
                    'delivery_status_webhooks' => true,
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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS whatsapp_messages_isolation_policy ON whatsapp_messages;');
            DB::statement('DROP POLICY IF EXISTS tenant_whatsapp_connections_isolation_policy ON tenant_whatsapp_connections;');
        }

        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('tenant_whatsapp_connections');
        Schema::dropIfExists('whatsapp_provider_definitions');
    }
};
