<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

function flaggedUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['must_change_password' => true])->save();

    return $user;
}

it('blocks a flagged user from other endpoints until the password is changed', function () {
    $user = flaggedUser();

    $this->actingAs($user)->getJson(route('profile.edit'))
        ->assertForbidden()
        ->assertJson(['error_code' => 'must_change_password']);

    // The password-update endpoint itself stays reachable.
    $this->actingAs($user)->putJson(route('password.update'), [
        'current_password' => 'password',
        'password' => 'Fresh-Str0ng-Password-77',
        'password_confirmation' => 'Fresh-Str0ng-Password-77',
    ])->assertOk();
});

it('still allows a flagged user to log out', function () {
    $user = flaggedUser();

    $this->actingAs($user)->postJson(route('logout'))->assertOk();
    $this->assertGuest();
});

it('clears the flag and stamps password_changed_at on a self-service change', function () {
    $user = flaggedUser();

    $this->actingAs($user)->putJson(route('password.update'), [
        'current_password' => 'password',
        'password' => 'Fresh-Str0ng-Password-77',
        'password_confirmation' => 'Fresh-Str0ng-Password-77',
    ])->assertOk();

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(Hash::check('Fresh-Str0ng-Password-77', $user->password))->toBeTrue();

    // Navigation opens back up once the password is changed.
    $this->actingAs($user)->getJson(route('profile.edit'))->assertOk();
});

it('rejects weak passwords on the forced-change form', function () {
    $user = flaggedUser();

    $this->actingAs($user)->putJson(route('password.update'), [
        'current_password' => 'password',
        'password' => 'short1A',
        'password_confirmation' => 'short1A',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('flags users created through user management', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    $this->actingAs(fullAdmin())->postJson(route('user-management.users.store'), [
        'name' => 'Provisioned User',
        'email' => 'provisioned@example.com',
        'password' => 'Adm1n-Provisioned-Pass-9',
        'password_confirmation' => 'Adm1n-Provisioned-Pass-9',
        'status' => User::STATUS_ACTIVE,
        'role_ids' => [],
        'permissions' => [],
        'warehouse_ids' => [],
    ])->assertCreated();

    $created = User::where('email', 'provisioned@example.com')->firstOrFail();
    expect($created->must_change_password)->toBeTrue();
});

it('lets an admin require two-factor authentication for a user', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    $this->actingAs(fullAdmin())->postJson(route('user-management.users.store'), [
        'name' => 'Enforced User',
        'email' => 'enforced@example.com',
        'password' => 'Adm1n-Provisioned-Pass-9',
        'password_confirmation' => 'Adm1n-Provisioned-Pass-9',
        'status' => User::STATUS_ACTIVE,
        'two_factor_required' => true,
        'role_ids' => [],
        'permissions' => [],
        'warehouse_ids' => [],
    ])->assertCreated();

    $created = User::where('email', 'enforced@example.com')->firstOrFail();
    expect($created->two_factor_required)->toBeTrue();

    // And it can be cleared again on edit.
    $this->actingAs(fullAdmin())->putJson(route('user-management.users.update', $created), [
        'name' => $created->name,
        'email' => $created->email,
        'status' => User::STATUS_ACTIVE,
        'two_factor_required' => false,
        'role_ids' => [],
        'permissions' => [],
        'warehouse_ids' => [],
    ])->assertOk();

    expect($created->fresh()->two_factor_required)->toBeFalse();
});

it('flags users whose password an admin resets', function () {
    $user = User::factory()->create();

    $this->actingAs(fullAdmin())->postJson(route('user-management.users.reset-password', $user), [
        'password' => 'Adm1n-Reset-Pass-2024x',
        'password_confirmation' => 'Adm1n-Reset-Pass-2024x',
    ])->assertOk();

    expect($user->fresh()->must_change_password)->toBeTrue();
});
