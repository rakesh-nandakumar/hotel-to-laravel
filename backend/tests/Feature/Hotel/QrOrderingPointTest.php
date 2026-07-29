<?php

use App\Models\Hotel\DiningTable;
use App\Models\Hotel\QrOrderingPoint;
use App\Models\Hotel\Room;
use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\TableStatus;
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
    $this->seed(SettingsSeeder::class);
    $this->seed(BranchSeeder::class);
});

function qrTable(string $tableNo = 'T1'): DiningTable
{
    return DiningTable::create([
        'table_no' => $tableNo,
        'capacity' => 4,
        'table_status_id' => Lookup::id(LookupType::TABLE_STATUS, TableStatus::FREE),
    ]);
}

it('blocks staff without the permission from managing QR ordering points', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/qr-ordering')->assertForbidden();
});

it('lists every room and table, decorated with their QR point when one exists', function () {
    $admin = fullAdmin();
    $this->seed(HotelRoomsSeeder::class);
    $table = qrTable();

    $response = $this->actingAs($admin)->getJson('/api/qr-ordering')->assertOk();

    expect($response->json('rooms'))->not->toBeEmpty()
        ->and($response->json('tables'))->toHaveCount(1)
        ->and($response->json('tables.0.qr'))->toBeNull();

    QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'abc123']);

    $response = $this->actingAs($admin)->getJson('/api/qr-ordering')->assertOk();
    expect($response->json('tables.0.qr.token'))->toBe('abc123')
        ->and($response->json('tables.0.qr.enabled'))->toBeTrue()
        ->and($response->json('tables.0.qr.url'))->toContain('/order/abc123');
});

it('generates a QR point for a table, rejecting a duplicate and a room+table combo', function () {
    $admin = fullAdmin();
    $table = qrTable();

    $created = $this->actingAs($admin)->postJson('/api/qr-ordering', ['dining_table_id' => $table->id])
        ->assertCreated();
    expect($created->json('qr_ordering_point.token'))->toHaveLength(32);

    $this->actingAs($admin)->postJson('/api/qr-ordering', ['dining_table_id' => $table->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dining_table_id');

    $this->actingAs($admin)->postJson('/api/qr-ordering', ['dining_table_id' => $table->id, 'room_id' => 1])
        ->assertUnprocessable();
});

it('regenerates a token, invalidating the old one, and toggles enabled', function () {
    $admin = fullAdmin();
    $table = qrTable();
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'original-token']);

    $regenerated = $this->actingAs($admin)->postJson("/api/qr-ordering/{$point->id}/regenerate")->assertOk();
    expect($regenerated->json('qr_ordering_point.token'))->not->toBe('original-token');

    $disabled = $this->actingAs($admin)->putJson("/api/qr-ordering/{$point->id}", ['enabled' => false])->assertOk();
    expect($disabled->json('qr_ordering_point.enabled'))->toBeFalse();
});

it('streams a scannable QR image for a point', function () {
    $admin = fullAdmin();
    $table = qrTable();
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'imgtoken']);

    $this->actingAs($admin)->get("/api/qr-ordering/{$point->id}/image")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
});

it('cascades: deleting the underlying room removes its QR point', function () {
    $this->seed(HotelRoomsSeeder::class);
    $room = Room::query()->first();
    $point = QrOrderingPoint::create(['room_id' => $room->id, 'token' => 'roomtoken']);

    $room->forceDelete();

    expect(QrOrderingPoint::find($point->id))->toBeNull();
});
