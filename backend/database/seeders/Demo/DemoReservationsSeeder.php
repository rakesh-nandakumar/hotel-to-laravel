<?php

namespace Database\Seeders\Demo;

use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\Guest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Package;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Hotel\BillingService;
use App\Services\Hotel\HousekeepingService;
use App\Services\Hotel\LaundryService;
use App\Services\Hotel\OrderService;
use App\Services\Hotel\ReservationService;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\ReservationStatus;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/**
 * Books every room's history across a ~45-days-back / ~14-days-forward
 * window as one non-overlapping per-room timeline (so availability checks
 * always pass), driven entirely through ReservationService — same as the
 * app's own HTTP layer would — plus a couple of group bookings. Backdates
 * `now()` via Carbon::setTestNow() around each service call so booking date,
 * check-in/out timestamps, and folio activity land on realistic days instead
 * of everything being stamped "today".
 */
class DemoReservationsSeeder extends Seeder
{
    private ReservationService $reservations;

    private BillingService $billing;

    private OrderService $orders;

    private LaundryService $laundry;

    private HousekeepingService $housekeeping;

    /** @var list<int> */
    private array $guestIds;

    /** @var list<int> */
    private array $corporateIds;

    /** @var list<int> */
    private array $packageIds;

    /** @var list<int> */
    private array $staffIds;

    /** @var list<int> */
    private array $menuItemIds;

    /** @var list<int> */
    private array $laundryItemIds;

    private Carbon $today;

    private Carbon $windowStart;

    private Carbon $windowEnd;

