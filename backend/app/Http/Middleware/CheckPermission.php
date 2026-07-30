<?php

namespace App\Http\Middleware;

use App\Services\TenantModules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermissionTo($permission)) {
            abort(403, "Missing required permission: {$permission}");
        }

        // Module licensing is orthogonal to role — even this tenant's own
        // Full Administrator can't reach a module the platform operator
        // hasn't enabled for it (see App\Services\TenantModules).
        if (! TenantModules::isEnabled(Str::before($permission, '.'))) {
            abort(403, 'This module is not enabled for your account.');
        }

        return $next($request);
    }
}
