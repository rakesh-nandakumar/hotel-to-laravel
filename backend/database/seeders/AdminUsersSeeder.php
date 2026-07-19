<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('AdminUsersSeeder skipped in production.');

            return;
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@vellix.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ],
        );
        $this->assignRole($admin, 'Full Administrator');

        $manager = User::updateOrCreate(
            ['email' => 'manager@vellix.lk'],
            [
                'name' => 'Operations Manager',
                'password' => Hash::make('password'),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ],
        );
        $this->assignRole($manager, 'Manager');

        // One account per remaining operational role — useful for manual testing/demo
        // and for automated E2E coverage of role-gated behavior (Playwright logs in as each).
        foreach ([
            'owner@vellix.lk' => ['Owner Account', 'Owner'],
            'housekeeper@vellix.lk' => ['Housekeeping Staff', 'Housekeeper'],
            'chef@vellix.lk' => ['Head Chef', 'Chef'],
            'security@vellix.lk' => ['Security Officer', 'Security'],
        ] as $email => [$name, $roleName]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );
            $this->assignRole($user, $roleName);
        }
    }

    private function assignRole(User $user, string $roleName): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        // Multi-role: keep role_id as the primary for display, assign via the pivot.
        $user->update(['role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->flushPermissionCache();
    }
}
