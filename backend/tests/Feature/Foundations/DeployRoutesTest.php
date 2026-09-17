<?php

use App\Rules\ReservedSlug;
use Illuminate\Support\Facades\Route;

/**
 * The deploy utilities (App\Http\Controllers\DeployController) exist so a
 * host with no SSH can run migrations from a browser. Two things have to hold
 * for that, and both have broken before:
 *
 *   1. the BARE paths (/migrate, /migrate/status, /seed) are registered — they
 *      live in bootstrap/app.php's withRouting(then: ...), not routes/api.php,
 *      because apiPrefix would otherwise force an /api prefix on them;
 *   2. they answer with NO tenant context at all — IdentifyTenant must let
 *      them past, since on a fresh deploy there may be no tenant row yet and
 *      the request arrives on a bare host.
 *
 * The remaining half of "does /migrate work" is server config, which no test
 * can see: the release bundle's .htaccess (BuildRelease::writeDocrootHtaccess)
 * and web/nginx.conf must both send these paths to Laravel instead of the SPA
 * shell. Both list them next to api|sanctum|broadcasting|up.
 */
it('registers the bare deploy routes outside the api prefix', function () {
    foreach (['deploy.migrate.bare', 'deploy.migrate.status.bare', 'deploy.seed.bare'] as $name) {
        expect(Route::has($name))->toBeTrue("route [{$name}] is not registered");
    }

    expect(route('deploy.migrate.bare', absolute: false))->toBe('/migrate')
        ->and(route('deploy.migrate.status.bare', absolute: false))->toBe('/migrate/status')
        ->and(route('deploy.seed.bare', absolute: false))->toBe('/seed');
});

it('reports migration status as plain text with no tenant and no auth', function () {
    $response = $this->get('/migrate/status');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
    // Artisan output is a one-off: nothing may replay it for the next visit.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->getContent())
        ->toContain('php artisan migrate:status')
        ->toContain('OK (exit code 0)');
});

it('reaches the deploy utilities through index.php path-info, independent of any rewrite rule', function () {
    // https://{host}/index.php/migrate/status is the fallback the deploy notes
    // point at when /migrate comes back as the SPA: Apache serves index.php
    // itself (a real file) with PATH_INFO, so it works even where the
    // document root's .htaccess is stale or mod_rewrite is off. Symfony
    // derives the base URL from SCRIPT_NAME/SCRIPT_FILENAME, which is what
    // strips the /index.php prefix back off before routing.
    $response = $this->call('GET', '/index.php/migrate/status', server: [
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => public_path('index.php'),
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('php artisan migrate:status');
});

it('runs migrate from the bare path with no tenant and no auth', function () {
    // RefreshDatabase already migrated, so this is a no-op run — enough to
    // prove the route resolves, the middleware lets it through and the
    // artisan output comes back.
    $response = $this->get('/migrate');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('php artisan migrate')
        ->toContain('OK (exit code 0)');
});

it('serves the same utilities under the /api/deploy prefix', function () {
    $response = $this->get('/api/deploy/migrate/status');

    $response->assertOk();
    expect($response->getContent())->toContain('php artisan migrate:status');
});

it('refuses migrate and seed as tenant slugs, since the server routes them to Laravel', function () {
    $rule = new ReservedSlug;

    foreach (['migrate', 'seed'] as $slug) {
        $failed = false;
        $rule->validate('slug', $slug, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue("slug [{$slug}] should be reserved");
    }
});
