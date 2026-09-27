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
        Schema::table('venues', function (Blueprint $table) {
            // Hall type classification
            $table->enum('hall_type', ['luxury', 'basic'])->nullable()->after('name');

            // Package pricing structure
            $table->unsignedInteger('luxury_hall_charge')->default(45000)->after('full_day_rate')->comment('Fixed charge for luxury hall bookings');
            $table->unsignedInteger('basic_hall_charge')->default(40000)->after('luxury_hall_charge')->comment('Fixed charge for basic hall bookings');
            $table->unsignedInteger('per_plate_starting_price')->default(1950)->after('basic_hall_charge')->comment('Starting price per plate for hall+food (editable)');
            $table->unsignedInteger('hall_only_per_person')->default(500)->after('per_plate_starting_price')->comment('Per person charge for hall-only bookings');

            // Service inclusions
            $table->boolean('dj_included')->default(true)->after('hall_only_per_person')->comment('DJ service included in hall charge');
            $table->boolean('bar_charges_included')->default(true)->after('dj_included')->comment('Bar charges included in hall charge');
            $table->boolean('byod_allowed')->default(true)->after('bar_charges_included')->comment('Bring Your Own Drink allowed');

            // Pricing model flag
            $table->boolean('use_package_pricing')->default(false)->after('byod_allowed')->comment('Use new package pricing model vs legacy hourly/half-day/full-day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn([
                'hall_type',
                'luxury_hall_charge',
                'basic_hall_charge',
                'per_plate_starting_price',
                'hall_only_per_person',
                'dj_included',
                'bar_charges_included',
                'byod_allowed',
                'use_package_pricing',
            ]);
        });
    }
};
