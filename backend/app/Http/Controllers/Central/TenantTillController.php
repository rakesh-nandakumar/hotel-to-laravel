<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Till;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Till definitions (which cash drawers a tenant has) are configured only
 * from master control — the tenant's own Till page (App\Http\Controllers\
 * TillController) is read-only over them and only opens/closes sessions
 * against whatever exists here. TenantScope is unscoped for the `central`
 * guard (see App\Models\Scopes\TenantScope), so — like TenantModuleController —
 * this filters by tenant_id explicitly rather than relying on ambient
 * tenant context.
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

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $till = Till::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'is_active' => true,
        ]);

        return response()->json(['till' => $till], 201);
    }

    public function update(Request $request, Tenant $tenant, Till $till): JsonResponse
    {
        abort_unless($till->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ]);

        $till->update($data);

        return response()->json(['till' => $till]);
    }
}
