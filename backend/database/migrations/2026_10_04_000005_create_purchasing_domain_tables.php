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
        // 1. Suppliers Table
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 255);
            $table->string('contact_name', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_identifier', 50)->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'uq_suppliers_tenant_id_id');
            $table->unique(['tenant_id', 'code'], 'uq_suppliers_tenant_code');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'name']);
        });

        // 2. Purchase Orders Table (Aggregate Root)
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->uuid('destination_location_id');
            $table->string('reference_number', 50);
            $table->string('status', 30)->default('draft'); // draft, submitted, approved, partially_received, received, cancelled
            $table->timestampTz('ordered_at');
            $table->timestampTz('expected_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_po_tenant_id_id');
            $table->unique(['tenant_id', 'reference_number'], 'uq_po_tenant_reference_number');
            $table->index(['tenant_id', 'supplier_id', 'status']);
            $table->index(['tenant_id', 'destination_location_id']);
            $table->index(['tenant_id', 'ordered_at']);
        });

        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_supplier_tenant FOREIGN KEY (tenant_id, supplier_id) REFERENCES suppliers (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_location_tenant FOREIGN KEY (tenant_id, destination_location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');

        // 3. Purchase Order Items Table
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('purchase_order_id');
            $table->uuid('inventory_item_id');
            $table->decimal('ordered_quantity', 14, 4);
            $table->unsignedBigInteger('unit_cost_minor'); // IDR minor unit (Rp 15.000 = 15000)
            $table->decimal('received_quantity', 14, 4)->default('0.0000');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_po_items_tenant_id_id');
            $table->unique(['tenant_id', 'purchase_order_id', 'inventory_item_id'], 'uq_po_item_unique');
            $table->index(['tenant_id', 'inventory_item_id']);
        });

        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT fk_po_items_po_tenant FOREIGN KEY (tenant_id, purchase_order_id) REFERENCES purchase_orders (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT fk_po_items_item_tenant FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT chk_po_items_ordered_qty CHECK (ordered_quantity > 0);');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT chk_po_items_received_qty CHECK (received_quantity >= 0);');

        // 4. Purchase Receipts Table (Goods Receipt Note / GRN)
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('purchase_order_id');
            $table->uuid('destination_location_id');
            $table->string('reference_number', 50);
            $table->string('status', 20)->default('draft'); // draft, posted, cancelled
            $table->timestampTz('received_at');
            $table->uuid('received_by');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_receipts_tenant_id_id');
            $table->unique(['tenant_id', 'reference_number'], 'uq_receipts_tenant_reference_number');
            $table->index(['tenant_id', 'purchase_order_id']);
            $table->index(['tenant_id', 'destination_location_id', 'status']);
        });

        DB::statement('ALTER TABLE purchase_receipts ADD CONSTRAINT fk_receipts_po_tenant FOREIGN KEY (tenant_id, purchase_order_id) REFERENCES purchase_orders (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE purchase_receipts ADD CONSTRAINT fk_receipts_location_tenant FOREIGN KEY (tenant_id, destination_location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');

        // 5. Purchase Receipt Items Table
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('purchase_receipt_id');
            $table->uuid('purchase_order_item_id');
            $table->uuid('inventory_item_id');
            $table->decimal('quantity', 14, 4);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_receipt_items_tenant_id_id');
            $table->index(['tenant_id', 'purchase_receipt_id']);
            $table->index(['tenant_id', 'inventory_item_id']);
        });

        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT fk_receipt_items_receipt_tenant FOREIGN KEY (tenant_id, purchase_receipt_id) REFERENCES purchase_receipts (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT fk_receipt_items_po_item_tenant FOREIGN KEY (tenant_id, purchase_order_item_id) REFERENCES purchase_order_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT fk_receipt_items_item_tenant FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE purchase_receipt_items ADD CONSTRAINT chk_receipt_items_qty CHECK (quantity > 0);');

        // Apply Row Level Security to all 5 purchasing tables
        $purchasingTables = [
            'suppliers',
            'purchase_orders',
            'purchase_order_items',
            'purchase_receipts',
            'purchase_receipt_items',
        ];

        foreach ($purchasingTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;");
            DB::statement("
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_policies WHERE tablename = '{$table}' AND policyname = '{$table}_tenant_isolation_policy'
                    ) THEN
                        CREATE POLICY {$table}_tenant_isolation_policy ON {$table}
                            FOR ALL
                            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
                    END IF;
                END $$;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $purchasingTables = [
            'purchase_receipt_items',
            'purchase_receipts',
            'purchase_order_items',
            'purchase_orders',
            'suppliers',
        ];

        foreach ($purchasingTables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation_policy ON {$table};");
            Schema::dropIfExists($table);
        }
    }
};
