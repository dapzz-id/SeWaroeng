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
     * Model A (Historical Cost Provenance Only):
     * Removes misleading remaining_quantity from inventory_cost_layers.
     * inventory_cost_layers is strictly an immutable historical provenance record of inbound receipts.
     * MWA (inventory_cost_states) is the sole valuation authority.
     * Physical stock is tracked in inventory_movements & inventory_item_locations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_cost_layers DROP CONSTRAINT IF EXISTS chk_cost_layers_remaining_non_negative;');
        DB::statement('ALTER TABLE inventory_cost_layers DROP CONSTRAINT IF EXISTS chk_cost_layers_remaining_lte_qty;');

        Schema::table('inventory_cost_layers', function (Blueprint $table) {
            $table->dropColumn('remaining_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_cost_layers', function (Blueprint $table) {
            $table->decimal('remaining_quantity', 15, 4)->default(0);
        });

        DB::statement('UPDATE inventory_cost_layers SET remaining_quantity = quantity WHERE remaining_quantity = 0;');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT chk_cost_layers_remaining_non_negative CHECK (remaining_quantity >= 0);');
        DB::statement('ALTER TABLE inventory_cost_layers ADD CONSTRAINT chk_cost_layers_remaining_lte_qty CHECK (remaining_quantity <= quantity);');
    }
};
