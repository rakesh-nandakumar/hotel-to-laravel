<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Services\CurrentContext;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

/**
 * Resolution of "master control" vs. a tenant — the boundary the whole
 * architecture rests on (App\Http\Middleware\IdentifyTenant plus
 * EnsureCentralContext).
 *
 * Master control is named by the central path: a request with NO
 * X-Tenant-Slug header on the bare host (phpunit's APP_URL, "http://localhost")
 * is the apex — central, exactly like hms.com in production. A tenant-slot
 * request always names its slug in the header, the way the SPA at /{slug}/…
 * does. The old {slug}.{base} host style is exercised by the host-fallback
 * unit tests (TenantHostResolverTest).
 */
beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    // Master control requests carry NO tenant header — the TestCase's default
    // X-Tenant-Slug would override this suite's absence.
    $this->withoutHeader('X-Tenant-Slug');
});

it('serves master control on the bare host (no tenant header)', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $this->getJson('/api/central/tenants')
        ->assertOk()
        ->assertJsonStructure(['tenants']);
});

it('hides master control from a tenant-slot request even for a signed-in central admin', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    actingAsCentral(CentralAdmin::factory()->create());

    // EnsureCentralContext: a resolved tenant means this is NOT master control,
    // so the panel 404s rather than leaking a tenant context into central scope.
    $this->getJson('/api/central/tenants', ['X-Tenant-Slug' => 'acme'])
        ->assertNotFound();
});

it('rejects an unknown slug rather than falling through unscoped', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->getJson('/api/dashboard', ['X-Tenant-Slug' => 'nobody'])->assertNotFound();
});

it('rejects a suspended tenant slug', function () {
    Tenant::factory()->suspended()->create(['slug' => 'lapsed']);

    $this->getJson('/api/dashboard', ['X-Tenant-Slug' => 'lapsed'])->assertNotFound();
});

it('resolves the tenant the header names', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    Tenant::factory()->create(['slug' => 'globex']);

    // Unauthenticated, so this stops at 401 rather than 404 — proving the slug
    // resolved to a real tenant and the request got past IdentifyTenant.
    $this->getJson('/api/dashboard', ['X-Tenant-Slug' => 'acme'])->assertUnauthorized();

    expect(app(CurrentContext::class)->tenantId())->toBe($acme->id);
});
