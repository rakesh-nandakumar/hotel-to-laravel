<?php

use App\Models\Hotel\DiningTable;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\MenuItemModifier;
use App\Models\Hotel\MenuItemModifierGroup;
use App\Models\Lookup;
use App\Services\Settings;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\TableStatus;
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

function restaurantMenuItem(string $name = 'Fried Rice', int $price = 100000): MenuItem
{
    $category = MenuCategory::create(['name' => $name.' Category '.uniqid()]);

    return MenuItem::create(['name' => $name, 'menu_category_id' => $category->id, 'price' => $price]);
}

function freeDiningTable(string $tableNo = 'T1'): DiningTable
{
    return DiningTable::create([
        'table_no' => $tableNo,
        'capacity' => 4,
        'table_status_id' => Lookup::id(LookupType::TABLE_STATUS, TableStatus::FREE),
    ]);
}

it('opens a dine-in order against a free table, occupying it, and frees it to Cleaning on settle', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $item = restaurantMenuItem();
    $table = freeDiningTable();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'dining_table_id' => $table->id,
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    $this->actingAs($manager)->getJson('/api/dining-tables')
        ->assertJsonPath('dining_tables.0.status.code', 'occupied');

    $orderId = $created->json('order.id');
    $total = $created->json('order.total');
    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $total]],
    ])->assertOk();

    expect(DiningTable::find($table->id)->fresh()->status->code)->toBe('cleaning');
});

it('rejects opening a new order on an already-occupied table', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantMenuItem();
    $table = freeDiningTable();

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'dining_table_id' => $table->id,
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'dining_table_id' => $table->id,
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('dining_table_id');
});

it('cannot directly set a table to Occupied — only opening an order can', function () {
    $manager = staffWithRole('Manager');
    $table = freeDiningTable();

    $this->actingAs($manager)->putJson("/api/dining-tables/{$table->id}/status", ['status' => 'occupied'])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('creates a delivery order requiring an address, waives service charge, and dispatches a rider', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantMenuItem();
    Settings::set('billing.service_charge_pct', 10);
    Settings::set('billing.vat_pct', 10);

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'delivery', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['delivery_address', 'delivery_phone']);

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'delivery', 'delivery_address' => '123 Galle Rd', 'delivery_phone' => '0771234567',
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    expect($created->json('order.service_charge'))->toBe(0)
        ->and($created->json('order.vat'))->toBe(10000)
        ->and($created->json('order.delivery_status.code'))->toBe('pending');

    $rider = staffWithRole('Manager');
    $orderId = $created->json('order.id');
    $dispatch = $this->actingAs($manager)->putJson("/api/orders/{$orderId}/delivery/rider", ['rider_id' => $rider->id])
        ->assertOk();

    expect($dispatch->json('order.delivery_status.code'))->toBe('out_for_delivery')
        ->and($dispatch->json('order.delivery_rider.id'))->toBe($rider->id);

    $this->actingAs($manager)->putJson("/api/orders/{$orderId}/delivery/status", ['status' => 'delivered'])
        ->assertOk()->assertJsonPath('order.delivery_status.code', 'delivered');
});

it('splits a bill into child orders whose totals sum back to the original', function () {
    $manager = staffWithRole('Manager');
    $itemA = restaurantMenuItem('A', 100000);
    $itemB = restaurantMenuItem('B', 50000);

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'takeaway',
        'items' => [['menu_item_id' => $itemA->id, 'qty' => 1], ['menu_item_id' => $itemB->id, 'qty' => 1]],
    ])->assertCreated();
    $orderId = $created->json('order.id');
    $originalTotal = $created->json('order.total');
    $itemIds = collect($created->json('order.items'))->pluck('id')->all();

    $split = $this->actingAs($manager)->postJson("/api/orders/{$orderId}/split", [
        'groups' => [[$itemIds[0]], [$itemIds[1]]],
    ])->assertOk();

    expect(collect($split->json('orders'))->sum('total'))->toBe($originalTotal);

    $this->actingAs($manager)->getJson("/api/orders/{$orderId}")->assertJsonPath('order.status.code', 'split');
});

it('rejects a split that leaves an item unassigned', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantMenuItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 2]],
    ])->assertCreated();
    $orderId = $created->json('order.id');

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/split", [
        'groups' => [[999999], [999998]],
    ])->assertUnprocessable();
});

it('merges two open orders into one, and marks the folded-in order Merged', function () {
    $manager = staffWithRole('Manager');
    $itemA = restaurantMenuItem('A', 100000);
    $itemB = restaurantMenuItem('B', 50000);

    $orderA = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'takeaway', 'items' => [['menu_item_id' => $itemA->id, 'qty' => 1]],
    ])->assertCreated();
    $orderB = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'takeaway', 'items' => [['menu_item_id' => $itemB->id, 'qty' => 1]],
    ])->assertCreated();

    $merged = $this->actingAs($manager)->postJson("/api/orders/{$orderA->json('order.id')}/merge", [
        'order_ids' => [$orderB->json('order.id')],
    ])->assertOk();

    expect($merged->json('order.subtotal'))->toBe(150000);
    $this->actingAs($manager)->getJson("/api/orders/{$orderB->json('order.id')}")
        ->assertJsonPath('order.status.code', 'merged');
});

it('prices order items with modifiers and enforces required modifier groups', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantMenuItem('Latte', 50000);
    $group = MenuItemModifierGroup::create(['menu_item_id' => $item->id, 'name' => 'Size', 'is_required' => true, 'max_select' => 1]);
    $large = MenuItemModifier::create(['modifier_group_id' => $group->id, 'name' => 'Large', 'price_delta' => 15000]);

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1, 'modifier_ids' => [$large->id]]],
    ])->assertCreated();

    expect($created->json('order.items.0.unit_price'))->toBe(65000)
        ->and($created->json('order.items.0.modifiers.0.name'))->toBe('Large');
});

it('rejects a kitchen station code that is not seeded', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/menu/categories', [
        'name' => 'Cocktails', 'kitchen_station' => 'not_a_real_station',
    ])->assertUnprocessable()->assertJsonValidationErrors('kitchen_station');

    $this->actingAs($manager)->postJson('/api/menu/categories', [
        'name' => 'Cocktails', 'kitchen_station' => 'bar',
    ])->assertCreated()->assertJsonPath('menu_category.kitchen_station.code', 'bar');
});

it('edits a dining table\'s details', function () {
    $manager = staffWithRole('Manager');
    $table = freeDiningTable();

    $this->actingAs($manager)->putJson("/api/dining-tables/{$table->id}", [
        'table_no' => 'T1-renamed', 'capacity' => 6,
    ])->assertOk()->assertJsonPath('dining_table.table_no', 'T1-renamed')
        ->assertJsonPath('dining_table.capacity', 6);
});

it('blocks deleting a dining table that has an open order', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $item = restaurantMenuItem();
    $table = freeDiningTable();

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'dining_table_id' => $table->id,
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    $this->actingAs($manager)->deleteJson("/api/dining-tables/{$table->id}")
        ->assertUnprocessable()->assertJsonValidationErrors('dining_table');

    $this->assertDatabaseHas('dining_tables', ['id' => $table->id, 'deleted_at' => null]);
});

it('deletes a dining table with no open order', function () {
    $manager = staffWithRole('Manager');
    $table = freeDiningTable();

    $this->actingAs($manager)->deleteJson("/api/dining-tables/{$table->id}")->assertOk();

    $this->assertSoftDeleted('dining_tables', ['id' => $table->id]);
});
