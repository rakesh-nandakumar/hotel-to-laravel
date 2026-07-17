<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Single point of "who/where/what tenant am I" for services.
 *
 * Branch resolution order:
 *   1. "All branches" mode (aggregate views) — branchId() returns null but
 *      isAllBranches() is true, so BranchScope passes through instead of
 *      scoping to "no rows".
 *   2. Explicit override (set by ResolveBranchContext middleware from the
 *      session, X-Branch-Id header, or `branch_id` query param).
 *   3. Authenticated user's default branch (users.default_warehouse_id, if present).
 *   4. The single active branch when exactly one exists — this is what makes a
 *      single-branch deployment "just work" with no selector.
 *   5. The first branch the user has explicit access to.
 *   6. null — caller decides what to do.
 */
class CurrentContext
{
    protected ?int $branchOverride = null;

    protected bool $explicit = false;

    protected bool $allBranches = false;

    public function user(): ?User
    {
        return Auth::user();
    }

    public function userId(): ?int
    {
        return Auth::id();
    }

    /**
     * Phase 9 placeholder. Returns null today; a future migration adds the column.
     */
    public function tenantId(): ?int
    {
        return Auth::user()?->tenant_id ?? null;
    }

    public function setBranch(int $branchId, bool $explicit = true): void
    {
        $this->branchOverride = $branchId;
        $this->explicit = $explicit;
        $this->allBranches = false;
    }

    /**
     * Aggregate ("All branches") mode for dashboards and reports.
     */
    public function setAllBranches(): void
    {
        $this->allBranches = true;
        $this->explicit = true;
        $this->branchOverride = null;
    }

    public function isAllBranches(): bool
    {
        return $this->allBranches;
    }

    public function hasExplicitBranch(): bool
    {
        return $this->explicit;
    }

    public function branchId(): ?int
    {
        if ($this->allBranches) {
            return null;
        }

        if ($this->branchOverride !== null) {
            return $this->branchOverride;
        }

        $user = Auth::user();
        if (! $user) {
            return null;
        }

        // Default branch on the user, if the column exists.
        $default = $user->default_warehouse_id ?? null;
        if ($default) {
            return (int) $default;
        }

        // Single-branch deployment: exactly one active branch → use it implicitly.
        $active = Branch::query()->active()->limit(2)->pluck('id');
        if ($active->count() === 1) {
            return (int) $active->first();
        }

        // Otherwise fall back to the first branch the user can access.
        $accessible = \DB::table('user_warehouse_access')
            ->where('user_id', $user->id)
            ->pluck('warehouse_id');

        if ($accessible->isNotEmpty()) {
            return (int) $accessible->first();
        }

        return null;
    }

    /**
     * Active branches this user may view — drives the branch selector. A user
     * with no explicit access list sees every active branch (single-tenant
     * deployment), which keeps the single-branch case working out of the box.
     *
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        $user = Auth::user();
        if (! $user) {
            return Branch::query()->whereRaw('1 = 0')->get();
        }

        $query = Branch::query()->active()->orderBy('name');

        $accessible = $this->accessibleBranchIds();
        if ($accessible !== null) {
            $query->whereIn('id', $accessible);
        }

        return $query->get();
    }

    /**
     * Branch IDs the current user is restricted to, or null when unrestricted
     * (full admin, or no explicit access list on a single-tenant deployment).
     *
     * @return SupportCollection<int, int>|null
     */
    public function accessibleBranchIds(): ?SupportCollection
    {
        $user = Auth::user();
        if (! $user) {
            return collect();
        }

        if (method_exists($user, 'isFullAdmin') && $user->isFullAdmin()) {
            return null;
        }

        $accessible = \DB::table('user_warehouse_access')
            ->where('user_id', $user->id)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id);

        return $accessible->isEmpty() ? null : $accessible;
    }

    /**
     * Whether the current user may operate in the given branch.
     */
    public function canAccessBranch(int $branchId): bool
    {
        return $this->branches()->pluck('id')->contains($branchId);
    }

    public function hasMultipleBranches(): bool
    {
        return $this->branches()->count() > 1;
    }

    /**
     * Best-effort branch resolution from a request — used by middleware.
     * Silently ignores branches the current user has no access to, so a
     * crafted X-Branch-Id header can never widen visibility.
     */
    public function resolveFromRequest(Request $request): void
    {
        $explicit = $request->header('X-Branch-Id') ?? $request->query('branch_id');
        if ($explicit && $this->canAccessBranch((int) $explicit)) {
            $this->setBranch((int) $explicit, explicit: true);
        }
    }
}
