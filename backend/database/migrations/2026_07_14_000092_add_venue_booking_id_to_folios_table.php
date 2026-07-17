<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Deferred from Module 4 — a folio can now also belong to a venue booking. */
    public function up(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->foreignId('venue_booking_id')->nullable()->unique()->after('reservation_id')
                ->constrained('venue_bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_booking_id');
        });
    }
};
