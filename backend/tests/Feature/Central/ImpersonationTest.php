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
    expect($url)->toContain("{$tenant->slug}.")->and($url)->toContain('/impersonate/');

    $token = last(explode('/impersonate/', (string) $url));

    $this->postJson("/api/impersonate/{$token}", [], ['X-Tenant-Slug' => $tenant->slug])->assertOk();

    $this->assertAuthenticatedAs($admin);
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
