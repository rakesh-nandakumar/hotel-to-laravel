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
            $table->string('service_charge_on')->default('both')->after('service_charge_pct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->dropColumn('service_charge_on');
        });
    }
};
