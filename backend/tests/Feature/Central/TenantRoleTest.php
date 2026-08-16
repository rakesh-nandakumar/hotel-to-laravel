<?php

use App\Models\CentralAdmin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Support\ModuleCatalog;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * A fresh tenant with its baseline system roles provisioned (distinct from the
 * shared demo tenant) and optionally the given licensable modules enabled —
 * a factory tenant starts with NO modules licensed.
 */
function licensedTenant(array $catalogKeys = []): Tenant
{
    $tenant = Tenant::factory()->create();
    app(PermissionsAndRolesSeeder::class)->seedSystemRoles($tenant->id);

    foreach ($catalogKeys as $key) {
        TenantModule::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => $key],
            ['is_enabled' => true],
        );
    }

    return $tenant;
}

it('requires central authentication to reach a tenant roles', function () {
    $tenant = licensedTenant();

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles")
        ->assertUnauthorized();
});

it('lists a tenant baseline roles', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant();

    $response = $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles")
        ->assertOk();

    $names = collect($response->json('roles'))->pluck('name');
    expect($names)->toContain('Full Administrator', 'Manager', 'Owner', 'Housekeeper');

    $fullAdmin = collect($response->json('roles'))->firstWhere('name', 'Full Administrator');
    expect($fullAdmin['is_full_admin'])->toBeTrue();
});

it('lets the operator create a role with licensed permissions', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant([ModuleCatalog::HOTEL_OPERATIONS]);

    $response = $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Night Auditor',
        'description' => 'Overnight front-desk shift',
        'is_active' => true,
        'permissions' => ['dashboard.access', 'hotel_rooms.access', 'hotel_reservations.view'],
    ])->assertCreated();

    $role = Role::query()->withoutTenantScope()->findOrFail($response->json('role.id'));
    expect($role->tenant_id)->toBe($tenant->id)
        ->and($role->is_system)->toBeFalse()
        ->and($role->permissions->pluck('name'))->toContain('hotel_rooms.access');
});

it('refuses to grant a permission whose module the tenant is not licensed for', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant(); // no modules licensed

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Sneaky',
        'is_active' => true,
        'permissions' => ['hotel_rooms.access'],
    ])->assertForbidden();
});

it('rejects a role name already used in the same tenant', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant();

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Manager',
        'is_active' => true,
        'permissions' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('scopes the role editor matrix to the tenant licences and tags module groups', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant([ModuleCatalog::HOTEL_OPERATIONS, ModuleCatalog::RESTAURANT_POS]);

    $response = $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/create")
        ->assertOk();

    $sections = $response->json('matrix');
    $moduleKeys = collect($sections)->flatMap(fn ($s) => collect($s['modules'])->pluck('key'))->all();

    expect($moduleKeys)->toContain('hotel_rooms')        // hotel_operations licensed
        ->toContain('hotel_menu_items')                   // restaurant_pos licensed
        ->toContain('user_management_roles')              // core — always on
        ->not->toContain('apartment_properties')          // apartments not licensed
        ->not->toContain('hotel_payroll');                // payroll not licensed

    $groups = collect($response->json('groups'))->pluck('key');
    expect($groups)->toContain(
        ModuleCatalog::HOTEL_OPERATIONS,
        ModuleCatalog::RESTAURANT_POS,
        ModuleCatalog::APARTMENTS,
        'core',
    );

    $rooms = collect($sections)->firstWhere('section', 'Rooms');
    expect($rooms['group'])->toBe(ModuleCatalog::HOTEL_OPERATIONS);

    $administration = collect($sections)->firstWhere('section', 'Administration');
    expect($administration['group'])->toBe('core');
});

it('propagates a role update to assigned users immediately', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant([ModuleCatalog::HOTEL_OPERATIONS]);

    $created = $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Front Desk',
        'is_active' => true,
        'permissions' => ['hotel_rooms.access'],
    ])->assertCreated();

    $role = Role::query()->withoutTenantScope()->findOrFail($created->json('role.id'));
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->roles()->attach($role->id);
    $user->flushPermissionCache();

    expect($user->cachedPermissionNames())->toContain('hotel_rooms.access');

    $this->putJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$role->id}", [
        'name' => 'Front Desk',
        'is_active' => true,
        'permissions' => ['hotel_reservations.view'],
    ])->assertOk();

    expect($user->fresh()->cachedPermissionNames())
        ->not->toContain('hotel_rooms.access')
        ->toContain('hotel_reservations.view');
});

it('refuses to delete a system role', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant();
    $manager = Role::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->where('name', 'Manager')->firstOrFail();

    $this->deleteJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$manager->id}")
        ->assertStatus(422);
});

it('deletes a custom role with no members', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant([ModuleCatalog::HOTEL_OPERATIONS]);

    $created = $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Temp Role',
        'is_active' => true,
        'permissions' => ['hotel_rooms.access'],
    ])->assertCreated();
    $roleId = $created->json('role.id');

    $this->deleteJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$roleId}")
        ->assertOk();

    expect(Role::query()->withoutTenantScope()->find($roleId))->toBeNull();
});

it('duplicates a role with its permissions', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant([ModuleCatalog::HOTEL_OPERATIONS]);

    $created = $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Front Desk',
        'is_active' => true,
        'permissions' => ['hotel_rooms.access', 'hotel_reservations.view'],
    ])->assertCreated();

    $response = $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$created->json('role.id')}/duplicate")
        ->assertCreated();

    $copy = Role::query()->withoutTenantScope()->findOrFail($response->json('role.id'));
    expect($copy->name)->toBe('Copy of Front Desk')
        ->and($copy->tenant_id)->toBe($tenant->id)
        ->and($copy->is_system)->toBeFalse()
        ->and($copy->is_full_admin)->toBeFalse()
        ->and($copy->permissions->pluck('name'))->toContain('hotel_rooms.access', 'hotel_reservations.view');
});

it('toggles a role active state', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant();

    $created = $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles", [
        'name' => 'Temp Role',
        'is_active' => true,
        'permissions' => [],
    ])->assertCreated();
    $roleId = $created->json('role.id');

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$roleId}/toggle-active")
        ->assertOk();
    expect(Role::query()->withoutTenantScope()->find($roleId)->is_active)->toBeFalse();

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$roleId}/toggle-active")
        ->assertOk();
    expect(Role::query()->withoutTenantScope()->find($roleId)->is_active)->toBeTrue();
});

it('refuses to deactivate the full administrator role', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenant = licensedTenant();
    $fullAdmin = Role::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->where('is_full_admin', true)->firstOrFail();

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/roles/{$fullAdmin->id}/toggle-active")
        ->assertStatus(422);
});

it('cannot reach another tenant role through this tenant endpoint', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $tenantA = licensedTenant();
    $tenantB = licensedTenant();
    $roleB = Role::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->firstOrFail();

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenantA->id}/roles/{$roleB->id}")
        ->assertNotFound();

    $this->putJson("http://admin.localhost/api/central/tenants/{$tenantA->id}/roles/{$roleB->id}", [
        'name' => 'Hijacked',
        'is_active' => true,
        'permissions' => [],
    ])->assertNotFound();
});
