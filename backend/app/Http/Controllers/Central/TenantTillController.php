<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Till;
use Illuminate\Http\JsonResponse;

/**
 * Read-only visibility into a tenant's tills for the platform operator —
 * till creation/management itself stays tenant-side (App\Http\Controllers\
 * TillController, gated by the tenant's own till.manage permission).
 * TenantScope is unscoped for the `central` guard (see App\Models\Scopes\
 * TenantScope), so — like TenantModuleController — this filters by
 * tenant_id explicitly rather than relying on ambient tenant context.
 */
class TenantTillController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $tills = Till::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return response()->json(['tills' => $tills]);
    }
}
