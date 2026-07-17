<?php

use App\Models\AuditLog;
use App\Models\Hotel\Room;
use App\Services\AuditLog as AuditLogService;
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

it('records the requesting user agent and route on every audit entry', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)
        ->withHeader('User-Agent', 'PestTestAgent/1.0')
        ->getJson('/api/rooms');

    $room = Room::query()->firstOrFail();
    AuditLogService::record('room.updated', $room, ['number' => $room->number], $manager->id);

    $log = AuditLog::where('action', 'room.updated')->where('actor_id', $manager->id)->firstOrFail();
    expect($log->user_agent)->not->toBeNull()
        ->and($log->route)->toStartWith('GET ');
});

it('exposes the actor role and available entities, and honours page_size', function () {
    $admin = fullAdmin();
    $manager = staffWithRole('Manager');
    $room = Room::query()->firstOrFail();

    AuditLogService::record('room.updated', $room, ['number' => $room->number], $manager->id);

    $response = $this->actingAs($admin)->getJson('/api/audit-logs?page_size=1')->assertOk();

    expect($response->json('logs.per_page'))->toBe(1)
        ->and($response->json('logs.data.0.actor.roles.0.name'))->toBe('Manager')
        ->and($response->json('availableEntities'))->toContain(['value' => Room::class, 'label' => 'Room']);
});

it('filters the audit log list by entity (subject_type)', function () {
    $admin = fullAdmin();
    $manager = staffWithRole('Manager');
    $room = Room::query()->firstOrFail();

    AuditLogService::record('room.updated', $room, ['number' => $room->number], $manager->id);

    $response = $this->actingAs($admin)->getJson('/api/audit-logs?entity='.urlencode(Room::class))->assertOk();

    expect($response->json('logs.data'))->not->toBeEmpty();
    foreach ($response->json('logs.data') as $entry) {
        expect($entry['subject_type'])->toBe(Room::class);
    }
});
