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

it('generates a payroll run computing EPF/ETF on gross salary, with APIT exempt below the tax-free band', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');
    $staff->update(['base_salary' => 5000000, 'ot_hourly_rate' => 50000, 'monthly_allowance' => 200000, 'epf_enabled' => true]);

    // 10 hours OT beyond the 200-hour standard, worth 500,000 in OT pay.
    Attendance::create(['user_id' => $staff->id, 'clock_in' => now()->startOfMonth()->addDay(), 'clock_out' => now()->startOfMonth()->addDay()->addHours(210)]);

    $response = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->assertCreated();
    $line = collect($response->json('run.lines'))->firstWhere('user_id', $staff->id);

    // gross = 5,000,000 + 500,000 (OT) + 200,000 (allowance) - 0 (leave) = 5,700,000 (Rs 57,000 — below the
    // Rs 150,000 monthly APIT-exempt band, so apit = 0).
    // epfEmployee = round(5,700,000 * 8%) = 456,000; epfEmployer = round(5,700,000 * 12%) = 684,000
    // etf = round(5,700,000 * 3%) = 171,000
    // netPay = 5,700,000 - 456,000 (epf) - 0 (apit) - 0 (loan/advance/other) = 5,244,000
    // employerCost = 5,700,000 + 684,000 (epf employer) + 171,000 (etf) = 6,555,000
    expect($line['ot_hours'])->toBe(10)
        ->and($line['gross'])->toBe(5700000)
        ->and($line['epf_employee'])->toBe(456000)
        ->and($line['epf_employer'])->toBe(684000)
        ->and($line['etf'])->toBe(171000)
        ->and($line['apit'])->toBe(0)
        ->and($line['net_pay'])->toBe(5244000)
        ->and($line['employer_cost'])->toBe(6555000);
});

it('calculates APIT progressively across multiple bands once gross exceeds the tax-free threshold', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');
    // Rs 300,000/month gross (base salary only, no OT/allowance/bonus), EPF disabled to isolate the APIT math.
    $staff->update(['base_salary' => 30000000, 'ot_hourly_rate' => 0, 'monthly_allowance' => 0, 'epf_enabled' => false]);

    $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->assertCreated()->json('run');
    $line = collect($run['lines'])->firstWhere('user_id', $staff->id);

    // gross = 30,000,000 cents (Rs 300,000). APIT bands (LKR cents): 0-15,000,000 @0%,
    // next 8,333,333.33 @6%, next 4,166,666.67 @18%, next 4,166,666.67 @24% (capped — only
    // 2,500,000 of this band is used since gross runs out at 30,000,000):
    //   band2: 8,333,333.33 * 6%  = 500,000.00
    //   band3: 4,166,666.67 * 18% = 750,000.00
    //   band4: 2,500,000.00 * 24% = 600,000.00
    //   total apit = 1,850,000
    expect($line['gross'])->toBe(30000000)
        ->and($line['epf_employee'])->toBe(0)
        ->and($line['apit'])->toBe(1850000)
        ->and($line['net_pay'])->toBe(30000000 - 1850000);
});

it('subtracts unpaid leave from gross before computing EPF/APIT, and sums loan+advance+other into net', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');
    $staff->update(['base_salary' => 5000000, 'ot_hourly_rate' => 0, 'monthly_allowance' => 0, 'epf_enabled' => true]);

    $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->json('run');
    $line = collect($run['lines'])->firstWhere('user_id', $staff->id);

    $updated = $this->actingAs($owner)->putJson("/api/payroll/lines/{$line['id']}", [
        'unpaid_leave_deduction' => 500000, 'loan' => 100000, 'advance' => 50000, 'other_deduction' => 25000,
    ])->assertOk()->json('line');

    // gross = 5,000,000 - 500,000 (leave) = 4,500,000 (Rs 45,000 — still APIT-exempt).
    // epfEmployee = round(4,500,000 * 8%) = 360,000
    // netPay = 4,500,000 - 360,000 (epf) - 0 (apit) - 100,000 (loan) - 50,000 (advance) - 25,000 (other) = 3,965,000
    expect($updated['gross'])->toBe(4500000)
        ->and($updated['epf_employee'])->toBe(360000)
        ->and($updated['net_pay'])->toBe(3965000);
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
