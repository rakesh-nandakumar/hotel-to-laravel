<?php

use App\Models\Hotel\DiningTable;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\MenuItemModifier;
use App\Models\Hotel\MenuItemModifierGroup;
use App\Models\Lookup;
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

function restaurantItem(string $name = 'Fried Rice', int $price = 100000): MenuItem
{
    $category = MenuCategory::create(['name' => $name.' Category '.uniqid()]);

    return MenuItem::create(['name' => $name, 'menu_category_id' => $category->id, 'price' => $price]);
}

function diningTable(string $tableNo = 'T1'): DiningTable
{
    return DiningTable::create([
        'table_no' => $tableNo, 'capacity' => 4,
        'table_status_id' => Lookup::id(LookupType::TABLE_STATUS, TableStatus::FREE),
    ]);
}

it('blocks non-manager roles from every new restaurant report endpoint', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/menu-performance')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/modifiers')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/discounts-voids')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/table-server')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/delivery')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/kitchen-ticket-time')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/shift-sales')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/restaurant/reports/food-cost')->assertForbidden();
});

it('computes menu item performance: revenue/qty per item, category mix, and slow movers', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $fast = restaurantItem('Fried Rice', 100000);
    $slow = restaurantItem('Caviar', 500000);

    foreach ([[$fast, 3], [$slow, 1]] as [$item, $qty]) {
        $order = $this->actingAs($manager)->postJson('/api/orders', [
            'type' => 'walkin', 'dining_mode' => 'takeaway', 'items' => [['menu_item_id' => $item->id, 'qty' => $qty]],
        ])->assertCreated()->json('order');
        $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", ['payments' => [['method' => 'cash', 'amount' => $order['total']]]])->assertOk();
    }

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/menu-performance?from={$today}&to={$today}")->assertOk();

    expect($response->json('total_revenue'))->toBe(800000)
        ->and($response->json('best_sellers.0.name'))->toBe('Fried Rice')
        ->and($response->json('best_sellers.0.qty'))->toBe(3)
        ->and($response->json('best_sellers.0.amount'))->toBe(300000)
        ->and($response->json('slow_movers.0.name'))->toBe('Caviar');
});

it('computes modifier attach-rate and revenue', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $latte = restaurantItem('Latte', 50000);
    $group = MenuItemModifierGroup::create(['menu_item_id' => $latte->id, 'name' => 'Size', 'is_required' => false, 'max_select' => 1]);
    $large = MenuItemModifier::create(['modifier_group_id' => $group->id, 'name' => 'Large', 'price_delta' => 15000]);
    $tea = restaurantItem('Tea', 20000);

    $withModifier = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $latte->id, 'qty' => 1, 'modifier_ids' => [$large->id]]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$withModifier['id']}/settle", ['payments' => [['method' => 'cash', 'amount' => $withModifier['total']]]])->assertOk();

    $withoutModifier = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $tea->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$withoutModifier['id']}/settle", ['payments' => [['method' => 'cash', 'amount' => $withoutModifier['total']]]])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/modifiers?from={$today}&to={$today}")->assertOk();

    expect($response->json('order_items'))->toBe(2)
        ->and($response->json('order_items_with_modifiers'))->toBe(1)
        ->and($response->json('attach_rate_pct'))->toEqual(50)
        ->and($response->json('modifier_revenue'))->toBe(15000)
        ->and($response->json('by_modifier.Large.count'))->toBe(1)
        ->and($response->json('by_modifier.Large.revenue'))->toBe(15000);
});

it('computes discount totals by reason/authorizer and void rate/reasons', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantItem('Fried Rice', 100000);

    $discounted = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->putJson("/api/orders/{$discounted['id']}/discount", [
        'mode' => 'FIXED', 'value' => 10000, 'reason' => 'Loyal customer',
    ])->assertOk();

    $voided = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1], ['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$voided['id']}/items/{$voided['items'][0]['id']}/void", ['reason' => 'Wrong order'])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/discounts-voids?from={$today}&to={$today}")->assertOk();

    expect($response->json('total_discount'))->toBe(10000)
        ->and($response->json('discount_by_reason.Loyal customer'))->toBe(10000)
        ->and($response->json('voided_items_count'))->toBe(1)
        ->and($response->json('void_by_reason.Wrong order'))->toBe(1);
});

