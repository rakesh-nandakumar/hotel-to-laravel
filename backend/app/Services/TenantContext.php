<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Single point of "who/where/what tenant am I" for services.
 *
 * Replaces the legacy CurrentContext (branch-based). The tenant is resolved
 * from the request subdomain by the {@see \App\Http\Middleware\ResolveTenant}
 * middleware and set here. All data-access layers read from this service.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    protected bool $platformAdmin = false;

    public function user(): ?User
    {
        return Auth::user();
    }

    public function userId(): ?int
    {
        return Auth::id();
    }

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->platformAdmin = false;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * Mark this request as a platform admin context (admin subdomain).
     * Disables the TenantScope so admin queries span all tenants.
     */
    public function setPlatformAdmin(bool $value = true): void
    {
        $this->platformAdmin = $value;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformAdmin;
    }

    /**
     * Whether the current request has a resolved tenant.
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
}
