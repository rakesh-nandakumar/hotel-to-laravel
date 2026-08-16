<?php

use App\Models\Branch;
use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    actingAsCentral(CentralAdmin::factory()->create());
});

it('reports platform-wide counts broken down by status and environment', function () {
    // The demo tenant (Tenant::demo, slug `default`) is bound by the TestCase
    // setUp and counts toward the platform totals.
    Tenant::factory()->create(['status' => 'active', 'created_at' => now()->subDays(2)]);
    Tenant::factory()->create(['status' => 'active', 'created_at' => now()->subDays(2)]);
    Tenant::factory()->trial()->create(['created_at' => now()->subDays(2)]);
    Tenant::factory()->suspended()->create(['created_at' => now()->subDays(2)]);
    Tenant::factory()->create(['status' => 'cancelled', 'created_at' => now()->subDays(2)]);
    Tenant::factory()->create(['environment' => 'test', 'created_at' => now()->subDays(2)]);

    $tenant = Tenant::factory()->create(['created_at' => now()->subDays(2)]);
    $branch = Branch::query()->withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Downtown',
        'created_at' => now()->subDays(2),
    ]);
    User::query()->withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Staff',
        'email' => 'staff@dashboard.test',
        'password' => Hash::make('secret'),
    ]);
    $branch->tills()->create(['name' => 'Main Till']);

    $this->getJson('http://admin.localhost/api/central/dashboard')
        ->assertOk()
        ->assertJsonPath('counts.total', 8)
        ->assertJsonPath('counts.by_status.active', 5)
        ->assertJsonPath('counts.by_status.trial', 1)
        ->assertJsonPath('counts.by_status.suspended', 1)
        ->assertJsonPath('counts.by_status.cancelled', 1)
        ->assertJsonPath('counts.by_environment.live', 7)
        ->assertJsonPath('counts.by_environment.test', 1)
        ->assertJsonPath('counts.admins', 1)
        ->assertJsonPath('counts.users', 1)
        ->assertJsonPath('counts.branches', 1);
});

it('lists the most recently created tenants on the dashboard', function () {
    Tenant::factory()->create(['name' => 'Oldest', 'created_at' => now()->subDays(2)]);
    // Future timestamp beats any same-second tie with the demo tenant.
    Tenant::factory()->create(['name' => 'Newest', 'created_at' => now()->addSecond()]);

    $this->getJson('http://admin.localhost/api/central/dashboard')
        ->assertOk()
        ->assertJsonPath('recent_tenants.0.name', 'Newest');
});
