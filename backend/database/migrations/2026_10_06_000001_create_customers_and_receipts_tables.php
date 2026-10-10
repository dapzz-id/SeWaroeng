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
     * Creates Phase 1F:
     *   1. customers: tenant-scoped customer / CRM foundation with deterministic duplicate prevention
     *   2. sales: foreign key link to customers (restrict on delete)
     *   3. receipts: presentation/document records linked to finalized/settled sales
     *   4. Row-Level Security (RLS) enforcement on customers and receipts
     */
    public function up(): void
    {
        // 1. Customers Table
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_customers_tenant_id_id');
            $table->index(['tenant_id', 'name'], 'idx_customers_tenant_name');
            $table->index(['tenant_id', 'is_active'], 'idx_customers_tenant_active');
            $table->index(['tenant_id', 'created_at'], 'idx_customers_tenant_created');
        });

        // Deterministic tenant-scoped uniqueness for non-null phone and email in PostgreSQL
        DB::statement('CREATE UNIQUE INDEX uq_customers_tenant_phone ON customers (tenant_id, phone) WHERE phone IS NOT NULL;');
        DB::statement('CREATE UNIQUE INDEX uq_customers_tenant_email ON customers (tenant_id, email) WHERE email IS NOT NULL;');

        // 2. Link Sales to Customers
        DB::statement('ALTER TABLE sales ADD CONSTRAINT fk_sales_customer FOREIGN KEY (tenant_id, customer_id) REFERENCES customers (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('CREATE INDEX idx_sales_tenant_customer ON sales (tenant_id, customer_id);');

        // 3. Receipts Table
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outlet_id');
            $table->uuid('sale_id');
            $table->string('receipt_number', 50);
            $table->timestampTz('issued_at')->useCurrent();
            $table->unsignedInteger('reprint_count')->default(0);
            $table->timestampTz('last_reprinted_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_pos_receipts_tenant_id_id');
            $table->unique(['tenant_id', 'sale_id'], 'uq_pos_receipts_tenant_sale');
            $table->unique(['tenant_id', 'receipt_number'], 'uq_pos_receipts_tenant_receipt_number');
            $table->index(['tenant_id', 'outlet_id', 'created_at'], 'idx_pos_receipts_tenant_outlet_created');
        });

        DB::statement('ALTER TABLE receipts ADD CONSTRAINT fk_receipts_outlet_tenant FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT fk_receipts_sale_tenant FOREIGN KEY (tenant_id, sale_id) REFERENCES sales (tenant_id, id) ON DELETE RESTRICT;');

        // 4. PostgreSQL Row-Level Security (RLS) Policies
        DB::statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE customers FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY customer_tenant_isolation_policy ON customers
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");

        DB::statement('ALTER TABLE receipts ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE receipts FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY receipt_tenant_isolation_policy ON receipts
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS receipt_tenant_isolation_policy ON receipts;');
        DB::statement('DROP POLICY IF EXISTS customer_tenant_isolation_policy ON customers;');

        Schema::dropIfExists('receipts');

        DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS fk_sales_customer;');
        DB::statement('DROP INDEX IF EXISTS idx_sales_tenant_customer;');

        Schema::dropIfExists('customers');
    }
};
