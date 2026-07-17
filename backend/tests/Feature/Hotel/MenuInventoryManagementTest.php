<?php

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

it('lets any authenticated staff view the menu grid, categories, and items', function () {
    $category = MenuCategory::create(['name' => 'Mains']);
    MenuItem::create(['name' => 'Rice & Curry', 'menu_category_id' => $category->id, 'price' => 150000]);
    $security = staffWithRole('Security');

    $this->actingAs($security)->getJson('/api/menu/full')->assertOk()->assertJsonCount(1, 'categories');
    $this->actingAs($security)->getJson('/api/menu/categories')->assertOk()->assertJsonCount(1, 'menu_categories');
    $this->actingAs($security)->getJson('/api/menu/items')->assertOk()->assertJsonCount(1, 'menu_items');
});

it('blocks non-manager roles from creating menu categories or items', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->postJson('/api/menu/categories', ['name' => 'Drinks'])->assertForbidden();
});

it('blocks non-manager and non-chef roles from ingredients entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');
    $security = staffWithRole('Security');

    $this->actingAs($housekeeper)->getJson('/api/ingredients')->assertForbidden();
    $this->actingAs($security)->getJson('/api/ingredients')->assertForbidden();
});

it('creates a menu item with a recipe, auto-assigning the next item number', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    MenuItem::create(['name' => 'Existing Dish', 'menu_category_id' => $category->id, 'price' => 100000, 'item_no' => 5]);

    $response = $this->actingAs($manager)->postJson('/api/menu/items', [
        'name' => 'Fried Rice',
        'menu_category_id' => $category->id,
        'price' => 120000,
        'recipe' => [['ingredient_id' => $rice->id, 'qty' => 250]],
    ])->assertCreated();

    expect($response->json('menu_item.item_no'))->toBe(6)
        ->and($response->json('menu_item.recipe'))->toHaveCount(1);
});

it('hard-deletes a menu item that has never been ordered', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $item = MenuItem::create(['name' => 'Untouched Dish', 'menu_category_id' => $category->id, 'price' => 100000]);

    $response = $this->actingAs($manager)->deleteJson("/api/menu/items/{$item->id}")->assertOk();

    expect($response->json('archived'))->toBeFalse();
    $this->assertDatabaseMissing('pos_menu_items', ['id' => $item->id]);
});

it('archives instead of deleting a menu item that appears in past orders', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in',
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    $response = $this->actingAs($manager)->deleteJson("/api/menu/items/{$item->id}")->assertOk();

    expect($response->json('archived'))->toBeTrue()
        ->and($response->json('message'))->toContain('archived instead of deleted');
    $this->assertDatabaseHas('pos_menu_items', ['id' => $item->id, 'active' => false]);
});

it('blocks removing a category that still has items', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    MenuItem::create(['name' => 'Rice & Curry', 'menu_category_id' => $category->id, 'price' => 150000]);

    $this->actingAs($manager)->deleteJson("/api/menu/categories/{$category->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('menu_category');
});

it('replaces a menu item recipe on update', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    $chicken = Ingredient::create(['name' => 'Chicken', 'unit' => 'g', 'stock_qty' => 3000, 'low_stock_threshold' => 300]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 120000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $response = $this->actingAs($manager)->putJson("/api/menu/items/{$item->id}", [
        'recipe' => [['ingredient_id' => $chicken->id, 'qty' => 150]],
    ])->assertOk();

    expect($response->json('menu_item.recipe'))->toHaveCount(1)
        ->and($response->json('menu_item.recipe.0.ingredient_id'))->toBe($chicken->id);
});

it('blocks marking an item available again without enough raw materials', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 100, 'low_stock_threshold' => 50]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 120000, 'sold_out' => true]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $this->actingAs($manager)->putJson("/api/menu/items/{$item->id}/sold-out", ['sold_out' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sold_out');

    $rice->update(['stock_qty' => 500]);

    $this->actingAs($manager)->putJson("/api/menu/items/{$item->id}/sold-out", ['sold_out' => false])
        ->assertOk()
        ->assertJsonPath('menu_item.sold_out', false);
});

it('blocks deleting an ingredient still used in a recipe', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 120000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $this->actingAs($manager)->deleteJson("/api/ingredients/{$rice->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ingredient');
});

it('rejects a stock adjustment that would push the total negative', function () {
    $manager = staffWithRole('Manager');
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 100, 'low_stock_threshold' => 50]);

    $this->actingAs($manager)->postJson("/api/ingredients/{$rice->id}/adjust", [
        'delta' => -200, 'reason' => 'Spoilage',
    ])->assertUnprocessable()->assertJsonValidationErrors('delta');
});

it('receives stock into a new batch on a positive adjustment', function () {
    $chef = staffWithRole('Chef');
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 100, 'low_stock_threshold' => 50]);

    $this->actingAs($chef)->postJson("/api/ingredients/{$rice->id}/adjust", [
        'delta' => 5000, 'reason' => 'Weekly delivery', 'expiry_date' => '2026-12-01',
    ])->assertOk()->assertJsonPath('ingredient.stock_qty', 5100);

    expect($rice->batches()->count())->toBe(1)
        ->and($rice->batches()->first()->qty)->toBe(5000.0);
});

it('drains batches FEFO on a negative stock adjustment', function () {
    $chef = staffWithRole('Chef');
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 300, 'low_stock_threshold' => 50]);
    $soonBatch = $rice->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-08-01']);
    $laterBatch = $rice->batches()->create(['qty' => 200, 'initial_qty' => 200, 'expiry_date' => '2026-12-01']);

    $this->actingAs($chef)->postJson("/api/ingredients/{$rice->id}/adjust", [
        'delta' => -150, 'reason' => 'Kitchen write-down',
    ])->assertOk()->assertJsonPath('ingredient.stock_qty', 150);

    expect($soonBatch->fresh()->qty)->toBe(0.0)
        ->and($laterBatch->fresh()->qty)->toBe(150.0);
});

it('shows the expiry board and writes off a batch with a mandatory reason', function () {
    $manager = staffWithRole('Manager');
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 100, 'low_stock_threshold' => 50]);
    $batch = $rice->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => now()->addDay()->toDateString()]);

    $this->actingAs($manager)->getJson('/api/ingredients/expiry')
        ->assertOk()
        ->assertJsonCount(1, 'batches');

    $this->actingAs($manager)->postJson("/api/ingredients/batches/{$batch->id}/write-off", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->actingAs($manager)->postJson("/api/ingredients/batches/{$batch->id}/write-off", ['reason' => 'Spoiled'])
        ->assertOk()
        ->assertJsonPath('written_off', 100);

    expect($rice->fresh()->stock_qty)->toBe(0.0);
});
