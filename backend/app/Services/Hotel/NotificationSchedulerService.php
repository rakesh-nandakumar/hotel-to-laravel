<?php

namespace App\Services\Hotel;

use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\Notification;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\VenueBooking;
use App\Services\Settings;
use App\Support\Lookups\NotificationChannel;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\VenueBookingStatus;

/**
 * Automated notification triggers — pre-arrival reminders, venue pre-event
 * and payment reminders, and a food-expiry digest. Each sweep is dedup'd by
 * checking for an existing Notification row before sending, so it is safe
 * to run repeatedly. Fired hourly by the scheduler (routes/console.php) and
 * manually from the Notifications screen. Ported from the Node app's
 * lib/scheduler.ts::runScheduledNotifications().
 */
class NotificationSchedulerService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly BillingService $billing,
    ) {}

    /**
     * @return array{sent: int}
     */
    public function run(): array
    {
        $hotelName = Settings::str('hotel.name', 'Mount View Hotel');

        $sent = $this->preArrivalReminders($hotelName)
            + $this->venuePreEventReminders($hotelName)
            + $this->venuePaymentReminders()
            + $this->foodExpiryDigest();

        return ['sent' => $sent];
    }

    private function preArrivalReminders(string $hotelName): int
    {
        $preDays = (int) Settings::num('notifications.pre_arrival_days', 1);
        $target = today()->addDays($preDays);
        $checkInTime = Settings::str('frontdesk.check_in_time', '14:00');

        $sent = 0;

        $arrivals = Reservation::query()
            ->statusCode(ReservationStatus::CONFIRMED)
            ->whereDate('check_in', $target)
            ->with('guest')
            ->get();

        foreach ($arrivals as $reservation) {
            if ($this->alreadySent('PRE_ARRIVAL', $reservation->id)) {
                continue;
            }

            $this->notifications->notifyGuest(
                ['email' => $reservation->guest->email, 'phone' => $reservation->guest->phone],
                [
                    'type' => 'PRE_ARRIVAL',
                    'subject' => "We look forward to welcoming you — {$hotelName}",
                    'body' => "Dear {$reservation->guest->name}, a reminder that your stay ({$reservation->code}) begins on {$reservation->check_in->format('Y-m-d')}. Check-in from {$checkInTime}. You can speed things up with online pre-check-in.",
                    'ref_type' => 'RESERVATION',
                    'ref_id' => $reservation->id,
                ]
            );
            $sent++;
        }

        return $sent;
    }

    private function venuePreEventReminders(string $hotelName): int
    {
        $eventDay = today()->addDay();

        $sent = 0;

        $events = VenueBooking::query()
            ->whereHas('status', fn ($q) => $q->where('code', VenueBookingStatus::CONFIRMED))
            ->whereDate('date', $eventDay)
            ->with('venue')
            ->get();

        foreach ($events as $booking) {
            if ($this->alreadySent('VENUE_PRE_EVENT', $booking->id)) {
                continue;
            }

            $this->notifications->notifyGuest(
                ['email' => $booking->client_email, 'phone' => $booking->client_phone],
                [
                    'type' => 'VENUE_PRE_EVENT',
                    'subject' => "Your event at {$booking->venue->name} is tomorrow — {$hotelName}",
                    'body' => "Dear {$booking->client_name}, a reminder of your {$booking->event_type} at {$booking->venue->name} tomorrow ({$booking->start_time}–{$booking->end_time}). Expected guests: {$booking->guest_count}.",
                    'ref_type' => 'VENUE_BOOKING',
                    'ref_id' => $booking->id,
                ]
            );
            $sent++;
        }

        return $sent;
    }

    private function venuePaymentReminders(): int
    {
        $payDay = today()->addDays(7);

        $sent = 0;

        $upcoming = VenueBooking::query()
            ->whereHas('status', fn ($q) => $q->where('code', VenueBookingStatus::CONFIRMED))
            ->whereDate('date', $payDay)
            ->with('folio')
            ->get();

        foreach ($upcoming as $booking) {
            if (! $booking->folio) {
                continue;
            }

            $balance = $this->billing->totals($booking->folio)['balance'];
            if ($balance <= 0) {
                continue;
            }

            if ($this->alreadySent('VENUE_PAYMENT_REMINDER', $booking->id)) {
                continue;
            }

            $this->notifications->notifyGuest(
                ['email' => $booking->client_email, 'phone' => $booking->client_phone],
                [
                    'type' => 'VENUE_PAYMENT_REMINDER',
                    'subject' => "Payment reminder — {$booking->venue->name} on {$booking->date->format('Y-m-d')}",
                    'body' => "Dear {$booking->client_name}, the outstanding balance for your event is LKR ".number_format($balance / 100).'. Please settle before the event date.',
                    'ref_type' => 'VENUE_BOOKING',
                    'ref_id' => $booking->id,
                ]
            );
            $sent++;
        }

        return $sent;
    }

    private function foodExpiryDigest(): int
    {
        $warnDays = (int) Settings::num('inventory.expiry_warn_days', 3);
        $cutoff = today()->addDays($warnDays);

        $expiring = IngredientBatch::query()
            ->where('qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $cutoff)
            ->with('ingredient:id,name,unit')
            ->orderBy('expiry_date')
            ->get();

        if ($expiring->isEmpty()) {
            return 0;
        }

        if (Notification::where('type', 'FOOD_EXPIRY')->where('created_at', '>=', today())->exists()) {
            return 0;
        }

        $lines = $expiring->map(function (IngredientBatch $batch) {
            $days = today()->diffInDays($batch->expiry_date, false);
            $status = match (true) {
                $days < 0 => 'EXPIRED '.abs($days).'d ago',
                $days === 0 => 'expires TODAY',
                default => "expires in {$days}d",
            };

            return "- {$batch->ingredient->name}: {$batch->qty}{$batch->ingredient->unit} {$status}";
        });

        $hotelEmail = Settings::str('hotel.email', 'manager@mountview.lk');

        $this->notifications->send([
            'type' => 'FOOD_EXPIRY',
            'channel' => NotificationChannel::EMAIL,
            'to' => $hotelEmail,
            'subject' => "Food expiry alert — {$expiring->count()} batch(es) need attention",
            'body' => "The following ingredient batches are expired or expiring soon:\n".$lines->implode("\n")."\n\nWrite off spoiled stock from the Inventory screen.",
        ]);

        return 1;
    }

    private function alreadySent(string $type, int $refId): bool
    {
        return Notification::where('type', $type)->where('ref_id', $refId)->exists();
    }
}
