<?php

use App\Models\CentralAdmin;
use App\Models\Setting;
use App\Models\Tenant;
use Database\Seeders\SettingsSeeder;

it('shows catalog defaults for a tenant with no overrides yet', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = Tenant::factory()->create();

    $response = $this->getJson("/api/central/tenants/{$tenant->id}/settings")->assertOk();

    $vat = collect($response->json('settings'))->firstWhere('key', 'billing.vat_pct');
    expect($vat)->not->toBeNull()->and($vat['overridden'])->toBeFalse();
});

it('lets a central admin update one tenant setting without affecting another', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    (new SettingsSeeder)->run($tenantA->id);
    (new SettingsSeeder)->run($tenantB->id);

    $this->putJson("/api/central/tenants/{$tenantA->id}/settings/billing.vat_pct", ['value' => 12])->assertOk();

    $readFor = fn (int $tenantId) => (float) json_decode(
        Setting::query()->withoutTenantScope()->where('tenant_id', $tenantId)->where('key', 'billing.vat_pct')->value('value')
    );

    expect($readFor($tenantA->id))->toBe(12.0);
    expect($readFor($tenantB->id))->not->toBe(12.0);
});

it('validates the setting type on write', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = Tenant::factory()->create();
    (new SettingsSeeder)->run($tenant->id);

    $this->putJson("/api/central/tenants/{$tenant->id}/settings/billing.vat_pct", ['value' => 150])
        ->assertUnprocessable()->assertJsonValidationErrors('value');
});

it('requires central authentication', function () {
    $tenant = Tenant::factory()->create();

    $this->getJson("/api/central/tenants/{$tenant->id}/settings")->assertUnauthorized();
});
