<?php

use App\Models\CentralAdmin;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\ModuleCatalog;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

it('requires central authentication to reach master control', function () {
    $this->getJson('/api/central/tenants')->assertUnauthorized();
});

it('lets a central admin create a tenant and auto-provisions its admin user', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $response = $this->postJson('/api/central/tenants', [
        'name' => 'Acme Hotels',
        'slug' => 'acme',
        'admin_email' => 'owner@acme.test',
        'admin_name' => 'Acme Owner',
    ])->assertCreated();

    $tenantId = $response->json('tenant.id');
    expect($tenantId)->not->toBeNull();

    $tenant = Tenant::query()->findOrFail($tenantId);
    expect($tenant->slug)->toBe('acme');

    $role = Role::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->where('name', 'Full Administrator')->first();
    expect($role)->not->toBeNull()->and($role->is_full_admin)->toBeTrue();

    $admin = User::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->where('email', 'owner@acme.test')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('Acme Owner')
        ->and($admin->roles->pluck('name'))->toContain('Full Administrator');

    foreach (ModuleCatalog::keys() as $moduleKey) {
        expect(TenantModule::query()->where('tenant_id', $tenant->id)->where('module_key', $moduleKey)->where('is_enabled', true)->exists())->toBeTrue();
    }

    // A tenant with no settings rows runs its whole app on hardcoded fallbacks
    // and shows master control nothing to edit.
    expect(Setting::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())
        ->toBe(count(SettingsSeeder::definitions()));
});

it('rejects a tenant slug matching the reserved central subdomain', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $this->postJson('/api/central/tenants', [
        'name' => 'Sneaky',
        'slug' => config('tenancy.central_subdomain'),
        'admin_email' => 'a@b.test',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('rejects a duplicate tenant slug', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    Tenant::factory()->create(['slug' => 'taken']);

    $this->postJson('/api/central/tenants', [
        'name' => 'Duplicate',
        'slug' => 'taken',
        'admin_email' => 'a@b.test',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('lets a central admin update a tenant status', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = Tenant::factory()->create();

    $this->putJson("/api/central/tenants/{$tenant->id}", ['status' => 'suspended'])->assertOk();

    expect($tenant->fresh()->status)->toBe('suspended');
});
