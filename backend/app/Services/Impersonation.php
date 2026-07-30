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
            ->firstOrFail();

        $plainToken = Str::random(64);

        ImpersonationToken::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'central_admin_id' => $centralAdmin->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        $scheme = request()?->getScheme() ?? 'https';
        $url = "{$scheme}://{$tenant->slug}.".config('tenancy.base_domain')."/impersonate/{$plainToken}";

        return ['url' => $url, 'user' => $user];
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
