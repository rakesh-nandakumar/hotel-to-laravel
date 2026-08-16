<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Services\AuditLog as AuditLogService;
use App\Services\CurrentContext;

beforeEach(function () {
    actingAsCentral(CentralAdmin::factory()->create());
});

it('lists a tenants full audit trail including central actions', function () {
    $tenant = Tenant::factory()->create();

    app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant): void {
        AuditLogService::record('tenant.suspended', $tenant, ['central_admin' => 'ops@platform.test']);
        AuditLogService::record('tenant.resumed', $tenant, ['central_admin' => 'ops@platform.test']);
    });

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/audit-logs")
        ->assertOk()
        ->assertJsonCount(2, 'logs.data')
        ->assertJsonPath('logs.data.0.central_admin', 'ops@platform.test');

    $actions = collect($this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/audit-logs")->json('logs.data'))
        ->pluck('action')
        ->all();

    expect($actions)->toContain('tenant.suspended', 'tenant.resumed');
});

it('never leaks audit logs from other tenants', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    app(CurrentContext::class)->runForTenant($other->id, function () use ($other): void {
        AuditLogService::record('tenant.suspended', $other);
    });

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/audit-logs")
        ->assertOk()
        ->assertJsonCount(0, 'logs.data');
});

it('filters the audit trail by action', function () {
    $tenant = Tenant::factory()->create();

    app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant): void {
        AuditLogService::record('tenant.suspended', $tenant);
        AuditLogService::record('tenant.resumed', $tenant);
    });

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/audit-logs?action=tenant.resumed")
        ->assertOk()
        ->assertJsonCount(1, 'logs.data')
        ->assertJsonPath('logs.data.0.action', 'tenant.resumed');
});

it('resolves a human-readable description for each log entry', function () {
    $tenant = Tenant::factory()->create();

    app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant): void {
        AuditLogService::record('tenant.suspended', $tenant);
    });

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/audit-logs")
        ->assertOk()
        ->assertJsonPath('logs.data.0.description', AuditLogService::describe($tenant->auditLogs()->first()));
});
