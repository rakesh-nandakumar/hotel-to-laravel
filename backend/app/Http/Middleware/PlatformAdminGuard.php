<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PlatformAdminGuard
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // This middleware should only be applied to admin routes where the
        // TenantContext has already been marked as a platform admin request
        // (by ResolveTenant) and the user is authenticated on the platform guard.

        if (! $this->context->isPlatformAdmin()) {
            return response()->json([
                'message' => 'This endpoint can only be accessed from the admin panel.',
            ], 403);
        }

        return $next($request);
    }
}
