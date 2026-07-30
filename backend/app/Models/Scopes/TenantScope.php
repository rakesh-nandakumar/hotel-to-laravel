<?php

namespace App\Models\Scopes;

use App\Services\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant row-level isolation (see App\Models\Concerns\BelongsToTenant). Rules:
 *
 *   - Central admin (guard `central`) authenticated → unscoped, sees every tenant.
 *   - Console (artisan, seeders, the test suite's own CLI process) → unscoped,
 *     unless a test opted into CurrentContext::simulateWebRequest() to verify
 *     the fail-closed behavior below.
 *   - A tenant resolved (by subdomain, via IdentifyTenant) → scoped to that
 *     tenant_id only.
 *   - Otherwise (a genuine web request that resolved no tenant) → fail
 *     closed: zero rows. Never fall through to "unscoped" here — a missed
 *     tenant resolution must never leak another tenant's data.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::guard('central')->check()) {
            return;
        }

        if (app()->runningInConsole() && ! CurrentContext::isSimulatingWebRequest()) {
            return;
        }

        $tenantId = app(CurrentContext::class)->tenantId();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
