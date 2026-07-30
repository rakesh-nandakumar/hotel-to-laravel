<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\AuditLog;
use App\Services\TenantModules;
use App\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Per-tenant module licensing — which feature groups (see App\Support\ModuleCatalog)
 * a tenant's own users can reach at all, enforced regardless of role
 * (App\Http\Middleware\CheckPermission, App\Services\MenuRenderer).
 */
class TenantModuleController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $enabled = TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_enabled', true)
            ->pluck('module_key');

        $modules = collect(ModuleCatalog::definitions())->map(fn (array $definition, string $key) => [
            'key' => $key,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'enabled' => $enabled->contains($key),
        ])->values();

        return response()->json(['tenant' => $tenant, 'modules' => $modules]);
    }

    public function update(Request $request, Tenant $tenant, string $moduleKey): JsonResponse
    {
        if (! array_key_exists($moduleKey, ModuleCatalog::definitions())) {
            abort(404, 'Unknown module.');
        }

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $module = TenantModule::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => $moduleKey],
            ['is_enabled' => $data['enabled'], 'granted_by' => $request->user('central')->id],
        );

        TenantModules::flush($tenant->id);

        AuditLog::record('tenant_module.toggled', $tenant, [
            'module_key' => $moduleKey,
            'enabled' => $data['enabled'],
            'central_admin' => $request->user('central')->email,
        ], Auth::guard('web')->id());

        return response()->json(['module' => $module]);
    }
}
