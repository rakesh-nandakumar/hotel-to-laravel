<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

function twoFactorUser(): array
{
    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();

    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode([
            'RECOVERY-CODE-one-11111',
            'RECOVERY-CODE-two-22222',
        ])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return [$user, $secret];
}

it('challenges a confirmed two-factor user instead of logging in', function () {
    [$user] = twoFactorUser();

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()->assertJson(['challenge' => 'two-factor']);
    $this->assertGuest();
});

it('logs in a user without two-factor directly', function () {
    $user = User::factory()->create();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

it('completes the challenge with a valid TOTP code and stamps the login', function () {
    [$user, $secret] = twoFactorUser();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertGuest();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson('/api/two-factor-challenge', ['code' => $code])
        ->assertNoContent();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects an invalid TOTP code at the challenge', function () {
    [$user] = twoFactorUser();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);

    $this->postJson('/api/two-factor-challenge', ['code' => '000000'])
        ->assertUnprocessable();

    $this->assertGuest();
});

it('does not allow opening the challenge without a pending login', function () {
    // Fortify's view route (GET /two-factor-challenge) is disabled entirely
    // ('views' => false) — only the POST verification route is registered,
    // so GET matches no route method and Laravel returns 405.
    $this->getJson('/api/two-factor-challenge')->assertStatus(405);
});

it('still refuses wrong passwords for two-factor users before any challenge', function () {
    [$user] = twoFactorUser();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->assertGuest();
});
