<?php

namespace App\Services;

use App\Models\CentralAdmin;
use App\Models\ImpersonationToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Lets a platform operator land on a tenant's own subdomain already logged in
 * as one of its users, without ever handling that user's password. A minted
 * token is a plaintext, single-use, short-lived credential — only its hash is
 * stored (same treatment as a password reset token) so a leaked audit log or
 * database dump can't be replayed.
 */
class Impersonation
{
    private const TTL_SECONDS = 90;

    public function startFor(Tenant $tenant, CentralAdmin $centralAdmin, ?User $user = null): array
    {
        $user ??= User::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('is_full_admin', true))
            ->first();

        // Every tenant gets one at provisioning (see TenantProvisioning), so
        // this only happens if it was since deleted or stripped of the role.
        // Say so plainly rather than surfacing a raw model-not-found 404.
        if (! $user) {
            abort(422, 'This tenant has no Full Administrator to impersonate.');
        }

        $plainToken = Str::random(64);

        ImpersonationToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'central_admin_id' => $centralAdmin->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return ['url' => $this->landingUrl($tenant, $plainToken), 'user' => $user];
    }

    /**
     * Where the operator's browser is sent to redeem the token: always the
     * tenant's own subdomain, which is what binds the resulting session to the
     * right tenant (IdentifyTenant resolves it from that Host, and the token
     * is refused anywhere else).
     *
     * Locally that works too, as long as TENANCY_BASE_DOMAIN is a name whose
     * subdomains actually resolve — `localhost` is the obvious one, since
     * browsers send every *.localhost straight to 127.0.0.1 with no hosts-file
     * entry. The only difference from production is the port: the SPA is on
     * Vite's, which a bare hostname doesn't carry.
     */
    private function landingUrl(Tenant $tenant, string $plainToken): string
    {
        $host = $tenant->slug.'.'.config('tenancy.base_domain');
        $scheme = request()?->getScheme() ?? 'https';

        if (config('tenancy.dev_fallback')) {
            $frontend = parse_url((string) config('app.frontend_url')) ?: [];
            $scheme = $frontend['scheme'] ?? $scheme;

            if (isset($frontend['port'])) {
                $host .= ':'.$frontend['port'];
            }
        }

        return "{$scheme}://{$host}/impersonate/{$plainToken}";
    }

    /**
     * Consumes a token for the CURRENT tenant (as resolved by IdentifyTenant
     * from the request's own Host) — a token minted for one tenant can never
     * be replayed against another subdomain, even if somehow obtained.
     */
    public function consume(string $plainToken, int $resolvedTenantId): ?User
    {
        $token = ImpersonationToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('tenant_id', $resolvedTenantId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $token) {
            return null;
        }

        $token->update(['used_at' => now()]);

        return User::query()->withoutTenantScope()->find($token->user_id);
    }
}
