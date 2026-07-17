<?php

use App\Models\Hotel\Guest;
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
    $this->seed(BranchSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(HotelRoomsSeeder::class);
});

it('blocks non-manager roles from reports entirely', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/reports/dashboard')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/daily')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/monthly')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/pos')->assertForbidden();
    $this->actingAs($housekeeper)->postJson('/api/reports/night-audit/run')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/night-audit')->assertForbidden();
});

it('computes the daily report from a checked-out reservation and a settled walk-in order', function () {
    $manager = staffWithRole('Manager');
    Settings::set('billing.service_charge_pct', 10);
    Settings::set('billing.vat_pct', 10);

    // Room stay: base 2,400,000 -> SC 240,000 -> VAT 264,000 -> total 2,904,000 (same math as the reservation checkout regression test).
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();
    $reservationId = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated()->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_904_000]],
    ])->assertOk();

    // Walk-in POS order: subtotal 100,000 -> SC 10,000 -> VAT 11,000 -> total 121,000.
    $item = posMenuItem();
    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => 121000]],
    ])->assertOk();

    $response = $this->actingAs($manager)->getJson('/api/reports/daily')->assertOk();

    // Walk-in orders never post folio lines (no folio exists for a walk-in) — their
    // SC/VAT only ever show up via walkin_pos_revenue/payments, never revenue_by_source.
    expect($response->json('revenue_by_source.room'))->toBe(2_400_000)
        ->and($response->json('revenue_by_source.service_charge'))->toBe(240000)
        ->and($response->json('revenue_by_source.vat'))->toBe(264000)
        ->and($response->json('walkin_pos_revenue'))->toBe(121000)
        ->and($response->json('total_charges_posted'))->toBe(3_025_000)
        ->and($response->json('payments.by_method.cash'))->toBe(3_025_000)
        ->and($response->json('payments.collected'))->toBe(3_025_000)
        ->and($response->json('payments.net'))->toBe(3_025_000)
        ->and($response->json('cash_collected'))->toBe(3_025_000)
        ->and($response->json('pos.by_category.Mains'))->toBe(100000)
        ->and($response->json('pos.best_sellers.0.name'))->toBe('Fried Rice')
        ->and($response->json('pos.order_count'))->toBe(1);
});

it('runs a night audit once per business date and blocks a duplicate run', function () {
    $manager = staffWithRole('Manager');

    $first = $this->actingAs($manager)->postJson('/api/reports/night-audit/run', ['date' => '2026-07-10'])
        ->assertCreated();
    expect($first->json('business_date'))->toStartWith('2026-07-10');

    $this->actingAs($manager)->postJson('/api/reports/night-audit/run', ['date' => '2026-07-10'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    $list = $this->actingAs($manager)->getJson('/api/reports/night-audit')->assertOk();
    expect($list->json('night_audits'))->toHaveCount(1);
});

it('renders every report and document PDF endpoint successfully', function () {
    $manager = staffWithRole('Manager');
    $owner = staffWithRole('Owner');

    // POS order (thermal receipt, walk-in slip, KOT ticket).
    $item = posMenuItem();
    $order = $this->actingAs($manager)->postJson('/api/orders', [
        'type' => 'walkin', 'dining_mode' => 'dine_in', 'items' => [['menu_item_id' => $item->id, 'qty' => 1]],
    ])->assertCreated()->json('order');
    $this->actingAs($manager)->postJson("/api/orders/{$order['id']}/settle", [
        'payments' => [['method' => 'cash', 'amount' => $order['total']]],
    ])->assertOk();

    foreach (['/api/orders/'.$order['id'].'/receipt', '/api/orders/'.$order['id'].'/receipt?format=a4', '/api/orders/'.$order['id'].'/slip', '/api/orders/'.$order['id'].'/kot-ticket'] as $url) {
        $pdf = $this->actingAs($manager)->get($url)->assertSuccessful();
        expect($pdf->headers->get('content-type'))->toContain('application/pdf');
    }

    // Guest-stay folio invoice.
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();
    $reservationId = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated()->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();
    $checkoutResponse = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_400_000]],
    ])->assertOk();
    $folioId = $checkoutResponse->json('id');

    foreach (['/api/folios/'.$folioId.'/invoice', '/api/folios/'.$folioId.'/invoice?format=thermal'] as $url) {
        $pdf = $this->actingAs($manager)->get($url)->assertSuccessful();
        expect($pdf->headers->get('content-type'))->toContain('application/pdf');
    }

    // Payslip (owner-only).
    $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->assertCreated()->json('run');
    $line = $run['lines'][0];
    $this->actingAs($owner)->get("/api/payroll/lines/{$line['id']}/payslip")->assertSuccessful();
    $this->actingAs($manager)->get("/api/payroll/lines/{$line['id']}/payslip")->assertForbidden();

    // Report PDFs.
    $this->actingAs($manager)->get('/api/reports/daily/pdf')->assertSuccessful();
    $this->actingAs($manager)->get('/api/reports/monthly/pdf')->assertSuccessful();
    $this->actingAs($manager)->get('/api/reports/pos/pdf')->assertSuccessful();

    $nightAudit = $this->actingAs($manager)->postJson('/api/reports/night-audit/run')->assertCreated()->json();
    $this->actingAs($manager)->get("/api/reports/night-audit/{$nightAudit['id']}/pdf")->assertSuccessful();
});
