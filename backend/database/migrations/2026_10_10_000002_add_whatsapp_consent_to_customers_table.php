<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Extends customers table to support WhatsApp communication preferences,
     * opt-out enforcement, and auditable consent history without duplicate tables.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false)->after('marketing_opt_in');
            $table->boolean('whatsapp_opted_out')->default(false)->after('whatsapp_opt_in');
            $table->timestampTz('whatsapp_opted_out_at')->nullable()->after('whatsapp_opted_out');
            $table->jsonb('whatsapp_consent_history')->nullable()->after('whatsapp_opted_out_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_opt_in',
                'whatsapp_opted_out',
                'whatsapp_opted_out_at',
                'whatsapp_consent_history',
            ]);
        });
    }
};
