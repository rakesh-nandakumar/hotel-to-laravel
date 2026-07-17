<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Room;
use App\Services\Settings;
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

function posMenuItem(int $stock = 5000): MenuItem
{
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => $stock, 'low_stock_threshold' => 500]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    return $item;
}

it('blocks non-manager and non-chef roles from orders entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/orders')->assertForbidden();
});

it('creates a walk-in order, deducts recipe stock, and computes totals', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();
    Settings::set('billing.service_charge_pct', 10);
    Settings::set('billing.vat_pct', 10);

    $response = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in',
        'items' => [['menu_item_id' => $item->id, 'qty' => 2]],
    ])->assertCreated();

    // subtotal 200000, sc 10% = 20000, vat 10% of 220000 = 22000, total 242000
    expect($response->json('order.subtotal'))->toBe(200000)
        ->and($response->json('order.service_charge'))->toBe(20000)
        ->and($response->json('order.vat'))->toBe(22000)
        ->and($response->json('order.total'))->toBe(242000);

    expect($item->recipe()->first()->ingredient->fresh()->stock_qty)->toBe(4500.0);
});

it('waives service charge for takeaway orders but still applies VAT', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();
    Settings::set('billing.service_charge_pct', 10);
    Settings::set('billing.vat_pct', 10);

    $response = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'takeaway',
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();

    expect($response->json('order.service_charge'))->toBe(0)
        ->and($response->json('order.vat'))->toBe(10000);
});

it('rejects an order needing more stock than available and marks the item sold out', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem(stock: 100); // needs 250g per portion

    $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items');

    expect($item->fresh()->sold_out)->toBeTrue()
        ->and($item->recipe()->first()->ingredient->fresh()->stock_qty)->toBe(100.0); // rolled back
});

it('voids an item while NEW (restocking) but blocks voiding while preparing', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();
    $orderId = $created->json('order.id');
    $itemId = $created->json('order.items.0.id');

    $this->actingAs($manager)->putJson("/api/orders/{$orderId}/kot", ['status' => 'preparing'])->assertOk();

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/items/{$itemId}/void", ['reason' => 'Changed mind'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('item');

    $this->actingAs($manager)->putJson("/api/orders/{$orderId}/kot", ['status' => 'new'])->assertOk();

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/items/{$itemId}/void", ['reason' => 'Changed mind'])
        ->assertOk();

    expect($item->recipe()->first()->ingredient->fresh()->stock_qty)->toBe(5000.0); // restocked
});

it('applies a percentage discount and recomputes the total', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();
    $orderId = $created->json('order.id');

    $response = $this->actingAs($manager)->putJson("/api/orders/{$orderId}/discount", [
        'mode' => 'PCT', 'value' => 10, 'reason' => 'Loyal customer',
    ])->assertOk();

    expect($response->json('order.discount'))->toBe(10000)
        ->and($response->json('order.total'))->toBe(90000);
});

it('settles an order only when split payments sum exactly to the total', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();
    $orderId = $created->json('order.id');
    $total = $created->json('order.total');

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $total - 100]],
    ])->assertUnprocessable()->assertJsonValidationErrors('payments');

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $total - 30000], ['method' => 'card', 'amount' => 30000]],
    ])->assertOk()->assertJsonPath('order.status.code', 'settled');
});

it('charges a room-guest order to the folio without double-taxing it at reservation checkout', function () {
    $manager = staffWithRole('Manager');
    $this->seed(HotelRoomsSeeder::class);
    Settings::set('billing.service_charge_pct', 10);
    Settings::set('billing.vat_pct', 10);

    $item = posMenuItem();
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();

    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $reservation->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'room_guest', 'room_id' => $room->id,
        'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();
    // subtotal 100000, sc 10% = 10000, vat 10% of 110000 = 11000, order total 121000
    $orderId = $order->json('order.id');
    expect($order->json('order.total'))->toBe(121000);

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/charge-to-room", [])
        ->assertOk()
        ->assertJsonPath('order.status.code', 'charged_to_room');

    // Room total for 2 nights at LKR 12,000/night = 2,400,000 + order's 121,000 = 2,521,000 base
    // for the folio's own SC/VAT — but the order's own SC/VAT lines must NOT be re-taxed.
    $checkoutPayment = 2_400_000; // room-only base, taxed at folio level
    $folioTax = round($checkoutPayment * 0.10) + round(($checkoutPayment + round($checkoutPayment * 0.10)) * 0.10);
    $expectedGrandTotal = 2_400_000 + $folioTax + 121000; // room+its tax, plus the order's own already-taxed total

    $checkout = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => (int) $expectedGrandTotal]],
    ])->assertOk();

    expect($checkout->json('total'))->toBe((int) $expectedGrandTotal);
});

it('voids an order with no payments, restocking if still NEW', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();
    $orderId = $created->json('order.id');

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/void", ['reason' => 'Guest left'])
        ->assertOk()
        ->assertJsonPath('restocked', true);

    expect($item->recipe()->first()->ingredient->fresh()->stock_qty)->toBe(5000.0);
});

it('refunds a settled order, capped at the amount paid', function () {
    $manager = staffWithRole('Manager');
    $item = posMenuItem();

    $created = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated();
    $orderId = $created->json('order.id');
    $total = $created->json('order.total');

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $total]],
    ])->assertOk();

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/refund", [
        'amount' => $total + 1, 'method' => 'cash', 'reason' => 'test',
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->actingAs($manager)->postJson("/api/orders/{$orderId}/refund", [
        'amount' => $total, 'method' => 'cash', 'reason' => 'Order cancelled after payment',
    ])->assertCreated();
});
