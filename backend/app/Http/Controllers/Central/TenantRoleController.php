<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\CentralAdmin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\PermissionMatrixBuilder;
use App\Services\TenantModules;
use App\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Per-tenant role administration from master control — the platform operator's
 * "Roles" tab. Mirrors UserManagement\RoleController (the tenant's own editor)
 * but is scoped to the tenant in the URL and, because a platform operator
 * holds every permission themselves, guards against tenant licensing instead
 * of against the actor's own permission set: a role can never be granted a
 * permission whose module the tenant isn't licensed for.
 */
class TenantRoleController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $roles = Role::query()
            ->where('tenant_id', $tenant->id)
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->row($role));

        return response()->json(['roles' => $roles]);
    }

    public function create(Tenant $tenant): JsonResponse
    {
        return response()->json($this->formProps($tenant));
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $this->validated($request, $tenant);
        $operator = $request->user('central');

        $this->assertPermissionsLicensed($tenant, $data['permissions'] ?? []);

        $role = DB::transaction(function () use ($tenant, $data, $operator) {
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_full_admin' => false,
                'is_active' => $data['is_active'],
            ]);

            $role->permissions()->sync($this->permissionIds($data['permissions'] ?? []));

            AuditLog::record('role.created', $role, [
                'name' => $role->name,
                'permissions_count' => count($data['permissions'] ?? []),
                'permissions' => $data['permissions'] ?? [],
                'operator_email' => $operator->email,
            ], description: $this->operatorDescription($operator, "created a new role \"{$role->name}\""));

            return $role;
        });

        return response()->json([
            'message' => "Role \"{$role->name}\" created for {$tenant->name}.",
            'role' => $this->row($role),
        ], 201);
    }

    public function show(Tenant $tenant, Role $role): JsonResponse
    {
        $this->assertOwnedBy($tenant, $role);
        $role->load('permissions:id,name');

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_full_admin' => $role->is_full_admin,
                'is_active' => $role->is_active,
                'permissions' => $role->permissions->pluck('name')->all(),
                'assigned_user_count' => $this->assignedUserCount($tenant, $role),
            ],
        ]);
    }

    public function edit(Tenant $tenant, Role $role): JsonResponse
    {
        $this->assertOwnedBy($tenant, $role);
        $role->load('permissions:id,name');

        return response()->json(array_merge($this->formProps($tenant), [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_full_admin' => $role->is_full_admin,
                'is_active' => $role->is_active,
                'permissions' => $role->permissions->pluck('name')->all(),
                'assigned_user_count' => $this->assignedUserCount($tenant, $role),
            ],
        ]));
    }

    public function update(Request $request, Tenant $tenant, Role $role): JsonResponse
    {
        $this->assertOwnedBy($tenant, $role);
        $data = $this->validated($request, $tenant, $role);
        $operator = $request->user('central');

        $this->assertPermissionsLicensed($tenant, $data['permissions'] ?? []);

        DB::transaction(function () use ($tenant, $data, $role, $operator) {
            $oldPermissions = $role->permissions()->pluck('name')->all();
            $newPermissions = $data['permissions'] ?? [];

            $role->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $role->is_full_admin ? true : $data['is_active'],
            ])->save();

            if (! $role->is_full_admin) {
                $role->permissions()->sync($this->permissionIds($newPermissions));
            }

            $this->flushRoleMembers($tenant, $role);

            AuditLog::record('role.updated', $role, [
                'name' => $role->name,
                'added' => array_values(array_diff($newPermissions, $oldPermissions)),
                'removed' => array_values(array_diff($oldPermissions, $newPermissions)),
                'operator_email' => $operator->email,
            ], description: $this->operatorDescription($operator, "updated role \"{$role->name}\" for {$tenant->name}"));
        });

        return response()->json(['message' => 'Role saved. All members updated automatically.']);
    }

    public function destroy(Request $request, Tenant $tenant, Role $role): JsonResponse
    {
        $this->assertOwnedBy($tenant, $role);

        if ($role->is_system) {
            abort(422, 'System roles cannot be deleted.');
        }

        if ($this->assignedUserCount($tenant, $role) > 0) {
            abort(422, "Role \"{$role->name}\" is assigned to users — delete those assignments first.");
        }

        $name = $role->name;
        $operator = $request->user('central');

        AuditLog::record('role.deleted', $role, [
            'name' => $name,
            'operator_email' => $operator->email,
        ], description: $this->operatorDescription($operator, "deleted role \"{$name}\" for {$tenant->name}"));

        $role->delete();

        return response()->json(['message' => "Role \"{$name}\" deleted."]);
    }

    public function duplicate(Request $request, Tenant $tenant, Role $role): JsonResponse
    {
        $this->assertOwnedBy($tenant, $role);
        $operator = $request->user('central');

        $copy = DB::transaction(function () use ($tenant, $role, $operator) {
            $copy = $role->replicate(['is_system']);
            $copy->tenant_id = $tenant->id;
            $copy->name = $this->uniqueCopyName($tenant, $role->name);
            $copy->is_system = false;
            $copy->is_full_admin = false;
            $copy->save();

            $copy->permissions()->sync($role->permissions()->pluck('id')->all());

            AuditLog::record('role.duplicated', $copy, [
                'name' => $copy->name,
                'source' => $role->name,
                'source_role_id' => $role->id,
                'operator_email' => $operator->email,
            ], description: $this->operatorDescription($operator, "duplicated role \"{$role->name}\" as \"{$copy->name}\""));

            return $copy;
        });

        return response()->json([
            'message' => "Role duplicated as \"{$copy->name}\".",
            'role' => $this->row($copy),
        ], 201);
    }

    public function toggleActive(Request $request, Tenant $tenant, Role $role): JsonResponse
    {
        $this->assertOwnedBy($tenant, $role);

        if ($role->is_full_admin) {
            abort(422, 'The Full Administrator role cannot be deactivated.');
        }

        $role->update(['is_active' => ! $role->is_active]);

        $this->flushRoleMembers($tenant, $role);

        $operator = $request->user('central');
        AuditLog::record('role.toggled_active', $role, [
            'is_active' => $role->is_active,
            'operator_email' => $operator->email,
        ], description: $this->operatorDescription(
            $operator,
            ($role->is_active ? 'activated' : 'deactivated')." role \"{$role->name}\" for {$tenant->name}",
        ));

        return response()->json([
            'message' => $role->is_active ? 'Role activated.' : 'Role deactivated.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(Tenant $tenant): array
    {
        return array_merge(app(PermissionMatrixBuilder::class)->for($tenant), [
            'grantable_permissions' => null, // platform operators may grant anything licensed
            'is_full_admin' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Tenant $tenant, ?Role $role = null): array
    {
        $unique = Rule::unique('roles', 'name')->where('tenant_id', $tenant->id);
        if ($role !== null) {
            $unique = $unique->ignore($role->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120', $unique],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function assertPermissionsLicensed(Tenant $tenant, array $permissionNames): void
    {
        $enabledKeys = TenantModules::enabledKeysFor($tenant->id);

        foreach ($permissionNames as $name) {
            $catalogKey = ModuleCatalog::catalogKeyFor(Str::before($name, '.'));

            if ($catalogKey !== null && ! $enabledKeys->contains($catalogKey)) {
                abort(403, "You cannot assign permission \"{$name}\" — that module is not enabled for {$tenant->name}.");
            }
        }
    }

    /**
     * @param  array<int, string>  $permissionNames
     * @return array<int, int>
     */
    private function permissionIds(array $permissionNames): array
    {
        return Permission::query()->whereIn('name', $permissionNames)->pluck('id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_full_admin' => $role->is_full_admin,
            'is_active' => $role->is_active,
            'users_count' => $role->users_count,
            'permissions_count' => $role->permissions_count,
            'updated_at' => $role->updated_at,
        ];
    }

    private function assignedUserCount(Tenant $tenant, Role $role): int
    {
        return $role->users()->count()
            + User::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
                ->count();
    }

    /**
     * Invalidate the permission cache for every user of this tenant holding
     * the role (primary `role_id` column and many-to-many assignments alike).
     */
    private function flushRoleMembers(Tenant $tenant, Role $role): void
    {
        $role->users()->get()->each(fn (User $user) => $user->flushPermissionCache());

        User::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->get()
            ->each(fn (User $user) => $user->flushPermissionCache());
    }

    private function uniqueCopyName(Tenant $tenant, string $baseName): string
    {
        $candidate = "Copy of {$baseName}";
        $n = 1;

        while (Role::query()->where('tenant_id', $tenant->id)->where('name', $candidate)->exists()) {
            $n++;
            $candidate = "Copy of {$baseName} ({$n})";
        }

        return $candidate;
    }

    private function assertOwnedBy(Tenant $tenant, Role $role): void
    {
        abort_if($role->tenant_id !== $tenant->id, 404);
    }

    private function operatorDescription(CentralAdmin $operator, string $action): string
    {
        return "Platform operator {$operator->email} {$action}.";
    }
}
