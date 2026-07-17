<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Email one-time-password engine for the login second factor.
 *
 * Storage rules: only a bcrypt hash of the code is persisted, codes expire,
 * are single-use, allow a bounded number of verification attempts, and
 * re-sending is held behind a cooldown.
 */
class LoginOtpService
{
    public const EXPIRY_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Generate and store a fresh code for the user. Returns the plain code
     * (for delivery) or null when still inside the resend cooldown.
     */
    public function issue(User $user): ?string
    {
        if ($this->secondsUntilResend($user) > 0) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'otp_attempts' => 0,
            'last_otp_sent_at' => now(),
        ])->save();

        return $code;
    }

    /**
     * Issue a fresh code and email it. Returns false when held by the
     * resend cooldown.
     */
    public function send(User $user): bool
    {
        $code = $this->issue($user);

        if ($code === null) {
            return false;
        }

        Mail::to($user->email)->queue(new OtpMail($code, 'login', self::EXPIRY_MINUTES));

        AuditLog::record('user.login_otp_sent', $user);

        return true;
    }

    /**
     * Verify a submitted code. Codes are single-use: success clears the
     * stored hash; each failure burns one of the bounded attempts.
     */
    public function verify(User $user, string $code): bool
    {
        if ($user->otp_hash === null
            || $user->otp_expires_at === null
            || $user->otp_expires_at->isPast()) {
            return false;
        }

        if ($user->otp_attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (! Hash::check($code, $user->otp_hash)) {
            $user->increment('otp_attempts');

            return false;
        }

        $this->clear($user);

        return true;
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();
    }

    public function secondsUntilResend(User $user): int
    {
        if ($user->last_otp_sent_at === null) {
            return 0;
        }

        $availableAt = $user->last_otp_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return max(0, (int) now()->diffInSeconds($availableAt, false));
    }

    public function attemptsRemaining(User $user): int
    {
        return max(0, self::MAX_ATTEMPTS - (int) $user->otp_attempts);
    }
}
