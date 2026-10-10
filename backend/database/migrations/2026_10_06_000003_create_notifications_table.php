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
     * Creates Phase 1H notifications table with PostgreSQL RLS and deduplication index.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50); // low_stock, shift_variance, payment_status, payment_failed
            $table->string('title', 255);
            $table->text('message');
            $table->string('severity', 20)->default('info'); // info, warning, critical
            $table->jsonb('data')->nullable();
            $table->string('dedup_key', 255)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_notifications_tenant_id_id');
            $table->index(['tenant_id', 'recipient_user_id', 'read_at'], 'idx_notifications_tenant_recipient_read');
            $table->index(['tenant_id', 'recipient_user_id', 'resolved_at'], 'idx_notifications_tenant_recipient_resolved');
            $table->index(['tenant_id', 'created_at'], 'idx_notifications_tenant_created');
            $table->index(['tenant_id', 'type'], 'idx_notifications_tenant_type');
        });

        // Partial unique index for idempotent active event deduplication
        DB::statement('CREATE UNIQUE INDEX uq_notifications_tenant_dedup ON notifications (tenant_id, dedup_key) WHERE dedup_key IS NOT NULL AND resolved_at IS NULL;');

        // PostgreSQL Row-Level Security (RLS) Policies
        DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;');
        DB::statement('ALTER TABLE notifications FORCE ROW LEVEL SECURITY;');
        DB::statement("
            CREATE POLICY notification_tenant_isolation_policy ON notifications
            FOR ALL
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid);
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS notification_tenant_isolation_policy ON notifications;');
        Schema::dropIfExists('notifications');
    }
};
