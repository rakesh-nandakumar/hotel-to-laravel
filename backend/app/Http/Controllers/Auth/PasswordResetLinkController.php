<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OtpMail;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));

        $burstKey = "password.reset.send.burst:{$email}";
        $dailyKey = "password.reset.send.daily:{$email}";

        if (RateLimiter::tooManyAttempts($burstKey, 3)) {
            throw ValidationException::withMessages([
                'email' => __('Too many requests. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($burstKey),
                ]),
            ]);
        }

        if (RateLimiter::tooManyAttempts($dailyKey, 10)) {
            throw ValidationException::withMessages([
                'email' => __('You have reached the daily limit for reset requests.'),
            ]);
        }

        RateLimiter::hit($burstKey, 300);
        RateLimiter::hit($dailyKey, 86400);

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $user->forceFill([
                'password_reset_otp_hash' => Hash::make($otp),
                'password_reset_otp_expires_at' => now()->addMinutes(15),
            ])->save();

            Mail::to($user->email)->queue(new OtpMail($otp, 'forgot_password', 15));

            AuditLog::record('user.password_reset_requested', $user, [
                'ip' => $request->ip(),
            ]);
        }

        $signedUrl = URL::temporarySignedRoute(
            'password.reset',
            now()->addMinutes(20),
            ['email' => $email],
        );

        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);

        return response()->json([
            'message' => __('If an account exists for this email, we have sent a verification code.'),
            'email' => $email,
            'expires' => $query['expires'] ?? null,
            'signature' => $query['signature'] ?? null,
        ]);
    }
}
