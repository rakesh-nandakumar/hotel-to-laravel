<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TenantPermissionController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $allPermissions = Permission::query()
            ->withoutGlobalScopes()
            ->select('id', 'name')
            ->distinct('name')
            ->orderBy('name')
            ->get();

        $enabledIds = $tenant->permissions()->pluck('permissions.id');

        return response()->json([
            'permissions' => $allPermissions->map(fn (Permission $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'enabled' => $enabledIds->contains($p->id),
            ]),
        ]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $tenant->permissions()->sync($data['permission_ids']);

        // Flush permission caches for all users of this tenant.
        Cache::forget("tenant:{$tenant->id}:permissions");
        $tenant->users()->each(fn ($user) => $user->flushPermissionCache());

        return response()->json(['message' => 'Tenant permissions updated.']);
    }
}
