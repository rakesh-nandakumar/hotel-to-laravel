<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchScope;
use App\Services\CurrentContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies BranchScope and auto-stamps branch_id on create from the resolved
 * branch context. The model's own tenant isolation comes transitively
 * through its branch (see BelongsToTenant on App\Models\Branch) — this trait
 * does not add a second, redundant tenant_id column/scope here.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function ($model): void {
            $model->branch_id ??= app(CurrentContext::class)->branchId();
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutBranchScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BranchScope::class);
    }
}
