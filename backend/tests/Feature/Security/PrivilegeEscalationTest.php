<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);

    // Password::uncompromised() checks haveibeenpwned — never let tests hit it.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

/**
 * A non-admin actor holding exactly the given permissions via a throwaway role.
 */
function escalationActor(string ...$permissionNames): User
{
    static $seq = 0;
    $seq++;

    $role = Role::create(['name' => "Escalation Actor Role {$seq}", 'is_full_admin' => false, 'is_active' => true, 'is_system' => false]);
    $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));

    $user = User::factory()->create();
    $user->roles()->sync([$role->id]);
    $user->flushPermissionCache();

    return $user;
}

function escalationRole(string $name, array $permissionNames, bool $active = true, bool $fullAdmin = false): Role
{
    $role = Role::create(['name' => $name, 'is_full_admin' => $fullAdmin, 'is_active' => $active, 'is_system' => false]);
    $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));

    return $role;
}

/**
 * @return array<string, mixed>
 */
function newUserPayload(array $overrides = []): array
{
    static $seq = 0;
    $seq++;

    return array_merge([
        'name' => "Escalation Target {$seq}",
        'email' => "escalation-target-{$seq}@example.com",
        'password' => 'Str0ng-And-Unique-Pass-9'.$seq,
        'password_confirmation' => 'Str0ng-And-Unique-Pass-9'.$seq,
        'status' => User::STATUS_ACTIVE,
        'role_ids' => [],
        'permissions' => [],
        'warehouse_ids' => [],
    ], $overrides);
}

const ACTOR_USER_PERMS = [
    'user_management_users.access', 'user_management_users.view',
    'user_management_users.create', 'user_management_users.edit',
    'user_management_users.delete',
];

// ─── Creating users ──────────────────────────────────────────────────────────

it('blocks a non-admin from creating a user with a full-admin role', function () {
    $fullAdminRole = Role::where('is_full_admin', true)->firstOrFail();
    $actor = escalationActor(...ACTOR_USER_PERMS);

    $this->actingAs($actor)
        ->postJson(route('user-management.users.store'), newUserPayload([
            'role_ids' => [$fullAdminRole->id],
        ]))
        ->assertForbidden();
});

it('blocks a non-admin from granting a permission they do not hold themselves', function () {
    $actor = escalationActor(...ACTOR_USER_PERMS);

    $this->actingAs($actor)
        ->postJson(route('user-management.users.store'), newUserPayload([
            'permissions' => ['audit_logs.access'],
        ]))
        ->assertForbidden();
});

it('strips role permissions beyond the requested effective set via deny overrides', function () {
    $powerful = escalationRole('Overpowered', ['audit_logs.access', 'audit_logs.view', 'user_management_roles.access']);
    $actor = escalationActor(...[...ACTOR_USER_PERMS, 'audit_logs.access']);

    $this->actingAs($actor)
        ->postJson(route('user-management.users.store'), newUserPayload([
            'email' => 'bounded-user@example.com',
            'role_ids' => [$powerful->id],
            'permissions' => ['audit_logs.access'],
        ]))
        ->assertCreated();

    $created = User::where('email', 'bounded-user@example.com')->firstOrFail();

    expect($created->computeEffectivePermissionNames()->sort()->values()->all())
        ->toBe(['audit_logs.access']);
});

it('rejects assigning an inactive role', function () {
    $dormant = escalationRole('Dormant', ['audit_logs.access'], active: false);
    $actor = escalationActor(...ACTOR_USER_PERMS);

    $this->actingAs($actor)
        ->postJson(route('user-management.users.store'), newUserPayload([
            'role_ids' => [$dormant->id],
        ]))
        ->assertStatus(422);
});

// ─── Editing users ───────────────────────────────────────────────────────────

