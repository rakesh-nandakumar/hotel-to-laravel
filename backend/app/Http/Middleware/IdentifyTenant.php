<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CurrentContext;
use App\Services\TenantHostResolver;
use App\Support\TenantReachability;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves which tenant this request belongs to from the Host header and
 * records it on CurrentContext before anything else runs (registered ahead
 * of the auth guard). The bare base domain and the reserved central
 * subdomain are "master control" — no tenant is resolved there, and
 * central-guard routes take over.
 *
 * Fails closed: every rejection — unknown host, unknown slug, suspended or
 * expired tenant — is the same 404, so no probe can tell tenants apart or
 * confirm a slug exists.
 */
class IdentifyTenant
{
    /**
     * Session key holding the dev-fallback tenant slug. Only ever written
     * when tenancy.dev_fallback is explicitly enabled.
     */
    private const DEV_TENANT_SESSION_KEY = 'dev_tenant_slug';

    public function __construct(
        private readonly CurrentContext $context,
        private readonly TenantHostResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // CurrentContext is a container singleton — start every request with
        // a clean slate rather than risk a previous request's tenant leaking
        // forward (see CurrentContext::resetTenant()'s doc block).
        $this->context->resetTenant();

        $hostContext = $this->resolver->resolve((string) $request->getHost());

        // Central context — no tenant resolved — unless the opt-in dev
        // fallback names one (central hosts like localhost double as the
        // fallback's last resort in local development).
        $tenant = $hostContext->isTenant()
            ? $this->findBySlug($hostContext->slug())
            : $this->devFallback($request);

        if ($hostContext->isCentral() && ! $tenant) {
            $this->context->markCentral();

            return $next($request);
        }

        if (! $tenant || ! TenantReachability::check($tenant)) {
            throw new NotFoundHttpException('Unknown host.');
        }

        // Resolve BEFORE loading the session user: TenantScope consults
        // CurrentContext::tenantId(), which falls back to Auth::user() when no
        // tenant override is set — loading that user runs a scoped query, whose
        // scope would then try to load the user again, forever (a re-entrant
        // Auth::user() that recurses until memory is exhausted).
        $this->context->setTenant($tenant->id);

        // The tenant's configured timezone drives every timestamp this request
        // renders — applied as early as possible, like the rest of the tenant's
        // identity (see CurrentContext::timezone()).
        $this->applyTenantTimezone();

        // A session whose own tenant disagrees with the resolved subdomain is
        // rejected outright — that would otherwise be a live cross-tenant
        // session hijack (a stale cookie replayed against another tenant's
        // subdomain). The guard's user is looked up scoped to the resolved
        // tenant, so a foreign session loads as null and is caught here.
        // Skipped entirely when the request carries no session (non-stateful
        // clients): there is no session to replay.
        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $sessionUserId = $request->session()->get($guard->getName());
            if ($sessionUserId !== null) {
                $user = $guard->user();
                if (! $user || $user->tenant_id !== $tenant->id) {
                    $guard->logout();
                    $request->session()->invalidate();

                    abort(401, 'Session does not match this tenant.');
                }
            }
        }

        return $next($request);
    }

    /**
     * A tenant is reachable when it's active, or trialling and not yet
     * expired. Everything else — suspended, cancelled, paused — is
     * indistinguishable from "does not exist".
     */
    private function findBySlug(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', $slug)->first();
    }

    /**
     * Local/dev/test convenience only — see config/tenancy.php. Only consulted
     * when dev_fallback is explicitly enabled AND the host is genuinely local
     * (localhost / *.localhost / loopback IPs) — real hosts never reach it.
     */
    private function devFallback(Request $request): ?Tenant
    {
        if (! config('tenancy.dev_fallback') || ! $this->isLocalHost((string) $request->getHost())) {
            return null;
        }

        $explicit = $request->header('X-Tenant-Slug') ?? $request->query('tenant');
        if ($explicit) {
            $tenant = $this->findBySlug((string) $explicit);

            // Sticky for the rest of the session: an impersonation hand-off
            // names its tenant once, in the landing URL, but every request the
            // app makes afterwards is an ordinary same-host call with no hint
            // on it.
            if ($tenant && $request->hasSession()) {
                $request->session()->put(self::DEV_TENANT_SESSION_KEY, $tenant->slug);
            }

            return $tenant;
        }

        if ($request->hasSession() && ($remembered = $request->session()->get(self::DEV_TENANT_SESSION_KEY))) {
            return $this->findBySlug((string) $remembered);
        }

        return null;
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '127.0.0.1'
            || $host === '[::1]'
            || $host === '::1';
    }

    private function applyTenantTimezone(): void
    {
        $timezone = $this->context->timezone();

        if ($timezone !== config('app.timezone')) {
            date_default_timezone_set($timezone);
        }
    }
}
