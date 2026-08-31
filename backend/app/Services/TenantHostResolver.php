<?php

namespace App\Services;

use App\Support\HostContext;
use Illuminate\Http\Request;

/**
 * Resolves a tenant identity — a slug, or (transitionally) a Host header —
 * into master-control, tenant or unknown. The single place IdentifyTenant,
 * HostContextController and anything else that needs to name a tenant look it
 * up.
 *
 * resolveFromSlug() is the primary (path/header) path: a bare slug is a
 * tenant name unless it is the central prefix or empty, and it never grants
 * central — master control is only ever named by the central path, never by
 * a tenant hint, so a forged header cannot lasso a central request.
 *
 * resolve() is the HOST fallback kept for the dual-mode window (old
 * {slug}.{base} and admin.{base} URL style). Its rules are RELATIVE: the
 * first DNS label is the identity and everything after it is the base, so the
 * same code self-configures under any wildcard domain ("admin.vellixglobal.com"
 * and "admin.hms.vellixglobal.com" are both central, "acme.anything.tld" is
 * tenant "acme"). No domain literal is baked anywhere.
 *
 *   - an IP literal is never a host that can own tenants → unknown
 *   - a base with fewer than tenancy.min_base_labels labels is the apex
 *     (e.g. "vellixglobal.com" → base "com") → central
 *   - *.localhost counts as a 1-label base, so local dev subdomains resolve
 *   - the first label matching tenancy.central_prefix → central
 *   - when tenancy.base_domain is pinned (strict mode), a base that doesn't
 *     match it → unknown; unset → relative mode, everything else resolves
 *   - otherwise → tenant(first label)
 *
 * Pure: no request, no database, fully unit-testable.
 */
class TenantHostResolver
{
    /**
     * The one resolution order every caller uses: X-Tenant-Slug header first,
     * then the `tenant` query parameter, then the Host header. This is the
     * single place the identity-source precedence lives — removing the host
     * fallback at cutover means deleting the fallback line here and nowhere
     * else.
     */
    public function resolveRequest(Request $request): HostContext
    {
        $slug = $request->header('X-Tenant-Slug') ?? $request->query('tenant');

        return is_string($slug) && trim($slug) !== ''
            ? $this->resolveFromSlug($slug)
            : $this->resolve((string) $request->getHost());
    }

    /**
     * Resolve a bare slug (X-Tenant-Slug header or `tenant` query parameter).
     * Normalised the same way a host is; a slug equal to the central prefix —
     * or empty/garbage — is unknown so the request fails closed instead of
     * drifting into an unscoped or falsely-central context.
     */
    public function resolveFromSlug(string $slug): HostContext
    {
        $slug = strtolower(trim($slug));
        $slug = rtrim($slug, '.');

        if ($slug === '' || $slug === strtolower((string) config('tenancy.central_prefix'))) {
            return HostContext::unknown();
        }

        return HostContext::tenant($slug);
    }

    public function resolve(string $host): HostContext
    {
        $host = $this->normalise($host);

        if ($host === '' || $this->isIpLiteral($host)) {
            return HostContext::unknown();
        }

        $pinned = config('tenancy.base_domain');
        $pinned = is_string($pinned) && trim($pinned) !== '' ? strtolower(trim($pinned)) : null;

        // The pinned base domain itself, bare, is the apex/central host. The
        // label-peeling apex check below only catches a short remainder (a
        // 2-label pin like "vellixglobal.com" peels down to "com", which
        // trips it) — a multi-label pin (e.g. "htl.vellixglobal.com") peels
        // down to a remainder that's still a valid-looking base ("vellixglobal.com"),
        // so without this direct match the pin's own bare host misreads as a
        // tenant subdomain of itself and 404s.
        if ($pinned !== null && $host === $pinned) {
            return HostContext::central();
        }

        $labels = explode('.', $host);
        $firstLabel = $labels[0];
        $base = implode('.', array_slice($labels, 1));

        if ($this->isApex($host, $base)) {
            return HostContext::central();
        }

        if ($firstLabel === strtolower((string) config('tenancy.central_prefix'))) {
            return HostContext::central();
        }

        if ($pinned !== null && $base !== $pinned) {
            return HostContext::unknown();
        }

        return HostContext::tenant($firstLabel);
    }

    /**
     * The base domain the given host sits under — used to build tenant URLs
     * ({slug}.{base}) when tenancy.base_domain is unset (relative mode). For
     * the apex host the base IS the whole host.
     */
    public function baseOf(string $host): string
    {
        $host = $this->normalise($host);

        if ($host === '') {
            return '';
        }

        $pinned = config('tenancy.base_domain');
        $pinned = is_string($pinned) && trim($pinned) !== '' ? strtolower(trim($pinned)) : null;

        // Same reasoning as the direct pin match in resolve(): a multi-label
        // pin (e.g. "htl.vellixglobal.com") is itself the base for tenant URLs
        // ("acme.htl.vellixglobal.com"), not the shorter remainder label-peeling
        // would otherwise produce ("vellixglobal.com").
        if ($pinned !== null && $host === $pinned) {
            return $host;
        }

        $labels = explode('.', $host);
        $first = $labels[0];
        $base = implode('.', array_slice($labels, 1));

        return $this->isApex($host, $base) ? $host : $base;
    }

    /**
     * Apex check: a host whose base is too short to be a domain in its own
     * right (default tenancy.min_base_labels = 2, so "com" fails) cannot be
     * a tenant subdomain — it IS the bare base domain. *.localhost gets a
     * floor of 1 label so acme.localhost still resolves locally.
     */
    private function isApex(string $host, string $base): bool
    {
        if ($base === '') {
            return true;
        }

        $min = max(1, (int) config('tenancy.min_base_labels', 2));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $min = 1;
        }

        return substr_count($base, '.') + 1 < $min;
    }

    private function normalise(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        $host = trim($host, '[]');

        // An IP literal is returned as-is — its ":" is part of the address
        // (IPv6), never a port, so it must not reach the port-strip below.
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $host = (string) preg_replace('/:\d+$/', '', $host);

        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $punycode = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($punycode !== false) {
                $host = strtolower($punycode);
            }
        }

        return $host;
    }

    private function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }
}
