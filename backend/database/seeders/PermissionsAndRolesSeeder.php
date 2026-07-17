<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Menu\SystemRoleDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PermissionsAndRolesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->derivePermissionsFromMenu();
            $this->seedSystemRoles();
        });

        User::query()->whereNotNull('role_id')->lazy()->each(function (User $user): void {
            $user->flushPermissionCache();
        });
    }

    private function derivePermissionsFromMenu(): void
    {
        $names = MenuItem::query()
            ->whereNotNull('module_key')
            ->get()
            ->flatMap(fn (MenuItem $item) => $item->permissionNames())
            ->unique();

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // Permissions that no longer correspond to a menu action (renamed or
        // removed from MenuDefinition) are stale — drop them along with their
        // role/user grants (cascade-deleted via FK) so they can't linger as
        // unusable, ungoverned access in the permissions UI.
        Permission::query()
            ->whereNotIn('name', $names)
            ->get()
            ->each(fn (Permission $permission) => $permission->forceDelete());
    }

    private function seedSystemRoles(): void
    {
        foreach (SystemRoleDefinition::roles() as $name => $config) {
            /** @var Role $role */
            $role = Role::firstOrNew(['name' => $name]);
            $role->fill([
                'description' => $config['description'] ?? null,
                'is_system' => true,
                'is_full_admin' => $config['is_full_admin'] ?? false,
                'is_active' => true,
            ])->save();

            $permissionNames = $this->resolveGrants($config['permissions'] ?? []);

            $existing = Permission::query()->whereIn('name', $permissionNames)->pluck('name')->all();
            $missing = array_diff($permissionNames, $existing);

            if (! empty($missing)) {
                throw new RuntimeException(
                    "System role '{$name}' references unknown permissions: "
                    .implode(', ', $missing)
                    .'. Check MenuDefinition module_keys against SystemRoleDefinition.'
                );
            }

            $permissionIds = Permission::query()->whereIn('name', $permissionNames)->pluck('id')->all();
            $role->permissions()->sync($permissionIds);
        }
    }

    /**
     * @param  string|array<string, array<int, string>>  $grants
     * @return array<int, string>
     */
    private function resolveGrants(string|array $grants): array
    {
        if ($grants === 'all') {
            return Permission::query()->pluck('name')->all();
        }

        $resolved = [];
        foreach ((array) $grants as $moduleKey => $actions) {
            foreach ((array) $actions as $action) {
                $resolved[] = "{$moduleKey}.{$action}";
            }
        }

        return $resolved;
    }
}
