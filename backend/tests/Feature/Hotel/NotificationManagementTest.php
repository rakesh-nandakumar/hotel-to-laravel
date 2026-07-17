<?php

use App\Models\Branch;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\Notification;
use App\Models\Hotel\Room;
use App\Models\Hotel\Venue;
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

function testVenueForNotifications(string $name = 'Grand Ballroom'): Venue
{
    return Venue::create([
        'name' => $name, 'max_capacity' => 200, 'facilities' => ['Stage'],
        'hourly_rate' => 500000, 'half_day_rate' => 2000000, 'full_day_rate' => 3500000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);
}

it('blocks non-manager roles from notifications entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/notifications')->assertForbidden();
    $this->actingAs($housekeeper)->postJson('/api/notifications/run-scheduled')->assertForbidden();
});

it('only allows a full administrator to send an integration test message', function () {
    $manager = staffWithRole('Manager');
    $admin = fullAdmin();

    $this->actingAs($manager)->postJson('/api/notifications/test', ['channel' => 'sms', 'to' => '0771234567'])
        ->assertForbidden();

    $response = $this->actingAs($admin)->postJson('/api/notifications/test', ['channel' => 'sms', 'to' => '0771234567'])
        ->assertOk();

    expect($response->json('notification.type'))->toBe('INTEGRATION_TEST')
        ->and($response->json('notification.status.code'))->toBe('sent')
        ->and($response->json('notification.error'))->toContain('SIMULATED');
});

it('sends a pre-arrival reminder for a reservation checking in tomorrow, without duplicating on a second sweep', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();
    $checkIn = now()->addDay()->toDateString();
    $checkOut = now()->addDays(3)->toDateString();

    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'new_guest' => ['name' => 'Alice Perera', 'phone' => '0771234567', 'email' => 'alice@example.com'],
        'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 2, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated()->json('reservation');

    $first = $this->actingAs($manager)->postJson('/api/notifications/run-scheduled')->assertOk();
    expect($first->json('sent'))->toBeGreaterThanOrEqual(1);
    expect(Notification::where('type', 'PRE_ARRIVAL')->where('ref_id', $reservation['id'])->exists())->toBeTrue();

    $countAfterFirst = Notification::where('type', 'PRE_ARRIVAL')->count();

    $this->actingAs($manager)->postJson('/api/notifications/run-scheduled')->assertOk();
    expect(Notification::where('type', 'PRE_ARRIVAL')->count())->toBe($countAfterFirst);
});

it('sends venue pre-event and payment reminders, skipping bookings already paid in full', function () {
    $manager = staffWithRole('Manager');
    $venue = testVenueForNotifications();

    $tomorrowBooking = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Tomorrow Event',
        'client_email' => 'tomorrow@example.com', 'client_phone' => '0711111111',
        'date' => now()->addDay()->toDateString(), 'duration_type' => 'full_day',
        'guest_count' => 100, 'confirm' => true,
    ])->assertCreated()->json('booking');

    $unpaidBooking = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Unpaid Event',
        'client_email' => 'unpaid@example.com', 'client_phone' => '0722222222',
        'date' => now()->addDays(7)->toDateString(), 'duration_type' => 'full_day',
        'guest_count' => 100, 'confirm' => true,
    ])->assertCreated()->json('booking');

    $secondVenue = testVenueForNotifications('Skyline Terrace');
    $paidBooking = $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $secondVenue->id, 'client_name' => 'Paid Event',
        'client_email' => 'paid@example.com', 'client_phone' => '0733333333',
        'date' => now()->addDays(7)->toDateString(), 'duration_type' => 'full_day',
        'guest_count' => 100, 'confirm' => true,
    ])->assertCreated()->json('booking');

    $paidFolio = $this->actingAs($manager)->getJson("/api/folios/{$paidBooking['folio']['id']}")->json('folio');
    $this->actingAs($manager)->postJson("/api/folios/{$paidBooking['folio']['id']}/payments", [
        'method' => 'cash', 'amount' => $paidFolio['total'],
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/notifications/run-scheduled')->assertOk();

    expect(Notification::where('type', 'VENUE_PRE_EVENT')->where('ref_id', $tomorrowBooking['id'])->exists())->toBeTrue()
        ->and(Notification::where('type', 'VENUE_PAYMENT_REMINDER')->where('ref_id', $unpaidBooking['id'])->exists())->toBeTrue()
        ->and(Notification::where('type', 'VENUE_PAYMENT_REMINDER')->where('ref_id', $paidBooking['id'])->exists())->toBeFalse();
});

it('sends one food-expiry digest per day, deduped on a second sweep the same day', function () {
    $manager = staffWithRole('Manager');
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    IngredientBatch::create([
        'ingredient_id' => $rice->id, 'qty' => 2000, 'initial_qty' => 2000,
        'expiry_date' => now()->addDay()->toDateString(),
    ]);

    $this->actingAs($manager)->postJson('/api/notifications/run-scheduled')->assertOk();
    expect(Notification::where('type', 'FOOD_EXPIRY')->count())->toBe(1);

    $this->actingAs($manager)->postJson('/api/notifications/run-scheduled')->assertOk();
    expect(Notification::where('type', 'FOOD_EXPIRY')->count())->toBe(1);
});

it('lets a manager list notifications', function () {
    $manager = staffWithRole('Manager');
    $admin = fullAdmin();
    $this->actingAs($admin)->postJson('/api/notifications/test', ['channel' => 'sms', 'to' => '0771234567'])->assertOk();

    $response = $this->actingAs($manager)->getJson('/api/notifications')->assertOk();
    expect($response->json('notifications'))->toHaveCount(1);
});
