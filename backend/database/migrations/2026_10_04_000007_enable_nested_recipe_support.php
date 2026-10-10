<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop existing variant foreign key & unique constraint to allow nullable variant
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS fk_recipes_variant_tenant;');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS uq_recipes_tenant_variant;');

        // 2. Modify recipes table columns
        Schema::table('recipes', function (Blueprint $table) {
            $table->uuid('product_variant_id')->nullable()->change();
            $table->uuid('inventory_item_id')->nullable()->after('product_variant_id');
        });

        // 3. Add foreign key for inventory_items (tenant-isolated composite FK)
        DB::statement('
            ALTER TABLE recipes
            ADD CONSTRAINT fk_recipes_variant_tenant
            FOREIGN KEY (tenant_id, product_variant_id)
            REFERENCES product_variants (tenant_id, id)
            ON DELETE RESTRICT;
        ');

        DB::statement('
            ALTER TABLE recipes
            ADD CONSTRAINT fk_recipes_item_tenant
            FOREIGN KEY (tenant_id, inventory_item_id)
            REFERENCES inventory_items (tenant_id, id)
            ON DELETE RESTRICT;
        ');

        // 4. Mutual exclusivity check: Recipe must belong to EITHER a product variant OR an inventory item
        DB::statement('
            ALTER TABLE recipes
            ADD CONSTRAINT chk_recipes_owner_exclusive
            CHECK (
                (product_variant_id IS NOT NULL AND inventory_item_id IS NULL) OR
                (product_variant_id IS NULL AND inventory_item_id IS NOT NULL)
            );
        ');

        // 5. Unique partial indexes per tenant for variant and inventory item
        DB::statement('
            CREATE UNIQUE INDEX uq_recipes_tenant_variant
            ON recipes (tenant_id, product_variant_id)
            WHERE product_variant_id IS NOT NULL AND deleted_at IS NULL;
        ');

        DB::statement('
            CREATE UNIQUE INDEX uq_recipes_tenant_item
            ON recipes (tenant_id, inventory_item_id)
            WHERE inventory_item_id IS NOT NULL AND deleted_at IS NULL;
        ');

        DB::statement('
            CREATE INDEX idx_recipes_tenant_item
            ON recipes (tenant_id, inventory_item_id);
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_recipes_tenant_item;');
        DB::statement('DROP INDEX IF EXISTS uq_recipes_tenant_item;');
        DB::statement('DROP INDEX IF EXISTS uq_recipes_tenant_variant;');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS chk_recipes_owner_exclusive;');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS fk_recipes_item_tenant;');
        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS fk_recipes_variant_tenant;');

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('inventory_item_id');
            $table->uuid('product_variant_id')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE recipes ADD CONSTRAINT fk_recipes_variant_tenant FOREIGN KEY (tenant_id, product_variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT;');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT uq_recipes_tenant_variant UNIQUE (tenant_id, product_variant_id);');
    }
};
