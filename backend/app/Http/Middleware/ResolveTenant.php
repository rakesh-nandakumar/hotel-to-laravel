<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant from the request subdomain and sets it on
 * the TenantContext singleton. The admin subdomain is treated specially —
 * it marks the request as a platform admin context instead.
 *
 * Subdomain resolution:
 *   - `company1.example.com` → tenant with slug "company1"
 *   - `admin.example.com`    → platform admin context (no tenant)
 *   - `example.com`          → 404 (no tenant)
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = $this->extractSubdomain($request);

        // No subdomain — running on the bare domain or localhost without subdomain.
        // In local dev, allow requests without a subdomain to pass through
        // (the default tenant is resolved via X-Tenant header or falls back).
        if ($subdomain === null || $subdomain === '') {
            return $this->resolveFromHeader($request, $next);
        }

        // Admin subdomain — mark as platform admin context.
        if ($subdomain === 'admin') {
            $this->context->setPlatformAdmin();

            return $next($request);
        }

        $tenant = Tenant::where('slug', $subdomain)->first();

        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        if ($tenant->isSuspended()) {
            return response()->json([
                'message' => 'This account has been suspended. Please contact support.',
            ], 403);
        }

        if (! $tenant->isActive()) {
            return response()->json(['message' => 'Tenant is not active.'], 403);
        }

        $this->context->setTenant($tenant);
        $tenant->update(['last_active_at' => now()]);

        return $next($request);
    }

    /**
     * Extract the subdomain from the host. Handles:
     *   - `company1.example.com` → "company1"
     *   - `localhost` → null
     *   - `company1.localhost` → "company1"
     */
    private function extractSubdomain(Request $request): ?string
    {
        $host = $request->getHost();

        // Local dev: tenant1.localhost
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.localhost')) {
            return explode('.', $host)[0];
        }

        $parts = explode('.', $host);

        // Needs at least 3 parts for a subdomain (sub.domain.tld).
        if (count($parts) < 3) {
            return null;
        }

        return $parts[0];
    }

    /**
     * Fallback for local dev without subdomains — resolve tenant from
     * X-Tenant-Slug header or default to the first active tenant.
     */
    private function resolveFromHeader(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant-Slug');

        if ($slug) {
            $tenant = Tenant::where('slug', $slug)->first();
            if ($tenant && $tenant->isActive()) {
                $this->context->setTenant($tenant);

                return $next($request);
            }
        }

        // In local dev, default to the first active tenant.
        if (app()->environment(['local', 'testing'])) {
            $tenant = Tenant::query()->active()->first();
            if ($tenant) {
                $this->context->setTenant($tenant);
            }
        }

        return $next($request);
    }
}
