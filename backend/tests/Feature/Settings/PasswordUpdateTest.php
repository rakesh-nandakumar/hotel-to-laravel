<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->putJson('/api/settings/password', [
            'current_password' => 'password',
            'password' => 'New-Settings-Pass-2024',
            'password_confirmation' => 'New-Settings-Pass-2024',
        ]);

    $response->assertOk();

    expect(Hash::check('New-Settings-Pass-2024', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->putJson('/api/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'New-Settings-Pass-2024',
            'password_confirmation' => 'New-Settings-Pass-2024',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');
});

test('weak passwords are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/settings/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});
