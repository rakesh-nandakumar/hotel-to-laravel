<?php

use App\Models\Apartment\Unit;
use App\Models\Apartment\UnitType;
use App\Models\Lookup;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
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

function makeOpsUnit(): Unit
{
    $unitType = UnitType::create([
        'name' => 'E2E Ops Type '.uniqid(),
        'max_occupancy' => 2, 'bedrooms' => 1, 'bathrooms' => 1,
        'nightly_rate' => 10000, 'min_nights' => 1, 'cleaning_fee' => 0, 'extra_guest_fee' => 0,
        'cleaning_checklist' => ['Vacuum floors', 'Change linens', 'Restock toiletries'],
    ]);

    return Unit::create([
        'unit_no' => 'O-'.uniqid(),
        'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'rental'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
    ]);
}

it('sends a unit dirty and creates a turnover task on booking checkout, then completing the checklist frees it', function () {
    $manager = staffWithRole('Manager');
    $unit = makeOpsUnit();

    $booking = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Turnover Guest'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
        'adults' => 1,
    ])->assertCreated()->json('booking.id');

    $this->actingAs($manager)->postJson("/api/apartments/bookings/{$booking}/check-in", ['id_number' => '900000000V'])->assertOk();

    $quote = $this->actingAs($manager)->getJson("/api/apartments/bookings/{$booking}/checkout-quote")->json('grand_total');
    $this->actingAs($manager)->postJson("/api/apartments/bookings/{$booking}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => $quote]],
    ])->assertOk();

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::DIRTY);

    // Direct admin edit back to Available is blocked — must go through housekeeping.
    $this->actingAs($manager)->putJson("/api/apartments/units/{$unit->id}/status", ['status' => 'available'])
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    $task = $this->actingAs($manager)->getJson('/api/apartments/housekeeping/tasks')->json('tasks.0');
    expect($task['unit']['id'])->toBe($unit->id);

    // Rejecting an incomplete checklist.
    $this->actingAs($manager)->postJson("/api/apartments/housekeeping/tasks/{$task['id']}/complete", [
        'checklist' => [
            ['item' => 'Vacuum floors', 'done' => true],
            ['item' => 'Change linens', 'done' => false],
            ['item' => 'Restock toiletries', 'done' => true],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('checklist');

    $this->actingAs($manager)->postJson("/api/apartments/housekeeping/tasks/{$task['id']}/complete", [
        'checklist' => [
            ['item' => 'Vacuum floors', 'done' => true],
            ['item' => 'Change linens', 'done' => true],
            ['item' => 'Restock toiletries', 'done' => true],
        ],
    ])->assertOk()->assertJsonPath('unit_status', ApartmentUnitStatus::AVAILABLE);

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::AVAILABLE);
});

it('logging a maintenance issue can take a unit out of service, and resolving it routes through housekeeping', function () {
    $manager = staffWithRole('Manager');
    $unit = makeOpsUnit();

    $issue = $this->actingAs($manager)->postJson('/api/apartments/maintenance', [
        'unit_id' => $unit->id,
        'description' => 'Air conditioning unit not cooling',
        'take_unit_out_of_service' => true,
    ])->assertCreated()->json('issue.id');

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::MAINTENANCE);

    $this->actingAs($manager)->putJson("/api/apartments/maintenance/{$issue}", [
        'status' => 'resolved',
        'resolution_notes' => 'Technician recharged the unit',
        'return_unit_to_service' => true,
    ])->assertOk()->assertJsonPath('issue.status.code', 'resolved');

    // Resolving returns the unit to DIRTY (cleaning gate), not straight to AVAILABLE.
    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::DIRTY);

    $tasks = $this->actingAs($manager)->getJson('/api/apartments/housekeeping/tasks')->json('tasks');
    expect(collect($tasks)->pluck('unit.id'))->toContain($unit->id);
});

it('never touches an occupied unit when logging maintenance against it', function () {
    $manager = staffWithRole('Manager');
    $unit = makeOpsUnit();
    $unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::OCCUPIED)]);

    $this->actingAs($manager)->postJson('/api/apartments/maintenance', [
        'unit_id' => $unit->id,
        'description' => 'Leaking tap reported by tenant',
        'take_unit_out_of_service' => true,
    ])->assertCreated();

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::OCCUPIED);
});

it('returns a sensible shape from the reports dashboard', function () {
    $manager = staffWithRole('Manager');
    makeOpsUnit();

    $res = $this->actingAs($manager)->getJson('/api/apartments/reports/dashboard')->assertOk();

    expect($res->json('units.total'))->toBeGreaterThanOrEqual(1)
        ->and($res->json('ops.pending_housekeeping'))->toBeInt()
        ->and($res->json('ops.open_maintenance'))->toBeInt()
        ->and($res->json('leases.active_count'))->toBeInt();
});
