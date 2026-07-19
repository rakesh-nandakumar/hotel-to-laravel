<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu item thumbnail, shown in the Point of Sale item grid. Stored inline as
 * a data URI (same approach as `hotel.logo_url` — see
 * 2026_07_18_053420_add_hotel_branding_settings.php) rather than a file on
 * the `public` disk, for the same reason: no separate file host needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->longText('image')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
