<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\BulkActionRequest;
use App\Http\Requests\UserManagement\StoreUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserManagementUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $sortable = ['name', 'email', 'status', 'last_login_at'];
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
        if (! in_array($sort, $sortable, true)) {
            $sort = 'name';
            $direction = 'asc';
        }

        $users = User::query()
            ->with('roles:id,name')
            ->when($request->string('search')->toString(), function ($q, $term) {
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($request->integer('role_id'), fn ($q, $id) => $q->whereHas('roles', fn ($r) => $r->where('roles.id', $id)))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString();

        $roles = Role::query()->select('id', 'name')->orderBy('name')->get();

        return response()->json([
            'users' => $users,
            'roles' => $roles,
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
                'role_id' => $request->integer('role_id') ?: null,
                'status' => $request->string('status')->toString() ?: null,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', User::class);

        return response()->json($this->formProps());
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        return DB::transaction(function () use ($data, $actor) {
            $permissions = $data['permissions'] ?? [];
            $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));

            $this->assertNoEscalation($permissions, $roleIds, $actor);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'],
                'role_id' => $roleIds[0] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'email_verified_at' => now(),
                // Admin-provisioned credential: force a change at first login.
                'must_change_password' => true,
                'two_factor_required' => (bool) ($data['two_factor_required'] ?? false),
            ]);

            $overrides = $this->applyRolesAndOverrides($user, $roleIds, $permissions, $actor);

            AuditLog::record('user.created', $user, [
                'roles' => $this->roleNames($roleIds),
                'allow_overrides' => $overrides['allow'],
                'deny_overrides' => $overrides['deny'],
            ]);

            return response()->json([
                'message' => "User \"{$user->name}\" created.",
                'user' => $user,
            ], 201);
        });
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load([
            'roles:id,name',
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
            ],
            // Per-permission provenance for the audit view.
            'permission_sources' => $user->permissionSources(),
        ]);
    }

    public function edit(User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->load([
            'roles:id,name',
        ]);

        return response()->json(array_merge($this->formProps(), [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'role_ids' => $user->roles->pluck('id')->all(),
                'locked_until' => $user->locked_until,
                'two_factor_required' => (bool) $user->two_factor_required,
                // The matrix edits the *effective* set; the backend re-derives overrides.
                'permissions' => $user->computeEffectivePermissionNames()->all(),
                'two_factor_confirmed' => $user->two_factor_confirmed_at !== null,
            ],
        ]));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $actor = $request->user();

        return DB::transaction(function () use ($data, $user, $actor) {
            $permissions = $data['permissions'] ?? [];
            $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));

            $this->assertNoEscalation($permissions, $roleIds, $actor);

            $changes = [];
            $original = $user->only(['name', 'email', 'phone', 'status']);
            $oldRoles = $user->roles()->pluck('name')->all();

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => $data['status'],
                'role_id' => $roleIds[0] ?? null,
                'updated_by' => $actor->id,
                'two_factor_required' => (bool) ($data['two_factor_required'] ?? $user->two_factor_required),
            ]);

            if (! empty($data['password'])) {
                // Admin-set credential: force a change at next login.
                $user->password = Hash::make($data['password']);
                $user->must_change_password = true;
            }

            $user->save();

            $overrides = $this->applyRolesAndOverrides($user, $roleIds, $permissions, $actor);

            foreach ($original as $field => $oldValue) {
                $newValue = $user->{$field};
                if ($oldValue !== $newValue) {
                    $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
                }
            }

            $newRoles = $this->roleNames($roleIds);
            if ($oldRoles !== $newRoles) {
                $changes['roles'] = ['from' => $oldRoles, 'to' => $newRoles];
            }

            AuditLog::record('user.updated', $user, [
                'changes' => $changes,
                'allow_overrides' => $overrides['allow'],
                'deny_overrides' => $overrides['deny'],
            ]);

            return response()->json([
                'message' => "User \"{$user->name}\" updated.",
                'user' => $user,
            ]);
        });
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $name = $user->name;
        $user->flushPermissionCache();

        AuditLog::record('user.deleted', $user, [
            'name' => $name,
            'email' => $user->email,
        ]);

        $user->delete();

        return response()->json(['message' => "User \"{$name}\" deleted."]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update(['status' => User::STATUS_SUSPENDED, 'updated_by' => $request->user()->id]);
        $user->flushPermissionCache();

        AuditLog::record('user.suspended', $user);

        return response()->json(['message' => 'User suspended.']);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update(['status' => User::STATUS_ACTIVE, 'updated_by' => $request->user()->id]);
        $user->flushPermissionCache();

        AuditLog::record('user.reactivated', $user);

        return response()->json(['message' => 'User reactivated.']);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->update(['status' => User::STATUS_INACTIVE, 'updated_by' => $request->user()->id]);
        $user->flushPermissionCache();

        AuditLog::record('user.deactivated', $user);

        return response()->json(['message' => 'User set to inactive.']);
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $user->forceFill([
            'locked_until' => null,
            'failed_login_count' => 0,
        ])->save();

        AuditLog::record('user.unlocked', $user);

        return response()->json(['message' => 'User unlocked.']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'password' => [
                'required',
                'confirmed',
                'max:128',
                Password::min(12)->mixedCase()->numbers()->uncompromised(),
            ],
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'failed_login_count' => 0,
            'locked_until' => null,
            'must_change_password' => true,
        ])->save();

        AuditLog::record('user.password_reset_by_admin', $user);

        return response()->json(['message' => 'Password reset.']);
    }

    public function bulkAction(BulkActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $userIds = collect($data['user_ids'])
            ->reject(fn ($id) => $id === $actor->id)
            ->all();

        $targets = User::query()->whereIn('id', $userIds)->get();

        foreach ($targets as $target) {
            $ability = $data['action'] === 'delete' ? 'delete' : 'update';
            $this->authorize($ability, $target);
        }

        DB::transaction(function () use ($targets, $data, $actor) {
            foreach ($targets as $target) {
                match ($data['action']) {
                    'delete' => $this->bulkDelete($target),
                    'suspend' => $this->bulkSetStatus($target, User::STATUS_SUSPENDED, 'user.suspended', $actor),
                    'reactivate' => $this->bulkSetStatus($target, User::STATUS_ACTIVE, 'user.reactivated', $actor),
                };
            }
        });

        return response()->json(['message' => 'Bulk action applied to '.count($targets).' user(s).']);
    }

    private function bulkDelete(User $target): void
    {
        AuditLog::record('user.deleted', $target, [
            'name' => $target->name,
            'email' => $target->email,
            'via' => 'bulk',
        ]);

        $target->flushPermissionCache();
        $target->delete();
    }

    private function bulkSetStatus(User $target, string $status, string $action, User $actor): void
    {
        $target->update(['status' => $status, 'updated_by' => $actor->id]);
        $target->flushPermissionCache();

        AuditLog::record($action, $target, ['via' => 'bulk']);
    }

    /**
     * Block privilege escalation: a non-full-admin actor can only grant
     * permissions/roles they themselves hold, and never a full-admin role.
     *
     * @param  array<int, string>  $desiredPermissions  the effective set the actor is assigning
     * @param  array<int, int>  $roleIds
     */
    private function assertNoEscalation(array $desiredPermissions, array $roleIds, User $actor): void
    {
        $roles = Role::query()->whereIn('id', $roleIds)->get();

        foreach ($roles as $role) {
            if (! $role->is_active) {
                abort(422, "Role \"{$role->name}\" is inactive and cannot be assigned.");
            }
        }

        if ($actor->isFullAdmin()) {
            return;
        }

        foreach ($roles as $role) {
            if ($role->is_full_admin) {
                AuditLog::record('escalation.blocked', $actor, [
                    'attempted_role_id' => $role->id,
                    'reason' => 'full_admin_role',
                ]);
                abort(403, 'You cannot assign a full-admin role.');
            }
        }

        // The effective set the user will end up with must be a subset of the actor's.
        $actorPermissions = $actor->cachedPermissionNames();

        foreach ($desiredPermissions as $permission) {
            if (! $actorPermissions->contains($permission)) {
                AuditLog::record('escalation.blocked', $actor, [
                    'attempted_permission' => $permission,
                ]);
                abort(403, "You cannot grant permission \"{$permission}\" — you do not hold it yourself.");
            }
        }
    }

    /**
     * Assign roles and store only the minimal allow/deny exceptions that differ
     * from what the active roles already grant. Returns the override names for audit.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, string>  $desiredPermissions
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    private function applyRolesAndOverrides(User $user, array $roleIds, array $desiredPermissions, User $actor): array
    {
        $user->roles()->sync($roleIds);

        // Baseline = permissions granted by the user's *active* assigned roles.
        $activeRoleIds = Role::query()->whereIn('id', $roleIds)->where('is_active', true)->pluck('id')->all();
        $roleUnion = empty($activeRoleIds)
            ? collect()
            : Permission::query()
                ->whereIn('id', fn ($q) => $q->select('permission_id')->from('role_permissions')->whereIn('role_id', $activeRoleIds))
                ->pluck('name');

        $desired = collect($desiredPermissions)->unique();
        $allow = $desired->diff($roleUnion)->values();   // extra beyond roles
        $deny = $roleUnion->diff($desired)->values();     // removed from roles

        $idByName = Permission::query()->whereIn('name', $allow->merge($deny))->pluck('id', 'name');
        $sync = [];
        foreach ($allow as $name) {
            if (isset($idByName[$name])) {
                $sync[$idByName[$name]] = ['type' => 'allow', 'granted_by' => $actor->id, 'granted_at' => now()];
            }
        }
        foreach ($deny as $name) {
            if (isset($idByName[$name])) {
                $sync[$idByName[$name]] = ['type' => 'deny', 'granted_by' => $actor->id, 'granted_at' => now()];
            }
        }

        $user->permissionOverrides()->sync($sync);
        $user->flushPermissionCache();

        return ['allow' => $allow->all(), 'deny' => $deny->all()];
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<int, string>
     */
    private function roleNames(array $roleIds): array
    {
        return Role::query()->whereIn('id', $roleIds)->orderBy('name')->pluck('name')->all();
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

        $roles = Role::query()
            ->where('is_active', true)
            ->when(! $isFullAdmin, fn ($q) => $q->where('is_full_admin', false))
            ->select('id', 'name', 'is_full_admin', 'description')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        // Map role_id → permission names for frontend role-preview feature
        $rolePermissions = $roles->mapWithKeys(fn (Role $r) => [
            $r->id => $r->permissions->pluck('name')->values()->all(),
        ]);

        return [
            'matrix' => $matrix,
            'allActions' => $allActions,
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'is_full_admin' => $r->is_full_admin,
                'description' => $r->description,
            ])->values()->all(),
            'rolePermissions' => $rolePermissions,
            'grantable_permissions' => $isFullAdmin
                ? null
                : $actorPermissions->values()->all(),
            'is_full_admin' => $isFullAdmin,
        ];
    }
}
