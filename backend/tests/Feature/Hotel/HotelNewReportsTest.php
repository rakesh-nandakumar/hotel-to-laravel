<?php

use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Venue;
use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\ReservationStatus;
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

it('blocks non-manager roles from every new hotel report endpoint', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->getJson('/api/reports/revpar')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/channel-mix')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/cancellations')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/guest-loyalty')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/corporate-ar')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/ops-sla')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/payroll-cost')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/venues')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/reports/laundry')->assertForbidden();
});

it('blocks a manager from payroll-cost — owner-only action, unlike every other report', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->getJson('/api/reports/payroll-cost')->assertForbidden();
});

function checkedInStay(string $roomNumber = '102'): array
{
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', $roomNumber)->firstOrFail();
    $guest = Guest::factory()->create();
    $checkIn = today()->toDateString();
    $checkOut = today()->addDays(2)->toDateString();

    $reservation = test()->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $checkIn, 'check_out' => $checkOut,
        'adults' => 1, 'rooms' => [['room_id' => $room->id]],
    ])->assertCreated()->json('reservation');

    test()->actingAs($manager)->postJson("/api/reservations/{$reservation['id']}/check-in", [])->assertOk();

    return ['manager' => $manager, 'guest' => $guest, 'reservation' => $reservation, 'check_in' => $checkIn, 'check_out' => $checkOut];
}

it('computes RevPAR/ADR/occupancy from a checked-in stay', function () {
    ['manager' => $manager, 'reservation' => $reservation, 'check_in' => $checkIn, 'check_out' => $checkOut] = checkedInStay();
    $nightlyRate = $reservation['rooms'][0]['nightly_rate'];
    $roomRevenue = $nightlyRate * 2; // 2 nights, posted at check-in

    $response = $this->actingAs($manager)->getJson("/api/reports/revpar?from={$checkIn}&to=".today()->addDay()->toDateString())->assertOk();

    expect($response->json('room_nights_sold'))->toBe(2)
        ->and($response->json('room_revenue'))->toBe($roomRevenue)
        ->and($response->json('adr'))->toBe((int) round($roomRevenue / 2))
        ->and($response->json('revpar'))->toBe((int) round($roomRevenue / $response->json('available_room_nights')));
});

it('computes booking channel mix from reservations on two different channels', function () {
    $manager = staffWithRole('Manager');
    $today = today()->toDateString();
    $tomorrow = today()->addDay()->toDateString();

    $walkin = Guest::factory()->create();
    $r1 = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $walkin->id, 'channel' => 'walkin', 'check_in' => $today, 'check_out' => $tomorrow,
        'adults' => 1, 'rooms' => [['room_id' => Room::query()->where('number', '102')->firstOrFail()->id]],
    ])->assertCreated()->json('reservation');
    $this->actingAs($manager)->postJson("/api/reservations/{$r1['id']}/check-in", [])->assertOk();

    $website = Guest::factory()->create();
    $r2 = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $website->id, 'channel' => 'website', 'check_in' => $today, 'check_out' => $tomorrow,
        'adults' => 1, 'rooms' => [['room_id' => Room::query()->where('number', '103')->firstOrFail()->id]],
    ])->assertCreated()->json('reservation');
    $this->actingAs($manager)->postJson("/api/reservations/{$r2['id']}/check-in", [])->assertOk();

    $response = $this->actingAs($manager)->getJson("/api/reports/channel-mix?from={$today}&to={$today}")->assertOk();

    expect($response->json('by_channel.walkin.reservations'))->toBe(1)
        ->and($response->json('by_channel.walkin.revenue'))->toBe($r1['rooms'][0]['nightly_rate'])
        ->and($response->json('by_channel.website.reservations'))->toBe(1)
        ->and($response->json('by_channel.website.revenue'))->toBe($r2['rooms'][0]['nightly_rate']);
});

