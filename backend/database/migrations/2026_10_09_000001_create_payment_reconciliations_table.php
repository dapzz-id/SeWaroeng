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
     * Creates V1 Payment Reconciliation & Settlement Verification table:
     *   - payment_reconciliations: normalized reconciliation and audit ledger
     */
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outlet_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->string('provider', 50); // midtrans, duitku, manual, mock, etc.
            $table->string('provider_reference', 100);
            $table->string('channel', 50)->nullable(); // qris, va, card, ewallet, etc.
            $table->bigInteger('expected_amount_minor')->nullable();
            $table->bigInteger('provider_amount_minor');
            $table->bigInteger('fee_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor')->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->string('payment_status', 30)->nullable();
            $table->string('settlement_status', 30)->default('pending'); // pending, settled, failed, disputed
            $table->string('reconciliation_status', 30)->default('unmatched'); // unmatched, matched, mismatched, needs_review, resolved
            $table->string('mismatch_reason', 100)->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->uuid('reconciled_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->jsonb('raw_evidence')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // Unique provider reference per tenant for deterministic idempotency
            $table->unique(['tenant_id', 'provider', 'provider_reference'], 'uq_pay_recon_tenant_prov_ref');

            // Query indexes
            $table->index(['tenant_id', 'reconciliation_status']);
            $table->index(['tenant_id', 'outlet_id']);
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'reconciled_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Composite foreign keys enforcing tenant isolation
        DB::statement('ALTER TABLE payment_reconciliations ADD CONSTRAINT fk_pay_recon_outlet_tenant FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE SET NULL;');
        DB::statement('ALTER TABLE payment_reconciliations ADD CONSTRAINT fk_pay_recon_payment_tenant FOREIGN KEY (tenant_id, payment_id) REFERENCES payments (tenant_id, id) ON DELETE SET NULL;');

        // PostgreSQL CHECK constraints
        DB::statement("ALTER TABLE payment_reconciliations ADD CONSTRAINT chk_pay_recon_status CHECK (reconciliation_status IN ('unmatched', 'matched', 'mismatched', 'needs_review', 'resolved'));");
        DB::statement("ALTER TABLE payment_reconciliations ADD CONSTRAINT chk_pay_recon_settlement CHECK (settlement_status IN ('pending', 'settled', 'failed', 'disputed'));");
        DB::statement('ALTER TABLE payment_reconciliations ADD CONSTRAINT chk_pay_recon_currency CHECK (length(currency) = 3);');
        DB::statement('ALTER TABLE payment_reconciliations ADD CONSTRAINT chk_pay_recon_prov_amount CHECK (provider_amount_minor >= 0);');
        DB::statement('ALTER TABLE payment_reconciliations ADD CONSTRAINT chk_pay_recon_fee_amount CHECK (fee_amount_minor >= 0);');

        // Row Level Security (RLS)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_reconciliations ENABLE ROW LEVEL SECURITY;');
            DB::statement('ALTER TABLE payment_reconciliations FORCE ROW LEVEL SECURITY;');
            DB::statement("
                CREATE POLICY payment_reconciliations_tenant_isolation_policy ON payment_reconciliations
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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS payment_reconciliations_tenant_isolation_policy ON payment_reconciliations');
        }

        Schema::dropIfExists('payment_reconciliations');
    }
};