it('computes table and server performance', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $item = restaurantItem('Fried Rice', 100000);
    $table = diningTable('T5');

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'dining_table_id' => $table->id,
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", ['payments' => [['method' => 'cash', 'amount' => $order['total']]]])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/table-server?from={$today}&to={$today}")->assertOk();

    // Array-key access, not json('by_server.<name>...') dot-notation — Faker
    // names occasionally include a literal "." (e.g. "Jr.", "Dr."), which
    // would corrupt a dot-path lookup and fail even though the report itself
    // computed the right thing.
    $byServer = $response->json('by_server');

    expect($response->json('total_orders'))->toBe(1)
        ->and($response->json('by_table.T5.orders'))->toBe(1)
        ->and($response->json('by_table.T5.revenue'))->toBe($order['total'])
        ->and($byServer[$manager->name]['orders'])->toBe(1);
});

it('computes delivery performance: status funnel, rider breakdown, and fulfillment time', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantItem('Fried Rice', 100000);
    $rider = staffWithRole('Manager');

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'delivery', 'delivery_address' => '123 Galle Rd', 'delivery_phone' => '0771234567',
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->putJson("/api/orders/{$order['id']}/delivery/rider", ['rider_id' => $rider->id])->assertOk();
    $this->actingAs($manager)->putJson("/api/orders/{$order['id']}/delivery/status", ['status' => 'delivered'])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/delivery?from={$today}&to={$today}")->assertOk();

    // Array-key access — see the table/server test above for why dot-notation
    // on a Faker-generated name is unsafe.
    $byRider = $response->json('by_rider');

    expect($response->json('total_orders'))->toBe(1)
        ->and($response->json('by_status.delivered'))->toBe(1)
        ->and($response->json('delivered_count'))->toBe(1)
        ->and($response->json('avg_fulfillment_minutes'))->toBeGreaterThanOrEqual(0)
        ->and($byRider[$rider->name]['orders'])->toBe(1);
});

it('computes kitchen ticket time from kot status transitions', function () {
    $manager = staffWithRole('Manager');
    $item = restaurantItem('Fried Rice', 100000);

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->putJson("/api/orders/{$order['id']}/kot", ['status' => 'preparing'])->assertOk();
    $this->actingAs($manager)->putJson("/api/orders/{$order['id']}/kot", ['status' => 'ready'])->assertOk();
    $this->actingAs($manager)->putJson("/api/orders/{$order['id']}/kot", ['status' => 'served'])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/kitchen-ticket-time?from={$today}&to={$today}")->assertOk();

    expect($response->json('tickets'))->toBe(1)
        ->and($response->json('avg_prep_minutes'))->toBeGreaterThanOrEqual(0)
        ->and($response->json('avg_pickup_minutes'))->toBeGreaterThanOrEqual(0)
        ->and($response->json('by_station.unassigned.tickets'))->toBe(1);
});

it('computes per-shift sales drill-down', function () {
    $manager = staffWithRole('Manager');
    $session = openTillFor($manager, 500000);
    $item = restaurantItem('Fried Rice', 100000);

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", ['payments' => [['method' => 'cash', 'amount' => $order['total']]]])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/restaurant/reports/shift-sales?from={$today}&to={$today}")->assertOk();
    $row = collect($response->json('shifts'))->firstWhere('id', $session->id);

    expect($row)->not->toBeNull()
        ->and($row['staff'])->toBe($manager->name)
        ->and($row['collected'])->toBe($order['total'])
        ->and($response->json('total_collected'))->toBeGreaterThanOrEqual($order['total']);
});

it('computes food cost % from recipe cost vs price, leaving uncosted items as null', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $uncosted = MenuItem::create(['name' => 'Mystery Dish', 'menu_category_id' => $category->id, 'price' => 50000]);

    // No cost set yet — the item shows up with cost: null, not counted in the average.
    $before = $this->actingAs($manager)->getJson('/api/restaurant/reports/food-cost')->assertOk();
    expect(collect($before->json('items'))->firstWhere('id', $item->id)['cost'])->toBeNull()
        ->and($before->json('items_missing_cost'))->toBeGreaterThanOrEqual(2);

    $this->actingAs($manager)->putJson("/api/ingredients/{$rice->id}", ['unit_cost' => 40])->assertOk();

    $after = $this->actingAs($manager)->getJson('/api/restaurant/reports/food-cost')->assertOk();
    $row = collect($after->json('items'))->firstWhere('id', $item->id);
    $uncostedRow = collect($after->json('items'))->firstWhere('id', $uncosted->id);

    // 250g * 40 cents/g = 10,000 cents cost on a 100,000-cent price = 10% food cost.
    expect($row['cost'])->toBe(10000)
        ->and($row['food_cost_pct'])->toEqual(10)
        ->and($uncostedRow['cost'])->toBeNull();
});
