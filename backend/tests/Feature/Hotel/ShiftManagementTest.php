<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Room;
use App\Models\Till;
use Database\Seeders\HotelRoomsSeeder;
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

    $response = $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", [
        'closing_cash' => 480000, 'reason' => 'Cash short — under investigation',
    ])->assertOk();

    expect($response->json('session.expected_cash'))->toBe(500000)
        ->and($response->json('session.variance'))->toBe(-20000);
});

it('requires a reason to close with a variance, but not to close balanced', function () {
    $manager = staffWithRole('Manager');
    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');

    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", ['closing_cash' => 480000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", ['closing_cash' => 500000])
        ->assertOk();
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

it('never exposes till creation or editing on the tenant side — that stays master-control-only', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/till/tills', ['name' => 'Restaurant Till'])->assertMethodNotAllowed();

    $tillId = Till::query()->value('id');
    $this->actingAs($manager)->putJson("/api/till/tills/{$tillId}", ['name' => 'Renamed', 'is_active' => true])->assertNotFound();
});

it('carries the opening balance over from the till\'s last closing, and requires a reason to open with a different amount', function () {
    $manager = staffWithRole('Manager');
    $tillId = Till::query()->value('id');

    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $tillId, 'opening_balance' => 500000,
    ])->json('session');
    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", ['closing_cash' => 480000, 'reason' => 'Short at close'])->assertOk();

    // Same amount as last closing — no reason needed.
    $reopened = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $tillId, 'opening_balance' => 480000,
    ])->assertCreated()->json('session');
    $this->actingAs($manager)->postJson("/api/till/{$reopened['id']}/close", ['closing_cash' => 480000])->assertOk();

    // A different opening amount than the last closing balance needs a reason.
    $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $tillId, 'opening_balance' => 500000,
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $tillId, 'opening_balance' => 500000, 'reason' => 'Found extra float, adding it back',
    ])->assertCreated();
});

it('stores what was carried over at open alongside the amount actually opened with', function () {
    $manager = staffWithRole('Manager');
    $tillId = Till::query()->value('id');

    $first = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $tillId, 'opening_balance' => 500000,
    ])->assertCreated()->json('session');

    // A till that has never been closed has nothing to carry over, so opening
    // at any amount is not a variance.
    expect($first['carried_opening_cash'])->toBeNull()
        ->and($first['opening_variance'])->toBeNull()
        ->and($first['opening_reason'])->toBeNull();

    $this->actingAs($manager)->postJson("/api/till/{$first['id']}/close", ['closing_cash' => 480000, 'reason' => 'Short at close'])->assertOk();

    $second = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => $tillId, 'opening_balance' => 600000, 'reason' => 'Overnight safe float added back',
    ])->assertCreated()->json('session');

    expect($second['carried_opening_cash'])->toBe(480000)
        ->and($second['opening_variance'])->toBe(120000)
        ->and($second['opening_reason'])->toBe('Overnight safe float added back')
        // The session still opens on the counted amount, not the carried-over one.
        ->and($second['opening_cash'])->toBe(600000);
});

it('offers the last closing count, and who counted it when, as the suggested opening amount', function () {
    $manager = staffWithRole('Manager');
    $tillId = Till::query()->value('id');

    $before = $this->actingAs($manager)->getJson('/api/till/tills')->assertOk()->json('tills.0');
    expect($before['last_closing_cash'])->toBeNull()
        ->and($before['last_closed_at'])->toBeNull()
        ->and($before['last_closed_by'])->toBeNull();

    $session = $this->actingAs($manager)->postJson('/api/till/open', ['till_id' => $tillId, 'opening_balance' => 500000])->json('session');
    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", [])->assertOk();

    $after = $this->actingAs($manager)->getJson('/api/till/tills')->assertOk()->json('tills.0');
    expect($after['last_closing_cash'])->toBe(500000)
        ->and($after['last_closed_at'])->not->toBeNull()
        ->and($after['last_closed_by'])->toBe($manager->name);
});

it('closes at the ledger balance when no cash count is submitted', function () {
    $manager = staffWithRole('Manager');
    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');

    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/movements", [
        'type' => 'cash_out', 'amount' => 100000, 'reason' => 'Bank deposit',
    ])->assertCreated();

    $response = $this->actingAs($manager)->postJson("/api/till/{$session['id']}/close", [])->assertOk();

    expect($response->json('session.expected_cash'))->toBe(400000)
        ->and($response->json('session.closing_cash'))->toBe(400000)
        ->and($response->json('session.variance'))->toBe(0);
});

it('itemises the close-out by the activity that produced each rupee', function () {
    $this->seed(HotelRoomsSeeder::class);
    $manager = staffWithRole('Manager');
    $category = MenuCategory::create(['name' => 'Mains']);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);

    $session = $this->actingAs($manager)->postJson('/api/till/open', [
        'till_id' => Till::query()->value('id'), 'opening_balance' => 500000,
    ])->json('session');

    // Restaurant cash — a POS order settled at the counter.
    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $order['total']]],
    ])->assertOk();
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/refund", [
        'amount' => 20000, 'method' => 'cash', 'reason' => 'Partial goodwill refund',
    ])->assertCreated();

    // Room-booking cash — a payment against a guest folio.
    $guest = Guest::factory()->create();
    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => Room::query()->where('number', '102')->value('id')]],
    ])->assertCreated()->json('reservation');
    $this->actingAs($manager)->postJson("/api/folios/{$reservation['folio']['id']}/payments", [
        'method' => 'cash', 'amount' => 300000,
    ])->assertCreated();

    // A card payment must never reach the drawer, itemised or otherwise.
    $order2 = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order2['id']}/settle", [
        'payments' => [['method' => 'card', 'amount' => $order2['total']]],
    ])->assertOk();

    // Manual movements on both sides of the drawer.
    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/movements", [
        'type' => 'cash_in', 'amount' => 25000, 'reason' => 'Extra float',
    ])->assertCreated();
    $this->actingAs($manager)->postJson("/api/till/{$session['id']}/movements", [
        'type' => 'cash_out', 'amount' => 40000, 'reason' => 'Bank deposit',
    ])->assertCreated();

    $summary = $this->actingAs($manager)->getJson("/api/till/{$session['id']}/summary")->assertOk()->json('summary');
    $collections = collect($summary['collections'])->keyBy('code');

    expect($summary['opening_balance'])->toBe(500000)
        ->and($collections['restaurant']['amount'])->toBe($order['total'])
        ->and($collections['room_bookings']['amount'])->toBe(300000)
        ->and($collections->keys()->all())->not->toContain('apartment_rent')
        ->and($summary['collections_total'])->toBe($order['total'] + 300000)
        ->and($summary['refunds'])->toBe(['count' => 1, 'amount' => -20000])
        ->and($summary['manual_cash_in'])->toBe(['count' => 1, 'amount' => 25000])
        ->and($summary['manual_cash_out_total'])->toBe(-40000)
        // The statement has to foot: what the drawer started with plus everything
        // that moved is exactly what the close reconciles against.
        ->and($summary['opening_balance'] + $summary['net_change'])->toBe($summary['expected_balance'])
        ->and($summary['expected_balance'])->toBe(500000 + $order['total'] - 20000 + 300000 + 25000 - 40000);
});
