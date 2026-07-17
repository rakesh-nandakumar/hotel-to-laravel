<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users flagged with must_change_password (admin-provisioned or admin-reset
 * credentials) are held on the password settings page until they set their
 * own password.
 */
class RequirePasswordChange
{
    /**
     * Routes the user may still reach while the flag is set — the password
     * form itself, both password-update endpoints, and logout.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'password.edit',
        'password.update',
        'user-password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && $user->must_change_password
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return response()->json([
                'message' => 'Please set a new password before continuing.',
                'error_code' => 'must_change_password',
            ], 403);
        }

        return $next($request);
    }
}
