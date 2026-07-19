<?php

use App\Models\Branch;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Notification;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Venue;
use App\Models\Hotel\VenueBooking;
use Database\Seeders\BranchSeeder;
use Database\Seeders\HotelRoomsSeeder;
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
    $this->seed(HotelRoomsSeeder::class);
});

it('serves branding with no authentication required', function () {
    $response = $this->getJson('/api/public/branding')->assertOk();

    expect($response->json('name'))->toBe('Mount View Hotel')
        ->and($response->json('check_in_time'))->toBe('14:00')
        ->and($response->json('theme_primary'))->toBe('#0462d3')
        ->and($response->json('theme_secondary'))->toBe('#3783f0')
        ->and($response->json('theme_sidebar'))->toBe('#0c182a');
});

it('lists only active venues publicly', function () {
    Venue::create([
        'name' => 'Grand Ballroom', 'max_capacity' => 200, 'active' => true,
        'hourly_rate' => 500000, 'half_day_rate' => 2000000, 'full_day_rate' => 3500000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);
    Venue::create([
        'name' => 'Retired Hall', 'max_capacity' => 50, 'active' => false,
        'hourly_rate' => 100000, 'half_day_rate' => 400000, 'full_day_rate' => 700000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);

    $response = $this->getJson('/api/public/venues')->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('Grand Ballroom');
});

it('lets a guest submit pre-check-in for a confirmed booking, pre-filling their profile', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create(['id_number' => null, 'phone' => '0770000000']);

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $code = $created->json('reservation.code');

    $response = $this->postJson('/api/public/pre-checkin', [
        'code' => strtolower($code), 'id_number' => '912345678V', 'full_name' => 'Jane Guest',
        'phone' => '0719999999', 'nationality' => 'British', 'eta' => '15:00',
    ])->assertOk();

    expect($response->json('ok'))->toBeTrue();
    expect($guest->fresh()->id_number)->toBe('912345678V')
        ->and($guest->fresh()->phone)->toBe('0719999999')
        ->and($guest->fresh()->nationality)->toBe('British');

    $reservation = Reservation::find($created->json('reservation.id'));
    expect($reservation->pre_check_in['full_name'])->toBe('Jane Guest')
        ->and($reservation->pre_check_in)->toHaveKey('submitted_at');
});

it('rejects pre-check-in for an unknown or not-yet-awaiting booking code', function () {
    $this->postJson('/api/public/pre-checkin', [
        'code' => 'NOPE-0000', 'id_number' => '912345678V', 'full_name' => 'Jane Guest',
    ])->assertNotFound();
});

it('records a public venue inquiry as an INQUIRY booking and notifies the hotel', function () {
    $venue = Venue::create([
        'name' => 'Garden Pavilion', 'max_capacity' => 100, 'active' => true,
        'hourly_rate' => 300000, 'half_day_rate' => 1200000, 'full_day_rate' => 2000000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);

    $response = $this->postJson('/api/public/venue-inquiry', [
        'venue_id' => $venue->id, 'client_name' => 'Perera Wedding', 'client_phone' => '0771112222',
        'date' => '2026-09-15', 'guest_count' => 80,
    ])->assertCreated();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('reference'))->toStartWith('VNB-');

    $booking = VenueBooking::where('code', $response->json('reference'))->first();
    expect($booking->status->code)->toBe('inquiry')
        ->and($booking->folio)->not->toBeNull();

    expect(Notification::where('type', 'VENUE_INQUIRY_RECEIVED')->where('ref_id', $booking->id)->exists())->toBeTrue();
});

it('rejects a public venue inquiry exceeding capacity', function () {
    $venue = Venue::create([
        'name' => 'Small Room', 'max_capacity' => 20, 'active' => true,
        'hourly_rate' => 100000, 'half_day_rate' => 400000, 'full_day_rate' => 700000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);

    $this->postJson('/api/public/venue-inquiry', [
        'venue_id' => $venue->id, 'client_name' => 'Big Party', 'client_phone' => '0771112222',
        'date' => '2026-09-15', 'guest_count' => 500,
    ])->assertUnprocessable()->assertJsonValidationErrors('guest_count');
});
