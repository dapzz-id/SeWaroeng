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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('status')->default('active')->after('is_active');
            $table->index(['status', 'created_at']);
        });

        // Backfill slug from code for any existing tenants
        DB::statement('UPDATE tenants SET slug = code WHERE slug IS NULL');

        Schema::table('outlets', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_active');
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn('status');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'status']);
        });
    }
};
