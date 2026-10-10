<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates Phase 1D Payment & Settlement Domain tables:
     *   1. payments: core payment aggregate
     *   2. payment_attempts: individual network/gateway attempts
     *   3. payment_refunds: immutable refund records
     *   4. payment_webhook_events: external provider webhook ingestion ledger
     */
    public function up(): void
    {
        // 1. Payments Aggregate
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('sale_id');
            $table->string('reference_number', 50);
            $table->string('status', 30)->default('pending');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('IDR');
            $table->string('method', 50); // cash, qris, card, bank_transfer, e_wallet
            $table->string('provider', 50)->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestampTz('initiated_at')->useCurrent();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_payments_tenant_id_id');
            $table->unique(['tenant_id', 'reference_number'], 'uq_payments_tenant_ref');
            $table->index(['tenant_id', 'sale_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'provider', 'provider_payment_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT fk_payments_sale_tenant FOREIGN KEY (tenant_id, sale_id) REFERENCES sales (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded', 'partially_refunded'));");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cash', 'qris', 'card', 'bank_transfer', 'e_wallet'));");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount_minor > 0);');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_currency CHECK (currency = 'IDR');");

        // 2. Payment Attempts (Network / Gateway Attempts)
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('payment_id');
            $table->integer('attempt_number');
            $table->string('status', 30)->default('pending'); // pending, processing, succeeded, failed, timeout
            $table->string('provider', 50);
            $table->string('provider_reference', 100)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->bigInteger('requested_amount_minor');
            $table->string('response_code', 50)->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_payment_attempts_tenant_id_id');
            $table->unique(['tenant_id', 'payment_id', 'attempt_number'], 'uq_payment_attempts_num');
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'provider', 'provider_reference']);
        });

        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT fk_pay_attempts_pay_tenant FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id) ON DELETE CASCADE;');
        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT chk_pay_attempts_status CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'timeout'));");
        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT chk_pay_attempts_amount CHECK (requested_amount_minor > 0);');

        // 3. Payment Refunds
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('payment_id');
            $table->string('reference_number', 50);
            $table->bigInteger('amount_minor');
            $table->text('reason');
            $table->string('status', 30)->default('pending'); // pending, completed, failed
            $table->string('provider_reference', 100)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_payment_refunds_tenant_id_id');
            $table->unique(['tenant_id', 'reference_number'], 'uq_payment_refunds_tenant_ref');
            $table->index(['tenant_id', 'payment_id']);
        });

        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT fk_pay_refunds_pay_tenant FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT chk_pay_refunds_status CHECK (status IN ('pending', 'completed', 'failed'));");
        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT chk_pay_refunds_amount CHECK (amount_minor > 0);');

        // 4. Payment Webhook Events (Platform boundary ingestion ledger)
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('external_event_id', 100);
            $table->jsonb('payload');
            $table->boolean('signature_verified')->default(false);
            $table->string('processing_status', 30)->default('pending'); // pending, processed, failed, ignored
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'uq_webhook_provider_event');
            $table->index(['provider', 'processing_status']);
            $table->index(['received_at']);
        });

        // 5. PostgreSQL Row-Level Security (RLS) on Tenant Tables
        $tenantTables = ['payments', 'payment_attempts', 'payment_refunds'];
        foreach ($tenantTables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("
                CREATE POLICY {$t}_tenant_isolation_policy ON {$t}
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
