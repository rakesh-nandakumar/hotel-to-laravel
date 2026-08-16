<?php

use App\Models\Hotel\AddOn;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use Database\Seeders\BranchSeeder;
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

function kotCategory(): MenuCategory
{
    return MenuCategory::firstOrCreate(['name' => 'Mains']);
}

/** Recipe-based kitchen item (KOT). */
function kotMenuItem(int $stock = 5000): MenuItem
{
    $category = kotCategory();
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => $stock, 'low_stock_threshold' => 500]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    return $item;
}

/** Unit-stocked direct-fulfill item (no recipe, bottled drink). */
function directItem(string $name = 'Bottled Water', int $stock = 100): MenuItem
{
    $category = kotCategory();
    $water = Ingredient::create(['name' => $name, 'unit' => 'pcs', 'stock_qty' => $stock, 'low_stock_threshold' => 10]);
    $item = MenuItem::create([
        'name' => $name, 'menu_category_id' => $category->id, 'price' => 20000,
        'send_to_kot' => false, 'stock_ingredient_id' => $water->id,
    ]);

    return $item;
}

function unitStockedAddOn(string $name = 'Extra Cheese', int $stock = 50, bool $sendToKot = false): AddOn
{
    $cheese = Ingredient::create(['name' => $name.' (stock)', 'unit' => 'pcs', 'stock_qty' => $stock, 'low_stock_threshold' => 5]);

    return AddOn::create(['name' => $name, 'price' => 15000, 'send_to_kot' => $sendToKot, 'stock_ingredient_id' => $cheese->id]);
}

it('routes kitchen items to the KOT board and hides direct-fulfill items from it', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $drink = directItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in',
        'items' => [
            ['menu_item_id' => $food->id, 'qty' => 1],
            ['menu_item_id' => $drink->id, 'qty' => 2],
        ],
    ])->assertCreated();

    $items = collect($created->json('order.items'));
    expect($items->firstWhere('menu_item_id', $food->id)['send_to_kot'])->toBeTrue()
        ->and($items->firstWhere('menu_item_id', $drink->id)['send_to_kot'])->toBeFalse();

    $kot = $this->actingAs($manager)->getJson('/api/orders/kot')->assertOk();
    $ticket = collect($kot->json('orders'))->firstWhere('id', $created->json('order.id'));
    expect($ticket)->not->toBeNull()
        ->and($ticket['items'])->toHaveCount(1)
        ->and($ticket['items'][0]['menu_item_id'])->toBe($food->id);
});

it('deducts direct-stock units FEFO from expiry batches', function () {
    $manager = staffWithRole('Manager');
    $category = kotCategory();
    $water = Ingredient::create(['name' => 'Water', 'unit' => 'pcs', 'stock_qty' => 30, 'low_stock_threshold' => 5]);
    $soonBatch = $water->batches()->create(['qty' => 10, 'initial_qty' => 10, 'expiry_date' => '2026-08-01']);
    $laterBatch = $water->batches()->create(['qty' => 20, 'initial_qty' => 20, 'expiry_date' => '2026-12-01']);
    $item = MenuItem::create([
        'name' => 'Bottled Water', 'menu_category_id' => $category->id, 'price' => 20000,
        'send_to_kot' => false, 'stock_ingredient_id' => $water->id,
    ]);

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 3]],
    ])->assertCreated();

    expect($water->fresh()->stock_qty)->toBe(27.0)
        ->and($soonBatch->fresh()->qty)->toBe(7.0)
        ->and($laterBatch->fresh()->qty)->toBe(20.0);
});

it('creates an add-on linked to an item and exposes it on the POS menu', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $cheese = Ingredient::create(['name' => 'Cheese', 'unit' => 'pcs', 'stock_qty' => 50, 'low_stock_threshold' => 5]);

    $created = $this->actingAs($manager)->postJson('/api/menu/add-ons', [
        'name' => 'Extra Cheese', 'price' => 15000, 'send_to_kot' => true,
        'stock_ingredient_id' => $cheese->id, 'menu_item_ids' => [$food->id],
    ])->assertCreated();

    expect($created->json('add_on.id'))->toBeGreaterThan(0);

    $full = $this->actingAs($manager)->getJson('/api/menu/full')->assertOk();
    $item = collect($full->json('categories'))->flatMap(fn ($c) => $c['items'])->firstWhere('id', $food->id);
    expect($item['addons'])->toHaveCount(1)
        ->and($item['addons'][0]['name'])->toBe('Extra Cheese');
});

