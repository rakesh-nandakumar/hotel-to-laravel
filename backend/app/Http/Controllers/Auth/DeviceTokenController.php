<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues a device token for the current session, unlocking PIN quick-login
 * on this device for this user only. Called by the SPA right after a full
 * login (or whenever the user opts in from a "remember this device" prompt) —
 * decoupled from the login response itself so it works identically whether
 * the session was established by password, 2FA, or email-OTP.
 */
class DeviceTokenController extends Controller
{
    public function __construct(private readonly DeviceTokenService $deviceTokens) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json(['device_token' => $this->deviceTokens->issue($request->user())]);
    }
}
