<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Safely migrates plaintext tables.qr_token to sha256 qr_token_hash.
     */
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->string('qr_token_hash', 64)->nullable()->unique()->after('status');
        });

        // Migrate existing plaintext tokens if any exist
        $existingTables = DB::table('tables')->whereNotNull('qr_token')->get(['id', 'qr_token']);
        foreach ($existingTables as $t) {
            DB::table('tables')->where('id', $t->id)->update([
                'qr_token_hash' => hash('sha256', (string) $t->qr_token),
            ]);
        }

        Schema::table('tables', function (Blueprint $table) {
            $table->dropColumn('qr_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->string('qr_token', 64)->nullable()->unique()->after('status');
            $table->dropColumn('qr_token_hash');
        });
    }
};
