<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ProvisionTenant;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->withCount('users');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tenants = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', 'unique:tenants,slug'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:50'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:100'],
            'max_users' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenant = Tenant::create($data);

        // Provision: create super admin, default roles, permissions, settings.
        $credentials = app(ProvisionTenant::class)->execute($tenant);

        return response()->json([
            'tenant' => $tenant->load('users'),
            'super_admin' => $credentials,
        ], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => $tenant->loadCount('users'),
        ]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
            'email' => ['sometimes', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:50'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:100'],
            'max_users' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenant->update($data);

        return response()->json(['tenant' => $tenant->fresh()]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        return response()->json(['message' => 'Tenant deleted.']);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant->update([
            'status' => Tenant::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $request->input('reason'),
        ]);

        return response()->json(['tenant' => $tenant->fresh()]);
    }

    public function activate(Tenant $tenant): JsonResponse
    {
        $tenant->update([
            'status' => Tenant::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        return response()->json(['tenant' => $tenant->fresh()]);
    }
}
