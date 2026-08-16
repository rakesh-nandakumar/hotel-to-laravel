<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    protected ?int $tenantOverride = null;

    /**
     * Set while tenantId() is loading the fallback user — a scoped query is
     * running under TenantScope at that moment, so a re-entrant load would
     * recurse forever (scope → tenantId → Auth::user → scoped query → scope).
     */
    protected bool $loadingAuthTenant = false;

    /**
     * True while runWithoutTenant()'s callback is executing — tenantId() then
     * reports null even when the authenticated user carries a tenant, and
     * TenantScope treats the query as an explicit unscoped pass-through.
     */
    protected bool $runningWithoutTenant = false;

    /**
     * Set by IdentifyTenant (or queue workers, see TenancyServiceProvider)
     * when no tenant is resolved: "this is master control, not a tenant".
     * TenantScope bypasses for central context, so central admins see every
     * tenant's rows through the explicit withoutTenantScope() escape hatches.
     */
    protected bool $isCentral = false;

    protected ?Tenant $resolvedTenant = null;

    protected static bool $simulatingWebRequest = false;

    public function user(): ?User
    {
        return Auth::user();
    }

    public function userId(): ?int
    {
        return Auth::id();
    }

    /**
     * The tenant resolved for this request by IdentifyTenant (from the Host
     * header), falling back to the authenticated user's own tenant_id. The
     * explicit override is what lets IdentifyTenant record "no tenant
     * resolved" (null) even when Auth already has a stale user loaded.
     */
    public function tenantId(): ?int
    {
        // Inside runWithoutTenant() the authenticated user's own tenant must
        // not leak back in — the callback asked for an explicit unscoped pass.
        if ($this->runningWithoutTenant) {
            return null;
        }

        if ($this->tenantOverride !== null) {
            return $this->tenantOverride;
        }

        // Re-entrancy guard: while the fallback user is being loaded, the
        // loading query itself applies TenantScope, which calls back into
        // tenantId(). Returning null there fail-closes that nested query
        // instead of recursing into another Auth::user() load forever.
        if ($this->loadingAuthTenant) {
            return null;
        }

        $this->loadingAuthTenant = true;

        try {
            return Auth::guard('web')->user()?->tenant_id;
        } finally {
            $this->loadingAuthTenant = false;
        }
    }

    public function setTenant(?int $tenantId): void
    {
        $this->tenantOverride = $tenantId;
    }

    /**
     * CurrentContext is a container singleton — called at the top of every
     * request (by IdentifyTenant) so a previous request's resolved tenant can
     * never leak forward into this one.
     */
    public function resetTenant(): void
    {
        $this->tenantOverride = null;
        $this->runningWithoutTenant = false;
        $this->isCentral = false;
        $this->resolvedTenant = null;
    }

    public function markCentral(): void
    {
        $this->isCentral = true;
    }

    public function isCentral(): bool
    {
        return $this->isCentral;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId() !== null;
    }

    /**
     * The resolved tenant model for the current context (cached per tenant).
     * Tenant is unscoped by design, so this is safe in central context too —
     * it only reflects the resolved tenant_id, or null when none.
     */
    public function tenant(): ?Tenant
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return null;
        }

        if ($this->resolvedTenant === null || $this->resolvedTenant->id !== $tenantId) {
            $this->resolvedTenant = Tenant::query()->find($tenantId);
        }

        return $this->resolvedTenant;
    }

    /**
     * Run a callback with a specific tenant bound, restoring the previous
     * context afterwards — the escape hatch for console work (commands,
     * seeders, queued jobs) that must operate inside one tenant's scope.
     * Passing null is equivalent to runWithoutTenant().
     */
    public function runForTenant(Tenant|int|null $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenantOverride;
        $previousWithout = $this->runningWithoutTenant;

        $this->tenantOverride = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $this->runningWithoutTenant = false;

        try {
            return $callback();
        } finally {
            $this->tenantOverride = $previousTenant;
            $this->runningWithoutTenant = $previousWithout;
        }
    }

    /**
     * Run a callback with no tenant bound at all — every scoped query inside
     * passes through unscoped (central-style sweep). Restores the previous
     * context afterwards.
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $previousTenant = $this->tenantOverride;
        $previousWithout = $this->runningWithoutTenant;

        $this->tenantOverride = null;
        $this->runningWithoutTenant = true;

        try {
            return $callback();
        } finally {
            $this->tenantOverride = $previousTenant;
            $this->runningWithoutTenant = $previousWithout;
        }
    }

    /**
     * The tenant's configured timezone (from settings). Only meaningful with
     * a tenant resolved — central context falls back to the app default.
     */
    public function timezone(): string
    {
        if (! $this->hasTenant()) {
            return config('app.timezone');
        }

        return Settings::str('hotel.timezone', config('app.timezone'));
    }

    public function localeTag(): string
    {
        return $this->hasTenant() ? Settings::str('hotel.locale', 'en') : 'en';
    }

    public function currencyCode(): string
    {
        return $this->hasTenant() ? Settings::str('hotel.currency_code', 'LKR') : 'LKR';
    }

    public function currencySymbol(): string
    {
        return $this->hasTenant() ? Settings::str('hotel.currency_symbol', 'Rs.') : 'Rs.';
    }

    /**
     * Test-only escape hatch: TenantScope/BranchScope treat console execution
     * as unscoped (artisan, seeders, the test runner's own CLI process), which
     * would hide a real bug if a test tried to verify the fail-closed "no
     * tenant resolved" behavior from a Pest test — Pest itself runs in
     * console. Wrapping an assertion in this flag makes the scopes behave as
     * if a genuine web request were in flight.
     */
    public static function isSimulatingWebRequest(): bool
    {
        return static::$simulatingWebRequest;
    }

    /**
     * True while a runWithoutTenant() callback is executing — TenantScope
     * reads this to allow an explicit unscoped pass in console.
     */
    public function isRunningWithoutTenant(): bool
    {
        return $this->runningWithoutTenant;
    }

    public static function simulateWebRequest(callable $callback): mixed
    {
        static::$simulatingWebRequest = true;

        try {
            return $callback();
        } finally {
            static::$simulatingWebRequest = false;
        }
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
     * Whether the current user may see every branch (full admin, or no
     * explicit access list — a single-tenant deployment without access rows).
     */
    public function hasUnrestrictedBranchAccess(): bool
    {
        return $this->accessibleBranchIds() === null;
    }

    /**
     * Narrows a branch-scoped query to the branches the current user may
     * access, unless they have unrestricted access (full admin / no explicit
     * list). Applies the same rule BranchScope itself uses, but for queries
     * that must cross branches deliberately (reports, aggregates).
     *
     * @param  Builder<Branch>  $query
     */
    public function restrictToAccessibleBranches(Builder $query): void
    {
        $accessible = $this->accessibleBranchIds();

        if ($accessible !== null) {
            $query->whereIn('branch_id', $accessible);
        }
    }

    /**
     * Till IDs this user may operate, or null when unrestricted. Tills inherit
     * their branch's accessibility — a till in an inaccessible branch is never
     * visible even when its id is known.
     *
     * @return SupportCollection<int, int>|null
     */
    public function accessibleTillIds(): ?SupportCollection
    {
        if ($this->hasUnrestrictedBranchAccess()) {
            return null;
        }

        $branchIds = $this->accessibleBranchIds();
        if ($branchIds === null) {
            return null;
        }

        return Till::query()
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * Tills of one branch, narrowed to those the user may actually operate.
     *
     * @return SupportCollection<int, int>
     */
    public function tillsForBranch(int $branchId): SupportCollection
    {
        $accessible = $this->accessibleTillIds();
        $query = Till::query()->where('branch_id', $branchId);

        if ($accessible !== null) {
            $query->whereIn('id', $accessible);
        }

        return $query->pluck('id')->map(fn (mixed $id): int => (int) $id);
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
