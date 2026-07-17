<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo('user_management_users.access');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasPermissionTo('user_management_users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo('user_management_users.create');
    }

    public function update(User $actor, User $target): bool
    {
        if (! $actor->hasPermissionTo('user_management_users.edit')) {
            return false;
        }

        if ($actor->id === $target->id) {
            return true;
        }

        if ($actor->isFullAdmin()) {
            return true;
        }

        if ($target->isFullAdmin()) {
            return false;
        }

        return $this->actorHoldsAllOfTargetsPermissions($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        if (! $actor->hasPermissionTo('user_management_users.delete')) {
            return false;
        }

        if ($actor->id === $target->id) {
            return false;
        }

        if ($actor->isFullAdmin()) {
            return true;
        }

        if ($target->isFullAdmin()) {
            return false;
        }

        return $this->actorHoldsAllOfTargetsPermissions($actor, $target);
    }

    private function actorHoldsAllOfTargetsPermissions(User $actor, User $target): bool
    {
        $actorPermissions = $actor->cachedPermissionNames();
        $targetPermissions = $target->cachedPermissionNames();

        foreach ($targetPermissions as $name) {
            if (! $actorPermissions->contains($name)) {
                return false;
            }
        }

        return true;
    }
}
