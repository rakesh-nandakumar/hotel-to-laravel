<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentContext;
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
            $this->seedSystemRoles(Tenant::demo()->id);
        });

        // A cross-tenant sweep (every user holding a role, in any tenant) —
        // explicitly unscoped: this seeder is console-only setup work.
        app(CurrentContext::class)->runWithoutTenant(function (): void {
            User::query()->whereNotNull('role_id')->lazy()->each(function (User $user): void {
                $user->flushPermissionCache();
            });
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

    /**
     * Seeds the baseline system roles (Full Administrator, Manager, ...) for
     * one tenant. Each tenant gets its own independent Role rows — editing
     * one tenant's "Manager" must never touch another's — so this is called
     * both for the demo tenant here and for every newly-provisioned tenant
     * (see App\Services\TenantProvisioning).
     */
    public function seedSystemRoles(int $tenantId): void
    {
        // Role rows are tenant-scoped, and a seeder runs in console with no
        // ambient tenant — bind the tenant explicitly so every query inside
        // behaves exactly like the tenant's own app would (see TenantScope).
        app(CurrentContext::class)->runForTenant($tenantId, function () use ($tenantId): void {
            foreach (SystemRoleDefinition::roles() as $name => $config) {
                /** @var Role $role */
                $role = Role::firstOrNew(['tenant_id' => $tenantId, 'name' => $name]);
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
        });
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
