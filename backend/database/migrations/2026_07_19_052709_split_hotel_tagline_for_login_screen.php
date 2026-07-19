<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar and login screen used to share one `hotel.tagline` value.
 * Splits them so each can be edited independently: `hotel.tagline` stays the
 * sidebar's (relabelled for clarity) and a new `hotel.login_tagline` is
 * seeded from the same current value, so already-configured sites keep
 * showing exactly what they show today until an admin changes one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'hotel.tagline')->update([
            'label' => 'Sidebar Tagline / Short Description',
            'hint' => 'The small line shown under the hotel name in the sidebar.',
        ]);

        if (! DB::table('settings')->where('key', 'hotel.login_tagline')->exists()) {
            $current = DB::table('settings')->where('key', 'hotel.tagline')->value('value')
                ?? json_encode('Hospitality Management System');

            DB::table('settings')->insert([
                'key' => 'hotel.login_tagline',
                'value' => $current,
                'type' => 'text',
                'category' => 'hotel',
                'label' => 'Login Screen Tagline / Short Description',
                'hint' => 'The small line shown under the hotel name on the login screen.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hotel.login_tagline')->delete();

        DB::table('settings')->where('key', 'hotel.tagline')->update([
            'label' => 'Tagline / Short Description',
            'hint' => 'The small line shown under the hotel name on the login screen and sidebar.',
        ]);
    }
};
