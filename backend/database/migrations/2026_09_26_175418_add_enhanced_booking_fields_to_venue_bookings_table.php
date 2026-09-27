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
        Schema::table('venue_bookings', function (Blueprint $table) {
            // Package type selection
            $table->enum('package_type', ['hall_only', 'hall_food'])->nullable()->after('duration_type_id');

            // Pricing details
            $table->unsignedInteger('per_plate_price')->nullable()->after('package_type')->comment('Actual per plate price used (editable from starting price)');
            $table->unsignedInteger('hall_charge_used')->nullable()->after('per_plate_price')->comment('Actual hall charge applied based on hall type');
            $table->unsignedInteger('service_charge_pct')->default(10)->after('hall_charge_used')->comment('Service charge percentage (default 10%)');

            // Service options
            $table->boolean('byod_selected')->default(false)->after('catering_by_hotel')->comment('Customer chose BYOD option');
            $table->boolean('dj_required')->default(true)->after('byod_selected')->comment('DJ service required (included in charge)');

            // Advance payment tracking
            $table->unsignedInteger('advance_payment')->default(0)->after('deposit_due')->comment('Advance payment amount');
            $table->timestamp('advance_paid_at')->nullable()->after('advance_payment')->comment('When advance payment was made');
            $table->string('advance_payment_method')->nullable()->after('advance_paid_at')->comment('Method used for advance payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'package_type',
                'per_plate_price',
                'hall_charge_used',
                'service_charge_pct',
                'byod_selected',
                'dj_required',
                'advance_payment',
                'advance_paid_at',
                'advance_payment_method',
            ]);
        });
    }
};
