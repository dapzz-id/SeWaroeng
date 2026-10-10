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
        // 0. Add composite unique constraint to outlets so composite foreign keys can reference it
        DB::statement('ALTER TABLE outlets ADD CONSTRAINT outlets_tenant_id_id_unique UNIQUE (tenant_id, id);');

        // 1. Categories Table
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();

            // Self-referencing check constraint and composite foreign key
            $table->unique(['tenant_id', 'id'], 'uq_categories_tenant_id_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'parent_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT chk_categories_not_self_parent CHECK (parent_id IS NULL OR parent_id != id);');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT fk_categories_parent_tenant FOREIGN KEY (tenant_id, parent_id) REFERENCES categories (tenant_id, id) ON DELETE SET NULL;');
        DB::statement('CREATE UNIQUE INDEX uq_categories_tenant_slug ON categories (tenant_id, slug) WHERE slug IS NOT NULL;');

        // 2. Products Table
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('category_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255)->nullable();
            $table->string('sku', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('product_type', 50)->default('product'); // product, service, bundle
            $table->string('status', 20)->default('active'); // active, inactive, archived
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_products_tenant_id_id');
            $table->index(['tenant_id', 'category_id', 'status']);
            $table->index(['tenant_id', 'name']);
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT fk_products_category_tenant FOREIGN KEY (tenant_id, category_id) REFERENCES categories (tenant_id, id) ON DELETE SET NULL;');
        DB::statement('CREATE UNIQUE INDEX uq_products_tenant_slug ON products (tenant_id, slug) WHERE slug IS NOT NULL;');
        DB::statement('CREATE UNIQUE INDEX uq_products_tenant_sku ON products (tenant_id, sku) WHERE sku IS NOT NULL;');

        // 3. Product Variants Table
        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->string('name', 255);
            $table->string('sku', 100)->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_product_variants_tenant_id_id');
            $table->index(['tenant_id', 'product_id', 'sort_order']);
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT fk_variants_product_tenant FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('CREATE UNIQUE INDEX uq_product_variants_tenant_sku ON product_variants (tenant_id, sku) WHERE sku IS NOT NULL;');
        DB::statement('CREATE UNIQUE INDEX idx_product_variants_one_default ON product_variants (product_id) WHERE is_default = true;');

        // 4. Product Barcodes Table
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('variant_id');
            $table->string('barcode', 100);
            $table->string('type', 50)->default('code128');
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_product_barcodes_tenant_id_id');
            $table->unique(['tenant_id', 'barcode'], 'uq_product_barcodes_tenant_barcode');
            $table->index(['tenant_id', 'variant_id']);
        });

        DB::statement('ALTER TABLE product_barcodes ADD CONSTRAINT fk_barcodes_variant_tenant FOREIGN KEY (tenant_id, variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE CASCADE;');

        // 5. Prices Table
        Schema::create('prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('variant_id');
            $table->uuid('outlet_id')->nullable();
            $table->bigInteger('amount'); // Minor unit integer e.g. IDR 18000
            $table->string('currency', 3)->default('IDR');
            $table->timestampTz('effective_from')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestampTz('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_prices_tenant_id_id');
            $table->index(['tenant_id', 'variant_id', 'outlet_id', 'is_active']);
        });

        DB::statement('ALTER TABLE prices ADD CONSTRAINT fk_prices_variant_tenant FOREIGN KEY (tenant_id, variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE prices ADD CONSTRAINT fk_prices_outlet_tenant FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE prices ADD CONSTRAINT chk_prices_amount_positive CHECK (amount >= 0);');
        DB::statement('ALTER TABLE prices ADD CONSTRAINT chk_prices_effective_dates CHECK (effective_until IS NULL OR effective_from <= effective_until);');

        // Database-level overlap prevention using PostgreSQL exclusion constraints (requires btree_gist)
        DB::statement("
            ALTER TABLE prices ADD CONSTRAINT no_overlapping_default_prices
            EXCLUDE USING gist (
                tenant_id WITH =,
                variant_id WITH =,
                tstzrange(effective_from, coalesce(effective_until, 'infinity'::timestamptz), '[)') WITH &&
            ) WHERE (is_active = true AND outlet_id IS NULL);
        ");

        DB::statement("
            ALTER TABLE prices ADD CONSTRAINT no_overlapping_outlet_prices
            EXCLUDE USING gist (
                tenant_id WITH =,
                variant_id WITH =,
                outlet_id WITH =,
                tstzrange(effective_from, coalesce(effective_until, 'infinity'::timestamptz), '[)') WITH &&
            ) WHERE (is_active = true AND outlet_id IS NOT NULL);
        ");

        // 6. Product Outlet Availabilities Table
        Schema::create('product_outlet_availabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->uuid('outlet_id');
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'outlet_id'], 'uq_prod_outlet_avail_composite');
        });

        DB::statement('ALTER TABLE product_outlet_availabilities ADD CONSTRAINT fk_avail_product_tenant FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE product_outlet_availabilities ADD CONSTRAINT fk_avail_outlet_tenant FOREIGN KEY (tenant_id, outlet_id) REFERENCES outlets (tenant_id, id) ON DELETE CASCADE;');

        // 7. Modifier Groups Table
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('selection_mode', 50)->default('single'); // single, multiple
            $table->integer('min_selection')->default(0);
            $table->integer('max_selection')->default(1);
            $table->boolean('is_required')->default(false);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_modifier_groups_tenant_id_id');
            $table->index(['tenant_id', 'status']);
        });

        DB::statement('ALTER TABLE modifier_groups ADD CONSTRAINT chk_mod_group_selections CHECK (min_selection >= 0 AND max_selection >= min_selection);');

        // 8. Modifiers Table
        Schema::create('modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('modifier_group_id');
            $table->string('name', 255);
            $table->bigInteger('price_delta')->default(0);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_modifiers_tenant_id_id');
            $table->index(['tenant_id', 'modifier_group_id', 'status']);
        });

        DB::statement('ALTER TABLE modifiers ADD CONSTRAINT fk_modifiers_group_tenant FOREIGN KEY (tenant_id, modifier_group_id) REFERENCES modifier_groups (tenant_id, id) ON DELETE CASCADE;');

        // 9. Product Modifier Groups Table (Pivot)
        Schema::create('product_modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->uuid('modifier_group_id');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'modifier_group_id'], 'uq_prod_mod_group_composite');
        });

        DB::statement('ALTER TABLE product_modifier_groups ADD CONSTRAINT fk_prod_mod_product_tenant FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id) ON DELETE CASCADE;');
        DB::statement('ALTER TABLE product_modifier_groups ADD CONSTRAINT fk_prod_mod_group_tenant FOREIGN KEY (tenant_id, modifier_group_id) REFERENCES modifier_groups (tenant_id, id) ON DELETE CASCADE;');

        // 10. Enable and FORCE Row-Level Security on all 9 catalog tables
        $catalogTables = [
            'categories',
            'products',
            'product_variants',
            'product_barcodes',
            'prices',
            'product_outlet_availabilities',
            'modifier_groups',
            'modifiers',
            'product_modifier_groups',
        ];

        foreach ($catalogTables as $table) {
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
        $catalogTables = [
            'product_modifier_groups',
            'modifiers',
            'modifier_groups',
            'product_outlet_availabilities',
            'prices',
            'product_barcodes',
            'product_variants',
            'products',
            'categories',
        ];

        foreach ($catalogTables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation_policy ON {$table};");
            Schema::dropIfExists($table);
        }

        DB::statement('ALTER TABLE outlets DROP CONSTRAINT IF EXISTS outlets_tenant_id_id_unique;');
    }
};