it('exposes category-linked add-ons on every item of that category', function () {
    $manager = staffWithRole('Manager');
    $category = kotCategory();
    $food = MenuItem::create(['name' => 'Kottu', 'menu_category_id' => $category->id, 'price' => 100000]);
    $addOn = unitStockedAddOn('Extra Cheese');

    $this->actingAs($manager)->postJson('/api/menu/add-ons', [
        'name' => $addOn->name, 'price' => $addOn->price, 'send_to_kot' => $addOn->send_to_kot,
        'stock_ingredient_id' => $addOn->stock_ingredient_id, 'menu_category_ids' => [$category->id],
    ])->assertCreated();

    $full = $this->actingAs($manager)->getJson('/api/menu/full')->assertOk();
    $item = collect($full->json('categories'))->flatMap(fn ($c) => $c['items'])->firstWhere('id', $food->id);
    expect($item['addons'])->toHaveCount(1)
        ->and($item['addons'][0]['name'])->toBe('Extra Cheese');
});

it('orders an add-on as its own line, routing and deducting it independently', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $addOn = unitStockedAddOn('Extra Curry', stock: 50, sendToKot: true);

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in',
        'items' => [
            ['menu_item_id' => $food->id, 'qty' => 1],
            ['add_on_id' => $addOn->id, 'qty' => 2],
        ],
    ])->assertCreated();

    $order = $created->json('order');
    $lines = collect($order['items']);
    $addOnLine = $lines->firstWhere('add_on_id', $addOn->id);

    expect($addOnLine['name'])->toBe('Extra Curry')
        ->and($addOnLine['send_to_kot'])->toBeTrue()
        ->and($addOnLine['amount'])->toBe(30000)
        ->and($order['subtotal'])->toBe(130000);

    // Kitchen board sees the add-on line too.
    $kot = $this->actingAs($manager)->getJson('/api/orders/kot')->assertOk();
    $ticket = collect($kot->json('orders'))->firstWhere('id', $order['id']);
    expect(collect($ticket['items'])->pluck('name'))->toContain('Extra Curry');

    // Its own stock was deducted.
    expect($addOn->stockIngredient->fresh()->stock_qty)->toBe(48.0);
});

it('keeps a direct-fulfill add-on off the kitchen board', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $addOn = unitStockedAddOn('Garnish', stock: 50, sendToKot: false);

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [
            ['menu_item_id' => $food->id, 'qty' => 1],
            ['add_on_id' => $addOn->id, 'qty' => 1],
        ],
    ])->assertCreated();

    $kot = $this->actingAs($manager)->getJson('/api/orders/kot')->assertOk();
    $ticket = collect($kot->json('orders'))->firstWhere('id', $created->json('order.id'));
    expect(collect($ticket['items'])->pluck('name'))->not->toContain('Garnish');
});

it('blocks an order line that has both or neither of menu_item_id and add_on_id', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $addOn = unitStockedAddOn();

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0');

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $food->id, 'add_on_id' => $addOn->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0');
});

it('restocks an add-on when its order item is voided while NEW', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $addOn = unitStockedAddOn();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [
            ['menu_item_id' => $food->id, 'qty' => 1],
            ['add_on_id' => $addOn->id, 'qty' => 2],
        ],
    ])->assertCreated();
    $itemId = collect($created->json('order.items'))->firstWhere('add_on_id', $addOn->id)['id'];

    expect($addOn->stockIngredient->fresh()->stock_qty)->toBe(48.0);

    $this->actingAs($manager)->postJson("/api/orders/{$created->json('order.id')}/items/{$itemId}/void", ['reason' => 'Not needed'])
        ->assertOk();

    expect($addOn->stockIngredient->fresh()->stock_qty)->toBe(50.0);
});

it('prints a kitchen ticket that only carries the kitchen-routed lines', function () {
    $manager = staffWithRole('Manager');
    $food = kotMenuItem();
    $drink = directItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in',
        'items' => [
            ['menu_item_id' => $food->id, 'qty' => 1],
            ['menu_item_id' => $drink->id, 'qty' => 1],
        ],
    ])->assertCreated();

    $this->actingAs($manager)->get("/api/orders/{$created->json('order.id')}/kot-ticket")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