    public function run(): void
    {
        if (Reservation::query()->count() > 5) {
            return; // already seeded — this seeder isn't safely re-runnable (would double-book rooms)
        }

        $this->reservations = app(ReservationService::class);
        $this->billing = app(BillingService::class);
        $this->orders = app(OrderService::class);
        $this->laundry = app(LaundryService::class);
        $this->housekeeping = app(HousekeepingService::class);

        $this->guestIds = Guest::query()->pluck('id')->all();
        $this->corporateIds = CorporateAccount::query()->pluck('id')->all();
        $this->packageIds = Package::query()->where('code', '!=', 'RO')->pluck('id')->all();
        $this->staffIds = User::query()->where('status', User::STATUS_ACTIVE)->pluck('id')->all();
        $this->menuItemIds = MenuItem::query()->pluck('id')->all();
        $this->laundryItemIds = \App\Models\Hotel\LaundryItem::query()->pluck('id')->all();

        $this->today = Carbon::today();
        $this->windowStart = $this->today->copy()->subDays(45);
        $this->windowEnd = $this->today->copy()->addDays(14);

        try {
            $rooms = Room::query()->orderBy('number')->get();

            foreach ($rooms as $room) {
                $this->seedRoomTimeline($room);
            }

            $this->seedGroupBookings($rooms);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seedRoomTimeline(Room $room): void
    {
        $cursor = $this->windowStart->copy();

        while ($cursor->lt($this->windowEnd)) {
            $cursor->addDays(random_int(1, 4));
            if ($cursor->gte($this->windowEnd)) {
                break;
            }

            $checkIn = $cursor->copy();
            $checkOut = $checkIn->copy()->addDays(random_int(1, 4));
            if ($checkOut->gt($this->windowEnd)) {
                $checkOut = $this->windowEnd->copy();
            }
            if ($checkOut->lte($checkIn)) {
                break;
            }

            $this->bookOneStay([$room->id], $checkIn, $checkOut);

            $cursor = $checkOut->copy();
        }
    }

    /**
     * @param  list<int>  $roomIds
     */
    private function bookOneStay(array $roomIds, Carbon $checkIn, Carbon $checkOut, ?array $group = null): void
    {
        $isPast = $checkOut->lte($this->today);
        $isCurrent = ! $isPast && $checkIn->lte($this->today);

        $channel = $this->randomChannel();
        $corporateId = ! $group && random_int(1, 100) <= 15 ? $this->pick($this->corporateIds) : null;
        $leadDays = $this->leadDaysFor($channel);
        $bookedAt = $checkIn->copy()->subDays($leadDays)->setTime(random_int(8, 20), random_int(0, 59));
        if ($bookedAt->gt(Carbon::now())) {
            $bookedAt = Carbon::now()->copy()->subMinutes(random_int(5, 180));
        }

        $this->at($bookedAt);

        try {
            $reservation = $this->reservations->create([
                'guest_id' => $this->pick($this->guestIds),
                'channel' => $channel,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'adults' => random_int(1, 10) === 1 ? 1 : random_int(2, 3),
                'children' => random_int(1, 8) === 1 ? random_int(1, 2) : 0,
                'package_id' => random_int(1, 100) <= 45 ? $this->pick($this->packageIds) : null,
                'corporate_account_id' => $corporateId,
                'rooms' => array_map(fn (int $id) => ['room_id' => $id], $roomIds),
                'notes' => null,
                'group' => $group,
            ], $this->pick($this->staffIds));
        } catch (ValidationException $e) {
            return; // room genuinely unavailable — skip rather than crash a big seed run
        }

        // Roughly 4 in 10 get a deposit recorded at booking time, corporate accounts less often (billed on account instead).
        if (random_int(1, 100) <= ($corporateId ? 15 : 40)) {
            $this->recordDeposit($reservation, $corporateId);
        }

        $outcome = random_int(1, 100);

        if ($isPast) {
            if ($outcome <= 8) {
                $this->at($bookedAt->copy()->addHours(random_int(1, 12)));
                $this->cancelReservation($reservation);
            } elseif ($outcome <= 13) {
                $this->at($checkIn->copy()->setTime(20, 0));
                $reservation->update(['reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::NO_SHOW)]);
            } else {
                $this->runFullStay($reservation, $checkIn, $checkOut);
            }
        } elseif ($isCurrent) {
            $this->at($checkIn->copy()->setTime(random_int(14, 19), random_int(0, 59)));
            $this->checkInReservation($reservation);
            // Guest is in-house right now — a live order shows up in today's KOT/dashboard feed.
            if (random_int(1, 100) <= 50) {
                Carbon::setTestNow();
                $this->maybeRoomOrder($reservation, $roomIds[0]);
            }
        } else {
            if ($outcome <= 8) {
                $this->at(Carbon::now()->copy()->subMinutes(random_int(10, 500)));
                $this->cancelReservation($reservation);
            } elseif ($outcome <= 23) {
                $reservation->update(['reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::PENDING)]);
            }
        }
    }

    private function runFullStay(Reservation $reservation, Carbon $checkIn, Carbon $checkOut): void
    {
        $this->at($checkIn->copy()->setTime(random_int(14, 19), random_int(0, 59)));
        if (! $this->checkInReservation($reservation)) {
            return;
        }

        $roomId = $reservation->rooms()->value('room_id');
        $nights = max(1, $checkIn->diffInDays($checkOut));

        if (random_int(1, 100) <= 45) {
            $this->at($checkIn->copy()->addDays(random_int(0, $nights - 1))->setTime([8, 13, 19][random_int(0, 2)], random_int(0, 59)));
            $this->maybeRoomOrder($reservation, $roomId);
        }
        if (random_int(1, 100) <= 25) {
            $this->at($checkIn->copy()->addDays(random_int(0, $nights - 1))->setTime(10, random_int(0, 59)));
            $this->maybeLaundryCharge($roomId);
        }

        $this->at($checkOut->copy()->setTime(random_int(9, 12), random_int(0, 59)));
        $this->checkoutReservation($reservation);
    }

    private function checkInReservation(Reservation $reservation): bool
    {
        try {
            $this->reservations->checkIn($reservation, [
                'id_number' => $reservation->guest->id_number ?: fake()->numerify('#########V'),
            ], $this->pick($this->staffIds));

            // checkIn() updates reservation_status_id via update(), which does not
            // invalidate the already-loaded `status` BelongsTo on this same in-memory
            // object — refresh so the next call (checkout) sees CHECKED_IN, not the
            // stale CONFIRMED it loaded before check-in.
            $reservation->refresh();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function checkoutReservation(Reservation $reservation): void
    {
        try {
            $balanceDue = $this->reservations->checkoutQuote($reservation, false)['balance_due'];
            $payments = $balanceDue > 0 ? [[
                'method' => $reservation->corporate_account_id && random_int(1, 2) === 1
                    ? PaymentMethod::CORPORATE_CREDIT : $this->randomPayMethod(),
                'amount' => $balanceDue,
            ]] : [];

            $this->reservations->checkout($reservation, ['payments' => $payments], $this->pick($this->staffIds));
            $this->cleanRoomAfterCheckout($reservation);
        } catch (ValidationException) {
            // e.g. rare same-day edge case — leave the folio open rather than fail the whole seed run.
        }
    }

    /**
     * Real turnover cleaning is what flips a checked-out room DIRTY→AVAILABLE
     * (see HousekeepingService::complete()). Without this, every room would
     * get stuck DIRTY after its first stay and every later check-in in that
     * room's timeline would fail — so the room-turnover clean always
     * completes immediately here; DemoOperationsSeeder adds the *separate*
     * ad-hoc tasks that realistically sit pending/in-progress on the board.
     */
    private function cleanRoomAfterCheckout(Reservation $reservation): void
    {
        $task = HousekeepingTask::query()->where('reservation_id', $reservation->id)->latest('id')->first();
        if (! $task) {
            return;
        }

        $doneChecklist = collect($task->checklist)->map(fn (array $row) => ['item' => $row['item'], 'done' => true])->all();

        try {
            $this->housekeeping->complete($task, $doneChecklist, 'Turnover clean — ready for next guest.', $this->pick($this->staffIds));
        } catch (ValidationException) {
        }
    }

    private function cancelReservation(Reservation $reservation): void
    {
        $reasons = ['Guest changed travel plans', 'Found alternative accommodation', 'Family emergency', 'Rebooking for different dates', 'Duplicate booking'];

        try {
            $this->reservations->cancel($reservation, fake()->randomElement($reasons), PaymentMethod::CASH, $this->pick($this->staffIds));
        } catch (ValidationException) {
        }
    }

    private function recordDeposit(Reservation $reservation, ?int $corporateId): void
    {
        if ($reservation->deposit_due <= 0) {
            return;
        }

        $this->billing->recordPayment([
            'folio_id' => $reservation->folio->id,
            'method' => $corporateId ? PaymentMethod::CORPORATE_CREDIT : $this->randomPayMethod(),
            'amount' => $reservation->deposit_due,
            'kind' => PaymentKind::DEPOSIT,
            'staff_id' => $this->pick($this->staffIds),
            'guest_id_for_loyalty' => $reservation->guest_id,
        ]);
    }

    private function maybeRoomOrder(Reservation $reservation, int $roomId): void
    {
        if ($this->menuItemIds === []) {
            return;
        }

        $items = collect(range(1, random_int(1, 3)))->map(fn () => [
            'menu_item_id' => $this->pick($this->menuItemIds),
            'qty' => random_int(1, 2),
        ])->all();

        try {
            $order = $this->orders->create([
                'type' => OrderType::ROOM_GUEST,
                'room_id' => $roomId,
                'items' => $items,
            ], $this->pick($this->staffIds));

            $this->orders->chargeToRoom($order, $this->pick($this->staffIds));
        } catch (\Throwable) {
            // insufficient stock / room not checked-in at this simulated moment — skip, not fatal for a bulk seed.
        }
    }

    private function maybeLaundryCharge(int $roomId): void
    {
        if ($this->laundryItemIds === []) {
            return;
        }

        $items = collect(range(1, random_int(1, 3)))->map(fn () => [
            'laundry_item_id' => $this->pick($this->laundryItemIds),
            'qty' => random_int(1, 2),
        ])->all();

        try {
            $this->laundry->chargeToRoom($roomId, $items, null, $this->pick($this->staffIds));
        } catch (\Throwable) {
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Room>  $rooms
     */
    private function seedGroupBookings($rooms): void
    {
        $candidates = [
            [$this->today->copy()->addDays(6), $this->today->copy()->addDays(8), 'Perera Wedding Party'],
            [$this->today->copy()->addDays(11), $this->today->copy()->addDays(13), 'Colombo Tech Summit Delegation'],
        ];

        $availability = app(\App\Services\Hotel\ReservationAvailabilityService::class);

        foreach ($candidates as [$checkIn, $checkOut, $name]) {
            $freeRoomIds = $availability->availableRooms($checkIn, $checkOut)->pluck('id')->all();
            if (count($freeRoomIds) < 3) {
                continue;
            }
            shuffle($freeRoomIds);
            $picked = array_slice($freeRoomIds, 0, 3);

            $this->bookOneStay($picked, $checkIn, $checkOut, [
                'name' => $name,
                'contact_name' => fake()->name(),
                'contact_phone' => fake()->numerify('07########'),
            ]);
        }
    }

    private function randomChannel(): string
    {
        return fake()->randomElement(['walkin', 'walkin', 'phone', 'phone', 'website', 'booking_com']);
    }

    private function leadDaysFor(string $channel): int
    {
        return match ($channel) {
            'walkin' => 0,
            'phone' => random_int(0, 3),
            'website' => random_int(1, 10),
            'booking_com' => random_int(2, 20),
            default => 0,
        };
    }

    private function randomPayMethod(): string
    {
        return fake()->randomElement([
            PaymentMethod::CASH, PaymentMethod::CASH,
            PaymentMethod::CARD, PaymentMethod::CARD,
            PaymentMethod::LANKAQR,
            PaymentMethod::BANK_TRANSFER,
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function pick(array $ids): mixed
    {
        return $ids[array_rand($ids)];
    }

    private function at(Carbon $moment): void
    {
        Carbon::setTestNow($moment);
    }
}
