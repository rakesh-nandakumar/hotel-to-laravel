<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CentralAdminSeeder::class,
            MenuSeeder::class,
            PermissionsAndRolesSeeder::class,
            TenantModuleSeeder::class,
            AdminUsersSeeder::class,
            LookupSeeder::class,
            SettingsSeeder::class,
            HotelRoomsSeeder::class,
            TillSeeder::class,
        ]);
    }
}
