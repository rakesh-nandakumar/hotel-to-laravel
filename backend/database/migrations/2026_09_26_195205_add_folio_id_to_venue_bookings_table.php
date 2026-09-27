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
            $table->foreignId('folio_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venue_bookings', function (Blueprint $table) {
            $table->dropForeign(['folio_id']);
            $table->dropColumn('folio_id');
        });
    }
};
