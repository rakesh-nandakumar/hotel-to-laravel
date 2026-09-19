<?php

use App\Models\Hotel\Guest;
use App\Models\Hotel\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings;
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

const GROUP_LINK = 'https://chat.whatsapp.com/AbCdEfGh123';

function shareLinkConfigured(string $link = GROUP_LINK): void
{
    Settings::set('notifications.whatsapp_group_link', $link, null, Tenant::demo()->id);
}

function confirmedBooking(array $overrides = [], ?User $manager = null): array
{
    $manager ??= staffWithRole('Manager');
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create(['name' => 'Nimal Perera', 'phone' => '+94 77 123 4567']);

    $response = test()->actingAs($manager)->postJson('/api/reservations', array_merge([
        'guest_id' => $guest->id, 'channel' => 'walkin',
        'check_in' => '2026-08-03', 'check_out' => '2026-08-05', // Mon → Wed, 2 weekday nights
        'adults' => 2, 'children' => 1, 'rooms' => [['room_id' => $room->id]],
        'notes' => 'Arriving late, airport pickup',
    ], $overrides))->assertCreated();

    return ['manager' => $manager, 'id' => $response->json('reservation.id'), 'code' => $response->json('reservation.code')];
}

it('composes the booking summary and returns the configured group link', function () {
    shareLinkConfigured();
    ['manager' => $manager, 'id' => $id, 'code' => $code] = confirmedBooking();

    $response = $this->actingAs($manager)->getJson("/api/reservations/{$id}/whatsapp-message")->assertOk();

    expect($response->json('group_link'))->toBe(GROUP_LINK);

    $message = $response->json('message');
    expect($message)
        ->toStartWith("*Booking {$code} — CONFIRMED*")
        ->toContain('Guest: Nimal Perera · +94 77 123 4567')
        ->toContain('Rooms: 102')
        ->toContain('Check-in: Mon 03 Aug 2026 (from 14:00)')
        ->toContain('Check-out: Wed 05 Aug 2026 (by 12:00)')
        ->toContain('Nights: 2 · Guests: 2 adults, 1 child')
        ->toContain('Package: Room only')
        ->toContain('Channel: Walk-in')
        // Nothing has posted to the folio yet, so the total is projected from
        // the booked nightly rate (12,000.00 × 2) and the 20% deposit is due.
        ->toContain('Stay total: LKR 24,000.00')
        ->toContain('Deposit due: LKR 4,800.00')
        ->toContain('Notes: Arriving late, airport pickup');
});

it('reports what has been paid once a deposit is taken', function () {
    shareLinkConfigured();
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['id' => $id] = confirmedBooking(['deposit_payment' => ['method' => 'cash', 'amount' => 500_000]], $manager);

    $message = $this->actingAs($manager)->getJson("/api/reservations/{$id}/whatsapp-message")->assertOk()->json('message');

    expect($message)
        ->toContain('Stay total: LKR 24,000.00')
        ->toContain('Paid: LKR 5,000.00 · Balance: LKR 19,000.00')
        ->not->toContain('Deposit due');
});

it('switches to the real folio bill once the guest is checked in', function () {
    shareLinkConfigured();
    ['manager' => $manager, 'id' => $id, 'code' => $code] = confirmedBooking();
    $this->actingAs($manager)->postJson("/api/reservations/{$id}/check-in", [])->assertOk();

    $message = $this->actingAs($manager)->getJson("/api/reservations/{$id}/whatsapp-message")->assertOk()->json('message');

    expect($message)
        ->toStartWith("*Booking {$code} — CHECKED IN*")
        ->toContain('Stay total: LKR 24,000.00');
});

it('refuses to share a cancelled booking', function () {
    shareLinkConfigured();
    $manager = staffWithRole('Manager');
    openTillFor($manager);
    ['id' => $id] = confirmedBooking([], $manager);
    $this->actingAs($manager)->postJson("/api/reservations/{$id}/cancel", ['reason' => 'Guest changed plans'])->assertOk();

    $this->actingAs($manager)->getJson("/api/reservations/{$id}/whatsapp-message")
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
});

it('refuses to share when no group link is configured', function () {
    ['manager' => $manager, 'id' => $id] = confirmedBooking();

    $this->actingAs($manager)->getJson("/api/reservations/{$id}/whatsapp-message")
        ->assertUnprocessable()->assertJsonValidationErrors('group_link');
});

it('is gated by the reservation view permission', function () {
    shareLinkConfigured();
    ['id' => $id] = confirmedBooking();

    $this->actingAs(staffWithRole('Chef'))->getJson("/api/reservations/{$id}/whatsapp-message")->assertForbidden();
});
