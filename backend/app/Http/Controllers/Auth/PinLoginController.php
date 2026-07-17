<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PinLoginRequest;
use App\Services\DeviceTokenService;
use App\Services\UserLanding;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * PIN quick-unlock for POS terminals — requires a device token issued at a
 * prior full login (see DeviceTokenController), so the PIN pad can never be
 * used for an account that hasn't credential-signed-in on this device.
 * Ported from the Node app's POST /auth/pin-login.
 */
class PinLoginController extends Controller
{
    public function __construct(private readonly DeviceTokenService $deviceTokens) {}

    public function store(PinLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->deviceTokens->resolve($data['device_token']);

        if (! $user || ! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            throw ValidationException::withMessages(['pin' => 'Wrong PIN, or this device is no longer trusted — sign in with email and password.']);
        }

        if (! $user->isActive() || $user->isLocked()) {
            throw ValidationException::withMessages(['pin' => 'This account is not available for sign-in.']);
        }

        // The PIN pad is a low-friction convenience, not a second factor —
        // any account that itself requires stronger verification must use
        // the full email+password (+2FA/OTP) login instead.
        $needsStrongerAuth = ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null)
            || $user->two_factor_email_enabled
            || $user->two_factor_required;

        if ($needsStrongerAuth) {
            throw ValidationException::withMessages(['pin' => 'This account requires two-factor sign-in — PIN unlock is not available.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['home' => UserLanding::urlFor($user)]);
    }
}
