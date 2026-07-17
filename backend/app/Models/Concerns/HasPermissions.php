<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Layered RBAC: a user's effective permissions are COMPUTED, never copied.
 *
 *   Effective = (permissions of every active assigned role) + allow overrides − deny overrides
 *
 * Roles are the bulk control; per-user allow/deny overrides are the exception
 * layer. Editing a role flows to its users immediately (no re-sync).
 */
trait HasPermissions
{
    /**
     * Per-user exception layer (allow/deny). Not the source of truth on its own.
     */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission_overrides')
            ->withPivot(['type', 'granted_by', 'granted_at']);
    }

    public function cachedPermissionNames(): Collection
    {
        return Cache::remember(
            $this->permissionCacheKey(),
            now()->addHour(),
            fn () => $this->computeEffectivePermissionNames(),
        );
    }

    /**
     * Resolve the live effective permission set from roles + overrides.
     */
    public function computeEffectivePermissionNames(): Collection
    {
        $rolePermissions = DB::table('role_permissions as rp')
            ->join('user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $this->id)
            ->where('r.is_active', true)
            ->whereNull('r.deleted_at')
            ->pluck('p.name');

        [$allows, $denies] = $this->overrideNames();

        return $rolePermissions
            ->merge($allows)
            ->unique()
            ->diff($denies)
            ->values();
    }

    /**
     * Per-permission provenance for auditing in the UI.
     *
     * @return array{roles: array<string, array<int, string>>, allow: array<int, string>, deny: array<int, string>, effective: array<int, string>}
     */
    public function permissionSources(): array
    {
        $byRole = DB::table('role_permissions as rp')
            ->join('user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $this->id)
            ->where('r.is_active', true)
            ->whereNull('r.deleted_at')
            ->get(['p.name as permission', 'r.name as role']);

        $roles = [];
        foreach ($byRole as $row) {
            $roles[$row->permission][] = $row->role;
        }

        [$allows, $denies] = $this->overrideNames();

        return [
            'roles' => $roles,
            'allow' => $allows->values()->all(),
            'deny' => $denies->values()->all(),
            'effective' => $this->computeEffectivePermissionNames()->all(),
        ];
    }

    public function hasPermissionTo(string $permission): bool
    {
        if ($this->isFullAdmin()) {
            return true;
        }

        return $this->cachedPermissionNames()->contains($permission);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isFullAdmin()) {
            return true;
        }

        $cached = $this->cachedPermissionNames();

        foreach ($permissions as $permission) {
            if ($cached->contains($permission)) {
                return true;
            }
        }

        return false;
    }

    public function flushPermissionCache(): void
    {
        Cache::forget($this->permissionCacheKey());
        Cache::forget($this->fullAdminCacheKey());
    }

    /**
     * @return array{0: Collection<int, string>, 1: Collection<int, string>}
     */
    private function overrideNames(): array
    {
        $rows = DB::table('user_permission_overrides as o')
            ->join('permissions as p', 'p.id', '=', 'o.permission_id')
            ->where('o.user_id', $this->id)
            ->get(['p.name', 'o.type']);

        return [
            $rows->where('type', 'allow')->pluck('name'),
            $rows->where('type', 'deny')->pluck('name'),
        ];
    }

    private function permissionCacheKey(): string
    {
        return "user:{$this->id}:permissions";
    }

    private function fullAdminCacheKey(): string
    {
        return "user:{$this->id}:full_admin";
    }
}
