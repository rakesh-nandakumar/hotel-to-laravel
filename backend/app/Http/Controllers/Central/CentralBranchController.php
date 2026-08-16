<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\AuditLog;
use App\Services\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Branch management from master control — a platform operator creates and
 * maintains a tenant's branches (a.k.a. warehouses) on its behalf. The
 * tenant itself has no self-service branch screen; its staff only pick a
 * branch and operate inside it (see CurrentContext).
 */
class CentralBranchController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $branches = Branch::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->withCount(['tills'])
            ->orderBy('name')
            ->get();

        return response()->json(['tenant' => $tenant, 'branches' => $branches]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $this->validateBranch($request, $tenant);

        $branch = Branch::query()->withoutTenantScope()->create([
            ...$data,
            'tenant_id' => $tenant->id,
        ]);

        $this->recordAudit($tenant, $request, 'branch.created', ['name' => $branch->name]);

        return response()->json(['branch' => $branch], 201);
    }

    public function update(Request $request, Tenant $tenant, Branch $branch): JsonResponse
    {
        $this->assertBelongsToTenant($tenant, $branch);

        $data = $this->validateBranch($request, $tenant, $branch->id);

        $branch->update($data);

        $this->recordAudit($tenant, $request, 'branch.updated', ['name' => $branch->name]);

        return response()->json(['branch' => $branch->refresh()]);
    }

    public function destroy(Request $request, Tenant $tenant, Branch $branch): JsonResponse
    {
        $this->assertBelongsToTenant($tenant, $branch);

        abort_if(
            $branch->tills()->exists() || $branch->rooms()->exists(),
            422,
            'A branch with tills or rooms cannot be deleted — deactivate it instead.',
        );

        $name = $branch->name;
        $branch->delete();

        $this->recordAudit($tenant, $request, 'branch.deleted', ['name' => $name]);

        return response()->json(['message' => 'Branch deleted.']);
    }

    /**
     * @return array{name: string, phone?: ?string, email?: ?string, address?: ?string, city?: ?string, country?: ?string, is_active?: bool}
     */
    private function validateBranch(Request $request, Tenant $tenant, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('warehouses', 'name')
                ->where('tenant_id', $tenant->id)
                ->ignore($ignoreId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function assertBelongsToTenant(Tenant $tenant, Branch $branch): void
    {
        abort_unless($branch->tenant_id === $tenant->id, 404, 'Branch not found in this tenant.');
    }

    private function recordAudit(Tenant $tenant, Request $request, string $action, array $context = []): void
    {
        app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant, $request, $action, $context): void {
            AuditLog::record(
                $action,
                $tenant,
                [...$context, 'central_admin' => $request->user('central')->email],
                Auth::guard('web')->id(),
            );
        });
    }
}
