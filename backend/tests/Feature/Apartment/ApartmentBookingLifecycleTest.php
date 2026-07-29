<?php

use App\Models\Apartment\Booking;
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

function makeRentalUnit(array $unitTypeOverrides = [], array $unitOverrides = []): Unit
{
    $unitType = UnitType::create(array_merge([
        'name' => 'E2E Unit Type '.uniqid(),
        'max_occupancy' => 3,
        'bedrooms' => 1,
        'bathrooms' => 1,
        'nightly_rate' => 10000,
        'weekly_rate' => 60000,
        'monthly_rate' => 200000,
        'min_nights' => 1,
        'cleaning_fee' => 0,
        'extra_guest_fee' => 0,
    ], $unitTypeOverrides));

    return Unit::create(array_merge([
        'unit_no' => 'U-'.uniqid(),
        'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'rental'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
    ], $unitOverrides));
}

it('books a unit, checks the customer in and out, and settles the ledger', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $unit = makeRentalUnit();

    $book = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Nadia Perera', 'phone' => '0771234567'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'adults' => 2,
    ])->assertCreated();

    $bookingId = $book->json('booking.id');
    expect($book->json('booking.nightly_rate'))->toBe(10000)
        ->and($book->json('booking.rate_basis'))->toBe('nightly')
        ->and($book->json('booking.deposit_due'))->toBe((int) round(3 * 10000 * 0.2));

    // Unit is busy for those dates now — a second, overlapping booking must be rejected.
    $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Other Guest'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(5)->toDateString(),
        'adults' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');

    $checkIn = $this->actingAs($manager)->postJson("/api/apartments/bookings/{$bookingId}/check-in", [
        'id_number' => '912345678V',
    ])->assertOk();
    expect($checkIn->json('ledger.total'))->toBe(3 * 10000);
    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::OCCUPIED);

    $quote = $this->actingAs($manager)->getJson("/api/apartments/bookings/{$bookingId}/checkout-quote")->assertOk();
    $grandTotal = $quote->json('grand_total');

    $checkout = $this->actingAs($manager)->postJson("/api/apartments/bookings/{$bookingId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => $grandTotal]],
    ])->assertOk();

    expect($checkout->json('balance'))->toBe(0)
        ->and($checkout->json('invoice_no'))->not->toBeNull();
    // Unit goes DIRTY, not straight to AVAILABLE — a turnover cleaning task
    // gates the final step (see ApartmentOpsTest for the full housekeeping flow).
    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::DIRTY);
    expect(Booking::find($bookingId)->status->code)->toBe('checked_out');
});

it('rejects a stay shorter than the unit type minimum', function () {
    $manager = staffWithRole('Manager');
    $unit = makeRentalUnit(['min_nights' => 3]);

    $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Short Stay Guest'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'adults' => 1,
    ])->assertUnprocessable();
});

it('prices a long stay off the weekly/monthly tier instead of the nightly rate', function () {
    $manager = staffWithRole('Manager');
    $unit = makeRentalUnit();

    $weekly = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Weekly Stay Guest'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(1 + 7)->toDateString(),
        'adults' => 1,
    ])->assertCreated();
    expect($weekly->json('booking.rate_basis'))->toBe('weekly')
        ->and($weekly->json('booking.nightly_rate'))->toBe((int) round(60000 / 7));

    $otherUnit = makeRentalUnit();
    $monthly = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Monthly Stay Guest'],
        'channel' => 'direct',
        'unit_id' => $otherUnit->id,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(1 + 28)->toDateString(),
        'adults' => 1,
    ])->assertCreated();
    expect($monthly->json('booking.rate_basis'))->toBe('monthly')
        ->and($monthly->json('booking.nightly_rate'))->toBe((int) round(200000 / 30));
});

it('refunds according to the cancellation policy tiers', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $unit = makeRentalUnit();

    $book = $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Cancel Guest'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDays(10)->toDateString(),
        'check_out' => now()->addDays(13)->toDateString(),
        'adults' => 1,
        'deposit_payment' => ['method' => 'cash', 'amount' => 6000],
    ])->assertCreated();

    $cancel = $this->actingAs($manager)->postJson("/api/apartments/bookings/{$book->json('booking.id')}/cancel", [
        'reason' => 'Change of plans',
    ])->assertOk();

    // 10 days out clears the 7-day/100% tier from SettingsSeeder's default cancellation_rules.
    expect($cancel->json('refund_pct'))->toBe(100)
        ->and($cancel->json('refunded'))->toBe(6000);
});

it('never offers a unit that is reserved or sold, regardless of dates', function () {
    $manager = staffWithRole('Manager');
    $reservedUnit = makeRentalUnit(unitOverrides: [
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::RESERVED),
    ]);
    $soldUnit = makeRentalUnit(unitOverrides: [
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::SOLD),
    ]);

    $checkIn = now()->addDay()->toDateString();
    $checkOut = now()->addDays(3)->toDateString();

    $available = $this->actingAs($manager)
        ->getJson("/api/apartments/bookings/availability?check_in={$checkIn}&check_out={$checkOut}")
        ->assertOk()
        ->json('units');

    $unitIds = collect($available)->pluck('id');
    expect($unitIds)->not->toContain($reservedUnit->id)->not->toContain($soldUnit->id);

    $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Blocked Guest'],
        'channel' => 'direct',
        'unit_id' => $reservedUnit->id,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'adults' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
});
