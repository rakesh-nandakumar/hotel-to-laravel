<?php

use Illuminate\Support\Facades\Route;

arch('no debugging calls ship to production')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('controllers do not use env() directly')
    ->expect('App\Http\Controllers')
    ->not->toUse(['env']);

it('protects every state-changing route with auth or guest-flow middleware', function () {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->filter(function ($route) {
            // The framework's local-disk upload endpoint validates a signed
            // URL inside its handler rather than via middleware.
            if (str_starts_with((string) $route->getName(), 'storage.')) {
                return false;
            }

            // Hotel guest-facing public endpoints (pre-check-in, venue inquiry) are
            // intentionally unauthenticated — anyone with a booking code or a phone
            // can reach them, matching the Node app's zero-middleware public.ts.
            if (str_starts_with((string) $route->getName(), 'public.')) {
                return false;
            }

            // PIN quick-unlock is deliberately reachable with no prior session (a
            // terminal may be mid-shift-switch) — protected instead by requiring
            // possession of a device token from an earlier full login, throttling,
            // and excluding any account that itself requires 2FA/OTP.
            if ($route->getName() === 'pin-login') {
                return false;
            }

            // Guest-flow endpoints (login, password reset, OTP challenge) are
            // intentionally unauthenticated; everything else needs auth.
            $protected = collect($route->gatherMiddleware())->contains(
                fn ($m) => is_string($m)
                    && (str_starts_with($m, 'auth') || str_starts_with($m, 'guest')),
            );

            return ! $protected && ! str_starts_with($route->uri(), '_');
        })
        ->map(fn ($route) => implode('|', $route->methods()).' /'.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([], 'State-changing routes without auth/guest middleware: '.implode(', ', $unprotected));
});

it('requires a permission on every authenticated GET page under the app prefixes', function () {
    $prefixes = ['api/user-management/', 'api/audit-logs', 'api/dashboard'];

    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(function ($route) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($route->uri(), $prefix)) {
                    return true;
                }
            }

            return false;
        })
        ->filter(function ($route) {
            $middleware = $route->gatherMiddleware();

            $hasPermission = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'can_do:'),
            );

            return ! $hasPermission;
        })
        ->map(fn ($route) => '/'.$route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([], 'App pages without a can_do permission: '.implode(', ', $unguarded));
});
