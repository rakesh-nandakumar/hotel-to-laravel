<?php

use App\Models\CentralAdmin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentContext;
use App\Support\ModuleCatalog;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * Provisions a fresh tenant (distinct from the shared demo tenant beforeEach
 * already seeds) with its own Full Administrator, and returns [$tenant, $admin].
 * Requests must carry X-Tenant-Slug so IdentifyTenant's local/testing
 * dev-fallback resolves THIS tenant rather than failing its "exactly one
 * tenant in the DB" convenience (two tenants now exist: demo + this one).
 */
function tenantWithFullAdmin(): array
{
    $tenant = Tenant::factory()->create();
    app(PermissionsAndRolesSeeder::class)->seedSystemRoles($tenant->id);

    $fullAdminRole = Role::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->where('is_full_admin', true)->firstOrFail();
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->roles()->attach($fullAdminRole->id);
    $admin->flushPermissionCache();

    return [$tenant, $admin];
}

/**
 * Module licensing is called out explicitly by name in App\Services\TenantModules'
 * own docblock as orthogonal to role: a tenant's own Full Administrator must
 * still be blocked from a module the platform operator hasn't enabled, even
 * though `is_full_admin` bypasses every ordinary permission check.
 *
 * Wrapped in simulateWebRequest() — TenantModules::isEnabled() bypasses
 * entirely for console execution (the whole Pest run is technically
 * "console"), same reasoning as TenantScopeTest.
 */
it('blocks even a full admin from a module the tenant is not licensed for', function () {
    [$tenant, $admin] = tenantWithFullAdmin();

    // No TenantModule rows exist yet for this tenant — every business module
    // starts disabled until master control turns it on.
    CurrentContext::simulateWebRequest(fn () => $this->actingAs($admin)
        ->withHeaders(['X-Tenant-Slug' => $tenant->slug])
        ->getJson(route('apartments.properties.index'))
        ->assertForbidden());
});

it('lets a central admin enable a module and the tenant gains access immediately', function () {
    [$tenant, $admin] = tenantWithFullAdmin();

    actingAsCentral(CentralAdmin::factory()->create());
    $this->withHeaders(['X-Tenant-Slug' => $tenant->slug])
        ->putJson("/api/central/tenants/{$tenant->id}/modules/".ModuleCatalog::APARTMENTS, ['enabled' => true])
        ->assertOk();

    CurrentContext::simulateWebRequest(fn () => $this->actingAs($admin)
        ->withHeaders(['X-Tenant-Slug' => $tenant->slug])
        ->getJson(route('apartments.properties.index'))
        ->assertOk());
});

it('never gates core modules like dashboard', function () {
    [$tenant, $admin] = tenantWithFullAdmin();

    // No modules enabled at all — core must still work.
    CurrentContext::simulateWebRequest(fn () => $this->actingAs($admin)
        ->withHeaders(['X-Tenant-Slug' => $tenant->slug])
        ->getJson(route('dashboard'))
        ->assertOk());
});

/**
 * /me's `enabled_modules` is what the SPA (App\lib\auth.tsx) cross-checks
 * every permission against before treating it as usable — a disabled
 * module's permission must never appear there, even for a Full
 * Administrator whose role holds every permission in the system. Without
 * this, the sidebar/route guards showed unlicensed modules until the API
 * call 403'd.
 */
it('excludes an unlicensed module from /me enabled_modules even for a full admin', function () {
    [$tenant, $admin] = tenantWithFullAdmin();

    // No TenantModule rows exist yet — every business module starts disabled.
    CurrentContext::simulateWebRequest(function () use ($tenant, $admin) {
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Slug' => $tenant->slug])
            ->getJson(route('me'))
            ->assertOk();

        expect($response->json('enabled_modules'))
            ->not->toContain('apartment_properties')
            ->toContain('dashboard'); // core — never gated

        // The role still grants it; only the module gate must reject it.
        expect($response->json('permissions'))->toContain('apartment_properties.access');
    });
});

it('adds a module to /me enabled_modules once master control licenses it', function () {
    [$tenant, $admin] = tenantWithFullAdmin();

    actingAsCentral(CentralAdmin::factory()->create());
    $this->withHeaders(['X-Tenant-Slug' => $tenant->slug])
        ->putJson('/api/central/tenants/'.$tenant->id.'/modules/'.ModuleCatalog::APARTMENTS, ['enabled' => true])
        ->assertOk();

    CurrentContext::simulateWebRequest(function () use ($tenant, $admin) {
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Tenant-Slug' => $tenant->slug])
            ->getJson(route('me'))
            ->assertOk();

        expect($response->json('enabled_modules'))->toContain('apartment_properties');
    });
});
