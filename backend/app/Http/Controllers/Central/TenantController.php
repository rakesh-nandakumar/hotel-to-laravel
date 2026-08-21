<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\ReservedSlug;
use App\Services\AuditLog as AuditLogService;
use App\Services\CurrentContext;
use App\Services\TenantProvisioning;
use App\Support\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tenant provisioning/management — the core of the "master control" panel.
 * Every tenant's own branches/users/settings/modules are reachable from here
 * for the platform operator, but this controller itself only manages the
 * Tenant row (see TenantSettingController, TenantModuleController and
 * CentralBranchController).
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

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->loadCount(['branches', 'users', 'auditLogs']);

        $owner = $this->ownerAdmin($tenant);

        return response()->json([
            'tenant' => $tenant,
            'owner_admin' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'status' => $owner->status,
                'created_at' => $owner->created_at?->toIso8601String(),
                // Hotel tenants are only ever accessed through impersonation
                // (TenantProvisioning mints a never-communicated password), so
                // there are no credentials to hand out.
                'impersonation_only' => true,
            ] : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:63', 'alpha_dash', 'lowercase', Rule::unique('tenants', 'slug'), new ReservedSlug],
            'status' => ['nullable', 'string', Rule::in([TenantStatus::TRIAL, TenantStatus::ACTIVE, TenantStatus::SUSPENDED, TenantStatus::CANCELLED])],
            'trial_ends_at' => ['nullable', 'date'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:150'],
        ]);

        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => $data['status'] ?? TenantStatus::ACTIVE,
            'trial_ends_at' => $data['trial_ends_at'] ?? null,
            'created_by' => $request->user('central')->id,
        ]);

        $this->provisioning->provision($tenant, $data['admin_email'], $data['admin_name'] ?? null);

        $this->recordAudit($tenant, $request, 'tenant.provisioned', [
            'admin_email' => $data['admin_email'],
        ]);

        return response()->json(['tenant' => $tenant], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'string', Rule::in([TenantStatus::TRIAL, TenantStatus::ACTIVE, TenantStatus::SUSPENDED, TenantStatus::CANCELLED])],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $before = $tenant->only(['name', 'status', 'trial_ends_at']);

        $tenant->update([...$data, 'updated_by' => $request->user('central')->id]);

        $this->recordAudit($tenant, $request, 'tenant.updated', [
            'changed' => collect($data)
                ->mapWithKeys(fn ($value, $key) => [
                    $key => isset($before[$key]) && $before[$key] != $value
                        ? "{$before[$key]} → {$value}"
                        : null,
                ])
                ->filter()
                ->all(),
        ]);

        return response()->json(['tenant' => $tenant]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        abort_if($tenant->isSuspended(), 422, 'Tenant is already suspended.');

        $tenant->update([
            'status' => TenantStatus::SUSPENDED,
            'updated_by' => $request->user('central')->id,
        ]);

        $this->recordAudit($tenant, $request, 'tenant.suspended', [
            'from' => $tenant->getOriginal('status'),
        ]);

        return response()->json(['tenant' => $tenant->refresh()]);
    }

    public function resume(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->isSuspended(), 422, 'Only a suspended tenant can be resumed.');

        $tenant->update([
            'status' => TenantStatus::ACTIVE,
            'updated_by' => $request->user('central')->id,
        ]);

        $this->recordAudit($tenant, $request, 'tenant.resumed');

        return response()->json(['tenant' => $tenant->refresh()]);
    }

    /**
     * Regenerates the tenant owner's password (never-communicated by design —
     * impersonation is the access path, see TenantProvisioning). The one-time
     * password is returned here and nowhere else.
     */
    public function resetAdminPassword(Request $request, Tenant $tenant): JsonResponse
    {
        $admin = $this->ownerAdmin($tenant);
        abort_if($admin === null, 422, 'This tenant has no administrator user yet.');

        $password = Str::random(14);

        app(CurrentContext::class)->runForTenant($tenant->id, function () use ($admin, $password): void {
            $admin->update(['password' => $password, 'must_change_password' => true]);
        });

        $this->recordAudit($tenant, $request, 'tenant.admin_password_reset', [
            'email' => $admin->email,
        ]);

        return response()->json([
            'password' => $password,
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
        ]);
    }

    /**
     * Who the tenant owner is — there are no recoverable credentials, the
     * account is impersonation-only (see show()).
     */
    public function credentials(Tenant $tenant): JsonResponse
    {
        $admin = $this->ownerAdmin($tenant);

        return response()->json([
            'tenant' => $tenant,
            'owner_admin' => $admin ? [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'status' => $admin->status,
                'created_at' => $admin->created_at?->toIso8601String(),
                'impersonation_only' => true,
            ] : null,
        ]);
    }

    /**
     * This tenant's full audit trail — the master-control view of everything
     * that happened inside the tenant AND every central action on it.
     */
    public function auditLogs(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'action' => ['nullable', 'string', 'max:120'],
            'actor' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = AuditLog::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->when($validated['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($validated['actor'] ?? null, fn (Builder $q, string $actor) => $q->whereHas(
                'actor',
                fn (Builder $q) => $q->where('name', 'like', "%{$actor}%")->orWhere('email', 'like', "%{$actor}%"),
            ))
            ->when($validated['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('created_at');

        $logs = $query->with('actor:id,name,email')->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'tenant' => $tenant,
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => AuditLogService::describe($log),
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email] : null,
                'central_admin' => $log->context['central_admin'] ?? null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * The tenant's administrator user — the first full-admin user, falling
     * back to the first user the provisioning created.
     */
    private function ownerAdmin(Tenant $tenant): ?User
    {
        return User::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn (Builder $q) => $q->where('is_full_admin', true))
            ->orderBy('id')
            ->first()
            ?? User::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->first();
    }

    /**
     * Records a central-admin action on this tenant. Runs inside
     * runForTenant() so the audit row is stamped with the tenant and shows up
     * in the tenant's own audit trail as well as the central view. The actor
     * is a CentralAdmin, not a `users` row, so their identity goes in
     * $context — same convention as TenantSettingController.
     */
    private function recordAudit(Tenant $tenant, Request $request, string $action, array $context = []): void
    {
        app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant, $request, $action, $context): void {
            AuditLogService::record(
                $action,
                $tenant,
                [...$context, 'central_admin' => $request->user('central')->email],
                Auth::guard('web')->id(),
            );
        });
    }
}
