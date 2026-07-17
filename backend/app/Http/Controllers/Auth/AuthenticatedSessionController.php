<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\LoginOtpService;
use App\Services\UserLanding;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * A real bcrypt hash checked when the email is unknown, so failed logins
     * take the same time whether or not the account exists.
     */
    private const DUMMY_HASH = '$2y$12$R3wmC/5gROdBN0xdpYKmGuWDGCRAwOJaBqmf5MF/1sgUl7bebAu1O';

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $password = $validated['password'];
        $remember = (bool) ($validated['remember'] ?? false);

        $burstKey = $this->burstKey($email, $request);
        $lockoutKey = $this->lockoutKey($email);

        if (RateLimiter::tooManyAttempts($burstKey, 5)) {
            event(new Lockout($request));

            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($burstKey),
                ]),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password ?? self::DUMMY_HASH)) {
            if ($user) {
                $user->increment('failed_login_count');

                if ($user->failed_login_count >= User::LOCKOUT_THRESHOLD) {
                    $user->forceFill([
                        'locked_until' => now()->addHours(User::LOCKOUT_DURATION_HOURS),
                    ])->save();

                    AuditLog::record('user.locked', $user, [
                        'reason' => 'failed_login_threshold',
                    ]);
                }
            }

            RateLimiter::hit($burstKey, 60);
            RateLimiter::hit($lockoutKey, 86400);

            AuditLog::record('user.login_failed', $user, [
                'email' => $email,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if ($user->isLocked()) {
            AuditLog::record('user.login_failed', $user, [
                'reason' => 'locked',
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => __('This account is temporarily locked. Contact an administrator.'),
            ]);
        }

        if (! $user->isActive()) {
            AuditLog::record('user.login_failed', $user, [
                'reason' => $user->status,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => $user->isSuspended()
                    ? __('Your account has been suspended. Contact an administrator.')
                    : __('Your account is not active. Contact an administrator.'),
            ]);
        }

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        RateLimiter::clear($burstKey);
        RateLimiter::clear($lockoutKey);

        // Confirmed two-factor users must pass the Fortify challenge (TOTP or
        // recovery code) before a session is established.
        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $remember,
            ]);

            AuditLog::record('user.two_factor_challenged', $user, [
                'ip' => $request->ip(),
            ]);

            return response()->json(['challenge' => 'two-factor']);
        }

        // Email-OTP second factor: opted in by the user, or enforced by an
        // administrator (which needs no enrolment — the inbox is the factor).
        if ($user->two_factor_email_enabled || $user->two_factor_required) {
            $request->session()->put([
                'otp.login.id' => $user->getKey(),
                'otp.login.remember' => $remember,
            ]);

            app(LoginOtpService::class)->send($user);

            AuditLog::record('user.two_factor_challenged', $user, [
                'ip' => $request->ip(),
                'method' => 'email_otp',
            ]);

            return response()->json(['challenge' => 'otp']);
        }

        // Last-login stamping + the user.login audit record happen in the
        // Login event listener (FortifyServiceProvider), shared with the
        // two-factor challenge path.
        Auth::login($user, $remember);

        $request->session()->regenerate();

        return response()->json([
            'home' => UserLanding::urlFor($user),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLog::record('user.logout', $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    private function burstKey(string $email, Request $request): string
    {
        return 'login:burst:'.Str::transliterate(Str::lower($email)).'|'.$request->ip();
    }

    private function lockoutKey(string $email): string
    {
        return 'login:lockout:'.Str::transliterate(Str::lower($email));
    }
}
