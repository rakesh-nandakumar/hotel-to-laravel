<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentContext;
use Illuminate\Support\Facades\Auth;

/**
 * The primary tenancy identity source (X-Tenant-Slug header, falling back to
 * the `tenant` query parameter): a browser at /{slug}/… sends its own slug on
 * every API call, and IdentifyTenant + HostContextController resolve it ahead
 * of — and instead of — the Host header.
 */
it('resolves the tenant named by the header, regardless of host', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);

    // Host would resolve "default"-era apex (localhost is central); the header
    // is the identity, so this real tenant comes back.
    $this->getJson('http://localhost/api/host-context', ['X-Tenant-Slug' => 'acme'])
        ->assertOk()
        ->assertJsonPath('mode', 'tenant');

    expect(app(CurrentContext::class)->tenantId())->toBe($acme->id);
});

it('prefers the header over a host that names a different tenant', function () {
    Tenant::factory()->create(['slug' => 'globex']);

    $this->getJson('http://globex.localhost/api/host-context', ['X-Tenant-Slug' => 'globex'])
        ->assertOk()
        ->assertJsonPath('mode', 'tenant');

    // And with the header naming something else, the header wins.
    $this->getJson('http://globex.localhost/api/host-context', ['X-Tenant-Slug' => 'nobody'])
        ->assertNotFound();
});

it('rejects the central prefix as a header slug', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    // A tenant-slot hint can never name master control.
    $this->getJson('http://localhost/api/host-context', ['X-Tenant-Slug' => config('tenancy.central_prefix')])
        ->assertNotFound();

    $this->getJson('http://localhost/api/central/tenants', ['X-Tenant-Slug' => config('tenancy.central_prefix')])
        ->assertNotFound();
});

it('keeps master control unreachable while a tenant header is present', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    actingAsCentral(CentralAdmin::factory()->create());

    // Any tenant-slot identity — real tenant included — is never master
    // control: EnsureCentralContext 404s the panel rather than running it
    // under a tenant context.
    $this->getJson('/api/central/tenants', ['X-Tenant-Slug' => 'acme'])->assertNotFound();

    expect(app(CurrentContext::class)->tenantId())->toBe($acme->id);
});

it('rejects a session from tenant A against tenant B without leaking rows', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    Tenant::factory()->create(['slug' => 'globex']);
    $userA = User::factory()->create(['tenant_id' => $acme->id]);

    CurrentContext::simulateWebRequest(function () use ($userA) {
        Auth::guard('web')->login($userA);

        // Own tenant first: the session is valid here.
        $this->getJson('/api/me', ['X-Tenant-Slug' => 'acme'])
            ->assertOk()
            ->assertJsonPath('user.id', $userA->id);

        // Cross-tenant: the guard 401s before any query runs — no data flow.
        $this->getJson('/api/me', ['X-Tenant-Slug' => 'globex'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Session does not match this tenant.');

        // The mismatch LOGS THE SESSION OUT (deliberately — one tenant session
        // per browser) — never a silent repoint of an old session onto B.
        $this->getJson('/api/me', ['X-Tenant-Slug' => 'acme'])
            ->assertUnauthorized();
    });

    $this->assertGuest();
});

it('falls back to the host when no header is present', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->withoutHeader('X-Tenant-Slug');

    $this->getJson('http://acme.localhost/api/host-context')
        ->assertOk()
        ->assertJsonPath('mode', 'tenant');

    expect(app(CurrentContext::class)->tenantId())->toBe($acme->id);
});

it('names the tenant via the ?tenant= query parameter too', function () {
    $acme = Tenant::factory()->create(['slug' => 'acme']);
    $this->withoutHeader('X-Tenant-Slug');

    $this->getJson('http://localhost/api/host-context?tenant=acme')
        ->assertOk()
        ->assertJsonPath('mode', 'tenant');

    expect(app(CurrentContext::class)->tenantId())->toBe($acme->id);
});
