<?php

namespace App\Jobs\Concerns;

use App\Models\Tenant;
use App\Services\CurrentContext;

/**
 * Opt-in tenant context for jobs that must do work for a specific tenant:
 * capture the ambient tenant at dispatch time and re-bind it on the worker
 * before running. The global TenancyServiceProvider already stamps every
 * payload, so jobs that are happy with the ambient context need nothing —
 * this trait is for jobs that must pin a tenant other than the dispatcher's
 * (or explicitly unbind).
 */
trait WithTenantContext
{
    /**
     * Pins the tenant this job must run under. Call from the job's
     * constructor (or wherever the job is built) before dispatch.
     */
    public function captureTenantContext(): void
    {
        $this->tenant_id ??= app(CurrentContext::class)->tenantId();
    }

    /**
     * Wraps the job's body so every scoped query inside runs as that tenant.
     */
    public function withTenantContext(callable $callback): mixed
    {
        $tenant = $this->tenant_id ?? null;

        if ($tenant !== null) {
            $tenant = Tenant::query()->find((int) $tenant);
        }

        return app(CurrentContext::class)->runForTenant($tenant, $callback);
    }
}