it('reports cancelled reservations by reason and counts no-shows', function () {
    $manager = staffWithRole('Manager');
    $today = today()->toDateString();

    $guest = Guest::factory()->create();
    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => $today, 'check_out' => today()->addDay()->toDateString(),
        'adults' => 1, 'rooms' => [['room_id' => Room::query()->where('number', '102')->firstOrFail()->id]],
    ])->assertCreated()->json('reservation');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservation['id']}/cancel", ['reason' => 'Guest changed plans'])->assertOk();

    // No dedicated "mark no-show" endpoint exists yet — exercised directly at the model level.
    $noShowGuest = Guest::factory()->create();
    $noShowReservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $noShowGuest->id, 'channel' => 'walkin', 'check_in' => $today, 'check_out' => today()->addDay()->toDateString(),
        'adults' => 1, 'rooms' => [['room_id' => Room::query()->where('number', '103')->firstOrFail()->id]],
    ])->assertCreated()->json('reservation');
    Reservation::query()->whereKey($noShowReservation['id'])->update([
        'reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::NO_SHOW),
    ]);

    $response = $this->actingAs($manager)->getJson("/api/reports/cancellations?from={$today}&to={$today}")->assertOk();

    expect($response->json('cancelled_count'))->toBe(1)
        ->and($response->json('no_show_count'))->toBe(1)
        ->and($response->json('total_reservations'))->toBe(2)
        ->and($response->json('cancellation_rate_pct'))->toEqual(50)
        ->and($response->json('by_reason.Guest changed plans'))->toBe(1)
        ->and($response->json('cancelled.0.guest'))->toBe($guest->name);
});

it('computes guest spend, repeat rate, and loyalty points issued/redeemed', function () {
    ['manager' => $manager, 'guest' => $guest, 'reservation' => $reservation] = checkedInStay();
    $stayTotal = $reservation['rooms'][0]['nightly_rate'] * 2;

    $this->actingAs($manager)->postJson("/api/guests/{$guest->id}/loyalty-adjust", ['points' => 100, 'reason' => 'Welcome bonus'])->assertOk();
    $this->actingAs($manager)->postJson("/api/guests/{$guest->id}/loyalty-adjust", ['points' => -20, 'reason' => 'Redeemed for a discount'])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/reports/guest-loyalty?from={$today}&to={$today}")->assertOk();

    expect($response->json('distinct_guests'))->toBe(1)
        ->and($response->json('top_guests.0.name'))->toBe($guest->name)
        ->and($response->json('top_guests.0.spend'))->toBe($stayTotal)
        ->and($response->json('loyalty_points_issued'))->toBe(100)
        ->and($response->json('loyalty_points_redeemed'))->toBe(20);
});

it('computes corporate AR outstanding from corporate-credit charges minus settlements', function () {
    $manager = staffWithRole('Manager');
    $account = CorporateAccount::factory()->create(['company_name' => 'Acme Corp', 'credit_limit' => 10_000_000]);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();

    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(),
        'adults' => 1, 'rooms' => [['room_id' => $room->id]], 'corporate_account_id' => $account->id,
    ])->assertCreated()->json('reservation');
    $this->actingAs($manager)->postJson("/api/reservations/{$reservation['id']}/check-in", [])->assertOk();

    $folioId = $reservation['folio']['id'];
    $stayTotal = $reservation['rooms'][0]['nightly_rate'] * 2;
    $this->actingAs($manager)->postJson("/api/folios/{$folioId}/payments", ['method' => 'corporate_credit', 'amount' => $stayTotal])->assertCreated();

    $response = $this->actingAs($manager)->getJson('/api/reports/corporate-ar')->assertOk();
    $row = collect($response->json('accounts'))->firstWhere('id', $account->id);

    expect($row['charged'])->toBe($stayTotal)
        ->and($row['paid'])->toBe(0)
        ->and($row['balance'])->toBe($stayTotal)
        ->and($row['aging_bucket'])->toBe('0-30')
        ->and($response->json('total_outstanding'))->toBe($stayTotal);

    // Settling in full clears the balance and moves it out of every aging bucket.
    $this->actingAs($manager)->postJson("/api/corporate/{$account->id}/settle", ['amount' => $stayTotal, 'method' => 'bank_transfer'])->assertCreated();
    $afterSettle = $this->actingAs($manager)->getJson('/api/reports/corporate-ar')->assertOk();
    $rowAfter = collect($afterSettle->json('accounts'))->firstWhere('id', $account->id);
    expect($rowAfter['balance'])->toBe(0)
        ->and($rowAfter['aging_bucket'])->toBe('current');
});

