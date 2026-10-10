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
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('request_fingerprint', 64);
            $table->string('status', 20)->default('processing'); // processing, completed, failed
            $table->integer('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // Unique constraint for tenant-scoped operations
        DB::statement('
            CREATE UNIQUE INDEX unique_tenant_idempotency_key 
            ON idempotency_records (tenant_id, idempotency_key) 
            WHERE tenant_id IS NOT NULL;
        ');

        // Unique constraint for global/unscoped operations
        DB::statement('
            CREATE UNIQUE INDEX unique_global_idempotency_key 
            ON idempotency_records (idempotency_key) 
            WHERE tenant_id IS NULL;
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
