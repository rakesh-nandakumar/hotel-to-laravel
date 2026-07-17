<?php

use App\Models\Role;
use App\Models\User;

test('guests receive a 401 from the dashboard endpoint', function () {
    $this->getJson('/api/dashboard')->assertUnauthorized();
});

test('authenticated users can visit the dashboard', function () {
    $role = Role::factory()->create(['is_full_admin' => true]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $user->roles()->attach($role->id);
    $user->flushPermissionCache();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
});
