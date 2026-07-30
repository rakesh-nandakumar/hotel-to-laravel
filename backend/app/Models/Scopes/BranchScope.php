<?php

namespace App\Models\Scopes;

use App\Services\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Branch-level isolation, layered inside TenantScope (a branch already
 * belongs to exactly one tenant, so this narrows further). Mirrors
 * TenantScope's console/central bypass rules, plus the existing "All
 * branches" aggregate mode dashboards/reports already rely on
 * (CurrentContext::isAllBranches()) — that mode intentionally passes through
 * unscoped, since it's an explicit, authorized request to see every branch
 * at once, not a missed resolution.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::guard('central')->check()) {
            return;
        }

        if (app()->runningInConsole() && ! CurrentContext::isSimulatingWebRequest()) {
            return;
        }

        $context = app(CurrentContext::class);

        if ($context->isAllBranches()) {
            return;
        }

        $branchId = $context->branchId();

        if ($branchId !== null) {
            $builder->where($model->qualifyColumn('branch_id'), $branchId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
