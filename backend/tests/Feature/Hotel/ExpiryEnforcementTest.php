<?php

use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\StockMovement;
use App\Models\Lookup;
use App\Services\Hotel\InventoryService;
use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\LookupType;
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

function expiredProduct(string $name, float $stock, string $expiryDate): Ingredient
{
    $product = Ingredient::create([
        'name' => $name, 'unit' => 'pcs', 'stock_qty' => $stock, 'low_stock_threshold' => 2,
        'inventory_kind_id' => Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::PRODUCT),
        'selling_price' => 25000, 'active' => true,
    ]);
    $product->batches()->create(['qty' => $stock, 'initial_qty' => $stock, 'expiry_date' => $expiryDate]);

    return $product;
}

it('blocks ordering a product whose only stock has expired', function () {
    $manager = staffWithRole('Manager');
    $juice = expiredProduct('Orange Juice', 10, now()->subDay()->toDateString());

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['product_id' => $juice->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items');

    expect($juice->fresh()->stock_qty)->toBe(10.0);
});

it('never shows or sells the expired portion of a partially-expired product', function () {
    $manager = staffWithRole('Manager');
    $coke = Ingredient::create([
        'name' => 'Coca-Cola 500ml', 'unit' => 'pcs', 'stock_qty' => 15, 'low_stock_threshold' => 5,
        'inventory_kind_id' => Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::PRODUCT),
        'selling_price' => 25000, 'active' => true,
    ]);
    $expiredBatch = $coke->batches()->create(['qty' => 5, 'initial_qty' => 5, 'expiry_date' => now()->subDay()->toDateString()]);
    $goodBatch = $coke->batches()->create(['qty' => 10, 'initial_qty' => 10, 'expiry_date' => now()->addMonth()->toDateString()]);

    // POS search shows only the 10 unexpired units, never the full 15.
    $search = $this->actingAs($manager)->getJson('/api/products/search')->assertOk();
    $listed = collect($search->json('products'))->firstWhere('id', $coke->id);
    expect($listed['stock_qty'])->toBe(10);

    // Selling 8 succeeds and must draw only from the unexpired batch.
    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['product_id' => $coke->id, 'qty' => 8]],
    ])->assertCreated();

    expect($expiredBatch->fresh()->qty)->toBe(5.0)
        ->and($goodBatch->fresh()->qty)->toBe(2.0)
        ->and($coke->fresh()->stock_qty)->toBe(7.0);
});

it('blocks a recipe-based menu item sale when its ingredient has been deactivated, even with plenty of stock_qty', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500, 'active' => false]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 120000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable();

    expect($rice->fresh()->stock_qty)->toBe(5000.0);
});

it('blocks a recipe-based menu item sale when its ingredient only has expired stock', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $chicken = Ingredient::create(['name' => 'Chicken', 'unit' => 'g', 'stock_qty' => 2000, 'low_stock_threshold' => 200]);
    $chicken->batches()->create(['qty' => 2000, 'initial_qty' => 2000, 'expiry_date' => now()->subDays(2)->toDateString()]);
    $item = MenuItem::create(['name' => 'Grilled Chicken', 'menu_category_id' => $category->id, 'price' => 180000]);
    $item->recipe()->create(['ingredient_id' => $chicken->id, 'qty' => 300]);

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable();

    expect($chicken->fresh()->stock_qty)->toBe(2000.0);
});

it('excludes a menu item from POS search/full once its recipe ingredient is fully expired, without waiting for the sold_out sweep', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $chicken = Ingredient::create(['name' => 'Chicken', 'unit' => 'g', 'stock_qty' => 2000, 'low_stock_threshold' => 200]);
    $chicken->batches()->create(['qty' => 2000, 'initial_qty' => 2000, 'expiry_date' => now()->subDays(2)->toDateString()]);
    $item = MenuItem::create(['name' => 'Grilled Chicken', 'menu_category_id' => $category->id, 'price' => 180000]);
    $item->recipe()->create(['ingredient_id' => $chicken->id, 'qty' => 300]);

    // Nothing has swept sold_out yet — the cached flag is still false.
    expect($item->fresh()->sold_out)->toBeFalse();

    $search = $this->actingAs($manager)->getJson('/api/menu/search')->assertOk();
    expect(collect($search->json('items'))->pluck('id'))->not->toContain($item->id);

    $full = $this->actingAs($manager)->getJson('/api/menu/full')->assertOk();
    $listedIds = collect($full->json('categories'))->flatMap(fn ($c) => collect($c['items'])->pluck('id'));
    expect($listedIds)->not->toContain($item->id);
});

it('auto-writes off every expired batch on a sweep and is idempotent on a second run', function () {
    staffWithRole('Manager'); // establishes tenant context, matching every other test in this suite
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 700, 'low_stock_threshold' => 100]);
    $expiredBatch = $rice->batches()->create(['qty' => 200, 'initial_qty' => 200, 'expiry_date' => now()->subDay()->toDateString()]);
    $goodBatch = $rice->batches()->create(['qty' => 500, 'initial_qty' => 500, 'expiry_date' => now()->addMonth()->toDateString()]);

    $result = app(InventoryService::class)->autoWriteOffExpiredBatches();

    expect($result['written_off_batches'])->toBe(1)
        ->and($expiredBatch->fresh()->qty)->toBe(0.0)
        ->and($goodBatch->fresh()->qty)->toBe(500.0)
        ->and($rice->fresh()->stock_qty)->toBe(500.0);

    $movement = StockMovement::query()
        ->where('ingredient_batch_id', $expiredBatch->id)
        ->where('reference_type', 'write_off')
        ->first();
    expect($movement)->not->toBeNull()
        ->and($movement->qty)->toBe(-200.0);

    $second = app(InventoryService::class)->autoWriteOffExpiredBatches();
    expect($second['written_off_batches'])->toBe(0);
});
