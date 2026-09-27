<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The subset of DatabaseSeeder safe to replay on its own from the /seed/menus
 * deploy route (DeployController) when only MenuDefinition or
 * SystemRoleDefinition changed — skips users/lookups/settings/demo data.
 * Order matters: PermissionsAndRolesSeeder derives its permission set from
 * the menu items MenuSeeder just wrote.
 */
class MenusAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MenuSeeder::class,
            PermissionsAndRolesSeeder::class,
        ]);
    }
}
