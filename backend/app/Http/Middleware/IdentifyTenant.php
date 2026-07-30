<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CurrentContext;
use App\Support\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves which tenant this request belongs to from the Host header and
 * records it on CurrentContext before anything else runs (registered ahead
 * of the auth guard). The bare base domain and the reserved central
 * subdomain are "master control" — no tenant is resolved there, and
 * central-guard routes take over.
 *
 * Fails closed: an unrecognized or suspended tenant subdomain 404s rather
 * than falling through unscoped. A `web`-guard session whose own tenant_id
 * disagrees with the resolved subdomain is rejected outright — that would
 * otherwise be a live cross-tenant session hijack (a stale cookie replayed
 * against a different tenant's subdomain).
 */
class IdentifyTenant
{
    public function __construct(private readonly CurrentContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // CurrentContext is a container singleton — start every request with
        // a clean slate rather than risk a previous request's tenant leaking
        // forward (see CurrentContext::resetTenant()'s doc block).
        $this->context->resetTenant();

        $host = $request->getHost();
        $baseDomain = config('tenancy.base_domain');
        $centralHost = config('tenancy.central_subdomain').'.'.$baseDomain;

        // The host check is what actually matters in production — reaching
        // /api/central/* from a live tenant subdomain must NOT grant central
        // context (EnsureCentralContext relies on tenant resolution having
        // genuinely run so it can reject exactly that). The path-based
        // shortcut only exists so /central/* is reachable in local dev/tests,
        // where there's no real subdomain to distinguish the two — it's
        // deliberately unavailable outside those environments.
        $isCentralPath = config('tenancy.dev_fallback') && $request->is('api/central', 'api/central/*');
        if ($host === $baseDomain || $host === $centralHost || $isCentralPath) {
            return $next($request); // central context — no tenant resolved
        }

        $tenant = Str::endsWith($host, ".{$baseDomain}")
            ? $this->findBySlug(Str::beforeLast($host, ".{$baseDomain}"))
            : $this->devFallback($request);

        if (! $tenant) {
            throw new NotFoundHttpException('Unknown host.');
        }

        if ($tenant->status === TenantStatus::SUSPENDED) {
            throw new NotFoundHttpException('This account is suspended.');
        }

        $user = Auth::guard('web')->user();
        if ($user && $user->tenant_id !== $tenant->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            abort(401, 'Session does not match this tenant.');
        }

        $this->context->setTenant($tenant->id);

        return $next($request);
    }

    private function findBySlug(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', $slug)->first();
    }

    /**
     * Local/dev/test convenience only — see config/tenancy.php.
     */
    private function devFallback(Request $request): ?Tenant
    {
        if (! config('tenancy.dev_fallback')) {
            return null;
        }

        $explicit = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        if ($explicit) {
            return $this->findBySlug((string) $explicit);
        }

        return Tenant::query()->count() === 1 ? Tenant::query()->first() : null;
    }
}
