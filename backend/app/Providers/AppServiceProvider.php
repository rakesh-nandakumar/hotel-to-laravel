<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentContext::class);
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->isActive()) {
                return false;
            }

            if ($user->isFullAdmin()) {
                return true;
            }

            return null;
        });
    }
}
