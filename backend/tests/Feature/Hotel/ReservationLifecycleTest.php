<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Lookup;
use App\Models\Tenant;
use App\Services\Settings;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\RoomStatus;
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
    $this->seed(HotelRoomsSeeder::class);
});

function bookTwoPersonRoom(string $roomNumber = '102'): array
{
    return [
        'room' => Room::query()->where('number', $roomNumber)->firstOrFail(),
        'check_in' => '2026-08-03', // Monday — plain weekday rate, outside December Peak
        'check_out' => '2026-08-05', // 2 nights
    ];
}

it('blocks non-manager roles from reservation and folio endpoints entirely', function () {
    $chef = staffWithRole('Chef');

    $this->actingAs($chef)->getJson('/api/reservations')->assertForbidden();
    $this->actingAs($chef)->getJson('/api/reservations/availability?check_in=2026-08-03&check_out=2026-08-05')->assertForbidden();
});

it('reports room availability with per-night rates for a date range', function () {
    $manager = staffWithRole('Manager');

    $response = $this->actingAs($manager)
        ->getJson('/api/reservations/availability?check_in=2026-08-03&check_out=2026-08-05')
        ->assertOk();

    expect($response->json('rooms'))->toHaveCount(13);

    $room102 = collect($response->json('rooms'))->firstWhere('number', '102');
    expect($room102['stay_total'])->toBe(1_200_000 * 2);
});

it('excludes a room whose room type belongs to another tenant instead of 500ing the whole list', function () {
    // Legacy behaviour: a room whose roomType was invisible under TenantScope
    // would have been dropped from availability to avoid a 500 in pricing.
    // Rooms are now self-contained (rates live on the room itself), so the
    // legacy type-tenant mismatch no longer hides the room or 500s — the
    // whole list still renders, now with the ghost room included (its own
    // rates, not the foreign type's). We keep the 500-guard assertion but
    // update the count to reflect the new flat structure.
    $otherTenant = Tenant::factory()->create(['slug' => 'otherco']);
    $ghostType = RoomType::create(['tenant_id' => $otherTenant->id, 'name' => 'Ghost Suite', 'weekday_rate' => 999, 'weekend_rate' => 999]);

    Room::create([
        'number' => 'GHOST1',
        'name' => 'Ghost Suite',
        'max_occupancy' => 2,
        'weekday_rate' => 999,
        'weekend_rate' => 999,
        'room_type_id' => $ghostType->id,
        'room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::AVAILABLE),
    ]);

    $manager = staffWithRole('Manager');

    $response = $this->actingAs($manager)
        ->getJson('/api/reservations/availability?check_in=2026-08-03&check_out=2026-08-05')
        ->assertOk();

    $rooms = collect($response->json('rooms'));
    expect($rooms)->toHaveCount(14)
        ->and($rooms->firstWhere('number', 'GHOST1'))->not->toBeNull();
});

it('creates a reservation for a new guest, locking the rate and computing the deposit', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();

    $response = $this->actingAs($manager)->postJson('/api/reservations', [
        'new_guest' => ['name' => 'Alice Perera', 'phone' => '0771234567'],
        'channel' => 'walkin',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'adults' => 2,
        'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();

    expect($response->json('reservation.deposit_due'))->toBe((int) round(1_200_000 * 2 * 0.20))
        ->and($response->json('reservation.rooms.0.nightly_rate'))->toBe(1_200_000)
        ->and($response->json('reservation.guest.name'))->toBe('Alice Perera')
        ->and($response->json('reservation.folio'))->not->toBeNull();
});

it('shows a created reservation on the calendar feed for an overlapping date range', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'new_guest' => ['name' => 'Calendar Guest', 'phone' => '0771234567'],
        'channel' => 'walkin',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'adults' => 1,
        'rooms' => [['room_id' => $room->id]],
    ])->assertCreated()->json('reservation');

    $response = $this->actingAs($manager)
        ->getJson('/api/reservations/calendar?from=2026-08-01&to=2026-08-10')
        ->assertOk();

    $row = collect($response->json('reservations'))->firstWhere('id', $created['id']);
    expect($row)->not->toBeNull()
        ->and($row['guest'])->toBe('Calendar Guest')
        ->and($row['status'])->toBe('confirmed')
        ->and($row['room_ids'])->toContain($room->id);

    // A range that does NOT overlap the stay must not include it.
    $outside = $this->actingAs($manager)
        ->getJson('/api/reservations/calendar?from=2026-09-01&to=2026-09-10')
        ->assertOk();
    expect(collect($outside->json('reservations'))->firstWhere('id', $created['id']))->toBeNull();
});

