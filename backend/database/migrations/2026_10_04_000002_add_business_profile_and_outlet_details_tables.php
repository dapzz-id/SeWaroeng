<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('business_type');
            $table->string('email')->nullable()->after('phone');
            $table->text('description')->nullable()->after('email');
            $table->string('address_line')->nullable()->after('description');
            $table->string('city')->nullable()->after('address_line');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('province');
            $table->string('country_code', 2)->default('ID')->after('postal_code');
            $table->decimal('latitude', 10, 8)->nullable()->after('country_code');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->string('currency', 3)->default('IDR')->after('longitude');
            $table->string('timezone', 50)->default('Asia/Jakarta')->after('currency');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->string('address_line')->nullable()->after('address');
            $table->string('city')->nullable()->after('address_line');
            $table->string('province')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('province');
            $table->decimal('latitude', 10, 8)->nullable()->after('postal_code');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->string('timezone', 50)->nullable()->after('longitude');
            $table->jsonb('operating_hours')->nullable()->after('timezone');
            $table->jsonb('settings')->nullable()->after('operating_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn([
                'address_line',
                'city',
                'province',
                'postal_code',
                'latitude',
                'longitude',
                'timezone',
                'operating_hours',
                'settings',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'email',
                'description',
                'address_line',
                'city',
                'province',
                'postal_code',
                'country_code',
                'latitude',
                'longitude',
                'currency',
                'timezone',
            ]);
        });
    }
};
