<?php

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // Password::uncompromised() checks haveibeenpwned — never let tests hit it.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
});

test('requesting a reset sends a one-time code by email and stores only its hash', function () {
    Mail::fake();

    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    Mail::assertQueued(OtpMail::class, fn ($mail) => $mail->hasTo($user->email));

    $user->refresh();
    expect($user->password_reset_otp_hash)->not->toBeNull()
        ->and($user->password_reset_otp_expires_at->isFuture())->toBeTrue();
});

test('requesting a reset for an unknown email reveals nothing', function () {
    Mail::fake();

    $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Mail::assertNothingQueued();
});

test('password can be reset with a valid code', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'password_reset_otp_hash' => Hash::make('123456'),
        'password_reset_otp_expires_at' => now()->addMinutes(10),
        'failed_login_count' => 7,
    ])->save();

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => '123456',
        'password' => 'New-Str0ng-Password-42',
        'password_confirmation' => 'New-Str0ng-Password-42',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('New-Str0ng-Password-42', $user->password))->toBeTrue()
        ->and($user->password_reset_otp_hash)->toBeNull()
        ->and($user->failed_login_count)->toBe(0);
});

test('an expired or wrong code is rejected', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'password_reset_otp_hash' => Hash::make('123456'),
        'password_reset_otp_expires_at' => now()->subMinute(),
    ])->save();

    // Expired
    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => '123456',
        'password' => 'New-Str0ng-Password-42',
        'password_confirmation' => 'New-Str0ng-Password-42',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');

    // Wrong code on a fresh window
    $user->forceFill(['password_reset_otp_expires_at' => now()->addMinutes(10)])->save();

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => '654321',
        'password' => 'New-Str0ng-Password-42',
        'password_confirmation' => 'New-Str0ng-Password-42',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});
