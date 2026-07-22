<?php

namespace App\Models\Scopes;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Automatically adds `WHERE tenant_id = ?` to every query on models that
 * use the {@see \App\Models\Concerns\BelongsToTenant} trait. Disabled
 * when the current request is from a platform admin (no tenant resolved).
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // Platform admin context or no tenant resolved — skip scoping.
        if ($context->isPlatformAdmin() || $context->tenantId() === null) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->tenantId());
    }
}
