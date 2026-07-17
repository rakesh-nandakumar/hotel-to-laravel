<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

function makeRole(string $name, array $permissionNames, bool $active = true, bool $fullAdmin = false): Role
{
    $role = Role::create(['name' => $name, 'is_active' => $active, 'is_full_admin' => $fullAdmin, 'is_system' => false]);
    $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id')->all());

    return $role;
}

it('computes effective permissions as roles + allow − deny', function () {
    $user = User::factory()->create();
    $role = makeRole('Clerk', ['user_management_users.access', 'user_management_users.view', 'user_management_users.create']);
    $user->roles()->sync([$role->id]);

    // allow an extra permission, deny one the role grants
    $user->permissionOverrides()->attach(Permission::where('name', 'user_management_users.edit')->value('id'), ['type' => 'allow', 'granted_at' => now()]);
    $user->permissionOverrides()->attach(Permission::where('name', 'user_management_users.create')->value('id'), ['type' => 'deny', 'granted_at' => now()]);
    $user->flushPermissionCache();

    $effective = $user->computeEffectivePermissionNames();

    expect($effective)->toContain('user_management_users.view');   // from role
    expect($effective)->toContain('user_management_users.edit');   // allow override
    expect($effective)->not->toContain('user_management_users.create'); // deny override wins
    expect($user->hasPermissionTo('user_management_users.edit'))->toBeTrue();
    expect($user->hasPermissionTo('user_management_users.create'))->toBeFalse();
});

it('unions permissions across multiple roles and ignores inactive roles', function () {
    $user = User::factory()->create();
    $users = makeRole('UsersA', ['user_management_users.access', 'user_management_users.create']);
    $roles = makeRole('RolesA', ['user_management_roles.access', 'user_management_roles.create']);
    $dead = makeRole('DeadA', ['audit_logs.access'], active: false);
    $user->roles()->sync([$users->id, $roles->id, $dead->id]);
    $user->flushPermissionCache();

    expect($user->hasPermissionTo('user_management_users.create'))->toBeTrue();
    expect($user->hasPermissionTo('user_management_roles.create'))->toBeTrue();
    expect($user->hasPermissionTo('audit_logs.access'))->toBeFalse(); // inactive role grants nothing
});

it('lets a full-admin role bypass every check', function () {
    $user = User::factory()->create();
    $user->roles()->sync([Role::where('name', 'Full Administrator')->value('id')]);
    $user->flushPermissionCache();

    expect($user->isFullAdmin())->toBeTrue();
    expect($user->hasPermissionTo('anything.not.seeded'))->toBeTrue();
    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
});

it('returns 403 for a user without dashboard access', function () {
    $rep = User::factory()->create();
    $rep->roles()->sync([makeRole('Users Only', ['user_management_users.access'])->id]);
    $rep->flushPermissionCache();

    $this->actingAs($rep)
        ->getJson('/api/dashboard')
        ->assertForbidden();
});

it('forbids modules the user lacks and allows the ones they hold', function () {
    $rep = User::factory()->create();
    $rep->roles()->sync([makeRole('Users Only', ['user_management_users.access'])->id]);
    $rep->flushPermissionCache();

    $this->actingAs($rep)->getJson(route('user-management.users.index'))->assertOk();  // granted
    $this->actingAs($rep)->getJson(route('audit-logs.index'))->assertForbidden();      // not granted
});

it('points a restricted user home at their first accessible module on login', function () {
    $rep = User::factory()->create();
    $rep->roles()->sync([makeRole('Users Only', ['user_management_users.access'])->id]);
    $rep->flushPermissionCache();

    $response = $this->postJson('/api/login', ['email' => $rep->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($rep);
    $response->assertOk()->assertJson(['home' => route('user-management.users.index')]);
});

it('points a full admin home at the dashboard on login', function () {
    $admin = fullAdmin();

    $response = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($admin);
    $response->assertOk()->assertJson(['home' => route('dashboard')]);
});
