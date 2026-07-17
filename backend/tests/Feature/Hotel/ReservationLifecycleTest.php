<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Room;
use App\Services\Settings;
use App\Support\Lookups\RoomStatus;
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

it('cancels a reservation inside the final tier with no refund', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = now()->addDay()->toDateString();
    $checkOut = now()->addDays(3)->toDateString();

    $created = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
        'deposit_payment' => ['method' => 'cash', 'amount' => 100_000],
    ])->assertCreated();

    $response = $this->actingAs($manager)
        ->postJson("/api/reservations/{$created->json('reservation.id')}/cancel", ['reason' => 'No-show risk'])
        ->assertOk();

    expect($response->json('refund_pct'))->toBe(0)
        ->and($response->json('refunded'))->toBe(0);
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
