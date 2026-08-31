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
 * Resolves which tenant this request belongs to and records it on
 * CurrentContext before anything else runs (registered ahead of the auth
 * guard). Identity comes from the X-Tenant-Slug header (or `tenant` query
 * parameter) first — the SPA reads its own slug from its URL prefix at boot
 * and sends it on every call — and falls back to the Host header while the
 * old {slug}.{base} URL style is still in play. The bare base domain and the
 * reserved central prefix are "master control" — no tenant is resolved there,
 * and central-guard routes take over.
 *
 * Fails closed: every rejection — unknown host, unknown slug, suspended or
 * expired tenant — is the same 404, so no probe can tell tenants apart or
 * confirm a slug exists.
 */
class IdentifyTenant
{
    public function __construct(
        private readonly CurrentContext $context,
        private readonly TenantHostResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Deploy utility (see routes/api.php): must work from a bare/unrecognized
        // host with no tenant context at all — that's the point of it, a
        // fallback for hosts where `php artisan migrate` can't be run over
        // SSH. Migrations are schema-only and tenant-agnostic, so tenant
        // resolution is skipped entirely for this one route rather than
        // requiring the host to already resolve as central or a known tenant.
        if ($request->is('api/deploy/migrate')) {
            return $next($request);
        }

        // CurrentContext is a container singleton — start every request with
        // a clean slate rather than risk a previous request's tenant leaking
        // forward (see CurrentContext::resetTenant()'s doc block).
        $this->context->resetTenant();

        $hostContext = $this->resolver->resolveRequest($request);

        $tenant = $hostContext->isTenant()
            ? $this->findBySlug($hostContext->slug())
            : null;

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

        // A session whose own tenant disagrees with the resolved tenant is
        // rejected outright — that would otherwise be a live cross-tenant
        // session hijack (a stale cookie replayed against another tenant's
        // prefix). The guard's user is looked up scoped to the resolved
        // tenant, so a foreign session loads as null and is caught here.
        // Skipped entirely when the request carries no session (non-stateful
        // clients): there is no session to replay.
        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $sessionUserId = $request->session()->get($guard->getName());
            if ($sessionUserId !== null) {
                $user = $guard->user();
                if (! $user || $user->tenant_id !== $tenant->id) {
                    // Log the tenant identity out only. One origin now hosts
                    // both panels, so both guards' state lives in the same
                    // session store — a full invalidate() would also destroy
                    // a central admin's logged-in session (e.g. after an
                    // impersonation hand-off), dropping the operator from
                    // /admin mid-flow.
                    $guard->logout();

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

    private function applyTenantTimezone(): void
    {
        $timezone = $this->context->timezone();

        if ($timezone !== config('app.timezone')) {
            date_default_timezone_set($timezone);
        }
    }
}
