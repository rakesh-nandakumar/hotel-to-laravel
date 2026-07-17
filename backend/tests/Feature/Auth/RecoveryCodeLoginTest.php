<?php

use App\Models\AuditLog;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

function recoveryUser(array $codes): User
{
    $engine = app(Google2FA::class);

    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt($engine->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function startRecoveryChallenge(User $user): void
{
    test()->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
    test()->assertGuest();
}

it('logs in with a valid recovery code and replaces it', function () {
    $user = recoveryUser(['alpha-code-11111', 'beta-code-22222']);

    startRecoveryChallenge($user);

    $this->postJson('/api/two-factor-challenge', ['recovery_code' => 'alpha-code-11111'])
        ->assertNoContent();

    $this->assertAuthenticatedAs($user);

    $remaining = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
    expect($remaining)->toHaveCount(2)
        ->and($remaining)->not->toContain('alpha-code-11111')
        ->and($remaining)->toContain('beta-code-22222');

    expect(AuditLog::where('action', 'user.recovery_code_used')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('refuses a recovery code that has already been used', function () {
    $user = recoveryUser(['alpha-code-11111', 'beta-code-22222']);

    startRecoveryChallenge($user);
    $this->postJson('/api/two-factor-challenge', ['recovery_code' => 'alpha-code-11111']);
    $this->assertAuthenticatedAs($user);

    $this->postJson('/api/logout');
    $this->assertGuest();

    startRecoveryChallenge($user);
    $this->postJson('/api/two-factor-challenge', ['recovery_code' => 'alpha-code-11111'])
        ->assertUnprocessable();

    $this->assertGuest();
});

it('refuses an unknown recovery code and records the failure', function () {
    $user = recoveryUser(['alpha-code-11111']);

    startRecoveryChallenge($user);

    $this->postJson('/api/two-factor-challenge', ['recovery_code' => 'not-a-real-code'])
        ->assertUnprocessable();

    $this->assertGuest();
    expect(AuditLog::where('action', 'user.two_factor_failed')->where('subject_id', $user->id)->exists())->toBeTrue();
});
