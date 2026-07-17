<?php

use App\Models\Branch;
use App\Models\Hotel\Venue;
use Database\Seeders\BranchSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(BranchSeeder::class);
    $this->seed(SettingsSeeder::class);
});

function testVenue(): Venue
{
    return Venue::create([
        'name' => 'Grand Ballroom', 'max_capacity' => 200, 'facilities' => ['Stage', 'Sound System'],
        'hourly_rate' => 500000, 'half_day_rate' => 2000000, 'full_day_rate' => 3500000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);
}

it('blocks non-manager roles from venues entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/venues')->assertForbidden();
});

it('creates a venue booking as an inquiry, computing the deposit from the flat-day rate', function () {
    $manager = staffWithRole('Manager');
    $venue = testVenue();

    $response = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Silva Wedding', 'date' => '2026-09-01',
        'duration_type' => 'full_day', 'guest_count' => 150,
    ])->assertCreated();

    // rental 3,500,000, deposit 25% = 875,000
    expect($response->json('booking.status.code'))->toBe('inquiry')
        ->and($response->json('booking.deposit_due'))->toBe(875000);
});

it('rejects a booking exceeding venue capacity', function () {
    $manager = staffWithRole('Manager');
    $venue = testVenue();

    $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Big Event', 'date' => '2026-09-01',
        'duration_type' => 'full_day', 'guest_count' => 500,
    ])->assertUnprocessable()->assertJsonValidationErrors('guest_count');
});

it('blocks confirming a second booking on a date already confirmed for that venue', function () {
    $manager = staffWithRole('Manager');
    $venue = testVenue();

    $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'First Event', 'date' => '2026-09-01',
        'duration_type' => 'full_day', 'confirm' => true,
    ])->assertCreated();

    // A second INQUIRY on the same date is allowed (only confirming clashes)...
    $second = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Second Event', 'date' => '2026-09-01',
        'duration_type' => 'full_day',
    ])->assertCreated();

    // ...but confirming it must fail.
    $this->actingAs($manager)->postJson("/api/venues/bookings/{$second->json('booking.id')}/confirm", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

it('completes a booking once the folio balance is zero, assigning a VNU invoice number', function () {
    $manager = staffWithRole('Manager');
    $venue = testVenue();

    $created = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Paid Event', 'date' => '2026-09-01', 'duration_type' => 'full_day',
    ])->json('booking');

    $this->actingAs($manager)->postJson("/api/venues/bookings/{$created['id']}/complete", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('balance');

    $this->actingAs($manager)->postJson("/api/folios/{$created['folio']['id']}/payments", [
        'method' => 'cash', 'amount' => 3500000,
    ])->assertCreated();

    $response = $this->actingAs($manager)->postJson("/api/venues/bookings/{$created['id']}/complete", [])->assertOk();

    expect($response->json('invoice_no'))->toBe('VNU-2026-0001');
});

it('cancels a booking more than 7 days out with a full refund, and within the window with none', function () {
    $manager = staffWithRole('Manager');
    $venue = testVenue();

    $farOut = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Far Event', 'date' => now()->addDays(10)->toDateString(), 'duration_type' => 'hourly', 'hours' => 4,
    ])->json('booking');
    $this->actingAs($manager)->postJson("/api/folios/{$farOut['folio']['id']}/payments", ['method' => 'cash', 'amount' => 500000])->assertCreated();

    $response = $this->actingAs($manager)->postJson("/api/venues/bookings/{$farOut['id']}/cancel", ['reason' => 'Change of plans'])->assertOk();
    expect($response->json('refunded'))->toBe(500000);

    $soon = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Soon Event', 'date' => now()->addDays(2)->toDateString(), 'duration_type' => 'hourly', 'hours' => 4,
    ])->json('booking');
    $this->actingAs($manager)->postJson("/api/folios/{$soon['folio']['id']}/payments", ['method' => 'cash', 'amount' => 500000])->assertCreated();

    $response = $this->actingAs($manager)->postJson("/api/venues/bookings/{$soon['id']}/cancel", ['reason' => 'Too late'])->assertOk();
    expect($response->json('refunded'))->toBe(0);
});
