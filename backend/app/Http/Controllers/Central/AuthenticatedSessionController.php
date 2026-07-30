<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\CentralAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Session auth for the "master control" panel — a small, trusted set of
 * platform operators. Deliberately minimal next to the tenant-side
 * AuthenticatedSessionController: no 2FA/OTP, no lockouts/permissions.
 */
class AuthenticatedSessionController extends Controller
{
    private const DUMMY_HASH = '$2y$12$R3wmC/5gROdBN0xdpYKmGuWDGCRAwOJaBqmf5MF/1sgUl7bebAu1O';

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $key = 'central-login:'.Str::transliterate($email).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        $admin = CentralAdmin::query()->where('email', $email)->first();

        if (! $admin || ! Hash::check($validated['password'], $admin->password ?? self::DUMMY_HASH) || ! $admin->is_active) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($key);

        Auth::guard('central')->login($admin);
        $request->session()->regenerate();

        return response()->json(['admin' => $admin->only(['id', 'name', 'email'])]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('central')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}
