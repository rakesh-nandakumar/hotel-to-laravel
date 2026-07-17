<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    /**
     * Verifies the signed reset link is still valid (the `signed` route
     * middleware already rejected a tampered/expired one) and echoes back
     * the email so the SPA's reset-password screen can render.
     */
    public function create(Request $request): JsonResponse
    {
        return response()->json([
            'valid' => true,
            'email' => Str::lower(trim((string) $request->query('email'))),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
            'password' => [
                'required',
                'confirmed',
                'max:128',
                Password::min(12)->mixedCase()->numbers()->uncompromised(),
            ],
        ]);

        $email = Str::lower(trim($validated['email']));
        $attemptKey = "password.reset.attempt:{$email}";

        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($attemptKey),
                ]),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user || $user->password_reset_otp_hash === null) {
            RateLimiter::hit($attemptKey, 300);

            throw ValidationException::withMessages([
                'code' => __('Invalid or expired code.'),
            ]);
        }

        if ($user->password_reset_otp_expires_at === null || $user->password_reset_otp_expires_at->isPast()) {
            RateLimiter::hit($attemptKey, 300);

            throw ValidationException::withMessages([
                'code' => __('Invalid or expired code.'),
            ]);
        }

        if (! Hash::check($validated['code'], $user->password_reset_otp_hash)) {
            RateLimiter::hit($attemptKey, 300);

            throw ValidationException::withMessages([
                'code' => __('Invalid or expired code.'),
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'password_reset_otp_hash' => null,
            'password_reset_otp_expires_at' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
            'remember_token' => Str::random(60),
        ])->save();

        RateLimiter::clear($attemptKey);

        AuditLog::record('user.password_reset_completed', $user, [
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => __('Your password has been reset. You can now log in.'),
        ]);
    }
}
