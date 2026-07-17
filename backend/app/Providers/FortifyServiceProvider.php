<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Block disabling TOTP for users whose 2FA an administrator enforces.
        $this->app->singleton(
            \Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class,
            DisableTwoFactorAuthentication::class,
        );
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Fired by the direct password login, the two-factor challenge and
        // remember-cookie restores — every way a session comes to exist.
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => request()->ip(),
                ])->save();

                AuditLog::record('user.login', $event->user, [
                    'ip' => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 255),
                ]);
            }
        });

        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event): void {
            if ($event->user instanceof User) {
                AuditLog::record('user.two_factor_enabled', $event->user);
            }
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, function (TwoFactorAuthenticationConfirmed $event): void {
            if ($event->user instanceof User) {
                AuditLog::record('user.two_factor_confirmed', $event->user);
            }
        });

        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event): void {
            if ($event->user instanceof User) {
                AuditLog::record('user.two_factor_disabled', $event->user);
            }
        });

        // A consumed recovery code is replaced with a fresh one — record the
        // use, since each code is effectively a one-time bypass of the TOTP.
        Event::listen(RecoveryCodeReplaced::class, function (RecoveryCodeReplaced $event): void {
            if ($event->user instanceof User) {
                AuditLog::record('user.recovery_code_used', $event->user, [
                    'ip' => request()->ip(),
                ]);
            }
        });

        Event::listen(TwoFactorAuthenticationFailed::class, function (TwoFactorAuthenticationFailed $event): void {
            if ($event->user instanceof User) {
                AuditLog::record('user.two_factor_failed', $event->user, [
                    'ip' => request()->ip(),
                ]);
            }
        });
    }
}
