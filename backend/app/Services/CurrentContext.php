<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Single point of "who/what tenant am I" for services.
 */
class CurrentContext
{
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
     * Test-only escape hatch: TenantScope treats console execution as
     * unscoped (artisan, seeders, the test runner's own CLI process), which
     * would hide a real bug if a test tried to verify the fail-closed "no
     * tenant resolved" behavior from a Pest test — Pest itself runs in
     * console. Wrapping an assertion in this flag makes the scope behave as
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
}
