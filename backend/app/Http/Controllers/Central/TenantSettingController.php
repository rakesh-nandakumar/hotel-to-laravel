<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\AuditLog;
use App\Services\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The relocated Settings admin — every business setting (VAT %, branding,
 * cancellation policy, QR ordering theme, ...) for ONE tenant, edited by a
 * platform operator on that tenant's behalf. Tenant staff have no
 * self-service settings screen anymore; see App\Services\Settings for the
 * read path every other part of the app still uses at runtime.
 */
class TenantSettingController extends Controller
{
    /**
     * Every known setting key (from the same catalog SettingsSeeder seeds
     * from), merged with this tenant's actual overrides — a key the tenant
     * has never had touched still shows its catalog default, unsaved.
     */
    public function index(Tenant $tenant): JsonResponse
    {
        $overrides = Setting::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->get()->keyBy('key');

        $settings = collect(SettingsSeeder::definitions())->map(function (array $definition) use ($overrides) {
            $row = $overrides->get($definition['key']);

            return [
                'key' => $definition['key'],
                'type' => $definition['type'],
                'category' => $definition['category'],
                'label' => $definition['label'],
                'hint' => $definition['hint'] ?? null,
                'value' => $row ? $row->value : json_encode($definition['value']),
                'overridden' => (bool) $row,
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        })->values();

        return response()->json(['tenant' => $tenant, 'settings' => $settings]);
    }

    public function update(Request $request, Tenant $tenant, string $key): JsonResponse
    {
        $data = $request->validate(['value' => ['present']]);

        $before = Setting::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->where('key', $key)->value('value');

        $updated = Settings::set($key, $data['value'], null, $tenant->id);

        // audit_logs.actor_id is a `users` FK — a central admin isn't a `users`
        // row, so their identity goes in $context instead. Explicitly pass the
        // `web` guard's id (never Auth::id()'s ambiguous default-guard
        // resolution) so this can never misattribute the write to an
        // unrelated `users` row that happens to share the central admin's id.
        AuditLog::record('tenant_setting.changed', $tenant, [
            'key' => $key,
            'from' => $before,
            'to' => $updated->value,
            'central_admin' => $request->user('central')->email,
        ], Auth::guard('web')->id());

        return response()->json(['setting' => $updated]);
    }
}
