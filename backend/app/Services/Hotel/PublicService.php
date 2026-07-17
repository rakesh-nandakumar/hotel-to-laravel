<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Reservation;
use App\Models\Hotel\Venue;
use App\Models\Hotel\VenueBooking;
use App\Models\Lookup;
use App\Services\DocumentNumberService;
use App\Services\Settings;
use App\Support\Lookups\DurationType;
use App\Support\Lookups\FolioStatus;
use App\Support\Lookups\FolioType;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\NotificationChannel;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\VenueBookingStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unauthenticated guest-facing actions — online pre-check-in and the venue
 * inquiry form. Ported from the Node app's routes/public.ts.
 */
class PublicService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function preCheckIn(array $data): void
    {
        $reservation = Reservation::query()->where('code', mb_strtoupper(trim($data['code'])))->with('guest', 'status')->first();

        if (! $reservation || ! in_array($reservation->status->code, [ReservationStatus::CONFIRMED, ReservationStatus::PENDING], true)) {
            throw new NotFoundHttpException('Booking not found or not awaiting arrival — check your booking code.');
        }

        $reservation->update(['pre_check_in' => [...$data, 'submitted_at' => now()->toIso8601String()]]);

        // Pre-fill the guest profile so front-desk check-in is instant.
        $guest = $reservation->guest;
        $guest->update([
            'id_number' => $data['id_number'],
            'phone' => $data['phone'] ?? $guest->phone,
            'email' => $data['email'] ?? $guest->email,
            'nationality' => $data['nationality'] ?? $guest->nationality,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function venueInquiry(array $data): VenueBooking
    {
        $venue = Venue::query()->findOrFail($data['venue_id']);

        if ($data['guest_count'] > $venue->max_capacity) {
            throw ValidationException::withMessages(['guest_count' => "Maximum capacity of {$venue->name} is {$venue->max_capacity} guests."]);
        }

        $booking = DB::transaction(function () use ($data, $venue) {
            $booking = VenueBooking::create([
                'code' => $this->documentNumbers->next(VenueBooking::class, 'code', 'VNB-'),
                'venue_id' => $venue->id,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'],
                'client_email' => $data['client_email'] ?? null,
                'event_type' => $data['event_type'] ?? null,
                'date' => $data['date'],
                'duration_type_id' => Lookup::id(LookupType::DURATION_TYPE, DurationType::FULL_DAY),
                'guest_count' => $data['guest_count'],
                'notes' => trim(($data['notes'] ?? '').' [Submitted via public inquiry form]'),
                'venue_booking_status_id' => Lookup::id(LookupType::VENUE_BOOKING_STATUS, VenueBookingStatus::INQUIRY),
            ]);

            $booking->folio()->create([
                'folio_type_id' => Lookup::id(LookupType::FOLIO_TYPE, FolioType::VENUE),
                'folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::OPEN),
            ]);

            return $booking;
        });

        $this->notifications->send([
            'type' => 'VENUE_INQUIRY_RECEIVED',
            'channel' => NotificationChannel::EMAIL,
            'to' => Settings::str('hotel.email', 'manager@mountview.lk'),
            'subject' => "New venue inquiry — {$venue->name} on {$data['date']}",
            'body' => "{$data['client_name']} ({$data['client_phone']}) asked about {$venue->name} for {$data['guest_count']} guests on {$data['date']}. Reference: {$booking->code}",
            'ref_type' => 'VENUE_BOOKING',
            'ref_id' => $booking->id,
        ]);

        return $booking;
    }
}
