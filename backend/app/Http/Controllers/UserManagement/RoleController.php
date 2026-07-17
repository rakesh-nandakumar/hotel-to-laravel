<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\StoreRoleRequest;
use App\Http\Requests\UserManagement\UpdateRoleRequest;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $sortable = ['name', 'permissions_count', 'users_count', 'is_active'];
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
        if (! in_array($sort, $sortable, true)) {
            $sort = 'name';
            $direction = 'asc';
        }

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->when($request->string('search')->toString(), function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
            })
            ->when($request->string('state')->toString(), fn ($q, $state) => $q->where('is_active', $state === 'active'))
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_full_admin' => $role->is_full_admin,
                'is_active' => $role->is_active,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'updated_at' => $role->updated_at,
            ]);

        return response()->json([
            'roles' => $roles,
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
                'state' => $request->string('state')->toString() ?: null,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', Role::class);

        return response()->json($this->formProps());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $this->assertActorHoldsPermissions($data['permissions'] ?? [], $actor);

        $role = DB::transaction(function () use ($data, $actor) {
            $role = Role::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_full_admin' => false,
                'is_active' => $data['is_active'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $permissionIds = Permission::query()
                ->whereIn('name', $data['permissions'] ?? [])
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);

            AuditLog::record('role.created', $role, [
                'name' => $role->name,
                'permissions_count' => count($data['permissions'] ?? []),
                'permissions' => $data['permissions'] ?? [],
            ]);

            return $role;
        });

        return response()->json([
            'message' => "Role \"{$role->name}\" created.",
            'role' => $role,
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

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
                'assigned_user_count' => $role->assignedUserCount(),
            ],
        ]);
    }

    public function edit(Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $role->load('permissions:id,name');

        return response()->json(array_merge($this->formProps(), [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_full_admin' => $role->is_full_admin,
                'is_active' => $role->is_active,
                'permissions' => $role->permissions->pluck('name')->all(),
                'assigned_user_count' => $role->assignedUserCount(),
            ],
        ]));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $data = $request->validated();
        $actor = $request->user();

        $this->assertActorHoldsPermissions($data['permissions'] ?? [], $actor);

        DB::transaction(function () use ($data, $role, $actor) {
            $oldPermissions = $role->permissions()->pluck('name')->all();
            $newPermissions = $data['permissions'] ?? [];

            $role->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $role->is_full_admin ? true : $data['is_active'],
                'updated_by' => $actor->id,
            ])->save();

            if (! $role->is_full_admin) {
                $permissionIds = Permission::query()->whereIn('name', $newPermissions)->pluck('id')->all();
                $role->permissions()->sync($permissionIds);
            }

            // Permissions are computed, so the change flows to every member at once —
            // just invalidate their caches.
            $this->flushRoleMembers($role);

            AuditLog::record('role.updated', $role, [
                'added' => array_values(array_diff($newPermissions, $oldPermissions)),
                'removed' => array_values(array_diff($oldPermissions, $newPermissions)),
            ]);
        });

        return response()->json(['message' => 'Role saved. All members updated automatically.']);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $name = $role->name;
        AuditLog::record('role.deleted', $role, ['name' => $name]);
        $role->delete();

        return response()->json(['message' => "Role \"{$name}\" deleted."]);
    }

    public function duplicate(Role $role): JsonResponse
    {
        $this->authorize('duplicate', $role);

        $copy = DB::transaction(function () use ($role) {
            $copy = $role->replicate(['is_system']);
            $copy->name = $this->uniqueCopyName($role->name);
            $copy->is_system = false;
            $copy->is_full_admin = false;
            $copy->created_by = auth()->id();
            $copy->updated_by = auth()->id();
            $copy->save();

            $copy->permissions()->sync($role->permissions()->pluck('id')->all());

            AuditLog::record('role.duplicated', $copy, [
                'name' => $copy->name,
                'source' => $role->name,
                'source_role_id' => $role->id,
            ]);

            return $copy;
        });

        return response()->json([
            'message' => "Role duplicated as \"{$copy->name}\".",
            'role' => $copy,
        ], 201);
    }

    public function toggleActive(Role $role): JsonResponse
    {
        $this->authorize('toggleActive', $role);

        $role->update(['is_active' => ! $role->is_active, 'updated_by' => auth()->id()]);

        // Activating/deactivating a role changes every member's effective permissions.
        $this->flushRoleMembers($role);

        AuditLog::record('role.toggled_active', $role, ['is_active' => $role->is_active]);

        return response()->json([
            'message' => $role->is_active ? 'Role activated.' : 'Role deactivated.',
        ]);
    }

    /**
     * Invalidate the permission cache for every user holding this role.
     */
    private function flushRoleMembers(Role $role): void
    {
        $role->users()->get()->each(fn (User $user) => $user->flushPermissionCache());

        User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->get()
            ->each(fn (User $user) => $user->flushPermissionCache());
    }

    private function uniqueCopyName(string $baseName): string
    {
        $candidate = "Copy of {$baseName}";
        $n = 1;
        while (Role::query()->where('name', $candidate)->exists()) {
            $n++;
            $candidate = "Copy of {$baseName} ({$n})";
        }

        return $candidate;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function assertActorHoldsPermissions(array $permissionNames, User $actor): void
    {
        if ($actor->isFullAdmin()) {
            return;
        }

        $actorPermissions = $actor->cachedPermissionNames();

        foreach ($permissionNames as $name) {
            if (! $actorPermissions->contains($name)) {
                AuditLog::record('escalation.blocked', $actor, [
                    'attempted_permission' => $name,
                    'context' => 'role_edit',
                ]);
                abort(403, "You cannot assign permission \"{$name}\" — you do not hold it yourself.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(): array
    {
        $actor = auth()->user();
        $actorPermissions = $actor?->cachedPermissionNames() ?? collect();
        $isFullAdmin = $actor?->isFullAdmin() ?? false;

        $sections = MenuItem::with('children')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        $matrix = $sections->map(function (MenuItem $section) {
            $modules = $section->isGroup()
                ? $section->children
                : collect([$section]);

            return [
                'section' => $section->name,
                'modules' => $modules
                    ->filter(fn (MenuItem $m) => $m->module_key !== null)
                    ->map(fn (MenuItem $m) => [
                        'key' => $m->module_key,
                        'label' => $m->name,
                        'actions' => $m->actions ?? [],
                    ])
                    ->values()
                    ->all(),
            ];
        })->filter(fn ($s) => count($s['modules']) > 0)->values()->all();

        $allActions = collect($matrix)
            ->flatMap(fn ($s) => collect($s['modules'])->flatMap(fn ($m) => $m['actions']))
            ->unique()
            ->values()
            ->all();

        return [
            'matrix' => $matrix,
            'allActions' => $allActions,
            'grantable_permissions' => $isFullAdmin
                ? null
                : $actorPermissions->values()->all(),
            'is_full_admin' => $isFullAdmin,
        ];
    }
}
