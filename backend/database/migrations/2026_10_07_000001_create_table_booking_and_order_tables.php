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
        // 1. Tables (Physical tables in outlets)
        Schema::create('tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->unsignedInteger('capacity')->default(4);
            $table->string('status', 30)->default('available'); // available, occupied, reserved, inactive
            $table->string('qr_token', 64)->nullable()->unique();
            $table->timestamp('qr_token_generated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'outlet_id', 'code'], 'tables_tenant_outlet_code_unique');
            $table->index(['tenant_id', 'outlet_id', 'status']);
        });

        // 2. Bookings (Reservations)
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('booking_code', 50);
            $table->string('customer_name_snapshot', 255);
            $table->string('customer_contact_snapshot', 100)->nullable();
            $table->unsignedInteger('party_size')->default(2);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 30)->default('pending'); // pending, confirmed, checked_in, cancelled, completed, no_show
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'booking_code'], 'bookings_tenant_code_unique');
            $table->index(['tenant_id', 'outlet_id', 'table_id', 'status']);
            $table->index(['starts_at', 'ends_at']);
        });

        // 3. Table Sessions (Operational dining sessions)
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('table_id')->constrained('tables')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('session_code', 64);
            $table->string('status', 30)->default('active'); // active, completed, cancelled
            $table->unsignedInteger('guest_count')->default(1);
            $table->foreignUuid('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'table_id', 'status']);
        });

        // 4. Orders (Customer QR and POS table orders)
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('table_id')->constrained('tables')->cascadeOnDelete();
            $table->foreignUuid('table_session_id')->constrained('table_sessions')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('order_number', 50);
            $table->string('status', 30)->default('submitted'); // submitted, accepted, preparing, ready, served, cancelled, converted
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->text('customer_notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('source', 30)->default('qr_customer'); // qr_customer, pos_staff
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number'], 'orders_tenant_number_unique');
            $table->index(['tenant_id', 'table_session_id', 'status']);
            $table->index(['tenant_id', 'idempotency_key']);
        });

        // 5. Order Items
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('product_name_snapshot', 255);
            $table->string('variant_name_snapshot', 255);
            $table->decimal('quantity', 12, 4)->default(1);
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('selected_modifiers')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        // 6. Enable and Force PostgreSQL Row-Level Security (Layer 2 Tenant Isolation)
        if (DB::getDriverName() === 'pgsql') {
            $domainTables = [
                'tables',
                'bookings',
                'table_sessions',
                'orders',
                'order_items',
            ];

            foreach ($domainTables as $t) {
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
                DB::statement("DROP POLICY IF EXISTS {$t}_tenant_isolation_policy ON {$t};");
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $domainTables = [
                'order_items',
                'orders',
                'table_sessions',
                'bookings',
                'tables',
            ];

            foreach ($domainTables as $t) {
                DB::statement("DROP POLICY IF EXISTS {$t}_tenant_isolation_policy ON {$t}");
            }
        }

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('table_sessions');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('tables');
    }
};
