<?php

namespace App\Models\Scopes;

use App\Services\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Tenant row-level isolation (see App\Models\Concerns\BelongsToTenant). Rules:
 *
 *   - Central context (master control host, or an authenticated central
 *     admin) → unscoped, sees every tenant.
 *   - Console (artisan, seeders, the test suite's own CLI process) → FAIL
 *     LOUD: a tenant-scoped query with no tenant bound throws instead of
 *     silently sweeping every tenant's rows — a missed context binding is a
 *     cross-tenant leak waiting to happen. Legitimate unscoped console work
 *     opts in explicitly via CurrentContext::runForTenant() /
 *     runWithoutTenant() (or CurrentContext::simulateWebRequest() in tests).
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
        $context = app(CurrentContext::class);

        $tenantId = $context->tenantId();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

            return;
        }

        if ($context->isCentral() || Auth::guard('central')->check()) {
            return;
        }

        if (app()->runningInConsole() && ! CurrentContext::isSimulatingWebRequest()) {
            if ($context->isRunningWithoutTenant()) {
                return;
            }

            throw new RuntimeException(
                'A tenant-scoped query ran in console with no tenant bound '
                ."({$model->getTable()}). Bind one with "
                .'CurrentContext::runForTenant(), or opt out explicitly with '
                .'CurrentContext::runWithoutTenant().'
            );
        }

        $builder->whereRaw('1 = 0');
    }
}
