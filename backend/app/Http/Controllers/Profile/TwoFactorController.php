<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\RecoveryCode;

class TwoFactorController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'confirmed' => $user->two_factor_confirmed_at !== null,
            'emailEnabled' => (bool) $user->two_factor_email_enabled,
            'required' => (bool) $user->two_factor_required,
            'hasRecoveryCodes' => $user->two_factor_recovery_codes !== null,
        ]);
    }

    public function enableEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['two_factor_email_enabled' => true])->save();

        $codes = null;
        if ($user->two_factor_recovery_codes === null) {
            $codes = $this->generateRecoveryCodes();
            $user->forceFill([
                'two_factor_recovery_codes' => encrypt($codes->toJson()),
            ])->save();
        }

        AuditLog::record('user.two_factor_email_enabled', $user);

        return response()->json([
            'message' => 'Email verification codes are now required when you log in.',
            'freshRecoveryCodes' => $codes?->all(),
        ]);
    }

    public function disableEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        // Administrator-enforced 2FA cannot be switched off by the user.
        if ($user->two_factor_required) {
            throw ValidationException::withMessages([
                'two_factor' => 'Two-factor authentication is required for your account and cannot be disabled.',
            ]);
        }

        $user->forceFill(['two_factor_email_enabled' => false])->save();

        AuditLog::record('user.two_factor_email_disabled', $user);

        return response()->json(['message' => 'Email verification at login has been disabled.']);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        $hasAnySecondFactor = $user->two_factor_confirmed_at !== null
            || $user->two_factor_email_enabled;

        if (! $hasAnySecondFactor) {
            throw ValidationException::withMessages([
                'two_factor' => 'Enable two-factor authentication first.',
            ]);
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt($codes->toJson()),
        ])->save();

        AuditLog::record('user.recovery_codes_regenerated', $user);

        return response()->json([
            'message' => 'New recovery codes generated. Your old codes no longer work.',
            'freshRecoveryCodes' => $codes->all(),
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function generateRecoveryCodes(): Collection
    {
        return Collection::times(8, fn () => RecoveryCode::generate());
    }
}
