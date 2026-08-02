<?php

use App\Models\CentralAdmin;
use App\Models\ImpersonationToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
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

it('mints an impersonation URL and logs the operator in as the tenant admin', function () {
    [$tenant, $admin] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $response = $this->postJson("/api/central/tenants/{$tenant->id}/impersonate")->assertOk();
    $url = $response->json('url');

    // Always the tenant's own subdomain — that Host is what binds the session
    // to this tenant. Locally it gains Vite's port; see the production test below.
    expect($url)->toContain($tenant->slug.'.'.config('tenancy.base_domain'))
        ->and($url)->toContain('/impersonate/');

    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('points the impersonation link at the tenant subdomain in production', function () {
    [$tenant] = provisionedTenant();
    config()->set('tenancy.dev_fallback', false);
    actingAsCentral(CentralAdmin::factory()->create());

    // dev_fallback off means the central path shortcut is gone too, so master
    // control has to be reached on its real host.
    $centralHost = config('tenancy.central_subdomain').'.'.config('tenancy.base_domain');

    // Over https, so the minted link inherits that scheme rather than the
    // frontend_url one the dev branch would have used.
    $url = $this->postJson("https://{$centralHost}/api/central/tenants/{$tenant->id}/impersonate")
        ->assertOk()
        ->json('url');

    // Real host, request's own scheme, and no dev port bolted on.
    expect($url)->toStartWith('https://'.$tenant->slug.'.'.config('tenancy.base_domain').'/impersonate/')
        ->and($url)->not->toContain(':5173');
});

it('lands on the tenant subdomain with the SPA port while developing', function () {
    [$tenant] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    // dev_fallback is on for the test suite, and app.frontend_url carries the
    // Vite port — a bare hostname would 404 in a browser without it.
    $url = $this->postJson("/api/central/tenants/{$tenant->id}/impersonate")->json('url');

    expect($url)->toStartWith('http://'.$tenant->slug.'.'.config('tenancy.base_domain').':5173/impersonate/');
});

it('accepts an explicit ?tenant= hint when consuming', function () {
    [$tenant, $admin] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson("/api/central/tenants/{$tenant->id}/impersonate")->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    // The subdomain normally identifies the tenant; this is the escape hatch
    // for a host whose subdomains don't resolve (see IdentifyTenant).
    $this->postJson("/api/impersonate/{$token}?tenant={$tenant->slug}")->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('consumes a token on the tenant subdomain with no hint at all', function () {
    [$tenant, $admin] = provisionedTenant();
    Tenant::factory()->create(); // a second tenant, so the "only tenant" fallback can't apply
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson("/api/central/tenants/{$tenant->id}/impersonate")->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    // Exactly what the browser does when it follows the minted link: the Host
    // is the only thing naming the tenant.
    $host = $tenant->slug.'.'.config('tenancy.base_domain');
    $this->postJson("http://{$host}/api/impersonate/{$token}")->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('keeps resolving the tenant after a hint-based hand-off', function () {
    [$tenant, $admin] = provisionedTenant();
    Tenant::factory()->create(); // a second tenant, so the "only tenant" fallback can't apply
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson("/api/central/tenants/{$tenant->id}/impersonate")->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson("/api/impersonate/{$token}?tenant={$tenant->slug}")->assertOk();

    // Follow-up calls made on a host that can't name the tenant carry no hint,
    // so it has to stay remembered from the one that could.
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $admin->id);
});

it('rejects a token replayed against a different tenant', function () {
    [$tenantA] = provisionedTenant();
    [$tenantB] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson("/api/central/tenants/{$tenantA->id}/impersonate")->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenantB->slug])->assertUnauthorized();
    $this->assertGuest();
});

it('rejects a token that has already been used once', function () {
    [$tenant] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson("/api/central/tenants/{$tenant->id}/impersonate")->json('url');
    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertOk();
    Auth::guard('web')->logout();

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertUnauthorized();
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

    $this->postJson('/api/impersonate/expired-plain-token', [], ['X-Tenant-Slug' => $tenant->slug])->assertUnauthorized();
});
