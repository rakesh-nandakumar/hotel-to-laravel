<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('user_management_roles.access');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermissionTo('user_management_roles.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('user_management_roles.create');
    }

    public function update(User $actor, Role $role): bool
    {
        if (! $actor->hasPermissionTo('user_management_roles.edit')) {
            return false;
        }

        if ($role->is_full_admin && ! $actor->isFullAdmin()) {
            return false;
        }

        return true;
    }

    public function delete(User $actor, Role $role): bool
    {
        if (! $actor->hasPermissionTo('user_management_roles.delete')) {
            return false;
        }

        if ($role->is_system) {
            return false;
        }

        if ($role->users()->count() > 0) {
            return false;
        }

        return true;
    }

    public function duplicate(User $actor, Role $role): bool
    {
        if (! $actor->hasPermissionTo('user_management_roles.duplicate')) {
            return false;
        }

        // Duplicating a full-admin role would mint a regular role carrying its
        // entire permission set — only a full admin may do that.
        if ($role->is_full_admin && ! $actor->isFullAdmin()) {
            return false;
        }

        return true;
    }

    public function toggleActive(User $actor, Role $role): bool
    {
        if (! $actor->hasPermissionTo('user_management_roles.toggle_active')) {
            return false;
        }

        if ($role->is_full_admin) {
            return false;
        }

        return true;
    }
}
