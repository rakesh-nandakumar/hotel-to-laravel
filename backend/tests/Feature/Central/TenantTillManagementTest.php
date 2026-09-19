<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\Till;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->withoutHeader('X-Tenant-Slug');
});

it('lets a central admin create a till for a tenant, which then appears in the tenant\'s own till list', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = Tenant::demo();

    $created = $this->postJson("/api/central/tenants/{$tenant->id}/tills", ['name' => 'Restaurant Till'])
        ->assertCreated();

    expect($created->json('till.tenant_id'))->toBe($tenant->id)
        ->and($created->json('till.is_active'))->toBeTrue();

    $index = $this->getJson("/api/central/tenants/{$tenant->id}/tills")->assertOk();
    expect(collect($index->json('tills'))->pluck('name'))->toContain('Restaurant Till');

    $manager = staffWithRole('Manager');
    $tenantIndex = $this->withHeader('X-Tenant-Slug', $tenant->slug)
        ->actingAs($manager)->getJson('/api/till/tills')->assertOk();
    expect(collect($tenantIndex->json('tills'))->pluck('name'))->toContain('Restaurant Till');
});

it('lets a central admin rename or deactivate a tenant\'s till', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = Tenant::demo();

    $till = $this->postJson("/api/central/tenants/{$tenant->id}/tills", ['name' => 'Pool Bar Till'])
        ->assertCreated()->json('till');

    $this->putJson("/api/central/tenants/{$tenant->id}/tills/{$till['id']}", ['name' => 'Pool Bar Till', 'is_active' => false])
        ->assertOk();

    $manager = staffWithRole('Manager');
    $this->withHeader('X-Tenant-Slug', $tenant->slug);

    $activeOnly = $this->actingAs($manager)->getJson('/api/till/tills')->assertOk();
    expect(collect($activeOnly->json('tills'))->pluck('id'))->not->toContain($till['id']);

    $withInactive = $this->actingAs($manager)->getJson('/api/till/tills?include_inactive=1')->assertOk();
    expect(collect($withInactive->json('tills'))->pluck('id'))->toContain($till['id']);
});

it('blocks a central admin from updating another tenant\'s till through a mismatched tenant id', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = Tenant::demo();
    $otherTenant = Tenant::factory()->create();

    $till = $this->postJson("/api/central/tenants/{$tenant->id}/tills", ['name' => 'Front Desk Till'])
        ->assertCreated()->json('till');

    $this->putJson("/api/central/tenants/{$otherTenant->id}/tills/{$till['id']}", ['name' => 'Hijacked', 'is_active' => false])
        ->assertNotFound();

    expect(Till::query()->find($till['id'])->name)->toBe('Front Desk Till');
});
