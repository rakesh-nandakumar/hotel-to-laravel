<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Folio;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\Venue;
use App\Models\Hotel\VenueBooking;
use App\Models\Hotel\VenueExtraCharge;
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
        $usePackagePricing = $data['use_package_pricing'] ?? $venue->usesPackagePricing();

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

        // Calculate pricing based on model (legacy vs package)
        if ($usePackagePricing) {
            $pricing = $this->calculatePackagePricing($venue, $data);
        } else {
            $pricing = $this->calculateLegacyPricing($venue, $data);
        }

        $extras = $data['extras'] ?? [];
        $extrasTotal = (int) collect($extras)->sum('amount');
        $depositDue = Settings::depositAmount($pricing['total'] + $extrasTotal, 'billing.venue_deposit_mode', 'billing.venue_deposit_pct', 'billing.venue_deposit_fixed', 25);

        $booking = DB::transaction(function () use ($data, $venue, $confirm, $pricing, $extras, $depositDue, $staffId, $usePackagePricing) {
            $bookingData = [
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
                'guest_count' => $data['guest_count'] ?? 0,
                'seating' => $data['seating'] ?? null,
                'av_needs' => $data['av_needs'] ?? null,
                'decoration' => $data['decoration'] ?? null,
                'catering_by_hotel' => $data['catering_by_hotel'] ?? false,
                'notes' => $data['notes'] ?? null,
                'venue_booking_status_id' => Lookup::id(LookupType::VENUE_BOOKING_STATUS, $confirm ? VenueBookingStatus::CONFIRMED : VenueBookingStatus::INQUIRY),
                'deposit_due' => $depositDue,
            ];

            // Legacy fields for backward compatibility
            if (! $usePackagePricing) {
                $bookingData['duration_type_id'] = Lookup::id(LookupType::DURATION_TYPE, $data['duration_type']);
                $bookingData['hours'] = $data['hours'] ?? null;
            } else {
                // Package pricing fields
                $bookingData['package_type'] = $data['package_type'] ?? null;
                $bookingData['per_plate_price'] = $pricing['per_plate_price'] ?? null;
                $bookingData['hall_charge_used'] = $pricing['hall_charge'];
                $bookingData['service_charge_pct'] = $pricing['service_charge_pct'];
                $bookingData['byod_selected'] = $data['byod_selected'] ?? false;
                $bookingData['dj_required'] = $data['dj_required'] ?? true;
                $bookingData['advance_payment'] = $data['advance_payment'] ?? 0;
                $bookingData['profit_margin'] = $data['profit_margin'] ?? 0;
                if ($bookingData['advance_payment'] > 0) {
                    $bookingData['advance_paid_at'] = now();
                    $paymentMethod = $data['advance_payment_method'] ?? null;
                    // Store as JSON if it's an array (split payment), otherwise as string
                    if (is_array($paymentMethod)) {
                        $bookingData['advance_payment_method'] = json_encode($paymentMethod);
                    } else {
                        $bookingData['advance_payment_method'] = $paymentMethod;
                    }
                }
            }

            $booking = VenueBooking::create($bookingData);

            $folio = $booking->folio()->create([
                'folio_type_id' => Lookup::id(LookupType::FOLIO_TYPE, FolioType::VENUE),
                'folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::OPEN),
            ]);

            $booking->update(['folio_id' => $folio->id]);

            $venueSourceId = Lookup::id(LookupType::LINE_SOURCE, LineSource::VENUE);

            // Create folio lines based on pricing model
            if ($usePackagePricing) {
                // Package pricing folio lines
                foreach ($pricing['folio_lines'] as $line) {
                    FolioLine::create([
                        'folio_id' => $folio->id,
                        'line_source_id' => $venueSourceId,
                        'description' => $line['description'],
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'amount' => $line['amount'],
                        'staff_id' => $staffId,
                    ]);
                }

                // Add advance payment as a folio line if provided
                if (isset($data['advance_payment']) && $data['advance_payment'] > 0) {
                    FolioLine::create([
                        'folio_id' => $folio->id,
                        'line_source_id' => $venueSourceId,
                        'description' => 'Advance payment',
                        'qty' => 1,
                        'unit_price' => -$data['advance_payment'], // Negative to reduce balance
                        'amount' => -$data['advance_payment'],
                        'staff_id' => $staffId,
                    ]);

                    // Record the payment(s)
                    $paymentMethod = $data['advance_payment_method'] ?? 'cash';
                    if (is_array($paymentMethod)) {
                        // Split payment - record each payment method
                        foreach ($paymentMethod as $pm) {
                            $amount = isset($pm['amount']) ? (int) $pm['amount'] : 0;
                            // Convert LKR to cents if amount is provided as number
                            if ($amount > 0 && $amount < 10000) {
                                $amount = $amount * 100; // Assume LKR if less than 10000
                            }
                            if ($amount > 0) {
                                $this->billing->recordPayment([
                                    'folio_id' => $folio->id,
                                    'method' => $pm['method'] ?? 'cash',
                                    'amount' => $amount,
                                    'kind' => PaymentKind::PAYMENT,
                                    'reason' => 'Advance payment for venue booking',
                                    'staff_id' => $staffId,
                                ]);
                            }
                        }
                    } else {
                        // Single payment method
                        $this->billing->recordPayment([
                            'folio_id' => $folio->id,
                            'method' => $paymentMethod,
                            'amount' => $data['advance_payment'],
                            'kind' => PaymentKind::PAYMENT,
                            'reason' => 'Advance payment for venue booking',
                            'staff_id' => $staffId,
                        ]);
                    }
                }
            } else {
                // Legacy pricing folio lines
                $rentalDescription = match ($data['duration_type']) {
                    DurationType::HOURLY => (($data['hours'] ?? 1)).'h rental',
                    DurationType::HALF_DAY => 'Half-day rental',
                    default => 'Full-day rental',
                };
                FolioLine::create([
                    'folio_id' => $folio->id,
                    'line_source_id' => $venueSourceId,
                    'description' => "{$venue->name} — {$rentalDescription}",
                    'qty' => 1,
                    'unit_price' => $pricing['rental'],
                    'amount' => $pricing['rental'],
                    'staff_id' => $staffId,
                ]);
            }

            // Add extra charges
            foreach ($extras as $extra) {
                FolioLine::create([
                    'folio_id' => $folio->id,
                    'line_source_id' => $venueSourceId,
                    'description' => "{$extra['description']} — optional extra",
                    'qty' => 1,
                    'unit_price' => $extra['amount'],
                    'amount' => $extra['amount'],
                    'staff_id' => $staffId,
                ]);
            }

            // Create venue extra charges records for unlimited extras
            if ($usePackagePricing && isset($data['venue_extras'])) {
                foreach ($data['venue_extras'] as $index => $extra) {
                    VenueExtraCharge::create([
                        'venue_booking_id' => $booking->id,
                        'description' => $extra['description'],
                        'amount' => $extra['amount'],
                        'charge_type' => $extra['charge_type'] ?? 'misc',
                        'is_percentage' => $extra['is_percentage'] ?? false,
                        'sort_order' => $index,
                        'created_by' => $staffId,
                        'updated_by' => $staffId,
                    ]);
                }
            }

            return $booking;
        });

        AuditLog::record('venue_booking.created', $booking, [
            'code' => $booking->code,
            'total' => $pricing['total'],
            'deposit_due' => $depositDue,
            'package_type' => $usePackagePricing ? ($data['package_type'] ?? null) : null,
        ]);

        return $booking->load(['venue', 'folio', 'status', 'durationType']);
    }

    /**
     * Calculate pricing using the new package model
     *
     * @param  array<string, mixed>  $data
     * @return array{total: int, hall_charge: int, per_plate_price: int|null, service_charge_pct: int, folio_lines: array<array{description: string, qty: int, unit_price: int, amount: int}>}
     */
    private function calculatePackagePricing(Venue $venue, array $data): array
    {
        $guestCount = $data['guest_count'] ?? 0;
        $packageType = $data['package_type'] ?? 'hall_food';
        $serviceChargePct = $data['service_charge_pct'] ?? 10;
        $profitMargin = $data['profit_margin'] ?? 0;

        $folioLines = [];
        $total = 0;
        $hallCharge = 0;
        $perPlatePrice = null;

        if ($packageType === 'hall_only') {
            // Hall only: 500 per person (or venue-specific rate)
            $hallOnlyRate = $venue->hall_only_per_person;
            $hallCharge = $hallOnlyRate * $guestCount;

            $folioLines[] = [
                'description' => "{$venue->name} — Hall Only ({$guestCount} persons)",
                'qty' => $guestCount,
                'unit_price' => $hallOnlyRate,
                'amount' => $hallCharge,
            ];

            $total = $hallCharge;
        } else {
            // Hall + Food: Hall charge + per plate price
            $hallCharge = $venue->getHallCharge();
            $perPlatePrice = $data['per_plate_price'] ?? $venue->per_plate_starting_price;
            $foodCost = $perPlatePrice * $guestCount;

            $folioLines[] = [
                'description' => "{$venue->name} — Hall Charge",
                'qty' => 1,
                'unit_price' => $hallCharge,
                'amount' => $hallCharge,
            ];

            $folioLines[] = [
                'description' => "Food & Beverage ({$guestCount} plates @ {$perPlatePrice})",
                'qty' => $guestCount,
                'unit_price' => $perPlatePrice,
                'amount' => $foodCost,
            ];

            $total = $hallCharge + $foodCost;
        }

        // Add service charge if applicable
        if ($serviceChargePct > 0) {
            $serviceCharge = (int) round($total * $serviceChargePct / 100);
            $folioLines[] = [
                'description' => "Service Charge ({$serviceChargePct}%)",
                'qty' => 1,
                'unit_price' => $serviceCharge,
                'amount' => $serviceCharge,
            ];
            $total += $serviceCharge;
        }

        // Add profit margin if applicable
        if ($profitMargin > 0) {
            $folioLines[] = [
                'description' => 'Profit Margin',
                'qty' => 1,
                'unit_price' => $profitMargin,
                'amount' => $profitMargin,
            ];
            $total += $profitMargin;
        }

        return [
            'total' => $total,
            'hall_charge' => $hallCharge,
            'per_plate_price' => $perPlatePrice,
            'service_charge_pct' => $serviceChargePct,
            'folio_lines' => $folioLines,
        ];
    }

    /**
     * Calculate pricing using the legacy hourly/half-day/full-day model
     *
     * @param  array<string, mixed>  $data
     * @return array{total: int, rental: int}
     */
    private function calculateLegacyPricing(Venue $venue, array $data): array
    {
        $rental = match ($data['duration_type']) {
            DurationType::FULL_DAY => $venue->full_day_rate,
            DurationType::HALF_DAY => $venue->half_day_rate,
            default => (int) round($venue->hourly_rate * ($data['hours'] ?? 1)),
        };

        return [
            'total' => $rental,
            'rental' => $rental,
        ];
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
     * Record an advance payment for a venue booking
     *
     * @return array{ok: bool, advance_payment: int}
     */
    public function recordAdvancePayment(VenueBooking $booking, int $amount, string $method, int $staffId): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Advance payment must be greater than zero.']);
        }

        $booking->update([
            'advance_payment' => $amount,
            'advance_paid_at' => now(),
            'advance_payment_method' => $method,
        ]);

        // If there's a folio, record the advance as a payment
        if ($booking->folio) {
            $this->billing->recordPayment([
                'folio_id' => $booking->folio->id,
                'method' => $method,
                'amount' => $amount,
                'kind' => PaymentKind::PAYMENT,
                'reason' => 'Advance payment for venue booking',
                'staff_id' => $staffId,
            ]);
        }

        AuditLog::record('venue_booking.advance_payment', $booking, [
            'code' => $booking->code,
            'amount' => $amount,
            'method' => $method,
        ]);

        return ['ok' => true, 'advance_payment' => $amount];
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
