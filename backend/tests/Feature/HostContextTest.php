<?php

use App\Models\Tenant;
use App\Services\Settings;
use Database\Seeders\SettingsSeeder;

/**
 * The SPA's boot gate (HostContextController) — the same tenant-resolution
 * rules IdentifyTenant applies to every API request, exposed so the frontend
 * can pick its shell before mounting (tenant tree vs. master control) and
 * render the unavailable page on paths that own nothing.
 */
beforeEach(function () {
    // Master control requests carry NO tenant header — the TestCase's default
    // X-Tenant-Slug would override this suite's absence.
    $this->withoutHeader('X-Tenant-Slug');
});

it('reports central context on the bare host (no tenant header)', function () {
    $this->getJson('/api/host-context')
        ->assertOk()
        ->assertExactJson(['mode' => 'central']);
});

it('reports a tenant named by the header with its full public branding', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    (new SettingsSeeder)->run($tenant->id);
    Settings::set('hotel.name', 'Acme Grand', null, $tenant->id);
    Settings::set('theme.primary', '#123456', null, $tenant->id);

    $this->getJson('/api/host-context', ['X-Tenant-Slug' => 'acme'])
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

    $this->getJson('/api/host-context', ['X-Tenant-Slug' => 'bare'])
        ->assertOk()
        ->assertJsonPath('mode', 'tenant')
        ->assertJsonPath('name', 'Mount View Hotel')
        ->assertJsonPath('theme_primary', '#0462d3');
});

it('404s a slug that names no tenant', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->getJson('/api/host-context', ['X-Tenant-Slug' => 'nobody'])->assertNotFound();
});

it('404s a suspended tenant like one that does not exist', function () {
    Tenant::factory()->suspended()->create(['slug' => 'lapsed']);

    $this->getJson('/api/host-context', ['X-Tenant-Slug' => 'lapsed'])->assertNotFound();
});

it('404s an expired trial tenant', function () {
    Tenant::factory()->create(['slug' => 'lapsed', 'status' => 'trial', 'trial_ends_at' => now()->subDay()]);

    $this->getJson('/api/host-context', ['X-Tenant-Slug' => 'lapsed'])->assertNotFound();
});

it('still resolves a tenant from the old subdomain Host during the fallback window', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $this->getJson('http://acme.localhost/api/host-context')
        ->assertOk()
        ->assertJsonPath('mode', 'tenant')
        ->assertJsonPath('name', 'Mount View Hotel');

    expect($tenant->fresh())->not->toBeNull();
});
