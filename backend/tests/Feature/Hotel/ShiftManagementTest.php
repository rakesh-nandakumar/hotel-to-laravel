<?php

use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Till;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TillSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(TillSeeder::class);
});

it('blocks non-manager roles from till entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/till/current')->assertForbidden();
});

it('opens a till and reports it as current', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 1000000,
    ])->assertCreated();

    $response = $this->actingAs($manager)->getJson('/api/till/current')->assertOk();

    expect($response->json('session.opening_cash'))->toBe(1000000)
        ->and($response->json('session.expected_balance'))->toBe(1000000);
});

it('blocks opening a second till session while one is already open', function () {
    $manager = staffWithRole('Manager');
    $tillId = Till::query()->value('id');

    $this->actingAs($manager)->postJson('/api/till/open', ['till_id' => $tillId, 'opening_balance' => 500000])->assertCreated();

    $this->actingAs($manager)->postJson('/api/till/open', ['till_id' => $tillId, 'opening_balance' => 200000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('till');
});

it('reconciles the drawer on close: cash payments count, refunds subtract, other methods are ignored', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);

    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $order['total']]],
    ])->assertOk();
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/refund", [
        'amount' => 20000, 'method' => 'cash', 'reason' => 'Partial goodwill refund',
    ])->assertCreated();

    // Also record a card payment on a second order — it must never touch the cash drawer.
    $order2 = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order2['id']}/settle", [
        'payments' => [['method' => 'card', 'amount' => $order2['total']]],
    ])->assertOk();

    // expected = 500,000 (opening) + order total (cash in) - 20,000 (cash refund) — card is ignored
    $expected = 500000 + $order['total'] - 20000;

    $response = $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", [
        'closing_cash' => $expected,
    ])->assertOk();

    expect($response->json('session.expected_cash'))->toBe($expected)
        ->and($response->json('session.variance'))->toBe(0);
});

it('reports a variance when counted cash does not match expected', function () {
    $manager = staffWithRole('Manager');
    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');

    $response = $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", ['closing_cash' => 480000])->assertOk();

    expect($response->json('session.expected_cash'))->toBe(500000)
        ->and($response->json('session.variance'))->toBe(-20000);
});

it('rejects closing an already-closed till session', function () {
    $manager = staffWithRole('Manager');
    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');
    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", ['closing_cash' => 500000])->assertOk();

    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", ['closing_cash' => 500000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('till');
});

it('requires a reason for a cash withdrawal, and it reduces the expected balance', function () {
    $manager = staffWithRole('Manager');
    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');

    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/movements", [
        'type' => 'cash_out', 'amount' => 100000,
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/movements", [
        'type' => 'cash_out', 'amount' => 100000, 'reason' => 'Bank deposit',
    ])->assertCreated();

    $current = $this->actingAs($manager)->getJson('/api/till/current')->assertOk();
    expect($current->json('session.expected_balance'))->toBe(400000);
});

it('blocks a cash payment when the cashier has no open till session, but leaves other methods unaffected', function () {
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->json('order');

    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $order['total']]],
    ])->assertUnprocessable()->assertJsonValidationErrors('method');

    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'card', 'amount' => $order['total']]],
    ])->assertOk();
});

it('creates a till which then appears in the till list for opening', function () {
    $manager = staffWithRole('Manager');

    $created = $this->actingAs($manager)->postJson('/api/till/tills', ['name' => 'Restaurant Till'])
        ->assertCreated();

    $index = $this->actingAs($manager)->getJson('/api/till/tills')->assertOk();
    expect(collect($index->json('tills'))->pluck('name'))->toContain('Restaurant Till');

    $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $created->json('till.id'), 'opening_balance' => 100000,
    ])->assertCreated();
});

it('lists a deactivated till only when include_inactive is requested', function () {
    $manager = staffWithRole('Manager');
    $till = $this->actingAs($manager)->postJson('/api/till/tills', ['name' => 'Pool Bar Till'])
        ->assertCreated()->json('till');

    $this->actingAs($manager)->putJson("/api/till/tills/{$till['id']}", ['name' => $till['name'], 'is_active' => false])
        ->assertOk();

    $activeOnly = $this->actingAs($manager)->getJson('/api/till/tills')->assertOk();
    expect(collect($activeOnly->json('tills'))->pluck('id'))->not->toContain($till['id']);

    $withInactive = $this->actingAs($manager)->getJson('/api/till/tills?include_inactive=1')->assertOk();
    expect(collect($withInactive->json('tills'))->pluck('id'))->toContain($till['id']);
});
