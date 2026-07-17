<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    /**
     * Administrator-enforced two-factor cannot be switched off by the user —
     * not via the profile UI and not by posting to Fortify's endpoint directly.
     *
     * @param  mixed  $user
     */
    public function __invoke($user): void
    {
        abort_if(
            (bool) $user->two_factor_required,
            403,
            'Two-factor authentication is required for your account and cannot be disabled.',
        );

        parent::__invoke($user);
    }
}
