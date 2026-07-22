<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MenuSeeder::class,
            PermissionsAndRolesSeeder::class,
            PlatformAdminSeeder::class,
            TenantSeeder::class,
            LookupSeeder::class,
            SettingsSeeder::class,
            HotelRoomsSeeder::class,
        ]);
    }
}
