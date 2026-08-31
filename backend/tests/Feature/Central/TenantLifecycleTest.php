<?php

use App\Models\AuditLog;
use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->withoutHeader('X-Tenant-Slug');
    actingAsCentral(CentralAdmin::factory()->create());
});

it('shows a tenant with its owner admin', function () {
    $tenant = Tenant::factory()->create(['name' => 'Shown Hotel']);
    $admin = User::query()->withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Owner',
        'email' => 'owner@shown.test',
        'password' => Hash::make('secret'),
    ]);

    $this->getJson("/api/central/tenants/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Shown Hotel')
        ->assertJsonPath('owner_admin.email', 'owner@shown.test')
        ->assertJsonPath('owner_admin.impersonation_only', true);
});

it('suspends an active tenant and records a tenant-scoped audit log', function () {
    $tenant = Tenant::factory()->create();

    $this->postJson("/api/central/tenants/{$tenant->id}/suspend")
        ->assertOk()
        ->assertJsonPath('tenant.status', 'suspended');

    expect($tenant->fresh()->status)->toBe('suspended')
        ->and(AuditLog::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'tenant.suspended')
            ->exists())->toBeTrue();
});

it('rejects suspending a tenant that is already suspended', function () {
    $tenant = Tenant::factory()->suspended()->create();

    $this->postJson("/api/central/tenants/{$tenant->id}/suspend")
        ->assertStatus(422);
});

it('resumes a suspended tenant back to active', function () {
    $tenant = Tenant::factory()->suspended()->create();

    $this->postJson("/api/central/tenants/{$tenant->id}/resume")
        ->assertOk()
        ->assertJsonPath('tenant.status', 'active');

    expect($tenant->fresh()->status)->toBe('active')
        ->and(AuditLog::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'tenant.resumed')
            ->exists())->toBeTrue();
});

it('rejects resuming a tenant that is not suspended', function () {
    $tenant = Tenant::factory()->create();

    $this->postJson("/api/central/tenants/{$tenant->id}/resume")
        ->assertStatus(422);
});

it('cancels a tenant through the update endpoint', function () {
    $tenant = Tenant::factory()->create();

    $this->putJson("/api/central/tenants/{$tenant->id}", ['status' => 'cancelled'])
        ->assertOk();

    expect($tenant->fresh()->status)->toBe('cancelled');
});

it('resets the owner admin password once and forces a change on next login', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::query()->withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Owner',
        'email' => 'owner@reset.test',
        'password' => Hash::make('old-secret'),
        'must_change_password' => false,
    ]);

    $response = $this->postJson("/api/central/tenants/{$tenant->id}/reset-admin-password")
        ->assertOk();

    $newPassword = $response->json('password');
    expect($newPassword)->not->toBeNull();

    $fresh = $admin->fresh();
    expect(Hash::check($newPassword, $fresh->password))->toBeTrue()
        ->and($fresh->must_change_password)->toBeTrue()
        ->and(AuditLog::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'tenant.admin_password_reset')
            ->exists())->toBeTrue();
});

it('returns the owner credentials without a recoverable password', function () {
    $tenant = Tenant::factory()->create();
    User::query()->withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Owner',
        'email' => 'owner@creds.test',
        'password' => Hash::make('secret'),
    ]);

    $this->getJson("/api/central/tenants/{$tenant->id}/credentials")
        ->assertOk()
        ->assertJsonPath('owner_admin.email', 'owner@creds.test')
        ->assertJsonPath('owner_admin.impersonation_only', true)
        ->assertJsonMissingPath('owner_admin.password');
});
