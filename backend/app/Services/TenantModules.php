<?php

namespace App\Services;

use App\Models\TenantModule;
use App\Support\ModuleCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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
     * execution bypass entirely, mirroring TenantScope/BranchScope.
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
}
