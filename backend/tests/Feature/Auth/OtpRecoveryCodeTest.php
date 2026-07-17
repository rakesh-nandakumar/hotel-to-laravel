<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function emailOtpUserWithRecovery(array $codes): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_email_enabled' => true,
        'two_factor_recovery_codes' => encrypt(json_encode($codes)),
    ])->save();

    return $user;
}

function startOtpChallenge(User $user): void
{
    test()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJson(['challenge' => 'otp']);
    test()->assertGuest();
}

it('accepts a recovery code as an alternative to the emailed otp', function () {
    $user = emailOtpUserWithRecovery(['otp-recovery-11111', 'otp-recovery-22222']);

    startOtpChallenge($user);

    $this->postJson(route('otp.login.store'), ['recovery_code' => 'otp-recovery-11111'])
        ->assertOk();

    $this->assertAuthenticatedAs($user);

    // The code was rotated out and the pending OTP invalidated.
    $user->refresh();
    $remaining = json_decode(decrypt($user->two_factor_recovery_codes), true);
    expect($remaining)->toHaveCount(2)
        ->and($remaining)->not->toContain('otp-recovery-11111')
        ->and($user->otp_hash)->toBeNull();

    expect(AuditLog::where('action', 'user.recovery_code_used')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('refuses a recovery code that was already used', function () {
    $user = emailOtpUserWithRecovery(['otp-recovery-11111']);

    startOtpChallenge($user);
    $this->postJson(route('otp.login.store'), ['recovery_code' => 'otp-recovery-11111']);
    $this->assertAuthenticatedAs($user);

    $this->postJson(route('logout'));

    startOtpChallenge($user);
    $this->postJson(route('otp.login.store'), ['recovery_code' => 'otp-recovery-11111'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recovery_code');

    $this->assertGuest();
});

it('refuses recovery codes for users who have none stored', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_email_enabled' => true])->save();

    startOtpChallenge($user);

    $this->postJson(route('otp.login.store'), ['recovery_code' => 'anything-at-all'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recovery_code');

    $this->assertGuest();
});