it('computes housekeeping turnaround and maintenance resolution time', function () {
    $manager = staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();

    $task = $this->actingAs($manager)->postJson('/api/housekeeping/tasks', ['room_id' => $room->id])->assertCreated()->json('task');
    $doneChecklist = collect($task['checklist'])->map(fn (array $c) => ['item' => $c['item'], 'done' => true])->all();
    $this->actingAs($manager)->postJson("/api/housekeeping/tasks/{$task['id']}/complete", ['checklist' => $doneChecklist])->assertOk();

    $issue = $this->actingAs($manager)->postJson('/api/maintenance', ['room_id' => $room->id, 'description' => 'Leaky faucet'])->assertCreated()->json('issue');
    $this->actingAs($manager)->putJson("/api/maintenance/{$issue['id']}", ['status' => 'resolved', 'resolution_notes' => 'Fixed'])->assertOk();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/reports/ops-sla?from={$today}&to={$today}")->assertOk();

    expect($response->json('housekeeping.total'))->toBe(1)
        ->and($response->json('housekeeping.completed'))->toBe(1)
        ->and($response->json('housekeeping.avg_turnaround_minutes'))->toBeGreaterThanOrEqual(0)
        ->and($response->json('maintenance.total'))->toBe(1)
        ->and($response->json('maintenance.resolved'))->toBe(1)
        ->and($response->json('maintenance.avg_resolution_hours'))->toBeGreaterThanOrEqual(0);
});

it('computes payroll labor cost for a month, owner-only', function () {
    $owner = staffWithRole('Owner');
    $staff = staffWithRole('Chef');
    $staff->update(['base_salary' => 5_000_000, 'ot_hourly_rate' => 50000, 'monthly_allowance' => 200000]);

    $run = $this->actingAs($owner)->postJson('/api/payroll/runs', ['month' => now()->format('Y-m')])->assertCreated()->json('run');
    $line = collect($run['lines'])->firstWhere('user_id', $staff->id);

    $response = $this->actingAs($owner)->getJson('/api/reports/payroll-cost?month='.now()->format('Y-m'))->assertOk();

    expect($response->json('found'))->toBeTrue()
        ->and($response->json('staff_count'))->toBe(count($run['lines']))
        ->and($response->json('totals.gross'))->toBe(collect($run['lines'])->sum('gross'))
        ->and($response->json('totals.net_pay'))->toBe(collect($run['lines'])->sum('net_pay'));
    expect(collect($response->json('by_staff'))->firstWhere('user', $staff->name)['gross'])->toBe($line['gross']);
});

it('reports venue bookings and revenue over a date range', function () {
    $manager = staffWithRole('Manager');
    $venue = Venue::create([
        'name' => 'Grand Ballroom', 'max_capacity' => 200, 'facilities' => ['Stage'],
        'hourly_rate' => 500000, 'half_day_rate' => 2000000, 'full_day_rate' => 3500000,
    ]);
    $eventDate = today()->addDays(10)->toDateString();

    $this->actingAs($manager)->postJson('/api/venues/bookings', [
        'venue_id' => $venue->id, 'client_name' => 'Silva Wedding', 'date' => $eventDate,
        'duration_type' => 'full_day', 'guest_count' => 150,
    ])->assertCreated();

    $response = $this->actingAs($manager)->getJson("/api/reports/venues?from={$eventDate}&to={$eventDate}")->assertOk();

    expect($response->json('total_bookings'))->toBe(1)
        ->and($response->json('by_venue.Grand Ballroom.bookings'))->toBe(1)
        ->and($response->json('total_guest_count'))->toBe(150);
});

it('computes laundry revenue by item, recovering the item name from the folio-line description', function () {
    ['manager' => $manager] = checkedInStay();
    $room = Room::query()->where('number', '102')->firstOrFail();

    $towel = $this->actingAs($manager)->postJson('/api/laundry/items', ['name' => 'Towel', 'price' => 5000])->assertCreated()->json('laundry_item');
    $this->actingAs($manager)->postJson('/api/laundry/charge', [
        'room_id' => $room->id, 'items' => [['laundry_item_id' => $towel['id'], 'qty' => 3]],
    ])->assertCreated();

    $today = today()->toDateString();
    $response = $this->actingAs($manager)->getJson("/api/reports/laundry?from={$today}&to={$today}")->assertOk();

    expect($response->json('total_revenue'))->toBe(15000)
        ->and($response->json('total_items'))->toEqual(3)
        ->and($response->json('by_item.Towel.qty'))->toEqual(3)
        ->and($response->json('by_item.Towel.amount'))->toBe(15000);
});
