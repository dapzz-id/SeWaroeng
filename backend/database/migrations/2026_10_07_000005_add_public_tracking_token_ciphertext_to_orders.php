<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Moves encrypted tracking credential out of generic metadata into dedicated column.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('public_tracking_token_ciphertext')->nullable()->after('public_tracking_token_hash');
        });

        // Migrate any existing encrypted tokens from metadata to dedicated column
        $legacyOrders = DB::table('orders')
            ->whereNotNull('metadata')
            ->get(['id', 'metadata']);

        foreach ($legacyOrders as $order) {
            $metadata = json_decode((string) $order->metadata, true);
            if (is_array($metadata) && ! empty($metadata['encrypted_tracking_token'])) {
                $ciphertext = $metadata['encrypted_tracking_token'];
                unset($metadata['encrypted_tracking_token']);
                DB::table('orders')->where('id', $order->id)->update([
                    'public_tracking_token_ciphertext' => $ciphertext,
                    'metadata' => json_encode($metadata),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('public_tracking_token_ciphertext');
        });
    }
};
