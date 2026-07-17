<?php

use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
});

it('lets any authenticated staff clock in and out', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->postJson('/api/attendance/clock-in')->assertCreated();

    $this->actingAs($housekeeper)->getJson('/api/attendance/me')
        ->assertOk()
        ->assertJsonCount(1, 'attendance')
        ->assertJsonPath('attendance.0.clock_out', null);

    $this->actingAs($housekeeper)->postJson('/api/attendance/clock-out')
        ->assertOk()
        ->assertJsonPath('attendance.clock_out', fn ($v) => $v !== null);
});

it('blocks clocking in twice without clocking out first', function () {
    $chef = staffWithRole('Chef');

    $this->actingAs($chef)->postJson('/api/attendance/clock-in')->assertCreated();

    $this->actingAs($chef)->postJson('/api/attendance/clock-in')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attendance');
});

it('rejects clocking out without an open record', function () {
    $security = staffWithRole('Security');

    $this->actingAs($security)->postJson('/api/attendance/clock-out')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attendance');
});

it('blocks non-manager roles from the on-duty widget and full history', function () {
    $chef = staffWithRole('Chef');

    $this->actingAs($chef)->getJson('/api/attendance/on-duty')->assertForbidden();
    $this->actingAs($chef)->getJson('/api/attendance')->assertForbidden();
});

it('shows staff currently on duty to a manager', function () {
    $manager = staffWithRole('Manager');
    $housekeeper = staffWithRole('Housekeeper');
    $chef = staffWithRole('Chef');

    $this->actingAs($housekeeper)->postJson('/api/attendance/clock-in')->assertCreated();
    $this->actingAs($chef)->postJson('/api/attendance/clock-in')->assertCreated();
    $this->actingAs($chef)->postJson('/api/attendance/clock-out')->assertOk();

    $response = $this->actingAs($manager)->getJson('/api/attendance/on-duty')->assertOk();

    expect($response->json('on_duty'))->toHaveCount(1)
        ->and($response->json('on_duty.0.name'))->toBe($housekeeper->name);
});

it('computes hours worked once clocked out', function () {
    $manager = staffWithRole('Manager');

    $created = $this->actingAs($manager)->postJson('/api/attendance/clock-in')->json('attendance');
    \App\Models\Hotel\Attendance::where('id', $created['id'])->update(['clock_in' => now()->subHours(8)]);

    $this->actingAs($manager)->postJson('/api/attendance/clock-out')->assertOk();

    $response = $this->actingAs($manager)->getJson('/api/attendance')->assertOk();

    expect($response->json('attendance.0.hours'))->toBe(8);
});
