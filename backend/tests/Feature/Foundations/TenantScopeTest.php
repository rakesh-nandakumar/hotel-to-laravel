<?php

use App\Models\Branch;
use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentContext;

/**
 * The core safety property of the whole multi-tenant design: TenantScope must
 * fail closed. A missed tenant resolution must never leak another tenant's
 * rows — it must return zero rows instead of falling through unscoped.
 */
it('scopes tenant-owned rows to the resolved tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
    User::factory()->create(['tenant_id' => $tenantB->id]);

    CurrentContext::simulateWebRequest(function () use ($tenantA, $userA) {
        app(CurrentContext::class)->setTenant($tenantA->id);

        expect(User::query()->pluck('id')->all())->toBe([$userA->id]);
    });
});

it('returns zero rows for a simulated web request with no tenant resolved', function () {
    Tenant::factory()->create();
    User::factory()->create();

    CurrentContext::simulateWebRequest(function () {
        app(CurrentContext::class)->setTenant(null);

        expect(User::query()->count())->toBe(0);
    });
});

it('is unscoped for an authenticated central admin', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenantA->id]);
    User::factory()->create(['tenant_id' => $tenantB->id]);

    actingAsCentral(CentralAdmin::factory()->create());

    CurrentContext::simulateWebRequest(function () {
        app(CurrentContext::class)->setTenant(null);

        expect(User::query()->count())->toBe(2);
    });
});

it('fails loud for console queries with no tenant bound', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenantA->id]);
    User::factory()->create(['tenant_id' => $tenantB->id]);

    // A tenant-scoped query in console without a bound tenant is a missed
    // context binding — a silent sweep would leak every tenant's rows, so it
    // must throw instead (see TenantScope). TestCase binds the demo tenant by
    // default, so unbind explicitly to exercise the guard.
    app(CurrentContext::class)->setTenant(null);

    expect(fn () => User::query()->count())
        ->toThrow(RuntimeException::class, 'tenant-scoped query ran in console with no tenant bound');
});

it('scopes console queries inside runForTenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
    User::factory()->create(['tenant_id' => $tenantB->id]);

    $seen = app(CurrentContext::class)->runForTenant($tenantA, fn () => User::query()->pluck('id')->all());

    expect($seen)->toBe([$userA->id]);
});

it('restores the previous context after runForTenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenantA->id]);
    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

    app(CurrentContext::class)->setTenant($tenantB->id);

    app(CurrentContext::class)->runForTenant($tenantA, fn () => expect(User::query()->count())->toBe(1));

    // The override survives the callback — TenantScope is still bound to B.
    CurrentContext::simulateWebRequest(function () use ($userB) {
        expect(User::query()->pluck('id')->all())->toBe([$userB->id]);
    });
});

it('sweeps unscoped inside runWithoutTenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenantA->id]);
    User::factory()->create(['tenant_id' => $tenantB->id]);

    $count = app(CurrentContext::class)->runWithoutTenant(fn () => User::query()->count());

    expect($count)->toBe(2);
});

it('auto-stamps tenant_id on create from the resolved tenant context', function () {
    $tenant = Tenant::factory()->create();

    CurrentContext::simulateWebRequest(function () use ($tenant) {
        app(CurrentContext::class)->setTenant($tenant->id);

        $branch = Branch::create(['name' => 'Auto Branch', 'is_active' => true]);

        expect($branch->tenant_id)->toBe($tenant->id);
    });
});

it('never falls through unscoped when a branch resolves no tenant', function () {
    Tenant::factory()->create();
    Branch::create(['name' => 'Orphan Branch', 'is_active' => true]);

    CurrentContext::simulateWebRequest(function () {
        app(CurrentContext::class)->setTenant(null);

        expect(Branch::query()->count())->toBe(0);
    });
});
