<?php

use App\Models\Apartment\Sale;
use App\Models\Apartment\Unit;
use App\Models\Apartment\UnitType;
use App\Models\Lookup;
use App\Services\Apartment\ApartmentSalesService;
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

function makeSaleUnit(): Unit
{
    $unitType = UnitType::create([
        'name' => 'E2E Sale Type '.uniqid(),
        'max_occupancy' => 3, 'bedrooms' => 2, 'bathrooms' => 2,
        'min_nights' => 1, 'cleaning_fee' => 0, 'extra_guest_fee' => 0,
    ]);

    return Unit::create([
        'unit_no' => 'S-'.uniqid(),
        'unit_type_id' => $unitType->id,
        'listing_type_id' => Lookup::id(LookupType::APARTMENT_LISTING_TYPE, 'sale'),
        'unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
        'sale_price' => 25000000,
    ]);
}

it('creates an inquiry without touching the unit, then reserving locks it', function () {
    $manager = staffWithRole('Manager');
    $unit = makeSaleUnit();

    $created = $this->actingAs($manager)->postJson('/api/apartments/sales', [
        'new_customer' => ['name' => 'Prospective Buyer'],
        'unit_id' => $unit->id,
        'agreed_price' => 25000000,
    ])->assertCreated();
    $saleId = $created->json('sale.id');

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::AVAILABLE);

    $this->actingAs($manager)->postJson("/api/apartments/sales/{$saleId}/reserve", [
        'deposit_payment' => ['method' => 'bank_transfer', 'amount' => 2500000],
    ])->assertOk()->assertJsonPath('sale.status.code', 'reserved');

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::RESERVED);
});

it('blocks booking or leasing a unit that is reserved for sale', function () {
    $manager = staffWithRole('Manager');
    $unit = makeSaleUnit();

    $sale = $this->actingAs($manager)->postJson('/api/apartments/sales', [
        'new_customer' => ['name' => 'Buyer'],
        'unit_id' => $unit->id,
        'agreed_price' => 25000000,
    ])->assertCreated()->json('sale.id');

    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/reserve", [])->assertOk();

    // A unit listed for SALE was never rentable in the first place (listing_type
    // gate), so bookings/leases against it are already rejected before RESERVED
    // even applies — assert that explicitly rather than assuming it.
    $this->actingAs($manager)->postJson('/api/apartments/bookings', [
        'new_customer' => ['name' => 'Renter'],
        'channel' => 'direct',
        'unit_id' => $unit->id,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
        'adults' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
});

it('refuses to complete a sale before it is paid in full, then completes and marks the unit sold', function () {
    $manager = staffWithRole('Manager');
    $unit = makeSaleUnit();

    $sale = $this->actingAs($manager)->postJson('/api/apartments/sales', [
        'new_customer' => ['name' => 'Full Payer'],
        'unit_id' => $unit->id,
        'agreed_price' => 25000000,
    ])->assertCreated()->json('sale.id');

    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/reserve", [])->assertOk();
    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/sign-agreement")->assertOk()
        ->assertJsonPath('sale.status.code', 'agreement_signed');

    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/complete")
        ->assertUnprocessable()->assertJsonValidationErrors('ledger');

    $ledgerId = $this->actingAs($manager)->getJson("/api/apartments/sales/{$sale}")->json('ledger.id');
    $this->actingAs($manager)->postJson("/api/apartments/ledgers/{$ledgerId}/payment", [
        'method' => 'bank_transfer', 'amount' => 25000000,
    ])->assertCreated();

    $completed = $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/complete")->assertOk();
    expect($completed->json('sale.status.code'))->toBe('completed')
        ->and(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::SOLD);
});

it('forfeits the deposit on cancellation per policy and frees the unit', function () {
    $manager = staffWithRole('Manager');
    $unit = makeSaleUnit();

    $sale = $this->actingAs($manager)->postJson('/api/apartments/sales', [
        'new_customer' => ['name' => 'Cold Feet Buyer'],
        'unit_id' => $unit->id,
        'agreed_price' => 25000000,
    ])->assertCreated()->json('sale.id');

    $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/reserve", [
        'deposit_payment' => ['method' => 'cash', 'amount' => 2500000],
    ])->assertOk();

    // Default apartment.sale_deposit_forfeit_pct is 100 — buyer gets nothing back.
    $cancelled = $this->actingAs($manager)->postJson("/api/apartments/sales/{$sale}/cancel", [
        'reason' => 'Buyer withdrew',
    ])->assertOk();
    expect($cancelled->json('refund_pct'))->toBe(0)->and($cancelled->json('refunded'))->toBe(0);

    expect(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::AVAILABLE);
});

it('auto-releases an expired reservation hold with no signed agreement', function () {
    $manager = staffWithRole('Manager');
    $unit = makeSaleUnit();

    $saleId = $this->actingAs($manager)->postJson('/api/apartments/sales', [
        'new_customer' => ['name' => 'Ghost Buyer'],
        'unit_id' => $unit->id,
        'agreed_price' => 25000000,
    ])->assertCreated()->json('sale.id');

    $this->actingAs($manager)->postJson("/api/apartments/sales/{$saleId}/reserve", [
        'reserved_until' => now()->addDay()->toDateString(),
    ])->assertOk();

    $sale = Sale::find($saleId);
    $sale->update(['reserved_until' => now()->subDay()]);

    $released = app(ApartmentSalesService::class)->releaseExpiredHolds();

    expect($released)->toBe(1)
        ->and($sale->fresh()->status->code)->toBe('cancelled')
        ->and(Unit::find($unit->id)->status->code)->toBe(ApartmentUnitStatus::AVAILABLE);
});
