<?php

use App\Models\Branch;
use App\Models\Hotel\Room;
use App\Models\Hotel\Venue;
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
    $this->seed(HotelRoomsSeeder::class);
});

it('lets any operational staff log a maintenance issue', function () {
    $security = staffWithRole('Security');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $this->actingAs($security)->postJson('/api/maintenance', [
        'room_id' => $room->id, 'description' => 'AC not cooling',
    ])->assertCreated();
});

it('takes an available room out of service, but never an occupied one', function () {
    $manager = staffWithRole('Manager');
    $availableRoom = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $occupiedRoom = Room::query()->statusCode(RoomStatus::AVAILABLE)->skip(1)->firstOrFail();
    $occupiedRoom->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::OCCUPIED)]);

    $this->actingAs($manager)->postJson('/api/maintenance', [
        'room_id' => $availableRoom->id, 'description' => 'Broken window latch', 'take_room_out_of_service' => true,
    ])->assertCreated();
    expect($availableRoom->fresh()->status->code)->toBe(RoomStatus::MAINTENANCE);

    $this->actingAs($manager)->postJson('/api/maintenance', [
        'room_id' => $occupiedRoom->id, 'description' => 'Broken window latch', 'take_room_out_of_service' => true,
    ])->assertCreated();
    expect($occupiedRoom->fresh()->status->code)->toBe(RoomStatus::OCCUPIED);
});

it('resolving an issue returns the room to DIRTY (never straight to AVAILABLE) and creates a cleaning task', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $issue = $this->actingAs($manager)->postJson('/api/maintenance', [
        'room_id' => $room->id, 'description' => 'Leaking tap', 'take_room_out_of_service' => true,
    ])->json('issue');
    expect($room->fresh()->status->code)->toBe(RoomStatus::MAINTENANCE);

    $response = $this->actingAs($manager)->putJson("/api/maintenance/{$issue['id']}", [
        'status' => 'resolved', 'resolution_notes' => 'Tap replaced', 'return_room_to_service' => true,
    ])->assertOk();

    expect($response->json('issue.status.code'))->toBe('resolved')
        ->and($room->fresh()->status->code)->toBe(RoomStatus::DIRTY);

    $this->assertDatabaseHas('housekeeping_tasks', ['room_id' => $room->id]);
});

it('does not touch room status when resolving without return_room_to_service', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $issue = $this->actingAs($manager)->postJson('/api/maintenance', [
        'room_id' => $room->id, 'description' => 'Broken remote', 'take_room_out_of_service' => true,
    ])->json('issue');

    $this->actingAs($manager)->putJson("/api/maintenance/{$issue['id']}", ['status' => 'resolved'])->assertOk();

    expect($room->fresh()->status->code)->toBe(RoomStatus::MAINTENANCE);
});

it('logs a maintenance issue against a venue instead of a room', function () {
    $manager = staffWithRole('Manager');
    $venue = Venue::create([
        'name' => 'Grand Ballroom', 'max_capacity' => 200, 'facilities' => ['Stage'],
        'hourly_rate' => 500000, 'half_day_rate' => 2000000, 'full_day_rate' => 3500000,
        'branch_id' => Branch::query()->active()->firstOrFail()->id,
    ]);

    $response = $this->actingAs($manager)->postJson('/api/maintenance', [
        'venue_id' => $venue->id, 'description' => 'Projector not working',
    ])->assertCreated();

    expect($response->json('issue.venue.name'))->toBe('Grand Ballroom')
        ->and($response->json('issue.room'))->toBeNull();
});

it('rejects a maintenance issue with neither a room nor a venue', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/maintenance', [
        'description' => 'Orphaned issue',
    ])->assertUnprocessable();
});

it('lists only open issues by default, and all issues with the all flag', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $issue = $this->actingAs($manager)->postJson('/api/maintenance', [
        'room_id' => $room->id, 'description' => 'Squeaky door',
    ])->json('issue');
    $this->actingAs($manager)->putJson("/api/maintenance/{$issue['id']}", ['status' => 'resolved'])->assertOk();

    $this->actingAs($manager)->getJson('/api/maintenance')->assertOk()->assertJsonCount(0, 'issues');
    $this->actingAs($manager)->getJson('/api/maintenance?all=1')->assertOk()->assertJsonCount(1, 'issues');
});
