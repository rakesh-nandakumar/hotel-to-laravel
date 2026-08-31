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
    // Master control requests carry NO tenant header — the TestCase's default
    // X-Tenant-Slug would override this suite's absence.
    $this->withoutHeader('X-Tenant-Slug');
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
 * The minted link is {FRONTEND_URL}/{slug}/impersonate/{token} — the tenant is
 * named by the URL prefix itself, and the SPA that lands there re-sends it as
 * X-Tenant-Slug on the consume call. FRONTEND_URL in testing is
 * http://localhost:5173 (the Vite dev origin), so it also covers the port the
 * old dev-fallback hack bolted on — no dev-only branch anymore.
 */
function tokenFrom(string $url): string
{
    return last(explode('/impersonate/', (string) $url));
}

it('mints an impersonation URL on the tenant prefix and logs the operator in as the tenant admin', function () {
    [$tenant, $admin] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('/api/central/tenants/'.$tenant->id.'/impersonate')->assertOk()->json('url');

    // Always the tenant's own URL prefix under the frontend origin — that
    // prefix is what binds the resulting session to this tenant.
    expect($url)->toContain("/{$tenant->slug}/impersonate/");

    $token = tokenFrom($url);

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('points the impersonation link at the frontend origin, whose scheme/port it inherits', function () {
    [$tenant] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    // FRONTEND_URL in .env.testing: http://localhost:5173 — the minted link
    // carries that origin exactly (no separate prod/dev URL shapes anymore).
    $url = $this->postJson('/api/central/tenants/'.$tenant->id.'/impersonate')
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('http://localhost:5173/'.$tenant->slug.'/impersonate/')
        ->and($url)->toContain('/impersonate/');
});

it('consumes a token on the tenant prefix with the slug header', function () {
    [$tenant, $admin] = provisionedTenant();
    Tenant::factory()->create(); // a second tenant, so nothing can be assumed
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = tokenFrom($url);

    // Exactly what the browser does when it follows the minted link: the path
    // prefix is the only thing naming the tenant.
    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertOk();

    $this->assertAuthenticatedAs($admin);
});

it('keeps the session scoped to the tenant after the prefix-based hand-off', function () {
    [$tenant, $admin] = provisionedTenant();
    Tenant::factory()->create(); // a second tenant, so nothing can be assumed
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = tokenFrom($url);

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertOk();

    // Follow-up calls on the tenant's own prefix carry the same slug — the
    // session stays bound to it, and the cross-tenant guard keeps it there.
    $this->getJson('/api/me', ['X-Tenant-Slug' => $tenant->slug])->assertOk()->assertJsonPath('user.id', $admin->id);
});

it('rejects a token replayed against a different tenant', function () {
    [$tenantA] = provisionedTenant();
    [$tenantB] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('/api/central/tenants/'.$tenantA->id.'/impersonate')->json('url');
    $token = tokenFrom($url);

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenantB->slug])->assertUnauthorized();
    $this->assertGuest();
});

it('rejects a token that has already been used once', function () {
    [$tenant] = provisionedTenant();
    actingAsCentral(CentralAdmin::factory()->create());

    $url = $this->postJson('/api/central/tenants/'.$tenant->id.'/impersonate')->json('url');
    $token = tokenFrom($url);

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

it('serves tenant requests without recursion when the session user loads against active scopes', function () {
    // Real web requests have TenantScope active. Loading the session user then
    // recursed: the scope consulted CurrentContext::tenantId(), which fell back
    // to Auth::user(), which re-entered the same load forever (OOM, empty 500).
    [$tenant, $admin] = provisionedTenant();

    CurrentContext::simulateWebRequest(function () use ($tenant, $admin) {
        Auth::guard('web')->login($admin);

        $this->getJson('/api/public/branding', ['X-Tenant-Slug' => $tenant->slug])->assertOk()->assertJsonPath('name', 'Mount View Hotel');
        $this->getJson('/api/me', ['X-Tenant-Slug' => $tenant->slug])->assertOk()->assertJsonPath('user.id', $admin->id);
    });
});

it('rejects a session from a different tenant on another tenant prefix', function () {
    [, $adminA] = provisionedTenant();
    [$tenantB] = provisionedTenant();

    CurrentContext::simulateWebRequest(function () use ($tenantB, $adminA) {
        Auth::guard('web')->login($adminA);

        $this->getJson('/api/me', ['X-Tenant-Slug' => $tenantB->slug])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Session does not match this tenant.');
    });

    $this->assertGuest();
});

it('allows a session on its own tenant prefix', function () {
    [$tenant, $admin] = provisionedTenant();

    CurrentContext::simulateWebRequest(function () use ($tenant, $admin) {
        Auth::guard('web')->login($admin);

        $this->getJson('/api/me', ['X-Tenant-Slug' => $tenant->slug])->assertOk()->assertJsonPath('user.id', $admin->id);
    });
});
