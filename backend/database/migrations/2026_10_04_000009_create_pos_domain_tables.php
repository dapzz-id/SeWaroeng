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
     * Creates Phase 1C POS / Sales Transaction Domain tables:
     *   1. sales: core sale / cart / transaction header
     *   2. sale_items: snapshot of sold product variants, resolved prices, and recipes
     *   3. sale_item_modifiers: snapshot of selected modifiers and price adjustments
     *   4. sale_consumptions: immutable inventory consumption and MWA COGS provenance
     */
    public function up(): void
    {
        // 1. Sales Header
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('outlet_id');
            $table->string('reference_number', 50);
            $table->string('status', 20)->default('open'); // open, finalized, cancelled
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cashier_user_id');
            $table->uuid('customer_id')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_sales_tenant_id_id');
            $table->unique(['tenant_id', 'reference_number'], 'uq_sales_tenant_ref');
            $table->index(['tenant_id', 'outlet_id', 'status']);
            $table->index(['tenant_id', 'cashier_user_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement('ALTER TABLE sales ADD CONSTRAINT fk_sales_outlet_tenant FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT chk_sales_status CHECK (status IN (\'open\', \'finalized\', \'cancelled\'));');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT chk_sales_amounts CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND tax_minor >= 0 AND total_minor >= 0);');

        // 2. Sale Items
        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('sale_id');
            $table->uuid('product_variant_id');
            $table->string('product_name_snapshot', 255);
            $table->string('variant_name_snapshot', 255);
            $table->decimal('quantity', 15, 4);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->uuid('price_id')->nullable();
            $table->uuid('recipe_version_id')->nullable();
            $table->jsonb('recipe_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_sale_items_tenant_id_id');
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'product_variant_id']);
        });

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_sale_tenant FOREIGN KEY (tenant_id, sale_id) REFERENCES sales (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_variant_tenant FOREIGN KEY (tenant_id, product_variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_qty CHECK (quantity > 0);');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_amounts CHECK (unit_price_minor >= 0 AND subtotal_minor >= 0 AND discount_minor >= 0 AND total_minor >= 0);');

        // 3. Sale Item Modifiers
        Schema::create('sale_item_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('sale_id');
            $table->uuid('sale_item_id');
            $table->uuid('modifier_id')->nullable();
            $table->string('modifier_name_snapshot', 255);
            $table->string('modifier_group_name_snapshot', 255);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_sale_item_mods_tenant_id_id');
            $table->index(['tenant_id', 'sale_item_id']);
        });

        DB::statement('ALTER TABLE sale_item_modifiers ADD CONSTRAINT fk_sale_mods_sale_tenant FOREIGN KEY (tenant_id, sale_id) REFERENCES sales (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE sale_item_modifiers ADD CONSTRAINT fk_sale_mods_item_tenant FOREIGN KEY (tenant_id, sale_item_id) REFERENCES sale_items (tenant_id, id) ON DELETE CASCADE;');

        // 4. Sale Consumptions (COGS and Inventory Provenance Snapshot)
        Schema::create('sale_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('sale_id');
            $table->uuid('sale_item_id');
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->decimal('consumed_quantity', 15, 4);
            $table->bigInteger('unit_cost_minor'); // Captured MWA at finalization
            $table->bigInteger('total_cost_minor'); // ROUND(consumed_quantity × unit_cost_minor)
            $table->uuid('inventory_movement_id');
            $table->jsonb('recipe_component_snapshot')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_sale_consumptions_tenant_id_id');
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['tenant_id', 'sale_item_id']);
            $table->index(['tenant_id', 'inventory_item_id', 'location_id']);
            $table->index(['tenant_id', 'inventory_movement_id']);
        });

        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT fk_sale_consump_sale_tenant FOREIGN KEY (tenant_id, sale_id) REFERENCES sales (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT fk_sale_consump_item_tenant FOREIGN KEY (tenant_id, sale_item_id) REFERENCES sale_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT fk_sale_consump_inv_tenant FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT fk_sale_consump_loc_tenant FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT fk_sale_consump_mov_tenant FOREIGN KEY (tenant_id, inventory_movement_id) REFERENCES inventory_movements (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT chk_sale_consump_qty CHECK (consumed_quantity > 0);');
        DB::statement('ALTER TABLE sale_consumptions ADD CONSTRAINT chk_sale_consump_cost CHECK (unit_cost_minor >= 0 AND total_cost_minor >= 0);');

        // PostgreSQL Row-Level Security (RLS)
        $tables = ['sales', 'sale_items', 'sale_item_modifiers', 'sale_consumptions'];
        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("
                CREATE POLICY {$t}_tenant_isolation_policy ON {$t}
                    FOR ALL
                    USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                    WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['sale_consumptions', 'sale_item_modifiers', 'sale_items', 'sales'];
        foreach ($tables as $t) {
            DB::statement("DROP POLICY IF EXISTS {$t}_tenant_isolation_policy ON {$t};");
            Schema::dropIfExists($t);
        }
    }
};
