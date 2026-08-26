<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\LaundryItem;
use App\Models\Hotel\Room;
use Database\Seeders\HotelRoomsSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(HotelRoomsSeeder::class);
});

it('blocks chef and security from laundry entirely', function () {
    $chef = staffWithRole('Chef');
    $security = staffWithRole('Security');

    $this->actingAs($chef)->getJson('/api/laundry/items')->assertForbidden();
    $this->actingAs($security)->getJson('/api/laundry/items')->assertForbidden();
});

it('blocks a housekeeper from managing the laundry price list but allows charging', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->postJson('/api/laundry/items', ['name' => 'Shirt', 'price' => 2000])
        ->assertForbidden();
});

it('lets a manager manage the laundry price list', function () {
    $manager = staffWithRole('Manager');

    $created = $this->actingAs($manager)->postJson('/api/laundry/items', ['name' => 'Shirt', 'price' => 2000])
        ->assertCreated();

    $this->actingAs($manager)->putJson("/api/laundry/items/{$created->json('laundry_item.id')}", ['price' => 2500])
        ->assertOk()
        ->assertJsonPath('laundry_item.price', 2500);
});

it('charges laundry to a checked-in guest folio', function () {
    $manager = staffWithRole('Manager');
    $housekeeper = staffWithRole('Housekeeper');
    $shirt = LaundryItem::create(['name' => 'Shirt', 'price' => 2000]);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();

    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $reservation->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $response = $this->actingAs($housekeeper)->postJson('/api/laundry/charge', [
        'room_id' => $room->id, 'items' => [['laundry_item_id' => $shirt->id, 'qty' => 3]],
    ])->assertCreated();

    expect($response->json('total'))->toBe(6000)
        ->and($response->json('lines'))->toBe(1);

    $this->assertDatabaseHas('folio_lines', ['description' => 'Laundry — Shirt × 3']);
});

it('rejects charging laundry to a room with no checked-in guest', function () {
    $manager = staffWithRole('Manager');
    $shirt = LaundryItem::create(['name' => 'Shirt', 'price' => 2000]);
    $room = Room::query()->where('number', '103')->firstOrFail();

    $this->actingAs($manager)->postJson('/api/laundry/charge', [
        'room_id' => $room->id, 'items' => [['laundry_item_id' => $shirt->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('room_id');
});

it('lists only checked-in rooms for the laundry charge dropdown, without requiring hotel_rooms.access', function () {
    // A laundry-only housekeeper doesn't hold hotel_rooms.access, so the
    // dropdown must be served by /laundry/rooms rather than the full /rooms
    // board — otherwise it 403s and the select silently stays empty.
    $housekeeper = staffWithRole('Housekeeper');
    $room102 = Room::query()->where('number', '102')->firstOrFail();
    $room103 = Room::query()->where('number', '103')->firstOrFail();
    $guest = Guest::factory()->create();

    $reservation = $this->actingAs(staffWithRole('Manager'))->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room102->id]],
    ])->assertCreated();
    $this->actingAs(staffWithRole('Manager'))
        ->postJson("/api/reservations/{$reservation->json('reservation.id')}/check-in", [])
        ->assertOk();

    $response = $this->actingAs($housekeeper)->getJson('/api/laundry/rooms')->assertOk();
    $rooms = collect($response->json('rooms'));

    expect($rooms->pluck('number'))->toContain('102')
        ->and($rooms->pluck('number'))->not->toContain('103');
});
