<?php

namespace App\Services;

use App\Support\HostContext;

/**
 * Resolves a Host header into master-control, tenant or unknown — the single
 * place both IdentifyTenant and anything else that needs to name a host's
 * tenant (e.g. Impersonation's landing URLs) look it up.
 *
 * The rules are RELATIVE: the first DNS label is the identity and everything
 * after it is the base, so the same code self-configures under any wildcard
 * domain ("admin.vellixglobal.com" and "admin.hms.vellixglobal.com" are both
 * central, "acme.anything.tld" is tenant "acme"). No domain literal is baked
 * anywhere.
 *
 *   - an IP literal is never a host that can own tenants → unknown
 *   - a base with fewer than tenancy.min_base_labels labels is the apex
 *     (e.g. "vellixglobal.com" → base "com") → central
 *   - *.localhost counts as a 1-label base, so local dev subdomains resolve
 *   - the first label matching tenancy.central_subdomain → central
 *   - when tenancy.base_domain is pinned (strict mode), a base that doesn't
 *     match it → unknown; unset → relative mode, everything else resolves
 *   - otherwise → tenant(first label)
 *
 * Pure: no request, no database, fully unit-testable.
 */
class TenantHostResolver
{
    public function resolve(string $host): HostContext
    {
        $host = $this->normalise($host);

        if ($host === '' || $this->isIpLiteral($host)) {
            return HostContext::unknown();
        }

        $labels = explode('.', $host);
        $firstLabel = $labels[0];
        $base = implode('.', array_slice($labels, 1));

        if ($this->isApex($host, $base)) {
            return HostContext::central();
        }

        if ($firstLabel === strtolower((string) config('tenancy.central_subdomain'))) {
            return HostContext::central();
        }

        $pinned = config('tenancy.base_domain');
        if (is_string($pinned) && trim($pinned) !== '' && $base !== strtolower(trim($pinned))) {
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