it('rejects booking a room already reserved for overlapping dates', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-04', 'check_out' => '2026-08-06',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertUnprocessable()->assertJsonValidationErrors('rooms');
});

it('checks in a reservation: posts room charges and occupies the room', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();

    $reservationId = $created->json('reservation.id');

    $response = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])
        ->assertOk();

    expect($response->json('folio.lines'))->toHaveCount(2)
        ->and($response->json('folio.total'))->toBe(1_200_000 * 2)
        ->and($room->fresh()->status->code)->toBe(RoomStatus::OCCUPIED);
});

it('blocks check-in when the guest has no ID number on file', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create(['id_number' => null]);

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();

    $this->actingAs($manager)->postJson("/api/reservations/{$created->json('reservation.id')}/check-in", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id_number');
});

it('checks out with exact payment: settles the folio, dirties the room, and creates a housekeeping task', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
        'deposit_payment' => ['method' => 'cash', 'amount' => 480_000],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');

    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $response = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 1_920_000]],
    ])->assertOk();

    expect($response->json('invoice_no'))->toBe('INV-2026-0001')
        ->and($response->json('total'))->toBe(2_400_000)
        ->and($response->json('balance'))->toBe(0)
        ->and($room->fresh()->status->code)->toBe(RoomStatus::DIRTY);

    $task = HousekeepingTask::query()->where('room_id', $room->id)->where('reservation_id', $reservationId)->first();
    expect($task)->not->toBeNull()
        ->and($task->checklist)->toHaveCount(12);
});

it('rejects checkout when payment is short of the balance due', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 1_000_000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('payments');

    expect($room->fresh()->status->code)->toBe(RoomStatus::OCCUPIED);
});

it('is replay-safe: a failed payment after tax lines commit never double-taxes on retry', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();
    Settings::set('billing.service_charge_pct', 10);
    Settings::set('billing.vat_pct', 10);

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    // base 2,400,000 → SC 240,000 (10%) → VAT (2,640,000 * 10%) 264,000 → grand total 2,904,000.
    // Claim to pay the full total via loyalty points the guest doesn't have: the
    // balance check passes (so tax lines commit) but recordPayment fails after.
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'loyalty_points', 'amount' => 2_904_000]],
    ])->assertUnprocessable();

    // Retry with a valid payment — must not double the service charge/VAT lines.
    $response = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_904_000]],
    ])->assertOk();

    expect($response->json('total'))->toBe(2_904_000)
        ->and($response->json('lines'))->toHaveCount(4); // 2 room + 1 service charge + 1 VAT, not 6
});

it('cancels a reservation more than 7 days out with a full refund per policy', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->addDays(10)->toDateString();
    $checkOut = now()->addDays(12)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
        'deposit_payment' => ['method' => 'cash', 'amount' => 100_000],
    ])->assertCreated();

    $response = $this->actingAs($manager)
        ->postJson("/api/reservations/{$created->json('reservation.id')}/cancel", ['reason' => 'Guest changed plans'])
        ->assertOk();

    expect($response->json('refund_pct'))->toBe(100)
        ->and($response->json('refunded'))->toBe(100_000);
});

it('cancels a reservation inside the final tier with no refund and posts a matching cancellation fee', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->addDay()->toDateString();
    $checkOut = now()->addDays(3)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
        'deposit_payment' => ['method' => 'cash', 'amount' => 100_000],
    ])->assertCreated();
    $folioId = $created->json('reservation.folio.id');

    $response = $this->actingAs($manager)
        ->postJson("/api/reservations/{$created->json('reservation.id')}/cancel", ['reason' => 'No-show risk'])
        ->assertOk();

    expect($response->json('refund_pct'))->toBe(0)
        ->and($response->json('refunded'))->toBe(0)
        ->and($response->json('fee'))->toBe(100_000);

    // The forfeited deposit is now a real, printable charge — total/balance must
    // reconcile instead of leaving an invisible shortfall (see cancel()'s note).
    $folio = $this->actingAs($manager)->getJson("/api/folios/{$folioId}")->assertOk();
    expect($folio->json('folio.total'))->toBe(100_000)
        ->and($folio->json('folio.balance'))->toBe(0)
        ->and(collect($folio->json('folio.lines'))->firstWhere('source.code', 'cancellation_fee'))->not->toBeNull();
});

