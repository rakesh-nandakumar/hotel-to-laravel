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
            $table->dropColumn('advance_payment_method');
            $table->json('advance_payment_method')->nullable()->after('advance_paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->dropColumn('advance_payment_method');
            $table->string('advance_payment_method')->nullable()->after('advance_paid_at');
        });
    }
};
