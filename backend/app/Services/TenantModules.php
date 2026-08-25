<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\TenantModule;
use App\Support\ModuleCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-tenant module licensing — orthogonal to role/permission (see
 * CheckPermission and MenuRenderer, the two enforcement call sites). A
 * tenant's own Full Administrator still can't reach a module the platform
 * operator hasn't enabled for that tenant; that's the point of this being a
 * separate check from hasPermissionTo(), not folded into it.
 */
class TenantModules
{
    /**
     * @return Collection<int, string> enabled catalog module keys
     */
    public static function enabledKeysFor(int $tenantId): Collection
    {
        return Cache::remember(
            "tenant:{$tenantId}:enabled_modules",
            now()->addHour(),
            fn () => TenantModule::query()
                ->where('tenant_id', $tenantId)
                ->where('is_enabled', true)
                ->pluck('module_key'),
        );
    }

    public static function flush(int $tenantId): void
    {
        Cache::forget("tenant:{$tenantId}:enabled_modules");
    }

    /**
     * Whether the CURRENT tenant context has the given fine-grained
     * module_key's licensed feature group enabled. Core module_keys (not
     * covered by any catalog module) always pass. Central admins and console
     * execution bypass entirely, mirroring TenantScope.
     */
    public static function isEnabled(string $fineModuleKey): bool
    {
        if (Auth::guard('central')->check()) {
            return true;
        }

        if (app()->runningInConsole() && ! CurrentContext::isSimulatingWebRequest()) {
            return true;
        }

        $catalogKey = ModuleCatalog::catalogKeyFor($fineModuleKey);
        if ($catalogKey === null) {
            return true; // core — always enabled
        }

        $tenantId = app(CurrentContext::class)->tenantId();
        if ($tenantId === null) {
            return false;
        }

        return self::enabledKeysFor($tenantId)->contains($catalogKey);
    }

    /**
     * Every fine-grained module_key (the part of a permission name before the
     * dot — see App\Http\Middleware\CheckPermission) currently reachable in
     * this context: core keys plus whatever the tenant is licensed for.
     * Exposed to the SPA (App\Http\Controllers\Api\MeController) so its nav
     * and route guards reflect the same licensing CheckPermission enforces
     * server-side, instead of only the user's role permissions — otherwise a
     * disabled module still shows in the sidebar (and, for a Full
     * Administrator whose role check always passes, remains navigable) until
     * the API call 403s.
     *
     * @return Collection<int, string>
     */
    public static function enabledFineKeys(): Collection
    {
        return Permission::query()
            ->pluck('name')
            ->map(fn (string $name) => Str::before($name, '.'))
            ->unique()
            ->filter(fn (string $key) => self::isEnabled($key))
            ->values();
    }
}
