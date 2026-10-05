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
            $table->json('venue_ids')->nullable()->after('venue_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->dropColumn('venue_ids');
        });
    }
};
