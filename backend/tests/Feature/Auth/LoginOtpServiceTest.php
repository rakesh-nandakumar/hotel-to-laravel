<?php

use App\Mail\OtpMail;
use App\Models\User;
use App\Services\LoginOtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->service = app(LoginOtpService::class);
    $this->user = User::factory()->create();
});

it('issues a six digit code and stores only its hash', function () {
    $code = $this->service->issue($this->user);

    expect($code)->toMatch('/^\d{6}$/');

    $this->user->refresh();
    expect($this->user->otp_hash)->not->toBe($code)
        ->and(Hash::check($code, $this->user->otp_hash))->toBeTrue()
        ->and($this->user->otp_expires_at->isFuture())->toBeTrue()
        ->and($this->user->otp_attempts)->toBe(0)
        ->and($this->user->last_otp_sent_at)->not->toBeNull();
});

it('verifies a valid code exactly once', function () {
    $code = $this->service->issue($this->user);

    expect($this->service->verify($this->user->refresh(), $code))->toBeTrue();

    // Single use: the hash is cleared, so the same code no longer verifies.
    expect($this->user->refresh()->otp_hash)->toBeNull()
        ->and($this->service->verify($this->user, $code))->toBeFalse();
});

it('rejects an expired code', function () {
    $code = $this->service->issue($this->user);
    $this->user->forceFill(['otp_expires_at' => now()->subMinute()])->save();

    expect($this->service->verify($this->user->refresh(), $code))->toBeFalse();
});

it('burns attempts on wrong codes and locks after the limit', function () {
    $code = $this->service->issue($this->user);
    $this->user->refresh();

    foreach (range(1, LoginOtpService::MAX_ATTEMPTS) as $i) {
        expect($this->service->verify($this->user, '000000'))->toBeFalse();
        $this->user->refresh();
    }

    expect($this->user->otp_attempts)->toBe(LoginOtpService::MAX_ATTEMPTS);

    // Even the correct code is refused once attempts are exhausted.
    expect($this->service->verify($this->user, $code))->toBeFalse();
});

it('enforces the resend cooldown', function () {
    expect($this->service->issue($this->user))->not->toBeNull();

    // Immediately again: held by the cooldown.
    expect($this->service->issue($this->user->refresh()))->toBeNull()
        ->and($this->service->secondsUntilResend($this->user))->toBeGreaterThan(0);

    // After the cooldown a fresh code is issued.
    $this->travel(LoginOtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    expect($this->service->issue($this->user->refresh()))->not->toBeNull();
});

it('sends the code by email through the queue', function () {
    Mail::fake();

    expect($this->service->send($this->user))->toBeTrue();

    Mail::assertQueued(OtpMail::class, fn ($mail) => $mail->hasTo($this->user->email) && $mail->purpose === 'login');

    // A second send inside the cooldown is refused and nothing is queued.
    expect($this->service->send($this->user->refresh()))->toBeFalse();
    Mail::assertQueuedCount(1);
});
