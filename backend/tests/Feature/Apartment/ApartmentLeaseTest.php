<?php

use App\Models\Apartment\Lease;
use App\Models\Apartment\Unit;
use App\Models\Apartment\UnitType;
use App\Models\Lookup;
use App\Services\Apartment\ApartmentLeaseBillingService;
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

function makeLeasableUnit(): Unit
{
    $unitType = UnitType::create([
        'name' => 'E2E Lease Type '.uniqid(),
        'max_occupancy' => 3, 'bedrooms' => 2, 'bathrooms' => 1,
        'nightly_rate' => 10000, 'monthly_rate' => 250000, 'min_nights' => 1,
        'cleaning_fee' => 0, 'extra_guest_fee' => 0,
    ]);

    return Unit::create([
        'unit_no' => 'L-'.uniqid(),
        'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'rental'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
    ]);
}

it('creates a lease, takes a security deposit, and occupies the unit', function () {
    $manager = staffWithRole('Manager');
    $unit = makeLeasableUnit();

    $res = $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Long Term Tenant', 'phone' => '0711112222'],
        'unit_id' => $unit->id,
        'start_date' => now()->addDay()->toDateString(),
        'monthly_rent' => 250000,
        'security_deposit' => 500000,
        'deposit_payment' => ['method' => 'bank_transfer', 'amount' => 500000],
    ])->assertCreated();

    expect($res->json('lease.status.code'))->toBe('active')
        ->and(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::OCCUPIED);
});

it('blocks leasing a unit that already has an overlapping short-stay booking, and vice versa', function () {
    $manager = staffWithRole('Manager');
    $unit = makeLeasableUnit();

    $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Short Stay Guest'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDays(5)->toDateString(),
        'check_out' => now()->addDays(8)->toDateString(),
        'adults' => 1,
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Blocked Tenant'],
        'unit_id' => $unit->id,
        'start_date' => now()->addDays(1)->toDateString(),
        'end_date' => now()->addDays(400)->toDateString(),
        'monthly_rent' => 250000,
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');

    $unit2 = makeLeasableUnit();
    $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Existing Tenant'],
        'unit_id' => $unit2->id,
        'start_date' => now()->addDay()->toDateString(),
        'monthly_rent' => 200000,
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Should Be Blocked'],
        'channel' => 'direct',
        'unit_id' => $unit2->id,
        'check_in' => now()->addDays(10)->toDateString(),
        'check_out' => now()->addDays(12)->toDateString(),
        'adults' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
});

it('generates monthly rent idempotently — running the job twice never double-charges', function () {
    $manager = staffWithRole('Manager');
    $unit = makeLeasableUnit();

    $created = $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Rent Tenant'],
        'unit_id' => $unit->id,
        'start_date' => now()->startOfMonth()->toDateString(),
        'monthly_rent' => 250000,
    ])->assertCreated();
    $leaseId = $created->json('lease.id');

    $service = app(ApartmentLeaseBillingService::class);
    $first = $service->generateMonthlyCharges(now());
    $second = $service->generateMonthlyCharges(now());

    expect($first['charged'])->toBe(1)->and($second['charged'])->toBe(0)->and($second['skipped_existing'])->toBe(1);

    $lease = Lease::with('ledger.lines')->find($leaseId);
    expect($lease->ledger->lines)->toHaveCount(1)
        ->and($lease->ledger->lines->first()->amount)->toBe(250000);
});

it('records a utility reading and posts the correct amount to the ledger', function () {
    $manager = staffWithRole('Manager');
    $unit = makeLeasableUnit();

    $created = $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Utility Tenant'],
        'unit_id' => $unit->id,
        'start_date' => now()->toDateString(),
        'monthly_rent' => 250000,
    ])->assertCreated();
    $leaseId = $created->json('lease.id');

    $res = $this->actingAs($manager)->postJson("/api/apartments/leases/{$leaseId}/utility-readings", [
        'utility_type' => 'electricity',
        'period_month' => now()->toDateString(),
        'previous_reading' => 100,
        'current_reading' => 145.5,
        'rate_per_unit' => 5000,
    ])->assertCreated();

    expect($res->json('utility_reading.amount'))->toBe((int) round(45.5 * 5000));

    $lease = Lease::with('ledger.lines')->find($leaseId);
    expect($lease->ledger->lines->sum('amount'))->toBe((int) round(45.5 * 5000));
});

it('renews and then terminates a lease, freeing the unit', function () {
    $manager = staffWithRole('Manager');
    $unit = makeLeasableUnit();

    $created = $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Renew Tenant'],
        'unit_id' => $unit->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'monthly_rent' => 250000,
    ])->assertCreated();
    $leaseId = $created->json('lease.id');

    $renewed = $this->actingAs($manager)->postJson("/api/apartments/leases/{$leaseId}/renew", [
        'end_date' => now()->addMonths(12)->toDateString(),
    ])->assertOk();
    expect($renewed->json('lease.status.code'))->toBe('renewed');

    $this->actingAs($manager)->postJson("/api/apartments/leases/{$leaseId}/terminate", [
        'reason' => 'Tenant moving out early',
    ])->assertOk()->assertJsonPath('lease.status.code', 'terminated');

    // Unit goes DIRTY, not straight to AVAILABLE — a turnover cleaning task
    // gates the final step (see ApartmentOpsTest for the full housekeeping flow).
    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::DIRTY);
});
