<?php

namespace App\Http\Middleware;

use App\Services\CurrentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the operational branch context for the request from the session
 * (set by the top-bar branch selector via POST /branch/select). When the user
 * has access to a single branch, or none is chosen, CurrentContext falls back
 * to its default resolution (which picks the lone active branch implicitly).
 *
 * Dashboard and report controllers override this per-request to switch to a
 * specific branch or the "All" aggregate based on their own ?branch_id param.
 */
class ResolveBranchContext
{
    public function __construct(private readonly CurrentContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $selected = $request->session()->get('selected_branch_id');
            $branches = $this->context->branches();

            if ($selected !== null && $selected !== '' && $branches->pluck('id')->contains((int) $selected)) {
                $this->context->setBranch((int) $selected);
            } elseif ($branches->count() === 1) {
                // Single branch: always use it implicitly.
                $this->context->setBranch($branches->first()->id);
            } else {
                // Multi-branch, no explicit selection = aggregate all.
                $this->context->setAllBranches();
            }
        }

        return $next($request);
    }
}
