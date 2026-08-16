<?php

use App\Models\Tenant;
use App\Services\Settings;
use Database\Seeders\SettingsSeeder;

/**
 * The SPA's boot gate (HostContextController) — the same host-resolution
 * rules IdentifyTenant applies to every API request, exposed so the frontend
 * can pick its shell before mounting and nginx can refuse to serve the app
 * on hosts that own nothing.
 */
it('reports central context on the central subdomain and the apex', function () {
    $this->getJson('http://admin.localhost/api/host-context')
        ->assertOk()
        ->assertExactJson(['mode' => 'central']);

    $this->getJson('http://localhost/api/host-context')
        ->assertOk()
        ->assertExactJson(['mode' => 'central']);
});

it('reports a tenant on its own subdomain with its full public branding', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    (new SettingsSeeder)->run($tenant->id);
    Settings::set('hotel.name', 'Acme Grand', null, $tenant->id);
    Settings::set('theme.primary', '#123456', null, $tenant->id);

    $this->getJson('http://acme.localhost/api/host-context')
        ->assertOk()
        ->assertJsonPath('mode', 'tenant')
        ->assertJsonPath('name', 'Acme Grand')
        ->assertJsonPath('theme_primary', '#123456')
        ->assertJsonPath('logo', '')
        ->assertJsonPath('check_in_time', '14:00')
        ->assertJsonPath('tagline', 'Hospitality Management System');
});

it('falls back to defaults for a tenant with no settings rows', function () {
    Tenant::factory()->create(['slug' => 'bare']);

    $this->getJson('http://bare.localhost/api/host-context')
        ->assertOk()
        ->assertJsonPath('mode', 'tenant')
        ->assertJsonPath('name', 'Mount View Hotel')
        ->assertJsonPath('theme_primary', '#0462d3');
});

it('404s a host that resolves no tenant', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->getJson('http://nobody.localhost/api/host-context')->assertNotFound();
});

it('404s a suspended tenant like a host that does not exist', function () {
    Tenant::factory()->suspended()->create(['slug' => 'lapsed']);

    $this->getJson('http://lapsed.localhost/api/host-context')->assertNotFound();
});

it('404s an expired trial tenant', function () {
    Tenant::factory()->create(['slug' => 'lapsed', 'status' => 'trial', 'trial_ends_at' => now()->subDay()]);

    $this->getJson('http://lapsed.localhost/api/host-context')->assertNotFound();
});
