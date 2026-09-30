<?php

namespace App\Services\Tenancy\DataPurge;

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Services\AuditLog as AuditLogService;
use App\Services\CurrentContext;
use Illuminate\Support\Facades\Auth;

/**
 * Writes master-control data operations into the tenant's audit log. An
 * operator is not a `users` row, so the operator's email travels in the
 * context and the row is stamped with the tenant via runForTenant — the same
 * pattern as the tenant and test-instance controllers.
 */
final class DataPurgeAudit
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(Tenant $tenant, CentralAdmin $admin, string $action, string $description, array $context = []): void
    {
        app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant, $admin, $action, $description, $context): void {
            AuditLogService::record(
                $action,
                $tenant,
                [...$context, 'central_admin' => $admin->email],
                Auth::guard('web')->id(),
                $description,
            );
        });
    }
}
