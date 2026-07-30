<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantProvisioning;
use App\Support\TenantStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tenant provisioning/management — the core of the "master control" panel.
 * Every tenant's own branches/users/settings/modules are reachable from here
 * for the platform operator, but this controller itself only manages the
 * Tenant row (see TenantSettingController and TenantModuleController).
 */
class TenantController extends Controller
{
    public function __construct(private readonly TenantProvisioning $provisioning) {}

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()
            ->withCount(['branches', 'users'])
            ->orderBy('name')
            ->get();

        return response()->json(['tenants' => $tenants]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:63', 'alpha_dash', 'lowercase', Rule::unique('tenants', 'slug')],
            'status' => ['nullable', 'string', Rule::in([TenantStatus::TRIAL, TenantStatus::ACTIVE, TenantStatus::SUSPENDED])],
            'trial_ends_at' => ['nullable', 'date'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:150'],
        ]);

        // "admin" is the reserved central subdomain (config('tenancy.central_subdomain'))
        // — a tenant slug matching it would make its own subdomain unreachable.
        if (Str::lower($data['slug']) === Str::lower(config('tenancy.central_subdomain'))) {
            return response()->json(['message' => 'That slug is reserved.', 'errors' => ['slug' => ['That slug is reserved.']]], 422);
        }

        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => $data['status'] ?? TenantStatus::TRIAL,
            'trial_ends_at' => $data['trial_ends_at'] ?? null,
            'created_by' => $request->user('central')->id,
        ]);

        $this->provisioning->provision($tenant, $data['admin_email'], $data['admin_name'] ?? null);

        return response()->json(['tenant' => $tenant], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'string', Rule::in([TenantStatus::TRIAL, TenantStatus::ACTIVE, TenantStatus::SUSPENDED])],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $tenant->update([...$data, 'updated_by' => $request->user('central')->id]);

        return response()->json(['tenant' => $tenant]);
    }
}
