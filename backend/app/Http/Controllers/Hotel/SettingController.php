<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only settings access for the tenant app — every business setting
 * (VAT %, deposit %, currency, ...) many operational pages (POS, Reservations,
 * Bookings, Dashboard, ...) read at runtime for calculations/display. Writing
 * settings is now exclusively a master-control action (see
 * App\Http\Controllers\Central\TenantSettingController) — there is no
 * tenant-side update endpoint anymore.
 */
class SettingController extends Controller
{
    /**
     * Deep/technical settings (integration credentials, gateways) stay
     * hidden from this broad read even here, matching the previous
     * tenant-side admin screen's same restriction.
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
}
