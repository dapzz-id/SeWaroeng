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
        // 1. Inventory Locations Table
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outlet_id')->nullable();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->string('type', 30)->default('outlet'); // outlet, warehouse, kitchen, storage, central_warehouse, other
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_inv_locations_tenant_id_id');
            $table->unique(['tenant_id', 'code'], 'uq_inv_locations_tenant_code');
            $table->index(['tenant_id', 'type', 'is_active']);
            $table->index(['tenant_id', 'outlet_id']);
        });

        DB::statement('ALTER TABLE inventory_locations ADD CONSTRAINT fk_inv_locations_outlet_tenant FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE RESTRICT;');

        // 2. Inventory Items Table (stock-controlled sellable or material unit)
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_variant_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 255);
            $table->string('item_type', 30)->default('sellable'); // sellable, raw_material, packaging, finished_good, semi_finished, asset
            $table->string('base_unit', 30)->default('piece'); // piece, kg, gram, liter, ml, box, pack
            $table->string('status', 20)->default('active'); // active, inactive
            $table->boolean('allow_negative_stock')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_inv_items_tenant_id_id');
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'item_type', 'status']);
        });

        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT fk_inv_items_variant_tenant FOREIGN KEY (tenant_id, product_variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('CREATE UNIQUE INDEX uq_inv_items_variant ON inventory_items (tenant_id, product_variant_id) WHERE product_variant_id IS NOT NULL;');

        // 3. Inventory Item Locations (Balance Read Model & Location Configuration)
        Schema::create('inventory_item_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->decimal('on_hand', 15, 4)->default(0);
            $table->decimal('reserved', 15, 4)->default(0);
            $table->decimal('reorder_level', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_inv_item_loc_tenant_id_id');
            $table->unique(['tenant_id', 'inventory_item_id', 'location_id'], 'uq_inv_item_loc_scope');
            $table->index(['tenant_id', 'location_id']);
        });

        DB::statement('ALTER TABLE inventory_item_locations ADD CONSTRAINT fk_inv_item_loc_item FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE inventory_item_locations ADD CONSTRAINT fk_inv_item_loc_location FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE inventory_item_locations ADD CONSTRAINT chk_inv_reserved_non_negative CHECK (reserved >= 0);');

        // 4. Inventory Movements Table (Immutable Ledger)
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('movement_number', 50);
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->string('movement_type', 40); // opening_balance, adjustment_in, adjustment_out, stock_opname, transfer_in, transfer_out, waste, expired, reserved, released
            $table->decimal('quantity_delta', 15, 4);
            $table->string('unit', 30);
            $table->decimal('before_on_hand', 15, 4);
            $table->decimal('after_on_hand', 15, 4);
            $table->string('reference_type', 50)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_inv_movements_tenant_id_id');
            $table->unique(['tenant_id', 'movement_number'], 'uq_inv_movements_tenant_number');
            $table->index(['tenant_id', 'inventory_item_id', 'occurred_at'], 'idx_inv_mov_item_occurred');
            $table->index(['tenant_id', 'location_id', 'occurred_at'], 'idx_inv_mov_loc_occurred');
            $table->index(['tenant_id', 'movement_type', 'occurred_at'], 'idx_inv_mov_type_occurred');
            $table->index(['tenant_id', 'reference_type', 'reference_id'], 'idx_inv_mov_reference');
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT fk_inv_mov_item FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT fk_inv_mov_location FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');

        // 5. Stock Opnames Table
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('opname_number', 50);
            $table->uuid('location_id');
            $table->string('status', 20)->default('draft'); // draft, in_review, approved, cancelled
            $table->text('notes')->nullable();
            $table->uuid('counted_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_stock_opnames_tenant_id_id');
            $table->unique(['tenant_id', 'opname_number'], 'uq_stock_opnames_tenant_number');
            $table->index(['tenant_id', 'location_id', 'status']);
        });

        DB::statement('ALTER TABLE stock_opnames ADD CONSTRAINT fk_stock_opnames_location FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');

        // 6. Stock Opname Items Table
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('opname_id');
            $table->uuid('inventory_item_id');
            $table->decimal('system_quantity', 15, 4);
            $table->decimal('physical_quantity', 15, 4);
            $table->decimal('difference', 15, 4);
            $table->string('reason', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_stock_opname_items_tenant_id_id');
            $table->unique(['tenant_id', 'opname_id', 'inventory_item_id'], 'uq_stock_opname_items_scope');
        });

        DB::statement('ALTER TABLE stock_opname_items ADD CONSTRAINT fk_stock_opname_items_opname FOREIGN KEY (tenant_id, opname_id) REFERENCES stock_opnames (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE stock_opname_items ADD CONSTRAINT fk_stock_opname_items_item FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');

        // 7. Stock Transfers Table
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('transfer_number', 50);
            $table->uuid('source_location_id');
            $table->uuid('destination_location_id');
            $table->string('status', 20)->default('draft'); // draft, pending, approved, in_transit, received, cancelled
            $table->text('notes')->nullable();
            $table->uuid('requested_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_stock_transfers_tenant_id_id');
            $table->unique(['tenant_id', 'transfer_number'], 'uq_stock_transfers_tenant_number');
            $table->index(['tenant_id', 'source_location_id', 'status']);
            $table->index(['tenant_id', 'destination_location_id', 'status']);
        });

        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT fk_stock_transfers_source FOREIGN KEY (tenant_id, source_location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT fk_stock_transfers_dest FOREIGN KEY (tenant_id, destination_location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT chk_stock_transfers_different_loc CHECK (source_location_id != destination_location_id);');

        // 8. Stock Transfer Items Table
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('transfer_id');
            $table->uuid('inventory_item_id');
            $table->decimal('quantity', 15, 4);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_stock_transfer_items_tenant_id_id');
            $table->unique(['tenant_id', 'transfer_id', 'inventory_item_id'], 'uq_stock_transfer_items_scope');
        });

        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT fk_stock_transfer_items_transfer FOREIGN KEY (tenant_id, transfer_id) REFERENCES stock_transfers (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT fk_stock_transfer_items_item FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_stock_transfer_quantity_positive CHECK (quantity > 0);');

        // 9. Enable Row Level Security (RLS) on all inventory tables
        $inventoryTables = [
            'inventory_locations',
            'inventory_items',
            'inventory_item_locations',
            'inventory_movements',
            'stock_opnames',
            'stock_opname_items',
            'stock_transfers',
            'stock_transfer_items',
        ];

        foreach ($inventoryTables as $table) {
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
        $inventoryTables = [
            'stock_transfer_items',
            'stock_transfers',
            'stock_opname_items',
            'stock_opnames',
            'inventory_movements',
            'inventory_item_locations',
            'inventory_items',
            'inventory_locations',
        ];

        foreach ($inventoryTables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation_policy ON {$table};");
            Schema::dropIfExists($table);
        }
    }
};
