<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\Package;
use App\Models\Hotel\Room;
use App\Models\Lookup;
use App\Models\Tenant;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\RoomStatus;
use Database\Seeders\HotelRoomsSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
});

it('lets any authenticated staff view rooms and packages', function () {
    $this->seed(HotelRoomsSeeder::class);
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/rooms')->assertOk()->assertJsonCount(13, 'rooms');
    $this->actingAs($housekeeper)->getJson('/api/rooms/packages')->assertOk()->assertJsonCount(4, 'packages');
});

it('blocks a housekeeper from creating a room', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->postJson('/api/rooms', [
        'number' => '199', 'max_occupancy' => 2, 'weekday_rate' => 10000, 'weekend_rate' => 12000,
    ])->assertForbidden();
});

it('lets a manager create a room directly with embedded details (no separate type)', function () {
    $manager = staffWithRole('Manager');

    $roomResponse = $this->actingAs($manager)->postJson('/api/rooms', [
        'number' => '999',
        'name' => 'Deluxe Suite',
        'max_occupancy' => 3,
        'bed_config' => 'King + Sofa',
        'weekday_rate' => 20000,
        'weekend_rate' => 25000,
        'amenities' => ['AC', 'WiFi'],
        'item_checklist' => ['Towels', 'TV remote'],
        'cleaning_checklist' => ['Mop floor', 'Make bed'],
    ])->assertCreated();

    expect($roomResponse->json('room.tenant_id'))->toBe(Tenant::demo()->id)
        ->and($roomResponse->json('room.status.code'))->toBe(RoomStatus::AVAILABLE)
        ->and($roomResponse->json('room.max_occupancy'))->toBe(3)
        ->and($roomResponse->json('room.name'))->toBe('Deluxe Suite');
});

it('rejects a duplicate room number', function () {
    $manager = staffWithRole('Manager');
    $this->seed(HotelRoomsSeeder::class);
    $existing = Room::query()->firstOrFail();

    $this->actingAs($manager)->postJson('/api/rooms', [
        'number' => $existing->number, 'max_occupancy' => 2, 'weekday_rate' => 10000, 'weekend_rate' => 12000,
    ])->assertUnprocessable()->assertJsonValidationErrors('number');
});

it('lets a housekeeper update room status but not create rooms', function () {
    $this->seed(HotelRoomsSeeder::class);
    $housekeeper = staffWithRole('Housekeeper');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $this->actingAs($housekeeper)->postJson('/api/rooms', ['number' => '200', 'max_occupancy' => 2, 'weekday_rate' => 10000, 'weekend_rate' => 12000])
        ->assertForbidden();

    $this->actingAs($housekeeper)->putJson("/api/rooms/{$room->id}/status", ['status' => RoomStatus::MAINTENANCE])
        ->assertOk()
        ->assertJsonPath('room.status.code', RoomStatus::MAINTENANCE);
});

it('blocks marking a dirty room available directly — must go through housekeeping', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::DIRTY)]);

    $this->actingAs($manager)->putJson("/api/rooms/{$room->id}/status", ['status' => RoomStatus::AVAILABLE])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($room->fresh()->status->code)->toBe(RoomStatus::DIRTY);
});

it('blocks marking an occupied room available directly — must check out first', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::OCCUPIED)]);

    $this->actingAs($manager)->putJson("/api/rooms/{$room->id}/status", ['status' => RoomStatus::AVAILABLE])
        ->assertUnprocessable();

    expect($room->fresh()->status->code)->toBe(RoomStatus::OCCUPIED);
});

it('allows other status transitions freely, e.g. maintenance back to dirty', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::MAINTENANCE)]);

    $this->actingAs($manager)->putJson("/api/rooms/{$room->id}/status", ['status' => RoomStatus::DIRTY])
        ->assertOk()
        ->assertJsonPath('room.status.code', RoomStatus::DIRTY);
});

it('adds and removes a seasonal rate for a room (per-room)', function () {
    $manager = staffWithRole('Manager');

    $roomResponse = $this->actingAs($manager)->postJson('/api/rooms', [
        'number' => '500',
        'name' => 'Seasonal Test Room',
        'max_occupancy' => 2,
        'weekday_rate' => 10000,
        'weekend_rate' => 12000,
    ])->assertCreated();

    $roomId = $roomResponse->json('room.id');

    $response = $this->actingAs($manager)->postJson("/api/rooms/{$roomId}/seasonal", [
        'name' => 'Peak', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'rate' => 15000,
    ])->assertCreated();

    $seasonalRateId = $response->json('seasonal_rate.id');

    $this->actingAs($manager)->deleteJson("/api/rooms/seasonal/{$seasonalRateId}")->assertOk();

    // Verify via fresh API fetch that the room has no seasonal rates left
    $roomsResp = $this->actingAs($manager)->getJson('/api/rooms')->assertOk();
    $roomData = collect($roomsResp->json('rooms'))->firstWhere('id', $roomId);
    $rates = $roomData['seasonal_rates'] ?? $roomData['seasonalRates'] ?? [];
    expect($rates)->toBeEmpty();
});

it('lets a manager update a package but blocks other roles', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $chef = staffWithRole('Chef');
    $package = Package::query()->where('code', 'BB')->firstOrFail();

    $this->actingAs($chef)->putJson("/api/rooms/packages/{$package->id}", ['name' => 'Nope'])->assertForbidden();

    $this->actingAs($manager)->putJson("/api/rooms/packages/{$package->id}", [
        'name' => 'Bed & Breakfast Plus',
    ])->assertOk()->assertJsonPath('package.name', 'Bed & Breakfast Plus');
});

it('blocks deleting an occupied room', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();
    $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::OCCUPIED)]);

    $this->actingAs($manager)->deleteJson("/api/rooms/{$room->id}")
        ->assertUnprocessable()->assertJsonValidationErrors('room');

    $this->assertDatabaseHas('rooms', ['id' => $room->id, 'deleted_at' => null]);
});

it('blocks deleting a room with an active or upcoming reservation', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '103')->firstOrFail();
    $guest = Guest::factory()->create();

    $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin',
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();

    $this->actingAs($manager)->deleteJson("/api/rooms/{$room->id}")
        ->assertUnprocessable()->assertJsonValidationErrors('room');
});

it('deletes a vacant room with no reservations or housekeeping tasks', function () {
    $manager = staffWithRole('Manager');
    $roomResponse = $this->actingAs($manager)->postJson('/api/rooms', [
        'number' => '888', 'max_occupancy' => 2, 'weekday_rate' => 10000, 'weekend_rate' => 12000,
    ])->assertCreated();
    $roomId = $roomResponse->json('room.id');

    $this->actingAs($manager)->deleteJson("/api/rooms/{$roomId}")->assertOk();

    $this->assertSoftDeleted('rooms', ['id' => $roomId]);
});
