<?php

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

function reportsRentalUnit(int $nightlyRate = 10000): Unit
{
    $unitType = UnitType::create([
        'name' => 'Report Unit Type '.uniqid(),
        'max_occupancy' => 3, 'bedrooms' => 1, 'bathrooms' => 1,
        'nightly_rate' => $nightlyRate, 'min_nights' => 1, 'cleaning_fee' => 0, 'extra_guest_fee' => 0,
        'cleaning_checklist' => ['Vacuum floors'],
    ]);

    return Unit::create([
        'unit_no' => 'R-'.uniqid(),
        'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'rental'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
    ]);
}

function reportsSaleUnit(int $price = 25000000): Unit
{
    $unitType = UnitType::create([
        'name' => 'Report Sale Type '.uniqid(),
        'max_occupancy' => 3, 'bedrooms' => 2, 'bathrooms' => 2,
        'min_nights' => 1, 'cleaning_fee' => 0, 'extra_guest_fee' => 0,
    ]);

    return Unit::create([
        'unit_no' => 'RS-'.uniqid(),
        'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'sale'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
        'sale_price' => $price,
    ]);
}

it('blocks non-manager roles from every new apartment report endpoint', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/apartments/reports/occupancy-trend')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/apartments/reports/revenue-channel')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/apartments/reports/rent-roll')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/apartments/reports/sales-pipeline')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/apartments/reports/utilities')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/apartments/reports/ops-sla')->assertForbidden();
});

it('computes occupancy trend from a checked-in booking', function () {
    $manager = staffWithRole('Manager');
    $unit = reportsRentalUnit();
    $today = today()->toDateString();

    $booking = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Occupancy Guest'], 'channel' => 'direct', 'unit_id' => $unit->id,
        'check_in' => $today, 'check_out' => today()->addDays(2)->toDateString(), 'adults' => 1,
    ])->assertCreated()->json('booking');
    $this->actingAs($manager)->postJson("/api/apartments/bookings/{$booking['id']}/check-in", ['id_number' => '900000001V'])->assertOk();

    $response = $this->actingAs($manager)->getJson("/api/apartments/reports/occupancy-trend?from={$today}&to={$today}")->assertOk();

    expect($response->json('total_rental_units'))->toBe(1)
        ->and($response->json('series.0.occupied_units'))->toBe(1)
        ->and($response->json('series.0.occupancy_pct'))->toBe(100)
        ->and($response->json('avg_occupancy_pct'))->toBe(100);
});

it('computes rental revenue & channel mix from checked-in bookings', function () {
    $manager = staffWithRole('Manager');
    $today = today()->toDateString();

    $direct = reportsRentalUnit(10000);
    $directBooking = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Direct Guest'], 'channel' => 'direct', 'unit_id' => $direct->id,
        'check_in' => $today, 'check_out' => today()->addDays(2)->toDateString(), 'adults' => 1,
    ])->assertCreated()->json('booking');
    $directLedger = $this->actingAs($manager)->postJson("/api/apartments/bookings/{$directBooking['id']}/check-in", ['id_number' => '900000002V'])->assertOk()->json('ledger');

    $airbnb = reportsRentalUnit(20000);
    $airbnbBooking = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Airbnb Guest'], 'channel' => 'airbnb', 'unit_id' => $airbnb->id,
        'check_in' => $today, 'check_out' => today()->addDays(2)->toDateString(), 'adults' => 1,
    ])->assertCreated()->json('booking');
    $airbnbLedger = $this->actingAs($manager)->postJson("/api/apartments/bookings/{$airbnbBooking['id']}/check-in", ['id_number' => '900000003V'])->assertOk()->json('ledger');

    $response = $this->actingAs($manager)->getJson("/api/apartments/reports/revenue-channel?from={$today}&to={$today}")->assertOk();

    expect($response->json('total_bookings'))->toBe(2)
        ->and($response->json('by_channel.direct.bookings'))->toBe(1)
        ->and($response->json('by_channel.direct.revenue'))->toBe($directLedger['total'])
        ->and($response->json('by_channel.airbnb.bookings'))->toBe(1)
        ->and($response->json('by_channel.airbnb.revenue'))->toBe($airbnbLedger['total']);
});

