<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_menu_categories', function (Blueprint $table) {
            // Nullable — most existing categories don't need station routing;
            // KOT.tsx only shows the station filter once at least one is set.
            $table->foreignId('kitchen_station_id')->nullable()->after('is_minibar')->constrained('lookups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kitchen_station_id');
        });
    }
};
