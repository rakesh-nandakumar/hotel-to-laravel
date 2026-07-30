<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Services\CurrentContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies TenantScope (see there for the exact fail-closed rules) and
 * auto-stamps tenant_id on create from the resolved tenant context.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $model->tenant_id ??= app(CurrentContext::class)->tenantId();
        });
    }

    /**
     * Central-admin escape hatch — explicitly cross tenants (e.g. "show me
     * tenant #4's settings" from the platform panel) without touching the
     * ambient tenant context every other query relies on.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
