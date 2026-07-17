<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Resolves where an authenticated user should land — and whether a given URL is
 * safe for them to be redirected to. Centralises the "first accessible page"
 * logic so login redirects, the home middleware, and the UI all agree.
 */
class UserLanding
{
    /**
     * The best landing URL for the user: the dashboard when they can reach it,
     * otherwise the first menu item they can access, falling back to their
     * profile (which every authenticated user may open).
     */
    public static function urlFor(User $user): string
    {
        if ($user->isFullAdmin() || $user->hasPermissionTo('dashboard.access')) {
            return route('dashboard');
        }

        foreach (MenuRenderer::forUser($user) as $item) {
            if ($url = self::firstHref($item)) {
                return $url;
            }
        }

        // No reachable menu item (e.g. a user with no roles): fall back to the
        // dashboard, which will surface a 403 rather than silently looping.
        return route('dashboard');
    }

    /**
     * Whether the user is allowed to view the page behind $url, based on the
     * `can_do:<permission>` middleware guarding its route. Unknown/unmatched
     * URLs are treated as unsafe so we never redirect into a 403.
     */
    public static function canAccess(User $user, string $url): bool
    {
        if ($user->isFullAdmin()) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can_do:')) {
                $permission = substr($middleware, strlen('can_do:'));

                if (! $user->hasPermissionTo($permission)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function firstHref(array $item): ?string
    {
        if (! empty($item['href'])) {
            return $item['href'];
        }

        foreach ($item['children'] ?? [] as $child) {
            if ($url = self::firstHref($child)) {
                return $url;
            }
        }

        return null;
    }
}