it('computes the rent roll with an arrears aging bucket for an unpaid lease', function () {
    $manager = staffWithRole('Manager');
    $unit = reportsRentalUnit();

    $lease = $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Rent Roll Tenant'], 'unit_id' => $unit->id,
        'start_date' => today()->startOfMonth()->toDateString(), 'monthly_rent' => 250000,
    ])->assertCreated()->json('lease');

    app(ApartmentLeaseBillingService::class)->generateMonthlyCharges(now());

    $response = $this->actingAs($manager)->getJson('/api/apartments/reports/rent-roll')->assertOk();
    $row = collect($response->json('leases'))->firstWhere('id', $lease['id']);

    expect($row['balance'])->toBe(250000)
        ->and($row['aging_bucket'])->toBe('0-30')
        ->and($response->json('total_arrears'))->toBeGreaterThanOrEqual(250000);
});

it('computes sales pipeline funnel, conversion rate, and pipeline value', function () {
    $manager = staffWithRole('Manager');
    $unit = reportsSaleUnit(25000000);

    $sale = $this->actingAs($manager)->postJson('/api/apartments/sales', [
        'new_customer' => ['name' => 'Report Buyer'], 'unit_id' => $unit->id, 'agreed_price' => 25000000,
    ])->assertCreated()->json('sale');
    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale['id']}/reserve", [])->assertOk();
    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale['id']}/sign-agreement")->assertOk();

    $ledgerId = $this->actingAs($manager)->getJson("/api/apartments/sales/{$sale['id']}")->json('ledger.id');
    $this->actingAs($manager)->postJson("/api/apartments/ledgers/{$ledgerId}/payment", ['method' => 'bank_transfer', 'amount' => 25000000])->assertCreated();
    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale['id']}/complete")->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/apartments/reports/sales-pipeline?from={$today}&to={$today}")->assertOk();

    expect($response->json('total_sales'))->toBe(1)
        ->and($response->json('by_status.completed'))->toBe(1)
        ->and($response->json('conversion_rate_pct'))->toEqual(100)
        ->and($response->json('cancelled_count'))->toBe(0)
        ->and($response->json('completed_value'))->toBe(25000000);
});

it('computes utility consumption and cost by type and unit', function () {
    $manager = staffWithRole('Manager');
    $unit = reportsRentalUnit();

    $lease = $this->actingAs($manager)->postJson('/api/apartments/leases', [
        'new_customer' => ['name' => 'Utility Tenant'], 'unit_id' => $unit->id,
        'start_date' => today()->toDateString(), 'monthly_rent' => 250000,
    ])->assertCreated()->json('lease');

    $this->actingAs($manager)->postJson("/api/apartments/leases/{$lease['id']}/utility-readings", [
        'utility_type' => 'electricity', 'period_month' => today()->toDateString(),
        'previous_reading' => 100, 'current_reading' => 145.5, 'rate_per_unit' => 5000,
    ])->assertCreated();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/apartments/reports/utilities?from={$today}&to={$today}")->assertOk();
    $expectedAmount = (int) round(45.5 * 5000);

    expect($response->json('total_amount'))->toBe($expectedAmount)
        ->and($response->json('by_type.electricity.amount'))->toBe($expectedAmount)
        ->and($response->json('by_type.electricity.consumption'))->toEqual(45.5)
        ->and($response->json('by_unit.'.$unit->unit_no))->toBe($expectedAmount);
});

it('computes housekeeping turnaround and maintenance resolution time', function () {
    $manager = staffWithRole('Manager');
    $unit = reportsRentalUnit();

    $task = $this->actingAs($manager)->postJson('/api/apartments/housekeeping/tasks', ['unit_id' => $unit->id])->assertCreated()->json('task');
    $doneChecklist = collect($task['checklist'])->map(fn (array $c) => ['item' => $c['item'], 'done' => true])->all();
    $this->actingAs($manager)->postJson("/api/apartments/housekeeping/tasks/{$task['id']}/complete", ['checklist' => $doneChecklist])->assertOk();

    $issue = $this->actingAs($manager)->postJson('/api/apartments/maintenance', ['unit_id' => $unit->id, 'description' => 'Leaky faucet'])->assertCreated()->json('issue');
    $this->actingAs($manager)->putJson("/api/apartments/maintenance/{$issue['id']}", ['status' => 'resolved', 'resolution_notes' => 'Fixed'])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/apartments/reports/ops-sla?from={$today}&to={$today}")->assertOk();

    expect($response->json('housekeeping.total'))->toBe(1)
        ->and($response->json('housekeeping.completed'))->toBe(1)
        ->and($response->json('maintenance.total'))->toBe(1)
        ->and($response->json('maintenance.resolved'))->toBe(1);
});
