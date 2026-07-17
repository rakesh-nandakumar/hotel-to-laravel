<?php

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Mail::fake();
});

function requiredUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['two_factor_required' => true])->save();

    return $user;
}

it('challenges a required user by email even without any enrolment', function () {
    $user = requiredUser();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJson(['challenge' => 'otp']);

    $this->assertGuest();
    Mail::assertQueued(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo($user->email));
});

it('prefers the authenticator challenge when a required user has TOTP configured', function () {
    $user = requiredUser();
    $user->forceFill([
        'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJson(['challenge' => 'two-factor']);

    Mail::assertNothingQueued();
});

it('refuses to let a required user disable email codes', function () {
    $user = requiredUser();
    $user->forceFill(['two_factor_email_enabled' => true])->save();

    $this->actingAs($user)->deleteJson(route('profile.two-factor.email.disable'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('two_factor');

    expect($user->fresh()->two_factor_email_enabled)->toBeTrue();
});

it('refuses to let a required user disable TOTP through the Fortify endpoint', function () {
    $user = requiredUser();
    $user->forceFill([
        'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)->deleteJson('/api/user/two-factor-authentication')
        ->assertForbidden();

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

it('still lets an unrequired user disable TOTP', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)->deleteJson('/api/user/two-factor-authentication')
        ->assertOk();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});
