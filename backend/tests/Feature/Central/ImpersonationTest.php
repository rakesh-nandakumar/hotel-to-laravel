<?php

use App\Models\CentralAdmin;
use App\Models\ImpersonationToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentContext;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

function provisionedTenant(): array
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
 * Tenant resolution is RELATIVE (first label = identity, rest = base), so
 * these tests ride *.localhost: "admin.localhost" is master control, and a
 * factory tenant's own host is "{slug}.localhost". Absolute URLs are required
 * — prepareUrlForRequest() derives HTTP_HOST from the URL and discards Host
 * headers on relative URIs (see CentralHostResolutionTest).
 */
function tenantHost(string $slug): string
{
    return $slug.'.localhost';
}

it('mints an impersonation URL and logs the operator in as the tenant admin', function () {
    [$tenant, $admin] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $response = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')->assertOk();
    $url = $response->json('url');

    // Always the tenant's own subdomain, derived from the request's own base —
    // that Host is what binds the session to this tenant.
    expect($url)->toContain(tenantHost($tenant->slug))
        ->and($url)->toContain('/impersonate/');

    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson('http://'.tenantHost($tenant->slug)."/api/impersonate/{$token}")->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('points the impersonation link at the tenant subdomain in production', function () {
    [$tenant] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    // Over https, so the minted link inherits that scheme rather than the
    // frontend_url one the dev branch would have used.
    $url = $this->postJson('https://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')
        ->assertOk()
        ->json('url');

    // Real host, request's own scheme, and no dev port bolted on.
    expect($url)->toStartWith('https://'.tenantHost($tenant->slug).'/impersonate/')
        ->and($url)->not->toContain(':5173');
});

it('lands on the tenant subdomain with the SPA port while developing', function () {
    [$tenant] = provisionedTenant();
    config()->set('tenancy.dev_fallback', true);
    actingAsCentral(CentralAdmin::factory()->create());

    // dev_fallback on makes the minted link carry app.frontend_url's scheme
    // and Vite port — a bare hostname would 404 in a browser without it.
    $url = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');

    expect($url)->toStartWith('http://'.tenantHost($tenant->slug).':5173/impersonate/');
});

it('accepts an explicit ?tenant= hint when consuming', function () {
    [$tenant, $admin] = provisionedTenant();
    config()->set('tenancy.dev_fallback', true);
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    // Consumed on a host that can't name the tenant (localhost is central);
    // the query hint is the escape hatch for exactly that (see IdentifyTenant).
    $this->postJson("http://localhost/api/impersonate/{$token}?tenant={$tenant->slug}")->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('consumes a token on the tenant subdomain with no hint at all', function () {
    [$tenant, $admin] = provisionedTenant();
    Tenant::factory()->create(); // a second tenant, so nothing can be assumed
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    // Exactly what the browser does when it follows the minted link: the Host
    // is the only thing naming the tenant.
    $this->postJson('http://'.tenantHost($tenant->slug)."/api/impersonate/{$token}")->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('keeps resolving the tenant after a hint-based hand-off', function () {
    [$tenant, $admin] = provisionedTenant();
    Tenant::factory()->create(); // a second tenant, so nothing can be assumed
    config()->set('tenancy.dev_fallback', true);
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson("http://localhost/api/impersonate/{$token}?tenant={$tenant->slug}")->assertOk();

    // Follow-up calls on the tenant's own subdomain carry no hint — the Host
    // keeps resolving the tenant on its own.
    $this->getJson('http://'.tenantHost($tenant->slug).'/api/me')->assertOk()->assertJsonPath('user.id', $admin->id);
});

it('rejects a token replayed against a different tenant', function () {
    [$tenantA] = provisionedTenant();
    [$tenantB] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenantA->id.'/impersonate')->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson('http://'.tenantHost($tenantB->slug)."/api/impersonate/{$token}")->assertUnauthorized();
    $this->assertGuest();
});

it('rejects a token that has already been used once', function () {
    [$tenant] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('http://admin.localhost/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson('http://'.tenantHost($tenant->slug)."/api/impersonate/{$token}")->assertOk();
    Auth::guard('web')->logout();

    $this->postJson('http://'.tenantHost($tenant->slug)."/api/impersonate/{$token}")->assertUnauthorized();
});

it('rejects an expired token', function () {
    [$tenant, $admin] = provisionedTenant();

    ImpersonationToken::create([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'central_admin_id' => CentralAdmin::factory()->create()->id,
        'token_hash' => hash('sha256', 'expired-plain-token'),
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('http://'.tenantHost($tenant->slug).'/api/impersonate/expired-plain-token')->assertUnauthorized();
});

it('serves tenant requests without recursion when the session user loads against active scopes', function () {
    // Real web requests have TenantScope active. Loading the session user then
    // recursed: the scope consulted CurrentContext::tenantId(), which fell back
    // to Auth::user(), which re-entered the same load forever (OOM, empty 500).
    [$tenant, $admin] = provisionedTenant();

    CurrentContext::simulateWebRequest(function () use ($tenant, $admin) {
        Auth::guard('web')->login($admin);

        $host = tenantHost($tenant->slug);

        $this->getJson("http://{$host}/api/public/branding")->assertOk()->assertJsonPath('name', 'Mount View Hotel');
        $this->getJson("http://{$host}/api/me")->assertOk()->assertJsonPath('user.id', $admin->id);
    });
});

it('rejects a session from a different tenant on this subdomain', function () {
    [, $adminA] = provisionedTenant();
    [$tenantB] = provisionedTenant();

    CurrentContext::simulateWebRequest(function () use ($tenantB, $adminA) {
        Auth::guard('web')->login($adminA);

        $host = tenantHost($tenantB->slug);

        $this->getJson("http://{$host}/api/me")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Session does not match this tenant.');
    });

    $this->assertGuest();
});

it('allows a session on its own tenant subdomain', function () {
    [$tenant, $admin] = provisionedTenant();

    CurrentContext::simulateWebRequest(function () use ($tenant, $admin) {
        Auth::guard('web')->login($admin);

        $host = tenantHost($tenant->slug);

        $this->getJson("http://{$host}/api/me")->assertOk()->assertJsonPath('user.id', $admin->id);
    });
});
