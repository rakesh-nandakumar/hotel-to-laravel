<?php

use App\Models\Apartment\Customer;
use App\Models\Apartment\Unit;
use App\Models\Apartment\UnitType;
use App\Models\Lookup;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
});

function makeApartmentUnitType(array $overrides = []): UnitType
{
    return UnitType::create(array_merge([
        'name' => 'Studio',
        'max_occupancy' => 2,
        'bedrooms' => 0,
        'bathrooms' => 1,
        'nightly_rate' => 10000,
        'min_nights' => 1,
        'cleaning_fee' => 0,
        'extra_guest_fee' => 0,
    ], $overrides));
}

it('blocks a role with no apartment grants from viewing units', function () {
    $chef = staffWithRole('Chef');

    $this->actingAs($chef)->getJson('/api/apartments/units')->assertForbidden();
    $this->actingAs($chef)->postJson('/api/apartments/unit-types', ['name' => 'X'])->assertForbidden();
});

it('lets a manager create a unit type and a rental unit under it', function () {
    $manager = staffWithRole('Manager');

    $typeResponse = $this->actingAs($manager)->postJson('/api/apartments/unit-types', [
        'name' => '1 Bedroom',
        'max_occupancy' => 3,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'nightly_rate' => 15000,
        'weekly_rate' => 90000,
        'monthly_rate' => 300000,
        'min_nights' => 2,
        'cleaning_fee' => 2000,
        'extra_guest_fee' => 1000,
    ])->assertCreated();

    $unitTypeId = $typeResponse->json('unit_type.id');

    $unitResponse = $this->actingAs($manager)->postJson('/api/apartments/units', [
        'unit_no' => 'A-101',
        'unit_type_id' => $unitTypeId,
        'listing_type' => 'rental',
    ])->assertCreated();

    expect($unitResponse->json('unit.status.code'))->toBe(ApartmentUnitStatus::AVAILABLE)
        ->and($unitResponse->json('unit.listing_type.code'))->toBe('rental');
});

it('requires a sale price when listing a unit for sale', function () {
    $manager = staffWithRole('Manager');
    $unitType = makeApartmentUnitType();

    $this->actingAs($manager)->postJson('/api/apartments/units', [
        'unit_no' => 'B-201',
        'unit_type_id' => $unitType->id,
        'listing_type' => 'sale',
    ])->assertUnprocessable()->assertJsonValidationErrors('sale_price');

    $this->actingAs($manager)->postJson('/api/apartments/units', [
        'unit_no' => 'B-201',
        'unit_type_id' => $unitType->id,
        'listing_type' => 'sale',
        'sale_price' => 25000000,
    ])->assertCreated()->assertJsonPath('unit.listing_type.code', 'sale');
});

it('rejects a duplicate unit type name and a duplicate unit number', function () {
    $manager = staffWithRole('Manager');
    makeApartmentUnitType(['name' => 'Studio']);

    $this->actingAs($manager)->postJson('/api/apartments/unit-types', [
        'name' => 'Studio', 'max_occupancy' => 2, 'bedrooms' => 0, 'bathrooms' => 1,
        'min_nights' => 1, 'cleaning_fee' => 0, 'extra_guest_fee' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    $unitType = UnitType::query()->where('name', 'Studio')->firstOrFail();
    Unit::create([
        'unit_no' => 'A-101', 'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'rental'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
    ]);

    $this->actingAs($manager)->postJson('/api/apartments/units', [
        'unit_no' => 'A-101', 'unit_type_id' => $unitType->id, 'listing_type' => 'rental',
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_no');
});

it('blocks setting a unit directly to occupied, reserved, or sold — those are workflow-only', function () {
    $manager = staffWithRole('Manager');
    $unitType = makeApartmentUnitType();
    $unit = Unit::create([
        'unit_no' => 'C-301', 'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'rental'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
    ]);

    foreach ([ApartmentUnitStatus::OCCUPIED, ApartmentUnitStatus::RESERVED, ApartmentUnitStatus::SOLD] as $status) {
        $this->actingAs($manager)->putJson("/api/apartments/units/{$unit->id}/status", ['status' => $status])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    $this->actingAs($manager)->putJson("/api/apartments/units/{$unit->id}/status", ['status' => ApartmentUnitStatus::MAINTENANCE])
        ->assertOk()->assertJsonPath('unit.status.code', ApartmentUnitStatus::MAINTENANCE);
});

it('adds and removes a seasonal rate for a unit type', function () {
    $manager = staffWithRole('Manager');
    $unitType = makeApartmentUnitType();

    $response = $this->actingAs($manager)->postJson("/api/apartments/unit-types/{$unitType->id}/seasonal", [
        'name' => 'Peak', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'rate' => 18000,
    ])->assertCreated();

    $seasonalRateId = $response->json('seasonal_rate.id');

    $this->actingAs($manager)->deleteJson("/api/apartments/unit-types/seasonal/{$seasonalRateId}")->assertOk();

    expect($unitType->seasonalRates()->count())->toBe(0);
});

it('creates, searches, and updates apartment customers', function () {
    $manager = staffWithRole('Manager');

    $created = $this->actingAs($manager)->postJson('/api/apartments/customers', [
        'name' => 'Nadia Perera', 'phone' => '0771234567', 'email' => 'nadia@example.com',
    ])->assertCreated();

    $customerId = $created->json('customer.id');

    $this->actingAs($manager)->getJson('/api/apartments/customers?q=Nadia')
        ->assertOk()->assertJsonCount(1, 'customers');

    $this->actingAs($manager)->putJson("/api/apartments/customers/{$customerId}", ['name' => 'Nadia P. Silva'])
        ->assertOk()->assertJsonPath('customer.name', 'Nadia P. Silva');

    expect(Customer::find($customerId)->name)->toBe('Nadia P. Silva');
});
