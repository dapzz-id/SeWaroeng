<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update check constraint to include 'processing'
        DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT IF EXISTS chk_pay_refunds_status;');
        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT chk_pay_refunds_status CHECK (status IN ('pending', 'processing', 'completed', 'failed'));");

        // 2. Add bank_confirmed_at and metadata columns
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->timestampTz('bank_confirmed_at')->nullable()->after('completed_at');
            $table->jsonb('metadata')->nullable()->after('bank_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropColumn(['bank_confirmed_at', 'metadata']);
        });

        DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT IF EXISTS chk_pay_refunds_status;');
        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT chk_pay_refunds_status CHECK (status IN ('pending', 'completed', 'failed'));");
    }
};
