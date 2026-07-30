<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
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

        // Every demo account belongs to the shared demo tenant — see
        // UserFactory's identical rationale (App\Http\Middleware\IdentifyTenant's
        // cross-tenant guard would otherwise log these accounts straight back
        // out on the very next request after login).
        $tenantId = Tenant::demo()->id;

        $admin = User::updateOrCreate(
            ['tenant_id' => $tenantId, 'email' => 'admin@vellix.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ],
        );
        $this->assignRole($admin, 'Full Administrator', $tenantId);

        $manager = User::updateOrCreate(
            ['tenant_id' => $tenantId, 'email' => 'manager@vellix.lk'],
            [
                'name' => 'Operations Manager',
                'password' => Hash::make('password'),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ],
        );
        $this->assignRole($manager, 'Manager', $tenantId);

        // One account per remaining operational role — useful for manual testing/demo
        // and for automated E2E coverage of role-gated behavior (Playwright logs in as each).
        foreach ([
            'owner@vellix.lk' => ['Owner Account', 'Owner'],
            'housekeeper@vellix.lk' => ['Housekeeping Staff', 'Housekeeper'],
            'chef@vellix.lk' => ['Head Chef', 'Chef'],
            'security@vellix.lk' => ['Security Officer', 'Security'],
        ] as $email => [$name, $roleName]) {
            $user = User::updateOrCreate(
                ['tenant_id' => $tenantId, 'email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                ],
            );
            $this->assignRole($user, $roleName, $tenantId);
        }
    }

    private function assignRole(User $user, string $roleName, int $tenantId): void
    {
        $role = Role::query()->withoutTenantScope()->where('tenant_id', $tenantId)->where('name', $roleName)->firstOrFail();

        // Multi-role: keep role_id as the primary for display, assign via the pivot.
        $user->update(['role_id' => $role->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->flushPermissionCache();
    }
}