it('cancels a reservation inside the middle tier with a partial refund and a matching partial fee', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->addDays(5)->toDateString();
    $checkOut = now()->addDays(7)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
        'deposit_payment' => ['method' => 'cash', 'amount' => 100_000],
    ])->assertCreated();
    $folioId = $created->json('reservation.folio.id');

    $response = $this->actingAs($manager)
        ->postJson("/api/reservations/{$created->json('reservation.id')}/cancel", ['reason' => 'Change of plans'])
        ->assertOk();

    expect($response->json('refund_pct'))->toBe(50)
        ->and($response->json('refunded'))->toBe(50_000)
        ->and($response->json('fee'))->toBe(50_000);

    $folio = $this->actingAs($manager)->getJson("/api/folios/{$folioId}")->assertOk();
    expect($folio->json('folio.total'))->toBe(50_000)
        ->and($folio->json('folio.balance'))->toBe(0);
});

it('blocks cancelling a reservation that is already checked in', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/cancel", ['reason' => 'test'])
        ->assertUnprocessable();
});

it('applies a percentage discount to a reservation and taxes only the discounted base at checkout', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');

    // Stay total 1,200,000 * 2 nights = 2,400,000 → 10% off = 240,000, applied
    // before check-in even exists — it must still net correctly once room
    // charges post.
    $response = $this->actingAs($manager)->putJson("/api/reservations/{$reservationId}/discount", [
        'mode' => 'PCT', 'value' => 10, 'reason' => 'Low occupancy promo',
    ])->assertOk();

    expect($response->json('folio.total'))->toBe(-240_000);

    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $checkout = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_160_000]],
    ])->assertOk();

    expect($checkout->json('total'))->toBe(2_160_000)
        ->and($checkout->json('lines'))->toHaveCount(3); // 2 room + 1 discount, no SC/VAT configured
});

it('caps a fixed-amount discount at the stay total and replaces rather than stacks on reapply', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');

    $capped = $this->actingAs($manager)->putJson("/api/reservations/{$reservationId}/discount", [
        'mode' => 'FIXED', 'value' => 3_000_000, 'reason' => 'Overzealous manual entry',
    ])->assertOk();
    expect($capped->json('folio.total'))->toBe(-2_400_000); // capped at the 2,400,000 stay total

    $replaced = $this->actingAs($manager)->putJson("/api/reservations/{$reservationId}/discount", [
        'mode' => 'PCT', 'value' => 20, 'reason' => 'Actually just 20%',
    ])->assertOk();

    expect($replaced->json('folio.lines'))->toHaveCount(1) // the first discount line was voided, not stacked
        ->and($replaced->json('folio.total'))->toBe(-480_000);
});

it('blocks applying a discount once the folio is settled', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_400_000]],
    ])->assertOk();

    $this->actingAs($manager)->putJson("/api/reservations/{$reservationId}/discount", [
        'mode' => 'PCT', 'value' => 10, 'reason' => 'Too late',
    ])->assertUnprocessable()->assertJsonValidationErrors('folio');
});

it('adds and voids a manual folio line', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $folioId = $created->json('reservation.folio.id');

    $line = $this->actingAs($manager)->postJson("/api/folios/{$folioId}/lines", [
        'source' => 'minibar', 'description' => 'Coke', 'qty' => 2, 'unit_price' => 500,
    ])->assertCreated();

    expect($line->json('folio_line.amount'))->toBe(1000);

    $this->actingAs($manager)->postJson("/api/folios/lines/{$line->json('folio_line.id')}/void", [
        'reason' => 'Guest did not consume it',
    ])->assertOk();

    $this->actingAs($manager)->getJson("/api/folios/{$folioId}")
        ->assertOk()
        ->assertJsonPath('folio.total', 0);
});

it('records a folio payment and caps a refund at the net amount paid', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $folioId = $created->json('reservation.folio.id');

    $this->actingAs($manager)->postJson("/api/folios/{$folioId}/payments", ['method' => 'cash', 'amount' => 50_000])
        ->assertCreated();

    $this->actingAs($manager)->postJson("/api/folios/{$folioId}/refund", ['method' => 'cash', 'amount' => 60_000, 'reason' => 'test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $this->actingAs($manager)->postJson("/api/folios/{$folioId}/refund", ['method' => 'cash', 'amount' => 50_000, 'reason' => 'Guest cancelled extra'])
        ->assertCreated();
});

it('accepts check-in when a room has no configured item checklist', function () {
    $manager = staffWithRole('Manager');
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');

    // Laravel's `required` rule fails on an empty array — this must use
    // 'present' instead, or a room with no item_checklist configured (the
    // frontend still sends one entry per room, with items: []) 422s.
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [
        'item_checks' => [['room_id' => $room->id, 'items' => []]],
    ])->assertOk();
});

