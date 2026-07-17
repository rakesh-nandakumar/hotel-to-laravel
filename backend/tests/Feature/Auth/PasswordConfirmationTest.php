<?php

use App\Models\User;

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertOk()->assertJsonStructure(['two_factor_setup_required']);
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/confirm-password', [
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('password');
});
