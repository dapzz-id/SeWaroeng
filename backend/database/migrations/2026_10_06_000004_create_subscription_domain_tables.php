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
     * Phase 1J: Subscription Foundation
     * - Platform Catalog: subscription_plans, subscription_plan_entitlements, subscription_add_ons (No Tenant RLS)
     * - Tenant Scoped: subscriptions, subscription_events, subscription_add_on_assignments (PostgreSQL RLS with platform override)
     */
    public function up(): void
    {
        // 1. Platform-scoped Plan Catalog
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->bigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->string('billing_interval', 20)->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // 2. Platform-scoped Plan Entitlements & Quotas
        Schema::create('subscription_plan_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('feature_key', 100);
            $table->boolean('is_enabled')->default(true);
            $table->integer('quota_limit')->nullable(); // NULL = unlimited
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key'], 'uq_plan_feature');
            $table->index(['plan_id', 'is_enabled']);
        });

        // 3. Platform-scoped Add-on Catalog
        Schema::create('subscription_add_ons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->bigInteger('price_minor')->default(0);
            $table->string('feature_key', 100)->nullable();
            $table->integer('quota_increment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // 4. Tenant-scoped Subscriptions
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('status', 20)->default('trialing'); // trialing, active, past_due, paused, cancelled, expired
            $table->timestampTz('starts_at');
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('billing_provider', 50)->default('manual');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_subscriptions_tenant_id_id');
            $table->index(['tenant_id', 'status'], 'idx_subscriptions_tenant_status');
        });

        // 5. Tenant-scoped Subscription Events (Audit Trail)
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('from_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignUuid('to_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_subscription_events_tenant_id_id');
            $table->index(['tenant_id', 'subscription_id'], 'idx_subscription_events_tenant_sub');
            $table->index(['tenant_id', 'created_at'], 'idx_subscription_events_tenant_created');
        });

        // 6. Tenant-scoped Add-on Assignments
        Schema::create('subscription_add_on_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('add_on_id')->constrained('subscription_add_ons')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_sub_addon_assign_tenant_id_id');
            $table->index(['tenant_id', 'subscription_id'], 'idx_sub_addon_assign_tenant_sub');
        });

        // PostgreSQL Row-Level Security (RLS) on tenant-scoped tables
        DB::statement('ALTER TABLE subscriptions ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE subscriptions FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY subscriptions_tenant_isolation_policy ON subscriptions
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

        DB::statement('ALTER TABLE subscription_events ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE subscription_events FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY subscription_events_tenant_isolation_policy ON subscription_events
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

        DB::statement('ALTER TABLE subscription_add_on_assignments ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE subscription_add_on_assignments FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY subscription_addon_assignments_isolation_policy ON subscription_add_on_assignments
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS subscription_addon_assignments_isolation_policy ON subscription_add_on_assignments;');
        DB::statement('DROP POLICY IF EXISTS subscription_events_tenant_isolation_policy ON subscription_events;');
        DB::statement('DROP POLICY IF EXISTS subscriptions_tenant_isolation_policy ON subscriptions;');

        Schema::dropIfExists('subscription_add_on_assignments');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_add_ons');
        Schema::dropIfExists('subscription_plan_entitlements');
        Schema::dropIfExists('subscription_plans');
    }
};
