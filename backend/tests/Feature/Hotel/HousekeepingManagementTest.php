<?php

use App\Models\Hotel\Room;
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

it('blocks chef and security from housekeeping entirely', function () {
    $chef = staffWithRole('Chef');
    $security = staffWithRole('Security');

    $this->actingAs($chef)->getJson('/api/housekeeping/tasks')->assertForbidden();
    $this->actingAs($security)->getJson('/api/housekeeping/tasks')->assertForbidden();
});

it('blocks a housekeeper from creating an ad-hoc task', function () {
    $housekeeper = staffWithRole('Housekeeper');
    $room = Room::query()->first();

    $this->actingAs($housekeeper)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->assertForbidden();
});

it('lets a manager create an ad-hoc task, dirtying an available room', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();

    $response = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', [
        'room_id' => $room->id, 'notes' => 'Guest requested extra cleaning',
    ])->assertCreated();

    expect($response->json('task.checklist'))->toHaveCount(12)
        ->and($room->fresh()->status->code)->toBe(RoomStatus::DIRTY);
});

it('includes the task status on the list endpoint, not just the room status', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->assertCreated();

    $response = $this->actingAs($manager)->getJson('/api/housekeeping/tasks')->assertOk();

    expect($response->json('tasks.0.status.code'))->not->toBeNull();
});

it('lets a manager assign a task, which moves it to in progress', function () {
    $manager = staffWithRole('Manager');
    $housekeeper = staffWithRole('Housekeeper');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $task = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->json('task');

    $response = $this->actingAs($manager)->putJson("/api/housekeeping/tasks/{$task['id']}/assign", [
        'assigned_to_id' => $housekeeper->id,
    ])->assertOk();

    expect($response->json('task.status.code'))->toBe('in_progress')
        ->and($response->json('task.assigned_to_id'))->toBe($housekeeper->id);
});

it('lets a housekeeper update the checklist and complete it, freeing the room', function () {
    $manager = staffWithRole('Manager');
    $housekeeper = staffWithRole('Housekeeper');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $task = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->json('task');

    $checklist = collect($task['checklist'])->map(fn ($c) => ['item' => $c['item'], 'done' => true])->values()->all();

    $this->actingAs($housekeeper)->putJson("/api/housekeeping/tasks/{$task['id']}/checklist", ['checklist' => $checklist])
        ->assertOk()
        ->assertJsonPath('task.status.code', 'in_progress');

    $response = $this->actingAs($housekeeper)->postJson("/api/housekeeping/tasks/{$task['id']}/complete", ['checklist' => $checklist])
        ->assertOk();

    expect($response->json('room_status'))->toBe(RoomStatus::AVAILABLE)
        ->and($room->fresh()->status->code)->toBe(RoomStatus::AVAILABLE);
});

it('rejects completion with an incomplete checklist', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $task = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->json('task');

    $this->actingAs($manager)->postJson("/api/housekeeping/tasks/{$task['id']}/complete", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('checklist');

    expect($room->fresh()->status->code)->toBe(RoomStatus::DIRTY);
});

it('does not flip an occupied room to available even after checklist completion', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $task = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->json('task');

    // Room somehow became occupied mid-cleaning (e.g. a walk-in check-in) — completion must not clobber that.
    $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::OCCUPIED)]);

    $checklist = collect($task['checklist'])->map(fn ($c) => ['item' => $c['item'], 'done' => true])->values()->all();

    $response = $this->actingAs($manager)->postJson("/api/housekeeping/tasks/{$task['id']}/complete", ['checklist' => $checklist])
        ->assertOk();

    expect($response->json('room_status'))->toBe(RoomStatus::OCCUPIED)
        ->and($room->fresh()->status->code)->toBe(RoomStatus::OCCUPIED);
});

it('rejects re-completing an already-completed task', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->statusCode(RoomStatus::AVAILABLE)->firstOrFail();
    $task = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->json('task');
    $checklist = collect($task['checklist'])->map(fn ($c) => ['item' => $c['item'], 'done' => true])->values()->all();

    $this->actingAs($manager)->postJson("/api/housekeeping/tasks/{$task['id']}/complete", ['checklist' => $checklist])->assertOk();

    $this->actingAs($manager)->postJson("/api/housekeeping/tasks/{$task['id']}/complete", ['checklist' => $checklist])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('task');
});
