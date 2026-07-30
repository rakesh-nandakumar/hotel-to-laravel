<?php

namespace App\Http\Middleware;

use App\Services\CurrentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Belt-and-braces alongside `auth:central`: rejects any /central/* request
 * that IdentifyTenant resolved a real tenant for (e.g. hit from a live
 * tenant subdomain rather than the central host) — the master control panel
 * must never be reachable "as" a specific tenant's context.
 */
class EnsureCentralContext
{
    public function __construct(private readonly CurrentContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->context->tenantId() !== null) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
