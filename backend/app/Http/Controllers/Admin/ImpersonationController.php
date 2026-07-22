<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Impersonation allows platform admins to log in as a tenant's super admin
 * without knowing their password. The admin session is preserved in the
 * session so we can return to it on "stop impersonation".
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, Tenant $tenant): JsonResponse
    {
        // Find the tenant's super admin (the full admin role user).
        $superAdmin = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('is_full_admin', true))
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if (! $superAdmin) {
            return response()->json([
                'message' => 'No active Super Admin found for this tenant.',
            ], 404);
        }

        // Store the platform admin's ID so we can restore the session later.
        $platformAdmin = Auth::guard('platform')->user();
        $request->session()->put('impersonating_from', $platformAdmin->id);
        $request->session()->put('impersonated_tenant_id', $tenant->id);

        // Log in as the tenant's super admin on the default (web) guard.
        Auth::guard('web')->login($superAdmin);

        return response()->json([
            'message' => 'Impersonation started.',
            'user' => [
                'id' => $superAdmin->id,
                'name' => $superAdmin->name,
                'email' => $superAdmin->email,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
        ]);
    }

    public function stop(Request $request): JsonResponse
    {
        $platformAdminId = $request->session()->get('impersonating_from');

        if (! $platformAdminId) {
            return response()->json(['message' => 'Not impersonating.'], 400);
        }

        // Log out from the tenant user session.
        Auth::guard('web')->logout();

        // Remove impersonation markers.
        $request->session()->forget(['impersonating_from', 'impersonated_tenant_id']);

        return response()->json(['message' => 'Impersonation stopped.']);
    }
}
