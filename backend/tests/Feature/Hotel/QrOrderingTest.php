<?php

use App\Models\Hotel\DiningTable;
use App\Models\Hotel\Guest;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Order;
use App\Models\Hotel\QrOrderingPoint;
use App\Models\Hotel\Room;
use App\Models\Lookup;
use App\Services\Settings;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\TableStatus;
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
});

function qrOrderingMenuItem(string $name = 'Fried Rice', int $price = 100000): MenuItem
{
    $category = MenuCategory::create(['name' => $name.' Category '.uniqid()]);

    return MenuItem::create(['name' => $name, 'menu_category_id' => $category->id, 'price' => $price]);
}

function qrOrderingTable(string $status = TableStatus::FREE): DiningTable
{
    return DiningTable::create([
        'table_no' => 'T'.uniqid(),
        'capacity' => 4,
        'table_status_id' => Lookup::id(LookupType::TABLE_STATUS, $status),
    ]);
}

function checkedInRoom(): Room
{
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create(['name' => 'Priya Fernando']);

    $reservation = test()->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    test()->actingAs($manager)->postJson("/api/reservations/{$reservation->json('reservation.id')}/check-in", [])->assertOk();

    return $room;
}

it('404s for an unknown token', function () {
    $this->getJson('/api/public/qr/does-not-exist/menu')->assertNotFound();
});

it('blocks ordering for a room with no checked-in guest', function () {
    $this->seed(HotelRoomsSeeder::class);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $point = QrOrderingPoint::create(['room_id' => $room->id, 'token' => 'room-token']);

    $this->getJson("/api/public/qr/{$point->token}/menu")->assertUnprocessable();
});

it('lets a checked-in room guest place a self-order that charges to their room, unattributed to staff', function () {
    $this->seed(HotelRoomsSeeder::class);
    $room = checkedInRoom();
    $point = QrOrderingPoint::create(['room_id' => $room->id, 'token' => 'room-token']);
    $item = qrOrderingMenuItem();

    $menu = $this->getJson("/api/public/qr/{$point->token}/menu")->assertOk();
    expect($menu->json('point.type'))->toBe('room')
        ->and($menu->json('categories.0.items.0.name'))->toBe('Fried Rice');

    $response = $this->postJson("/api/public/qr/{$point->token}/order", [
        'items' => [['menu_item_id' => $item->id, 'qty' => 2]],
    ])->assertCreated();

    $order = Order::query()->findOrFail($response->json('order.id'));
    expect($order->room_id)->toBe($room->id)
        ->and($order->staff_id)->toBeNull()
        ->and($order->placed_via_qr)->toBeTrue()
        ->and($order->customer_name)->toBe('Priya Fernando')
        ->and($order->type->code)->toBe('room_guest');

    // Chargeable to the room by staff exactly like any other order.
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $this->actingAs($manager)->postJson("/api/orders/{$order->id}/charge-to-room")
        ->assertOk()
        ->assertJsonPath('order.status.code', 'charged_to_room');
});

it('opens a new order on a free table, then appends to it (not a duplicate order) once occupied', function () {
    $table = qrOrderingTable(TableStatus::FREE);
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'table-token']);
    $item = qrOrderingMenuItem();

    $first = $this->postJson("/api/public/qr/{$point->token}/order", [
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
        'customer_name' => 'Table 1 guest',
    ])->assertCreated();

    expect(Order::query()->count())->toBe(1)
        ->and($table->fresh()->status->code)->toBe(TableStatus::OCCUPIED);

    $second = $this->postJson("/api/public/qr/{$point->token}/order", [
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    expect(Order::query()->count())->toBe(1)
        ->and($second->json('order.id'))->toBe($first->json('order.id'))
        ->and($second->json('order.items'))->toHaveCount(2);
});

it('blocks ordering on a reserved or cleaning table', function () {
    $table = qrOrderingTable(TableStatus::RESERVED);
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'reserved-token']);
    $item = qrOrderingMenuItem();

    $this->postJson("/api/public/qr/{$point->token}/order", [
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
        'customer_name' => 'Someone',
    ])->assertUnprocessable();
});

it('requires a name for table orders when the setting demands it, but never for room orders', function () {
    Settings::set('qr_ordering.collect_customer_name', true);
    $table = qrOrderingTable();
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'table-token']);
    $item = qrOrderingMenuItem();

    $this->postJson("/api/public/qr/{$point->token}/order", [
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('customer_name');
});

it('blocks the whole feature via the global setting even when a point is enabled', function () {
    Settings::set('qr_ordering.enabled', false);
    $table = qrOrderingTable();
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'table-token']);

    $this->getJson("/api/public/qr/{$point->token}/menu")->assertUnprocessable();
});

it('blocks a disabled point even when the global setting is on', function () {
    $table = qrOrderingTable();
    $point = QrOrderingPoint::create(['dining_table_id' => $table->id, 'token' => 'table-token', 'enabled' => false]);

    $this->getJson("/api/public/qr/{$point->token}/menu")->assertUnprocessable();
});

it('refuses to show an order that belongs to a different table\'s QR link', function () {
    $tableA = qrOrderingTable();
    $tableB = qrOrderingTable();
    $pointA = QrOrderingPoint::create(['dining_table_id' => $tableA->id, 'token' => 'token-a']);
    $pointB = QrOrderingPoint::create(['dining_table_id' => $tableB->id, 'token' => 'token-b']);
    $item = qrOrderingMenuItem();

    $order = $this->postJson("/api/public/qr/{$pointA->token}/order", [
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
        'customer_name' => 'Guest A',
    ])->assertCreated();

    $this->getJson("/api/public/qr/{$pointB->token}/orders/{$order->json('order.id')}")->assertNotFound();
    $this->getJson("/api/public/qr/{$pointA->token}/orders/{$order->json('order.id')}")->assertOk();
});
