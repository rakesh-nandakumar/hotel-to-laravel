<?php

use App\Support\Lookups\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the site identity fully configurable: repurposes the free-text
 * "Logo URL" into an uploadable image (stored inline as a data URI), adds a
 * "Tagline / Short Description" setting, and widens `settings.value` so a
 * base64 logo fits (MySQL TEXT caps at 64KB). Additive & safe to run on an
 * already-seeded database — an admin-edited tagline is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->longText('value')->nullable()->change();
        });

        DB::table('settings')->where('key', 'hotel.logo_url')->update([
            'type' => SettingType::IMAGE,
            'label' => 'Logo',
            'hint' => 'Shown in the sidebar, on the login screen and printed documents. Drag & drop, paste, or choose an image.',
        ]);

        DB::table('settings')->where('key', 'hotel.name')->update([
            'hint' => 'Shown in the sidebar, login screen, printed documents and guest pages.',
        ]);

        if (! DB::table('settings')->where('key', 'hotel.tagline')->exists()) {
            DB::table('settings')->insert([
                'key' => 'hotel.tagline',
                'value' => json_encode('Hospitality Management System'),
                'type' => SettingType::TEXT,
                'category' => 'hotel',
                'label' => 'Tagline / Short Description',
                'hint' => 'The small line shown under the hotel name on the login screen and sidebar.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hotel.tagline')->delete();

        DB::table('settings')->where('key', 'hotel.logo_url')->update([
            'type' => SettingType::TEXT,
            'label' => 'Logo URL',
            'hint' => null,
        ]);

        Schema::table('settings', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });
    }
};
