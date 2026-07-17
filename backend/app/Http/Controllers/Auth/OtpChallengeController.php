<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\LoginOtpService;
use App\Services\UserLanding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email-OTP second factor. The password step stores the pending user id in
 * the session (otp.login.id) and sends a code; no session is established
 * until the code verifies.
 */
class OtpChallengeController extends Controller
{
    public function __construct(private readonly LoginOtpService $otp) {}

    public function create(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return response()->json(['message' => 'No pending login challenge.'], 409);
        }

        return response()->json([
            'maskedEmail' => $this->maskEmail($user->email),
            'resendIn' => $this->otp->secondsUntilResend($user),
            'attemptsRemaining' => $this->otp->attemptsRemaining($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'size:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ]);

        $user = $this->pendingUser($request);

        if (! $user) {
            return response()->json(['message' => 'No pending login challenge.'], 409);
        }

        // Re-check account state: it may have changed since the password step.
        if (! $user->isActive() || $user->isLocked()) {
            $request->session()->forget(['otp.login.id', 'otp.login.remember']);

            throw ValidationException::withMessages([
                'email' => __('Your account is not able to log in. Contact an administrator.'),
            ]);
        }

        $verified = $request->filled('recovery_code')
            ? $this->redeemRecoveryCode($user, (string) $request->input('recovery_code'))
            : $this->otp->verify($user, (string) $request->input('code'));

        if (! $verified) {
            AuditLog::record('user.login_otp_failed', $user, [
                'ip' => $request->ip(),
                'via' => $request->filled('recovery_code') ? 'recovery_code' : 'otp',
            ]);

            $field = $request->filled('recovery_code') ? 'recovery_code' : 'code';

            throw ValidationException::withMessages([
                $field => __('The provided code is invalid or has expired.'),
            ]);
        }

        $remember = (bool) $request->session()->pull('otp.login.remember', false);
        $request->session()->forget('otp.login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return response()->json([
            'home' => UserLanding::urlFor($user),
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return response()->json(['message' => 'No pending login challenge.'], 409);
        }

        if (! $this->otp->send($user)) {
            throw ValidationException::withMessages([
                'code' => __('Please wait before requesting another code.'),
            ]);
        }

        return response()->json([
            'message' => __('A new code has been sent to your email.'),
        ]);
    }

    /**
     * A used recovery code is removed and replaced with a fresh one, matching
     * the Fortify behaviour the TOTP challenge uses.
     */
    private function redeemRecoveryCode(User $user, string $submitted): bool
    {
        if ($user->two_factor_recovery_codes === null) {
            return false;
        }

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];

        $match = collect($codes)->first(fn ($code) => hash_equals($code, $submitted));

        if ($match === null) {
            return false;
        }

        $replacement = Str::random(10).'-'.Str::random(10);

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(
                collect($codes)->map(fn ($code) => $code === $match ? $replacement : $code)->all(),
            )),
        ])->save();

        $this->otp->clear($user);

        AuditLog::record('user.recovery_code_used', $user, ['ip' => request()->ip()]);

        return true;
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('otp.login.id');

        return $id ? User::query()->find($id) : null;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + ['', ''];

        $visible = Str::substr($local, 0, 2);

        return $visible.str_repeat('*', max(1, strlen($local) - 2)).'@'.$domain;
    }
}