it('blocks a non-admin from editing a user who holds permissions they lack', function () {
    $actor = escalationActor(...ACTOR_USER_PERMS);
    $stronger = escalationActor('audit_logs.access', 'audit_logs.view');

    $this->actingAs($actor)
        ->putJson(route('user-management.users.update', $stronger), newUserPayload([
            'name' => $stronger->name,
            'email' => $stronger->email,
        ]))
        ->assertForbidden();
});

it('blocks a non-admin from editing or deleting a full-admin user', function () {
    $actor = escalationActor(...ACTOR_USER_PERMS);
    $admin = fullAdmin();

    $this->actingAs($actor)
        ->putJson(route('user-management.users.update', $admin), newUserPayload([
            'name' => $admin->name,
            'email' => $admin->email,
        ]))
        ->assertForbidden();

    $this->actingAs($actor)
        ->deleteJson(route('user-management.users.destroy', $admin))
        ->assertForbidden();
});

it('blocks a non-admin from granting extra permissions on update', function () {
    $actor = escalationActor(...ACTOR_USER_PERMS);
    $target = escalationActor(); // holds nothing

    $this->actingAs($actor)
        ->putJson(route('user-management.users.update', $target), newUserPayload([
            'name' => $target->name,
            'email' => $target->email,
            'permissions' => ['audit_logs.access'],
        ]))
        ->assertForbidden();
});

it('blocks users from deleting their own account through user management', function () {
    $actor = escalationActor(...ACTOR_USER_PERMS);

    $this->actingAs($actor)
        ->deleteJson(route('user-management.users.destroy', $actor))
        ->assertForbidden();
});

// ─── Roles ───────────────────────────────────────────────────────────────────

it('blocks creating a role containing permissions the actor does not hold', function () {
    $actor = escalationActor('user_management_roles.access', 'user_management_roles.create');

    $this->actingAs($actor)
        ->postJson(route('user-management.roles.store'), [
            'name' => 'Sneaky Role',
            'description' => null,
            'is_active' => true,
            'permissions' => ['audit_logs.access'],
        ])
        ->assertForbidden();
});

it('blocks updating a role to include permissions the actor does not hold', function () {
    $actor = escalationActor('user_management_roles.access', 'user_management_roles.edit', 'audit_logs.view');
    $role = escalationRole('Editable', ['audit_logs.view']);

    $this->actingAs($actor)
        ->putJson(route('user-management.roles.update', $role), [
            'name' => $role->name,
            'description' => null,
            'is_active' => true,
            'permissions' => ['audit_logs.view', 'audit_logs.access'],
        ])
        ->assertForbidden();
});

it('blocks a non-admin from duplicating a full-admin role', function () {
    $fullAdminRole = Role::where('is_full_admin', true)->firstOrFail();
    $actor = escalationActor('user_management_roles.access', 'user_management_roles.duplicate');

    $this->actingAs($actor)
        ->postJson(route('user-management.roles.duplicate', $fullAdminRole))
        ->assertForbidden();
});

it('lets a full admin duplicate the full-admin role as a regular role', function () {
    $fullAdminRole = Role::where('is_full_admin', true)->firstOrFail();

    $this->actingAs(fullAdmin())
        ->postJson(route('user-management.roles.duplicate', $fullAdminRole))
        ->assertCreated();

    $copy = Role::where('name', 'like', 'Copy of%')->firstOrFail();
    expect($copy->is_full_admin)->toBeFalse()
        ->and($copy->is_system)->toBeFalse();
});

// ─── Sanity: full admins are not blocked ─────────────────────────────────────

it('lets a full admin assign the full-admin role', function () {
    $fullAdminRole = Role::where('is_full_admin', true)->firstOrFail();

    $this->actingAs(fullAdmin())
        ->postJson(route('user-management.users.store'), newUserPayload([
            'email' => 'new-admin@example.com',
            'role_ids' => [$fullAdminRole->id],
        ]))
        ->assertCreated();

    $created = User::where('email', 'new-admin@example.com')->firstOrFail();
    expect($created->isFullAdmin())->toBeTrue();
});
