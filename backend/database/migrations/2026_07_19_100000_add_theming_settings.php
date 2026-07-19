<?php

use App\Support\Lookups\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the "Theming" sub-section under Settings → Hotel identity: three base
 * colors the whole UI's brand/sidebar palettes are generated from at runtime
 * (see web/src/lib/branding.tsx and tailwind.config.js). Defaults exactly
 * match the previously-hardcoded design (tailwind.config.js's old literal
 * hex values), so nothing changes visually until an admin picks new colors.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'key' => 'theme.primary',
                'value' => json_encode('#0462d3'),
                'type' => SettingType::COLOR,
                'category' => 'hotel',
                'label' => 'Primary color',
                'hint' => 'Buttons, links, focus rings and active states across the whole app.',
            ],
            [
                'key' => 'theme.secondary',
                'value' => json_encode('#3783f0'),
                'type' => SettingType::COLOR,
                'category' => 'hotel',
                'label' => 'Secondary color',
                'hint' => 'Accent highlight for the active menu item in the sidebar.',
            ],
            [
                'key' => 'theme.sidebar',
                'value' => json_encode('#0c182a'),
                'type' => SettingType::COLOR,
                'category' => 'hotel',
                'label' => 'Sidebar color',
                'hint' => 'Base color the sidebar\'s dark background, borders and text are shaded from.',
            ],
        ];

        foreach ($rows as $row) {
            if (! DB::table('settings')->where('key', $row['key'])->exists()) {
                DB::table('settings')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['theme.primary', 'theme.secondary', 'theme.sidebar'])->delete();
    }
};
