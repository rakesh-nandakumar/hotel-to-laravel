<?php

use App\Models\Branch;
use App\Models\Hotel\Package;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\RoomStatus;
use Database\Seeders\BranchSeeder;
use Database\Seeders\HotelRoomsSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(BranchSeeder::class);
});

it('lets any authenticated staff view rooms, room types, and packages', function () {
    $this->seed(HotelRoomsSeeder::class);
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/rooms')->assertOk()->assertJsonCount(13, 'rooms');
    $this->actingAs($housekeeper)->getJson('/api/rooms/types')->assertOk()->assertJsonCount(5, 'room_types');
    $this->actingAs($housekeeper)->getJson('/api/rooms/packages')->assertOk()->assertJsonCount(4, 'packages');
});

it('blocks a housekeeper from creating a room type', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->postJson('/api/rooms/types', [
        'name' => 'New Type', 'max_occupancy' => 2, 'weekday_rate' => 10000, 'weekend_rate' => 12000,
    ])->assertForbidden();
});

it('lets a manager create a room type and a room under it', function () {
    $branch = Branch::query()->active()->firstOrFail();
    $manager = staffWithRole('Manager');

    $typeResponse = $this->actingAs($manager)->postJson('/api/rooms/types', [
        'name' => 'Deluxe Suite',
        'max_occupancy' => 3,
        'weekday_rate' => 20000,
        'weekend_rate' => 25000,
    ])->assertCreated();

    $roomTypeId = $typeResponse->json('room_type.id');

    $roomResponse = $this->actingAs($manager)->postJson('/api/rooms', [
        'number' => '999',
        'room_type_id' => $roomTypeId,
    ])->assertCreated();

    expect($roomResponse->json('room.branch_id'))->toBe($branch->id)
        ->and($roomResponse->json('room.status.code'))->toBe(RoomStatus::AVAILABLE);
});

it('rejects a duplicate room type name', function () {
    $manager = staffWithRole('Manager');
    RoomType::create(['name' => 'Existing Type', 'weekday_rate' => 1, 'weekend_rate' => 1]);

    $this->actingAs($manager)->postJson('/api/rooms/types', [
        'name' => 'Existing Type', 'max_occupancy' => 2, 'weekday_rate' => 10000, 'weekend_rate' => 12000,
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('lets a housekeeper update room status but not create rooms', function () {
    $this->seed(HotelRoomsSeeder::class);
    $housekeeper = staffWithRole('Housekeeper');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $this->actingAs($housekeeper)->postJson('/api/rooms', ['number' => '200', 'room_type_id' => $room->room_type_id])
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

it('adds and removes a seasonal rate for a room type', function () {
    $manager = staffWithRole('Manager');
    $roomType = RoomType::create(['name' => 'Seasonal Test', 'weekday_rate' => 10000, 'weekend_rate' => 12000]);

    $response = $this->actingAs($manager)->postJson("/api/rooms/types/{$roomType->id}/seasonal", [
        'name' => 'Peak', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'rate' => 15000,
    ])->assertCreated();

    $seasonalRateId = $response->json('seasonal_rate.id');

    $this->actingAs($manager)->deleteJson("/api/rooms/seasonal/{$seasonalRateId}")->assertOk();

    expect($roomType->seasonalRates()->count())->toBe(0);
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
