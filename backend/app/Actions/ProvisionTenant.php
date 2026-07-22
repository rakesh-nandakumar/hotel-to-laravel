<?php

namespace App\Actions;

use App\Events\TenantCreated;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProvisionTenant
{
    /**
     * Set up a new tenant:
     * 1. Assign all available permissions to the tenant.
     * 2. Create the Full Administrator role.
     * 3. Create the Super Admin user.
     *
     * @return array{email: string, password: string, pin: string}
     */
    public function execute(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant) {
            // 1. Grant all platform permissions to the tenant.
            $allPermissionIds = Permission::withoutGlobalScopes()->pluck('id')->all();
            $tenant->permissions()->sync($allPermissionIds);

            // 2. Create system roles for this tenant.
            $rolesSeeder = new \Database\Seeders\PermissionsAndRolesSeeder;
            $rolesSeeder->run($tenant->id);

            $adminRole = Role::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('name', 'Full Administrator')
                ->firstOrFail();

            // 3. Create the Super Admin user.
            $password = Str::random(12);
            $pin = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            $superAdmin = User::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Super Admin',
                'email' => $tenant->email,
                'password' => Hash::make($password),
                'pin_hash' => Hash::make($pin),
                'role_id' => $adminRole->id,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]);

            // 4. Seed initial Settings and Menus for this tenant.
            $seeder = new \Database\Seeders\SettingsSeeder;
            $seeder->run($tenant->id);

            $menuSeeder = new \Database\Seeders\MenuSeeder;
            $menuSeeder->run($tenant->id);

            event(new TenantCreated($tenant));

            return [
                'email' => $superAdmin->email,
                'password' => $password,
                'pin' => $pin,
            ];
        });
    }
}
