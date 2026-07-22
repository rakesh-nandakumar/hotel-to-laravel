<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Settings;
use App\Services\TenantContext;
use App\Support\Lookups\SettingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings management for tenants — moved from the tenant app to the
 * master control panel so only platform admins can configure settings.
 */
class TenantSettingController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        // Temporarily scope to this tenant for the query.
        $context = app(TenantContext::class);
        $context->setTenant($tenant);

        $settings = Setting::query()
            ->orderBy('category')
            ->orderBy('label')
            ->get()
            ->map(fn (Setting $s) => [
                'key' => $s->key,
                'value' => json_decode((string) $s->value, true),
                'type' => $s->type,
                'category' => $s->category,
                'label' => $s->label,
                'hint' => $s->hint,
            ]);

        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request, Tenant $tenant, string $setting): JsonResponse
    {
        // Temporarily scope to this tenant.
        $context = app(TenantContext::class);
        $context->setTenant($tenant);

        $request->validate(['value' => ['present']]);

        Settings::set($setting, $request->input('value'));

        return response()->json(['message' => 'Setting updated.']);
    }
}
