<?php

use App\Models\User;

test('profile endpoint is accessible', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->getJson('/api/settings/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/settings/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response->assertOk();

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/settings/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response->assertOk();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->deleteJson('/api/settings/profile', [
            'password' => 'password',
        ]);

    $response->assertOk();

    $this->assertGuest();
    expect(User::withTrashed()->find($user->id)?->deleted_at)->not->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->deleteJson('/api/settings/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect($user->fresh())->not->toBeNull();
});
