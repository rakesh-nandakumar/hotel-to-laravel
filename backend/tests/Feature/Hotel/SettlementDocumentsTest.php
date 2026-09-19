<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Room;
use Database\Seeders\HotelRoomsSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

/*
 * The printed invoice / receipt settlement block: what the guest handed
 * over, what came back as change, and what (if anything) is still owed.
 * Rendered as the HTML the print path uses (?output=html) so the text can
 * be asserted on — the dompdf path is covered by ReportsAndNightAuditTest.
 */

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(HotelRoomsSeeder::class);
});

/** Collapses the rendered document to one whitespace-normalised text line so label/amount pairs can be matched in order. */
function documentText(string $html): string
{
    $body = preg_replace('/<style\b[^>]*>.*?<\/style>/s', '', $html);

    return trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
}

it('prints cash tendered, change returned and a zero balance on an over-tendered stay invoice', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();

    // 2 weekday nights at 12,000.00 = 24,000.00. Deposit 5,000.00 up front,
    // then 20,000.00 cash handed over at checkout against the 19,000.00 due.
    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
        'deposit_payment' => ['method' => 'cash', 'amount' => 500_000],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $folioId = $created->json('reservation.folio.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_000_000]],
    ])->assertOk()->assertJsonPath('change_due', 100_000);

    foreach (['a4', 'thermal'] as $format) {
        $text = documentText($this->actingAs($manager)->get("/api/folios/{$folioId}/invoice?format={$format}&output=html")->assertOk()->getContent());

        expect($text)
            ->toContain('TOTAL (LKR) 24,000.00')
            ->toContain('Deposit — CASH · '.now()->format('d/m/Y').' 5,000.00')
            ->toContain('Cash tendered · '.now()->format('d/m/Y').' 20,000.00')
            ->toContain('TOTAL RECEIVED 25,000.00')
            ->toContain('CHANGE RETURNED — CASH -1,000.00')
            ->toContain('NET PAID 24,000.00')
            ->toContain('BALANCE 0.00')
            ->not->toContain('TENDERED 25,000.00');
    }
});

it('keeps a single exact payment to one line and no change block', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $folioId = $created->json('reservation.folio.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'card', 'amount' => 2_400_000, 'reference' => 'SLIP-77']],
    ])->assertOk();

    $text = documentText($this->actingAs($manager)->get("/api/folios/{$folioId}/invoice?output=html")->assertOk()->getContent());

    expect($text)
        ->toContain('TOTAL (LKR) 24,000.00')
        ->toContain('Paid — CARD (SLIP-77) · '.now()->format('d/m/Y').' 24,000.00')
        ->toContain('BALANCE 0.00')
        ->not->toContain('TOTAL RECEIVED')
        ->not->toContain('CHANGE RETURNED')
        ->not->toContain('NET PAID');
});

it('prints cash tendered and change returned on an over-tendered POS receipt', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $category = MenuCategory::create(['name' => 'Mains']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'g', 'stock_qty' => 5000, 'low_stock_threshold' => 500]);
    $item = MenuItem::create(['name' => 'Fried Rice', 'menu_category_id' => $category->id, 'price' => 100_000]);
    $item->recipe()->create(['ingredient_id' => $rice->id, 'qty' => 250]);

    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'takeaway', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    expect($order['total'])->toBe(100_000);

    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => 150_000]],
    ])->assertOk();

    $text = documentText($this->actingAs($manager)->get("/api/orders/{$order['id']}/receipt?output=html")->assertOk()->getContent());

    expect($text)
        ->toContain('TOTAL (LKR) 1,000.00')
        ->toContain('Cash tendered · '.now()->format('d/m/Y').' 1,500.00')
        // The one tender is the total received — no subtotal repeating it.
        ->not->toContain('TOTAL RECEIVED')
        ->toContain('CHANGE RETURNED — CASH -500.00')
        ->toContain('NET PAID 1,000.00')
        ->toContain('BALANCE 0.00');
});
