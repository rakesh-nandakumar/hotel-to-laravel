<?php

namespace Database\Seeders;

use App\Models\CentralAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A default platform-operator login for local development — mirrors
 * AdminUsersSeeder's demo tenant-side accounts, skipped in production for the
 * same reason.
 */
class CentralAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('CentralAdminSeeder skipped in production.');

            return;
        }

        CentralAdmin::updateOrCreate(
            ['email' => 'platform@vellix.com'],
            [
                'name' => 'Platform Operator',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );
    }
}
