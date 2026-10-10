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
        // 1. Loyalty Programs (One per tenant)
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('status', 30)->default('active'); // active, inactive
            $table->unsignedInteger('points_per_currency')->default(1);
            $table->unsignedBigInteger('earn_unit_amount_minor')->default(100000); // Rp 1.000 in minor units = 1 point
            $table->unsignedBigInteger('minimum_earn_amount_minor')->default(1000000); // Rp 10.000 minimum spend to earn points
            $table->unsignedBigInteger('redemption_value_minor')->default(10000); // Rp 100 per 1 point redeemed
            $table->string('rounding_policy', 20)->default('floor'); // floor, round, ceil
            $table->unsignedInteger('expiration_days')->nullable(); // null = never expire
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique('tenant_id', 'loyalty_programs_tenant_unique');
        });

        // 2. Loyalty Tiers (Bronze, Silver, Gold, VIP, etc.)
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedInteger('rank')->default(1); // 1 = lowest/base tier
            $table->unsignedBigInteger('min_points_earned')->default(0); // threshold to reach tier
            $table->unsignedInteger('multiplier_bps')->default(10000); // 10000 bps = 1.0x earn multiplier
            $table->jsonb('perks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'loyalty_program_id', 'rank'], 'loyalty_tiers_program_rank_unique');
            $table->unique(['tenant_id', 'loyalty_program_id', 'name'], 'loyalty_tiers_program_name_unique');
        });

        // 3. Customer Loyalty Accounts
        Schema::create('customer_loyalty_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->foreignUuid('tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->bigInteger('current_balance')->default(0); // Read model cache, source-of-truth is ledger
            $table->unsignedBigInteger('lifetime_earned')->default(0);
            $table->unsignedBigInteger('lifetime_redeemed')->default(0);
            $table->string('status', 30)->default('active'); // active, suspended, closed
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id'], 'customer_loyalty_accounts_tenant_customer_unique');
            $table->index(['tenant_id', 'loyalty_program_id', 'status']);
        });

        // 4. Immutable Point Ledgers
        Schema::create('loyalty_point_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->foreignUuid('loyalty_account_id')->constrained('customer_loyalty_accounts')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 30); // purchase, redemption, campaign, referral, birthday, adjustment, expiration, reversal
            $table->bigInteger('points'); // positive = earn, negative = deduction
            $table->bigInteger('balance_after');
            $table->string('reference_type', 100)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'customer_id', 'created_at'], 'loyalty_ledgers_tenant_cust_created_idx');
            $table->index(['tenant_id', 'loyalty_account_id', 'created_at'], 'loyalty_ledgers_tenant_acct_created_idx');
        });

        // Add PostgreSQL partial unique indexes for idempotency and duplicate earn prevention
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX loyalty_ledgers_tenant_reference_type_unique
                ON loyalty_point_ledgers (tenant_id, reference_type, reference_id, type)
                WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL
            ');

            DB::statement('
                CREATE UNIQUE INDEX loyalty_ledgers_tenant_idempotency_unique
                ON loyalty_point_ledgers (tenant_id, idempotency_key)
                WHERE idempotency_key IS NOT NULL
            ');
        } else {
            // Fallback for sqlite / testing environments
            Schema::table('loyalty_point_ledgers', function (Blueprint $table) {
                $table->index(['tenant_id', 'reference_type', 'reference_id', 'type'], 'loyalty_ledgers_reference_idx');
                $table->index(['tenant_id', 'idempotency_key'], 'loyalty_ledgers_idempotency_idx');
            });
        }

        // 5. Loyalty Rewards (Catalog of redeemable perks / vouchers)
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('points_cost');
            $table->string('reward_type', 30)->default('discount_fixed'); // discount_fixed, discount_percent, free_item
            $table->unsignedBigInteger('discount_amount_minor')->default(0);
            $table->unsignedInteger('discount_percent')->nullable();
            $table->unsignedBigInteger('min_purchase_minor')->default(0);
            $table->integer('stock')->nullable(); // null = unlimited stock
            $table->unsignedInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'loyalty_program_id', 'is_active']);
        });

        // 6. Loyalty Vouchers (Issued vouchers owned by customers)
        Schema::create('loyalty_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('reward_id')->constrained('loyalty_rewards')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('status', 30)->default('active'); // active, used, expired, cancelled
            $table->unsignedBigInteger('discount_amount_minor')->default(0);
            $table->unsignedInteger('discount_percent')->nullable();
            $table->unsignedBigInteger('min_purchase_minor')->default(0);
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('used_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'loyalty_vouchers_tenant_code_unique');
            $table->index(['tenant_id', 'customer_id', 'status']);
        });

        // PostgreSQL Row-Level Security (RLS) enforcement
        if (DB::getDriverName() === 'pgsql') {
            $loyaltyTables = [
                'loyalty_programs',
                'loyalty_tiers',
                'customer_loyalty_accounts',
                'loyalty_point_ledgers',
                'loyalty_rewards',
                'loyalty_vouchers',
            ];

            foreach ($loyaltyTables as $t) {
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
            $loyaltyTables = [
                'loyalty_vouchers',
                'loyalty_rewards',
                'loyalty_point_ledgers',
                'customer_loyalty_accounts',
                'loyalty_tiers',
                'loyalty_programs',
            ];

            foreach ($loyaltyTables as $t) {
                DB::statement("DROP POLICY IF EXISTS {$t}_tenant_isolation_policy ON {$t}");
            }
        }

        Schema::dropIfExists('loyalty_vouchers');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_point_ledgers');
        Schema::dropIfExists('customer_loyalty_accounts');
        Schema::dropIfExists('loyalty_tiers');
        Schema::dropIfExists('loyalty_programs');
    }
};
