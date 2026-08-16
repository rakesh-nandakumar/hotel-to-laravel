<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\Till;

beforeEach(function () {
    actingAsCentral(CentralAdmin::factory()->create());
});

function makeBranch(Tenant $tenant, string $name = 'Downtown'): Branch
{
    return Branch::query()->withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'is_active' => true,
    ]);
}

it('lists a tenants branches with till counts', function () {
    $tenant = Tenant::factory()->create();
    $branch = makeBranch($tenant);

    Till::query()->create([
        'branch_id' => $branch->id,
        'name' => 'Main Till',
    ]);

    $this->getJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches")
        ->assertOk()
        ->assertJsonCount(1, 'branches')
        ->assertJsonPath('branches.0.name', 'Downtown')
        ->assertJsonPath('branches.0.tills_count', 1);
});

it('creates a branch for a tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches", [
        'name' => 'Airport',
        'city' => 'Colombo',
    ])
        ->assertCreated()
        ->assertJsonPath('branch.name', 'Airport');

    expect(Branch::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(AuditLog::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'branch.created')
            ->exists())->toBeTrue();
});

it('enforces unique branch names within a tenant only', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    makeBranch($tenant, 'Shared Name');

    $this->postJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches", [
        'name' => 'Shared Name',
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    $this->postJson("http://admin.localhost/api/central/tenants/{$other->id}/branches", [
        'name' => 'Shared Name',
    ])->assertCreated();
});

it('updates a branch belonging to the tenant', function () {
    $tenant = Tenant::factory()->create();
    $branch = makeBranch($tenant);

    $this->putJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches/{$branch->id}", [
        'name' => 'Rebranded',
    ])->assertOk()->assertJsonPath('branch.name', 'Rebranded');
});

it('refuses to touch a branch that belongs to another tenant', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $branch = makeBranch($other);

    $this->putJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches/{$branch->id}", [
        'name' => 'Hijack',
    ])->assertNotFound();

    $this->deleteJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches/{$branch->id}")
        ->assertNotFound();
});

it('deletes a branch with no tills or rooms', function () {
    $tenant = Tenant::factory()->create();
    $branch = makeBranch($tenant);

    $this->deleteJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches/{$branch->id}")
        ->assertOk();

    expect(Branch::query()->withoutTenantScope()->find($branch->id))->toBeNull()
        ->and(AuditLog::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'branch.deleted')
            ->exists())->toBeTrue();
});

it('refuses to delete a branch that still has tills', function () {
    $tenant = Tenant::factory()->create();
    $branch = makeBranch($tenant);

    Till::query()->create([
        'branch_id' => $branch->id,
        'name' => 'Main Till',
    ]);

    $this->deleteJson("http://admin.localhost/api/central/tenants/{$tenant->id}/branches/{$branch->id}")
        ->assertStatus(422);

    expect(Branch::query()->withoutTenantScope()->find($branch->id))->not->toBeNull();
});
