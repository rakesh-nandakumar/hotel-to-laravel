<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Folio;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\Venue;
use App\Models\Hotel\VenueBooking;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\DocumentNumberService;
use App\Services\Settings;
use App\Support\Lookups\DurationType;
use App\Support\Lookups\FolioStatus;
use App\Support\Lookups\FolioType;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\VenueBookingStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Venue booking lifecycle — parallel structure to ReservationService but
 * simpler: flat hourly/half-day/full-day pricing (no per-night waterfall),
 * and the double-booking check only fires when confirming (not on inquiry).
 * Ported from the Node app's routes/venues.ts.
 */
class VenueBookingService
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBooking(array $data, int $staffId): VenueBooking
    {
        $venue = Venue::query()->findOrFail($data['venue_id']);

        if (($data['guest_count'] ?? 0) > $venue->max_capacity) {
            throw ValidationException::withMessages(['guest_count' => "Capacity of {$venue->name} is {$venue->max_capacity}."]);
        }

        $confirm = $data['confirm'] ?? false;

        // Double-booking guard: same venue, same date, another CONFIRMED booking —
        // only blocks when this booking is itself being confirmed, not on inquiry.
        if ($confirm) {
            $clash = $this->confirmedClash($venue->id, $data['date']);
            if ($clash) {
                throw ValidationException::withMessages([
                    'date' => "{$venue->name} already has a confirmed booking on {$data['date']} ({$clash->code}).",
                ]);
            }
        }

        $rental = match ($data['duration_type']) {
            DurationType::FULL_DAY => $venue->full_day_rate,
            DurationType::HALF_DAY => $venue->half_day_rate,
            default => (int) round($venue->hourly_rate * ($data['hours'] ?? 1)),
        };
        $extras = $data['extras'] ?? [];
        $extrasTotal = (int) collect($extras)->sum('amount');
        $depositPct = Settings::num('billing.venue_deposit_pct', 25);
        $depositDue = (int) round(($rental + $extrasTotal) * $depositPct / 100);

        $booking = DB::transaction(function () use ($data, $venue, $confirm, $rental, $extras, $depositDue, $staffId) {
            $booking = VenueBooking::create([
                'code' => $this->documentNumbers->next(VenueBooking::class, 'code', 'VNB-'),
                'venue_id' => $venue->id,
                'guest_id' => $data['guest_id'] ?? null,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'client_email' => $data['client_email'] ?? null,
                'event_type' => $data['event_type'] ?? null,
                'date' => $data['date'],
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'duration_type_id' => Lookup::id(LookupType::DURATION_TYPE, $data['duration_type']),
                'hours' => $data['hours'] ?? null,
                'guest_count' => $data['guest_count'] ?? 0,
                'seating' => $data['seating'] ?? null,
                'av_needs' => $data['av_needs'] ?? null,
                'decoration' => $data['decoration'] ?? null,
                'catering_by_hotel' => $data['catering_by_hotel'] ?? false,
                'notes' => $data['notes'] ?? null,
                'venue_booking_status_id' => Lookup::id(LookupType::VENUE_BOOKING_STATUS, $confirm ? VenueBookingStatus::CONFIRMED : VenueBookingStatus::INQUIRY),
                'deposit_due' => $depositDue,
            ]);

            $folio = $booking->folio()->create([
                'folio_type_id' => Lookup::id(LookupType::FOLIO_TYPE, FolioType::VENUE),
                'folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::OPEN),
            ]);

            $venueSourceId = Lookup::id(LookupType::LINE_SOURCE, LineSource::VENUE);
            $rentalDescription = match ($data['duration_type']) {
                DurationType::HOURLY => (($data['hours'] ?? 1)).'h rental',
                DurationType::HALF_DAY => 'Half-day rental',
                default => 'Full-day rental',
            };
            FolioLine::create([
                'folio_id' => $folio->id, 'line_source_id' => $venueSourceId,
                'description' => "{$venue->name} — {$rentalDescription}",
                'qty' => 1, 'unit_price' => $rental, 'amount' => $rental, 'staff_id' => $staffId,
            ]);

            foreach ($extras as $extra) {
                FolioLine::create([
                    'folio_id' => $folio->id, 'line_source_id' => $venueSourceId,
                    'description' => "{$extra['description']} — optional extra",
                    'qty' => 1, 'unit_price' => $extra['amount'], 'amount' => $extra['amount'], 'staff_id' => $staffId,
                ]);
            }

            return $booking;
        });

        AuditLog::record('venue_booking.created', $booking, ['code' => $booking->code, 'rental' => $rental, 'deposit_due' => $depositDue]);

        return $booking->load(['venue', 'folio', 'status', 'durationType']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBooking(VenueBooking $booking, array $data): VenueBooking
    {
        $booking->update($data);

        return $booking->load(['venue', 'folio', 'status', 'durationType']);
    }

    /** Confirm an inquiry — re-checks the double-booking clash at confirm time. */
    public function confirmBooking(VenueBooking $booking, int $staffId): VenueBooking
    {
        $clash = $this->confirmedClash($booking->venue_id, $booking->date->toDateString(), excludeId: $booking->id);
        if ($clash) {
            throw ValidationException::withMessages(['date' => "Venue already confirmed for that date ({$clash->code})."]);
        }

        $booking->update(['venue_booking_status_id' => Lookup::id(LookupType::VENUE_BOOKING_STATUS, VenueBookingStatus::CONFIRMED)]);

        AuditLog::record('venue_booking.confirmed', $booking, ['code' => $booking->code]);

        return $booking->load(['venue', 'folio', 'status', 'durationType']);
    }

    /** Complete the event — requires the folio fully paid, assigns a VNU invoice number. */
    public function completeBooking(VenueBooking $booking, int $staffId): string
    {
        $booking->loadMissing('folio');
        if (! $booking->folio) {
            throw ValidationException::withMessages(['booking' => 'Venue booking has no folio.']);
        }

        $totals = $this->billing->totals($booking->folio);
        if ($totals['balance'] > 0) {
            throw ValidationException::withMessages([
                'balance' => 'Balance LKR '.number_format($totals['balance'] / 100, 2).' outstanding — collect payment first.',
            ]);
        }

        $invoiceNo = $this->documentNumbers->next(Folio::class, 'invoice_no', 'VNU-'.now()->year.'-');

        DB::transaction(function () use ($booking, $invoiceNo) {
            $booking->folio->update([
                'folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::SETTLED),
                'invoice_no' => $invoiceNo, 'settled_at' => now(),
            ]);
            $booking->update(['venue_booking_status_id' => Lookup::id(LookupType::VENUE_BOOKING_STATUS, VenueBookingStatus::COMPLETED)]);
        });

        if ($booking->guest_id) {
            $this->billing->accrueLoyalty($booking->guest_id, $totals['total'], 'VENUE', $booking->id, $staffId);
        }

        AuditLog::record('venue_booking.completed', $booking, ['invoice_no' => $invoiceNo]);

        return $invoiceNo;
    }

    /**
     * Cancellation refund policy here is a flat hardcoded rule (not the
     * configurable `policies.cancellation_rules` Setting Reservations uses)
     * — a deliberate inconsistency ported faithfully from Node, not unified.
     *
     * @return array{ok: bool, refunded: int}
     */
    public function cancelBooking(VenueBooking $booking, string $reason, string $refundMethodCode, int $staffId): array
    {
        $booking->loadMissing('folio', 'status');

        if (in_array($booking->status->code, [VenueBookingStatus::COMPLETED, VenueBookingStatus::CANCELLED], true)) {
            throw ValidationException::withMessages(['booking' => "Booking is {$booking->status->code}."]);
        }

        $refunded = 0;
        if ($booking->folio) {
            $totals = $this->billing->totals($booking->folio);
            $daysUntil = (int) round(($booking->date->copy()->startOfDay()->timestamp - now()->startOfDay()->timestamp) / 86400);
            $refundPct = $daysUntil >= 7 ? 100 : 0;
            $refunded = (int) round(($totals['paid'] - $totals['refunded']) * $refundPct / 100);

            if ($refunded > 0) {
                $this->billing->recordPayment([
                    'folio_id' => $booking->folio->id, 'method' => $refundMethodCode, 'amount' => $refunded,
                    'kind' => PaymentKind::REFUND,
                    'reason' => "Venue cancellation ({$daysUntil} days before): {$reason}",
                    'staff_id' => $staffId,
                ]);
            }

            $booking->folio->update(['folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::VOID)]);
        }

        $booking->update([
            'venue_booking_status_id' => Lookup::id(LookupType::VENUE_BOOKING_STATUS, VenueBookingStatus::CANCELLED),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        AuditLog::record('venue_booking.cancelled', $booking, ['reason' => $reason, 'refunded' => $refunded]);

        return ['ok' => true, 'refunded' => $refunded];
    }

    private function confirmedClash(int $venueId, string $date, ?int $excludeId = null): ?VenueBooking
    {
        return VenueBooking::query()
            ->where('venue_id', $venueId)
            ->whereDate('date', Carbon::parse($date))
            ->whereHas('status', fn ($q) => $q->where('code', VenueBookingStatus::CONFIRMED))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }
}
