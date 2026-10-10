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
        // 1. Add default_pos_location_id to outlets table
        Schema::table('outlets', function (Blueprint $table) {
            $table->uuid('default_pos_location_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'default_pos_location_id'], 'idx_outlets_pos_location');
        });

        // PostgreSQL Composite FK for tenant-safe default POS location reference
        DB::statement('ALTER TABLE outlets ADD CONSTRAINT fk_outlets_pos_location_tenant FOREIGN KEY (tenant_id, default_pos_location_id) REFERENCES inventory_locations (tenant_id, id) ON DELETE SET NULL;');

        // 2. Add production_mode to inventory_items table
        // 'stocked' (Mode A): Pre-produced / stocked; consumed directly from inventory.
        // 'make_to_order' (Mode B): Assembled / produced on order; exploded to child components.
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('production_mode', 30)->default('stocked')->after('item_type');
            $table->index(['tenant_id', 'production_mode'], 'idx_inv_items_production_mode');
        });

        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT chk_inv_items_production_mode CHECK (production_mode IN ('stocked', 'make_to_order'));");

        // 3. Add consumption_mode to recipe_components table (optional override per recipe component)
        Schema::table('recipe_components', function (Blueprint $table) {
            $table->string('consumption_mode', 30)->nullable()->after('component_type');
        });

        DB::statement("ALTER TABLE recipe_components ADD CONSTRAINT chk_recipe_comp_consumption_mode CHECK (consumption_mode IS NULL OR consumption_mode IN ('stocked', 'make_to_order'));");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE recipe_components DROP CONSTRAINT IF EXISTS chk_recipe_comp_consumption_mode;');
        Schema::table('recipe_components', function (Blueprint $table) {
            $table->dropColumn('consumption_mode');
        });

        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS chk_inv_items_production_mode;');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex('idx_inv_items_production_mode');
            $table->dropColumn('production_mode');
        });

        DB::statement('ALTER TABLE outlets DROP CONSTRAINT IF EXISTS fk_outlets_pos_location_tenant;');
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropIndex('idx_outlets_pos_location');
            $table->dropColumn('default_pos_location_id');
        });
    }
};
