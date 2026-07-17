<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\UpdateSettingRequest;
use App\Models\Setting;
use App\Services\AuditLog;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business-configurable Settings admin — every `hotel.*`/`billing.*`/
 * `notifications.*`/etc. key read throughout the app via {@see Settings}.
 * Ported from the Node app's routes/settings.ts.
 */
class SettingController extends Controller
{
    /**
     * Deep/technical settings (integration credentials, gateways) are
     * visible and writable by a Full Administrator only — hidden from the
     * Owner and everyone else. Business settings stay open to every staff
     * member for reading (matches Node: no role gate at all on the GET).
     */
    private const ADMIN_ONLY_CATEGORY = 'integrations';

    public function index(Request $request): JsonResponse
    {
        $isFullAdmin = $request->user()->isFullAdmin();

        $settings = Setting::query()
            ->when(! $isFullAdmin, fn ($q) => $q->where('category', '!=', self::ADMIN_ONLY_CATEGORY))
            ->orderBy('category')->orderBy('key')
            ->get();

        return response()->json(['settings' => $settings]);
    }

    public function update(UpdateSettingRequest $request, Setting $setting): JsonResponse
    {
        if ($setting->category === self::ADMIN_ONLY_CATEGORY && ! $request->user()->isFullAdmin()) {
            abort(403, 'Integration settings can only be changed by a Full Administrator.');
        }

        $before = $setting->value;
        $redact = $setting->category === self::ADMIN_ONLY_CATEGORY;

        $updated = Settings::set($setting->key, $request->validated('value'), $request->user()->id);

        // Setting's primary key is a string ("billing.vat_pct"), not an
        // auto-increment id, so it can't be passed as AuditLog::record()'s
        // $subject (audit_logs.subject_id is an unsignedBigInteger FK column)
        // — the key goes in $context instead.
        AuditLog::record('setting.changed', null, [
            'key' => $setting->key,
            'from' => $redact ? '[redacted]' : $before,
            'to' => $redact ? '[redacted]' : $updated->value,
        ]);

        return response()->json(['setting' => $updated]);
    }
}