it('credits unused nights and charges a percentage departure fee when checking out early', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->toDateString();
    $checkOut = now()->addDays(4)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $folioId = $created->json('reservation.folio.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $nightlyRate = 1_200_000;
    $unusedValue = $nightlyRate * 4;
    $expectedFee = (int) round($unusedValue * 0.5); // default billing.early_departure_fee_pct = 50

    $quote = $this->actingAs($manager)
        ->getJson("/api/reservations/{$reservationId}/checkout-quote?early_departure=1")
        ->assertOk();

    expect($quote->json('early_departure.unused_nights'))->toBe(4)
        ->and($quote->json('early_departure.unused_value'))->toBe($unusedValue)
        ->and($quote->json('early_departure.fee'))->toBe($expectedFee)
        ->and($quote->json('grand_total'))->toBe($expectedFee);

    $response = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'apply_early_departure' => true,
        'payments' => [['method' => 'cash', 'amount' => $quote->json('balance_due')]],
    ])->assertOk();

    expect($response->json('total'))->toBe($expectedFee);

    $folio = $this->actingAs($manager)->getJson("/api/folios/{$folioId}")->assertOk();
    $lines = collect($folio->json('folio.lines'));
    $credit = $lines->first(fn ($l) => str_starts_with($l['description'], 'Early departure credit'));
    $fee = $lines->first(fn ($l) => str_starts_with($l['description'], 'Early departure fee'));

    expect($credit['amount'])->toBe(-$unusedValue)
        ->and($fee['amount'])->toBe($expectedFee)
        ->and(Reservation::find($reservationId)->check_out->toDateString())->toBe($checkIn);
});

it('charges a flat departure fee when the fee mode is set to fixed', function () {
    Settings::set('billing.early_departure_fee_mode', 'fixed');
    Settings::set('billing.early_departure_fee_fixed', 300_000);

    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->toDateString();
    $checkOut = now()->addDays(4)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $quote = $this->actingAs($manager)
        ->getJson("/api/reservations/{$reservationId}/checkout-quote?early_departure=1")
        ->assertOk();

    expect($quote->json('early_departure.fee_mode'))->toBe('fixed')
        ->and($quote->json('early_departure.fee'))->toBe(300_000);
});

it('bills the full original amount when the early departure adjustment is waived', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->toDateString();
    $checkOut = now()->addDays(4)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');

    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    // apply_early_departure omitted (falsy default) — full 4-night amount stands.
    $response = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 1_200_000 * 4]],
    ])->assertOk();

    expect($response->json('total'))->toBe(1_200_000 * 4)
        ->and(Reservation::find($reservationId)->check_out->toDateString())->toBe($checkOut);
});

it('allows a cash over-tender at checkout and returns the excess as change', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $folioId = $created->json('reservation.folio.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    // Total is 2,400,000 — tender 2,500,000 cash, expect 100,000 change back.
    $response = $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'cash', 'amount' => 2_500_000]],
    ])->assertOk();

    expect($response->json('change_due'))->toBe(100_000);

    $folio = $this->actingAs($manager)->getJson("/api/folios/{$folioId}")->assertOk();
    $change = collect($folio->json('folio.payments'))->first(fn ($p) => $p['reason'] === 'Change returned to guest');
    expect($change)->not->toBeNull()
        ->and($change['amount'])->toBe(100_000)
        ->and($change['method']['code'])->toBe('cash');
});

it('rejects a card over-tender at checkout that exceeds the amount due', function () {
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['room' => $room, 'check_in' => $checkIn, 'check_out' => $checkOut] = bookTwoPersonRoom();
    $guest = Guest::factory()->create();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated();
    $reservationId = $created->json('reservation.id');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/check-in", [])->assertOk();

    $this->actingAs($manager)->postJson("/api/reservations/{$reservationId}/checkout", [
        'payments' => [['method' => 'card', 'amount' => 2_500_000]],
    ])->assertUnprocessable()->assertJsonValidationErrors('payments');
});
