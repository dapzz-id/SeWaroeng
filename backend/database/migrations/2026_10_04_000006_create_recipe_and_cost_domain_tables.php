<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. RECIPES
        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_variant_id');
            $table->string('name', 255);
            $table->string('code', 100)->nullable();
            $table->string('status', 20)->default('draft');
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'uq_recipes_tenant_id_id');
            $table->unique(['tenant_id', 'product_variant_id'], 'uq_recipes_tenant_variant');
            $table->index(['tenant_id', 'status'], 'idx_recipes_tenant_status');
        });

        DB::statement('ALTER TABLE recipes ADD CONSTRAINT fk_recipes_variant_tenant FOREIGN KEY (tenant_id, product_variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('CREATE UNIQUE INDEX uq_recipes_tenant_code ON recipes (tenant_id, code) WHERE code IS NOT NULL;');
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT chk_recipes_status CHECK (status IN ('draft', 'published', 'archived'));");

        // 2. RECIPE VERSIONS
        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('recipe_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->decimal('yield_quantity', 15, 4);
            $table->string('yield_unit', 30);
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->uuid('published_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_recipe_versions_tenant_id_id');
            $table->unique(['tenant_id', 'recipe_id', 'version_number'], 'uq_recipe_versions_number');
            $table->index(['tenant_id', 'recipe_id', 'status'], 'idx_recipe_versions_status');
            $table->index(['tenant_id', 'recipe_id', 'effective_from'], 'idx_recipe_versions_effective');
        });

        DB::statement('ALTER TABLE recipe_versions ADD CONSTRAINT fk_recipe_versions_recipe_tenant FOREIGN KEY (tenant_id, recipe_id) REFERENCES recipes (tenant_id, id) ON DELETE CASCADE;');
        DB::statement("ALTER TABLE recipe_versions ADD CONSTRAINT chk_recipe_versions_status CHECK (status IN ('draft', 'published', 'archived'));");
        DB::statement('ALTER TABLE recipe_versions ADD CONSTRAINT chk_recipe_versions_yield_positive CHECK (yield_quantity > 0);');
        DB::statement('ALTER TABLE recipe_versions ADD CONSTRAINT chk_recipe_versions_effective_order CHECK (effective_until IS NULL OR effective_until > effective_from);');

        // Exclusion constraint: no two published versions may overlap for the same recipe.
        // Reuses btree_gist already installed in Phase 1B.2.
        DB::statement("
            ALTER TABLE recipe_versions
            ADD CONSTRAINT excl_recipe_versions_no_overlap
            EXCLUDE USING GIST (
                recipe_id WITH =,
                tenant_id WITH =,
                tstzrange(effective_from, COALESCE(effective_until, 'infinity'::timestamptz), '[)') WITH &&
            )
            WHERE (status = 'published');
        ");

        // 3. RECIPE COMPONENTS
        Schema::create('recipe_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('recipe_version_id');
            $table->uuid('inventory_item_id');
            $table->decimal('quantity', 15, 4);
            $table->string('unit', 30);
            $table->string('component_type', 30)->default('raw_material');
            $table->decimal('waste_factor', 5, 4)->default('0.0000');
            $table->unsignedSmallInteger('sequence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_recipe_components_tenant_id_id');
            $table->unique(['tenant_id', 'recipe_version_id', 'inventory_item_id'], 'uq_recipe_component_scope');
            $table->index(['tenant_id', 'inventory_item_id'], 'idx_recipe_components_item');
        });

        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT fk_recipe_components_version_tenant FOREIGN KEY (tenant_id, recipe_version_id) REFERENCES recipe_versions (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT fk_recipe_components_item_tenant FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT chk_recipe_components_qty_positive CHECK (quantity > 0);');
        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT chk_recipe_components_waste_factor CHECK (waste_factor >= 0 AND waste_factor < 1);');
        DB::statement("ALTER TABLE recipe_components ADD CONSTRAINT chk_recipe_components_type CHECK (component_type IN ('raw_material', 'packaging', 'semi_finished', 'finished_good'));");

        // 4. INVENTORY COST LAYERS — immutable historical cost records
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->uuid('source_movement_id');
            $table->string('source_type', 40);
            $table->string('source_reference', 100)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('remaining_quantity', 15, 4);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('total_cost_minor');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_cost_layers_tenant_id_id');
            $table->unique(['tenant_id', 'source_movement_id'], 'uq_cost_layers_movement');
            $table->index(['tenant_id', 'inventory_item_id', 'location_id', 'created_at'], 'idx_cost_layers_item_loc');
            $table->index(['tenant_id', 'source_type', 'created_at'], 'idx_cost_layers_source_type');
        });

        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT fk_cost_layers_item_tenant FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT fk_cost_layers_location_tenant FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT fk_cost_layers_movement_tenant FOREIGN KEY (tenant_id, source_movement_id) REFERENCES inventory_movements (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT chk_cost_layers_qty_positive CHECK (quantity > 0);');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT chk_cost_layers_remaining_non_negative CHECK (remaining_quantity >= 0);');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT chk_cost_layers_remaining_lte_qty CHECK (remaining_quantity <= quantity);');
        DB::statement("ALTER TABLE inventory_cost_layers ADD CONSTRAINT chk_cost_layers_source_type CHECK (source_type IN ('purchase_receipt', 'transfer_in', 'adjustment_in', 'opening_balance', 'stock_opname'));");

        // 5. INVENTORY COST STATES — materialized MWA per (tenant, item, location)
        Schema::create('inventory_cost_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->unsignedBigInteger('average_cost_minor')->default(0);
            $table->uuid('last_movement_id')->nullable();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['tenant_id', 'id'], 'uq_cost_states_tenant_id_id');
            $table->unique(['tenant_id', 'inventory_item_id', 'location_id'], 'uq_cost_states_scope');
        });

        DB::statement('ALTER TABLE inventory_cost_states ADD CONSTRAINT fk_cost_states_item_tenant FOREIGN KEY (tenant_id, inventory_item_id) REFERENCES inventory_items (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE inventory_cost_states ADD CONSTRAINT fk_cost_states_location_tenant FOREIGN KEY (tenant_id, location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE CASCADE;');

        // 6. RLS
        $tables = ['recipes', 'recipe_versions', 'recipe_components', 'inventory_cost_layers', 'inventory_cost_states'];
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;");
            DB::statement("
                DO \$\$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_policies WHERE tablename = '{$table}' AND policyname = '{$table}_tenant_isolation_policy'
                    ) THEN
                        CREATE POLICY {$table}_tenant_isolation_policy ON {$table}
                            FOR ALL
                            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
                            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
                    END IF;
                END \$\$;
            ");
        }
    }

    public function down(): void
    {
        $tables = ['inventory_cost_states', 'inventory_cost_layers', 'recipe_components', 'recipe_versions', 'recipes'];
        foreach ($tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation_policy ON {$table};");
            Schema::dropIfExists($table);
        }
    }
};
