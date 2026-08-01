<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Services\CurrentContext;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

/**
 * Host-based resolution of "master control" vs. a tenant — the boundary the
 * whole architecture rests on (App\Http\Middleware\IdentifyTenant plus
 * EnsureCentralContext).
 *
 * Every test here turns `tenancy.dev_fallback` OFF. Left on (its default
 * outside production, including the test suite) IdentifyTenant short-circuits
 * any `api/central/*` path straight to central context so local dev on
 * http://localhost works at all — which would make these assertions pass
 * without the Host header ever being consulted.
 */
beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);

    config()->set('tenancy.dev_fallback', false);
});

/**
 * Absolute URLs, not a `Host` header: Laravel's prepareUrlForRequest() makes a
 * relative test URI absolute against config('app.url'), and Symfony's
 * Request::create() then derives HTTP_HOST from that — silently discarding any
 * Host header passed in. The host has to be in the URL itself to take effect.
 */
function centralUrl(string $path = '/api/central/tenants'): string
{
    return 'http://'.config('tenancy.central_subdomain').'.'.config('tenancy.base_domain').$path;
}

function tenantUrl(string $slug, string $path = '/api/dashboard'): string
{
    return 'http://'.$slug.'.'.config('tenancy.base_domain').$path;
}

it('serves master control on the central subdomain', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $this->getJson(centralUrl())
        ->assertOk()
        ->assertJsonStructure(['tenants']);
});

it('treats the bare base domain as central context too', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $this->getJson('http://'.config('tenancy.base_domain').'/api/central/tenants')
        ->assertOk();
});

it('hides master control from a tenant subdomain even for a signed-in central admin', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    actingAsCentral(CentralAdmin::factory()->create());

    // EnsureCentralContext: a resolved tenant means this is NOT master control,
    // so the panel 404s rather than leaking one tenant's host into central scope.
    $this->getJson(tenantUrl('acme', '/api/central/tenants'))
        ->assertNotFound();
});

it('rejects an unknown subdomain rather than falling through unscoped', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->getJson(tenantUrl('nobody'))->assertNotFound();
});

it('rejects a suspended tenant subdomain', function () {
    Tenant::factory()->suspended()->create(['slug' => 'lapsed']);

    $this->getJson(tenantUrl('lapsed'))->assertNotFound();
});

it('resolves the tenant that owns the subdomain', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    Tenant::factory()->create(['slug' => 'globex']);

    // Unauthenticated, so this stops at 401 rather than 404 — proving the host
    // resolved to a real tenant and the request got past IdentifyTenant.
    $this->getJson(tenantUrl('acme'))->assertUnauthorized();

    expect(app(CurrentContext::class)->tenantId())->toBe($acme->id);
});
