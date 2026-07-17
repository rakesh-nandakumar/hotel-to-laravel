<?php

use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
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

it('blocks non-manager roles from shifts entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/shifts/current')->assertForbidden();
});

it('opens a shift and reports it as current', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/shifts/open', ['opening_cash' => 1000000])->assertCreated();

    $response = $this->actingAs($manager)->getJson('/api/shifts/current')->assertOk();

    expect($response->json('shift.opening_cash'))->toBe(1000000)
        ->and($response->json('shift.expected_now'))->toBe(1000000);
});

it('blocks opening a second shift while one is already open', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/shifts/open', ['opening_cash' => 500000])->assertCreated();

    $this->actingAs($manager)->postJson('/api/shifts/open', ['opening_cash' => 200000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift');
});

it('reconciles the drawer on close: cash payments count, refunds subtract, other methods are ignored', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);

    $shift = $this->actingAs($manager)->postJson('/api/shifts/open', ['opening_cash' => 500000])->json();

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $order['total']]],
    ])->assertOk();
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/refund", [
        'amount' => 20000, 'method' => 'cash', 'reason' => 'Partial goodwill refund',
    ])->assertCreated();

    // expected = 500,000 (opening) + order total (cash in) - 20,000 (cash refund)
    $expected = 500000 + $order['total'] - 20000;

    $response = $this->actingAs($manager)->postJson("/api/shifts/{$shift['shift']['id']}/close", [
        'closing_cash' => $expected,
    ])->assertOk();

    expect($response->json('shift.expected_cash'))->toBe($expected)
        ->and($response->json('shift.variance'))->toBe(0);
});

it('reports a variance when counted cash does not match expected', function () {
    $manager = staffWithRole('Manager');
    $shift = $this->actingAs($manager)->postJson('/api/shifts/open', ['opening_cash' => 500000])->json('shift');

    $response = $this->actingAs($manager)->postJson("/api/shifts/{$shift['id']}/close", ['closing_cash' => 480000])->assertOk();

    expect($response->json('shift.expected_cash'))->toBe(500000)
        ->and($response->json('shift.variance'))->toBe(-20000);
});

it('rejects closing an already-closed shift', function () {
    $manager = staffWithRole('Manager');
    $shift = $this->actingAs($manager)->postJson('/api/shifts/open', ['opening_cash' => 500000])->json('shift');
    $this->actingAs($manager)->postJson("/api/shifts/{$shift['id']}/close", ['closing_cash' => 500000])->assertOk();

    $this->actingAs($manager)->postJson("/api/shifts/{$shift['id']}/close", ['closing_cash' => 500000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift');
});
