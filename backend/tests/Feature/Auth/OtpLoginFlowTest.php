<?php

use App\Mail\OtpMail;
use App\Models\User;
use App\Services\LoginOtpService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function emailOtpUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['two_factor_email_enabled' => true])->save();

    return $user;
}

function loginAndCaptureOtp(User $user): string
{
    test()->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJson(['challenge' => 'otp']);
    test()->assertGuest();

    $otp = null;
    Mail::assertQueued(OtpMail::class, function (OtpMail $mail) use (&$otp) {
        $otp = $mail->otp;

        return true;
    });

    return $otp;
}

it('sends a code and challenges an email-otp user', function () {
    $user = emailOtpUser();

    loginAndCaptureOtp($user);

    $this->getJson(route('otp.login'))->assertOk();
});

it('completes the login with the emailed code', function () {
    $user = emailOtpUser();
    $otp = loginAndCaptureOtp($user);

    $this->postJson(route('otp.login.store'), ['code' => $otp])->assertOk();

    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->otp_hash)->toBeNull()
        ->and($user->last_login_at)->not->toBeNull();
});

it('rejects a wrong code and stays unauthenticated', function () {
    $user = emailOtpUser();
    $otp = loginAndCaptureOtp($user);
    $wrong = $otp === '000000' ? '111111' : '000000';

    $this->postJson(route('otp.login.store'), ['code' => $wrong])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->assertGuest();
    expect($user->fresh()->otp_attempts)->toBe(1);
});

it('locks out the code after too many wrong attempts', function () {
    $user = emailOtpUser();
    $otp = loginAndCaptureOtp($user);
    $wrong = $otp === '000000' ? '111111' : '000000';

    foreach (range(1, LoginOtpService::MAX_ATTEMPTS) as $i) {
        $this->postJson(route('otp.login.store'), ['code' => $wrong]);
    }

    // Correct code no longer works once attempts are exhausted.
    $this->postJson(route('otp.login.store'), ['code' => $otp])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->assertGuest();
});

it('honours the resend cooldown and then sends a fresh code', function () {
    $user = emailOtpUser();
    loginAndCaptureOtp($user);

    // Inside the cooldown: refused.
    $this->postJson(route('otp.login.resend'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
    Mail::assertQueuedCount(1);

    $this->travel(LoginOtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    $this->postJson(route('otp.login.resend'))->assertOk();
    Mail::assertQueuedCount(2);
});

it('reports no pending challenge when none exists', function () {
    $this->getJson(route('otp.login'))->assertStatus(409);
    $this->postJson(route('otp.login.store'), ['code' => '123456'])->assertStatus(409);
});

it('does not challenge users without any second factor', function () {
    $user = User::factory()->create();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    Mail::assertNothingQueued();
});
