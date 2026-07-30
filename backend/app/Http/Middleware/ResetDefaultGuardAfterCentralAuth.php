<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's own `auth:central` middleware calls Auth::shouldUse('central')
 * the instant it authenticates — that repoints every *unguarded* Auth call
 * (Auth::id(), $request->user(), and anything built on them, like
 * AuditLog::record()'s actor-id fallback) at the CentralAdmin for the rest
 * of this request. Every one of those call sites across the app is written
 * assuming the ambient default is always the tenant `web` guard, so this
 * puts it back immediately after auth:central has done its own guard-
 * specific check — central controllers still explicitly reach for the
 * operator's identity via $request->user('central').
 */
class ResetDefaultGuardAfterCentralAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('web');

        return $next($request);
    }
}
