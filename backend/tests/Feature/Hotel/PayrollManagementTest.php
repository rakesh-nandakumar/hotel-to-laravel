<?php

use App\Models\Hotel\Attendance;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
});

it('blocks a manager from payroll entirely — owner-only module', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->getJson('/api/payroll/staff-pay')->assertForbidden();
    $this->actingAs($manager)->getJson('/api/payroll/runs')->assertForbidden();
});

it('lets the owner set staff pay', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');

    $this->actingAs($owner)->putJson("/api/payroll/staff-pay/{$staff->id}", [
        'base_salary' => 5000000, 'ot_hourly_rate' => 50000, 'monthly_allowance' => 200000,
    ])->assertOk();

    expect($staff->fresh()->base_salary)->toBe(5000000);
});

it('generates a payroll run computing EPF/ETF on base salary only, not gross', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');
    $staff->update(['base_salary' => 5000000, 'ot_hourly_rate' => 50000, 'monthly_allowance' => 200000, 'epf_enabled' => true]);

    // 10 hours OT beyond the 200-hour standard, worth 500,000 in OT pay.
    Attendance::create(['user_id' => $staff->id, 'clock_in' => now()->startOfMonth()->addDay(), 'clock_out' => now()->startOfMonth()->addDay()->addHours(210)]);

    $response = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->assertCreated();
    $line = collect($response->json('run.lines'))->firstWhere('user_id', $staff->id);

    // gross = 5,000,000 + 500,000 (OT) + 200,000 (allowance) = 5,700,000
    // epfEmployee = round(5,000,000 * 8%) = 400,000; netPay = 5,700,000 - 400,000 = 5,300,000
    expect($line['ot_hours'])->toBe(10)
        ->and($line['gross'])->toBe(5700000)
        ->and($line['epf_employee'])->toBe(400000)
        ->and($line['epf_employer'])->toBe(600000)
        ->and($line['net_pay'])->toBe(5300000);
});

it('includes the staff role caption in both staff-pay and a run\'s lines', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');

    $staffPay = $this->actingAs($owner)->getJson('/api/payroll/staff-pay')->assertOk()->json('staff');
    $chefEntry = collect($staffPay)->firstWhere('id', $staff->id);
    expect($chefEntry['roles'][0]['name'])->toBe('Chef');

    $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->json('run');

    $shown = $this->actingAs($owner)->getJson("/api/payroll/runs/{$run['id']}")->assertOk()->json('run');
    $line = collect($shown['lines'])->firstWhere('user_id', $staff->id);
    expect($line['user']['roles'][0]['name'])->toBe('Chef');
});

it('blocks generating a second run for the same month', function () {
    $owner = staffWithRole('Owner');

    $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => '2026-07'])->assertCreated();

    $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => '2026-07'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');
});

it('locks lines once finalized, and gates paying until finalized', function () {
    $owner = staffWithRole('Owner');
    $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => '2026-07'])->json('run');
    $line = $run['lines'][0] ?? null;

    if (! $line) {
        $staff = staffWithRole('Security');
        $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => '2026-08'])->json('run');
        $line = collect($run['lines'])->firstWhere('user_id', $staff->id);
    }

    $this->actingAs($owner)->postJson("/api/payroll/lines/{$line['id']}/mark-paid")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run');

    $this->actingAs($owner)->postJson("/api/payroll/runs/{$run['id']}/finalize")->assertOk();

    $this->actingAs($owner)->putJson("/api/payroll/lines/{$line['id']}", ['bonus' => 10000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run');

    $this->actingAs($owner)->postJson("/api/payroll/lines/{$line['id']}/mark-paid")->assertOk();
});

it('blocks deleting a finalized run but allows deleting a draft', function () {
    $owner = staffWithRole('Owner');
    $draftRun = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => '2026-07'])->json('run');
    $finalizedRun = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => '2026-08'])->json('run');
    $this->actingAs($owner)->postJson("/api/payroll/runs/{$finalizedRun['id']}/finalize")->assertOk();

    $this->actingAs($owner)->deleteJson("/api/payroll/runs/{$finalizedRun['id']}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('run');

    $this->actingAs($owner)->deleteJson("/api/payroll/runs/{$draftRun['id']}")->assertOk();
});
