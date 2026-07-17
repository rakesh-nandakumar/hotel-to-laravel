<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorForFullAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isFullAdmin()) {
            return $next($request);
        }

        if ($user->two_factor_confirmed_at !== null) {
            return $next($request);
        }

        if ($request->routeIs(
            'profile.two-factor.*',
            'logout',
            'two-factor.*',
            'password.confirm',
        )) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Two-factor authentication is mandatory for administrators.',
            'error_code' => 'two_factor_setup_required',
        ], 403);
    }
}
