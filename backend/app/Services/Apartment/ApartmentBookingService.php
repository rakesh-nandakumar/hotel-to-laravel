<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Booking;
use App\Models\Apartment\Customer;
use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\Ledger;
use App\Models\Apartment\LedgerLine;
use App\Models\Apartment\Payment;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\DocumentNumberService;
use App\Services\Settings;
use App\Support\Lookups\ApartmentBookingStatus;
use App\Support\Lookups\ApartmentLedgerStatus;
use App\Support\Lookups\ApartmentLineSource;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\TaskStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apartment short-stay booking lifecycle — booking, check-in, checkout,
 * cancellation. Mirrors the Hotel module's reservation service's
 * (App\Services\Hotel\ReservationService) shape (same method names, same
 * overall flow) but simplified for a single-unit-per-booking model with no
 * group/package/corporate concepts.
 */
class ApartmentBookingService
{
    public function __construct(
        private readonly ApartmentAvailabilityService $availability,
        private readonly ApartmentPricingService $pricing,
        private readonly ApartmentBillingService $billing,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $staffId): Booking
    {
        $checkIn = Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = Carbon::parse($data['check_out'])->startOfDay();

        return DB::transaction(function () use ($data, $checkIn, $checkOut, $staffId) {
            $customerId = $data['customer_id'] ?? null;
            if (! $customerId) {
                $customerId = Customer::create($data['new_customer'])->id;
            }

            $unit = $this->availability->assertUnitAvailable($data['unit_id'], $checkIn, $checkOut);
            $unit->loadMissing('unitType.seasonalRates');

            $rate = $this->pricing->resolveStayRate($unit->unitType, $checkIn, $checkOut);
            $stayTotal = $rate['stay_total'];

            $depositPct = Settings::num('apartment.deposit_pct', 20);
            $depositDue = (int) round($stayTotal * $depositPct / 100);

            $booking = Booking::create([
                'code' => $this->documentNumbers->next(Booking::class, 'code', 'APT-'),
                'unit_id' => $unit->id,
                'customer_id' => $customerId,
                'booking_status_id' => Lookup::id(LookupType::APARTMENT_BOOKING_STATUS, ApartmentBookingStatus::CONFIRMED),
                'channel_id' => Lookup::id(LookupType::APARTMENT_BOOKING_CHANNEL, $data['channel']),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $data['adults'],
                'children' => $data['children'] ?? 0,
                'nightly_rate' => $rate['nightly_rate'],
                'rate_basis' => $rate['rate_basis'],
                'deposit_due' => $depositDue,
                'notes' => $data['notes'] ?? null,
            ]);

            $ledger = $booking->ledger()->create([
                'ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::OPEN),
            ]);

            if (! empty($data['deposit_payment'])) {
                $this->billing->recordPayment([
                    'ledger_id' => $ledger->id,
                    'method' => $data['deposit_payment']['method'],
                    'amount' => $data['deposit_payment']['amount'],
                    'kind' => PaymentKind::DEPOSIT,
                    'reference' => $data['deposit_payment']['reference'] ?? null,
                    'staff_id' => $staffId,
                ]);
            }

            AuditLog::record('apartment_booking.created', $booking, [
                'code' => $booking->code, 'stay_total' => $stayTotal, 'deposit_due' => $depositDue,
            ]);

            return $booking->load(['customer', 'unit.unitType', 'ledger']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function checkIn(Booking $booking, array $data, int $staffId): array
    {
        $booking->loadMissing(['customer', 'ledger', 'status', 'unit.unitType', 'unit.status']);

        if (! in_array($booking->status->code, [ApartmentBookingStatus::CONFIRMED, ApartmentBookingStatus::PENDING], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot check in a {$booking->status->code} booking.",
            ]);
        }

        if ($booking->unit->status->code !== ApartmentUnitStatus::AVAILABLE) {
            throw ValidationException::withMessages([
                'unit' => "Unit {$booking->unit->unit_no} is {$booking->unit->status->code} — cannot check in.",
            ]);
        }

        $idNumber = trim((string) ($data['id_number'] ?? '')) ?: $booking->customer->id_number;
        if (! $idNumber) {
            throw ValidationException::withMessages([
                'id_number' => 'Customer ID/passport number is required at check-in.',
            ]);
        }
        if (! empty($data['id_number'])) {
            $booking->customer->update(['id_number' => trim($data['id_number'])]);
        }

        $ledger = $booking->ledger;

        DB::transaction(function () use ($booking, $ledger, $staffId) {
            $rentSourceId = Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::RENT);
            $nightCount = $this->pricing->nights($booking->check_in, $booking->check_out);

            if ($booking->rate_basis === 'nightly') {
                foreach ($nightCount as $date) {
                    LedgerLine::create([
                        'ledger_id' => $ledger->id, 'line_source_id' => $rentSourceId,
                        'description' => "Unit {$booking->unit->unit_no} — {$date}",
                        'qty' => 1, 'unit_price' => $booking->nightly_rate, 'amount' => $booking->nightly_rate,
                        'staff_id' => $staffId,
                    ]);
                }
            } else {
                $total = $booking->nightly_rate * count($nightCount);
                LedgerLine::create([
                    'ledger_id' => $ledger->id, 'line_source_id' => $rentSourceId,
                    'description' => "Unit {$booking->unit->unit_no} — {$booking->rate_basis} rate (".count($nightCount).' nights)',
                    'qty' => 1, 'unit_price' => $total, 'amount' => $total,
                    'staff_id' => $staffId,
                ]);
            }

            $occupiedId = Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::OCCUPIED);
            $booking->unit->update(['unit_status_id' => $occupiedId]);

            $booking->update([
                'booking_status_id' => Lookup::id(LookupType::APARTMENT_BOOKING_STATUS, ApartmentBookingStatus::CHECKED_IN),
                'checked_in_at' => now(),
            ]);
        });

        AuditLog::record('apartment_booking.checked_in', $booking, ['code' => $booking->code]);

        return $this->billing->present($ledger->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutQuote(Booking $booking, bool $applyLate): array
    {
        $ledger = $booking->ledger;
        if (! $ledger) {
            throw ValidationException::withMessages(['booking' => 'Booking has no ledger.']);
        }

        $totals = $this->billing->totals($ledger);
        $ledger->loadMissing(['lines.source', 'lines.staff:id,name', 'status']);

        $lateAmt = $applyLate ? (int) Settings::num('apartment.late_checkout_surcharge', 0) : 0;
        $cleanLines = $ledger->lines->reject(fn (LedgerLine $line) => $this->isStaleCheckoutLine($line));

        $base = (int) $cleanLines->sum('amount') + $lateAmt;
        $tax = $this->billing->calcTax($base);

        $grandTotal = (int) $cleanLines->sum('amount') + $lateAmt + $tax['service_charge'] + $tax['vat'];

        return [
            'ledger' => array_merge($ledger->toArray(), $totals),
            'lines' => $cleanLines->values(),
            'late_surcharge' => $lateAmt,
            'service_charge' => $tax['service_charge'],
            'service_charge_pct' => $tax['service_charge_pct'],
            'vat' => $tax['vat'],
            'vat_pct' => $tax['vat_pct'],
            'grand_total' => $grandTotal,
            'balance_due' => $grandTotal - $totals['paid'] + $totals['refunded'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function checkout(Booking $booking, array $data, int $staffId): array
    {
        $booking->loadMissing(['customer', 'ledger', 'status', 'unit']);

        if (! $booking->ledger) {
            throw ValidationException::withMessages(['booking' => 'Booking has no ledger.']);
        }
        if ($booking->status->code !== ApartmentBookingStatus::CHECKED_IN) {
            throw ValidationException::withMessages(['status' => 'Customer is not checked in.']);
        }

        $payments = $data['payments'] ?? [];
        $ledgerId = $booking->ledger->id;
        $lateAmt = ! empty($data['apply_late_surcharge']) ? (int) Settings::num('apartment.late_checkout_surcharge', 0) : 0;

        $result = DB::transaction(function () use ($ledgerId, $lateAmt, $payments, $staffId) {
            $scId = Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::SERVICE_CHARGE);
            $vatId = Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::VAT);
            $surchargeId = Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::SURCHARGE);

            LedgerLine::where('ledger_id', $ledgerId)
                ->where(function ($q) use ($scId, $vatId, $surchargeId) {
                    $q->whereIn('line_source_id', [$scId, $vatId])
                        ->orWhere(function ($q2) use ($surchargeId) {
                            $q2->where('line_source_id', $surchargeId)->where('description', 'Late check-out surcharge');
                        });
                })
                ->delete();

            if ($lateAmt > 0) {
                LedgerLine::create([
                    'ledger_id' => $ledgerId, 'line_source_id' => $surchargeId,
                    'description' => 'Late check-out surcharge', 'qty' => 1, 'unit_price' => $lateAmt, 'amount' => $lateAmt,
                    'staff_id' => $staffId,
                ]);
            }

            $base = (int) LedgerLine::where('ledger_id', $ledgerId)->where('voided', false)
                ->whereNotIn('line_source_id', [$scId, $vatId])->sum('amount');
            $tax = $this->billing->calcTax($base);

            if ($tax['service_charge'] > 0) {
                LedgerLine::create([
                    'ledger_id' => $ledgerId, 'line_source_id' => $scId,
                    'description' => "Service charge {$tax['service_charge_pct']}%", 'qty' => 1,
                    'unit_price' => $tax['service_charge'], 'amount' => $tax['service_charge'], 'staff_id' => $staffId,
                ]);
            }
            if ($tax['vat'] > 0) {
                LedgerLine::create([
                    'ledger_id' => $ledgerId, 'line_source_id' => $vatId,
                    'description' => "VAT {$tax['vat_pct']}%", 'qty' => 1,
                    'unit_price' => $tax['vat'], 'amount' => $tax['vat'], 'staff_id' => $staffId,
                ]);
            }

            $grandTotal = (int) LedgerLine::where('ledger_id', $ledgerId)->where('voided', false)->sum('amount');

            $refundKindId = Lookup::id(LookupType::PAYMENT_KIND, PaymentKind::REFUND);
            $paidSoFar = (int) Payment::where('ledger_id', $ledgerId)->where('payment_kind_id', '!=', $refundKindId)->sum('amount')
                - (int) Payment::where('ledger_id', $ledgerId)->where('payment_kind_id', $refundKindId)->sum('amount');
            $newTotal = (int) collect($payments)->sum('amount');
            $balance = $grandTotal - $paidSoFar - $newTotal;

            if ($balance > 0) {
                throw ValidationException::withMessages([
                    'payments' => 'Payment short by LKR '.number_format($balance / 100, 2).' — bill must be settled in full at checkout.',
                ]);
            }

            return ['grand_total' => $grandTotal, 'overpaid' => -$balance];
        });

        foreach ($payments as $p) {
            $this->billing->recordPayment([
                'ledger_id' => $ledgerId, 'method' => $p['method'], 'amount' => $p['amount'],
                'reference' => $p['reference'] ?? null, 'staff_id' => $staffId,
            ]);
        }
        if ($result['overpaid'] > 0) {
            $this->billing->recordPayment([
                'ledger_id' => $ledgerId, 'method' => $data['refund_method'] ?? PaymentMethod::CASH,
                'amount' => $result['overpaid'], 'kind' => PaymentKind::REFUND,
                'reason' => 'Deposit/overpayment refund at checkout', 'staff_id' => $staffId,
            ]);
        }

        $invoiceNo = $this->documentNumbers->next(Ledger::class, 'invoice_no', 'APT-INV-'.now()->year.'-');
        $booking->unit->loadMissing('unitType');

        DB::transaction(function () use ($booking, $ledgerId, $invoiceNo) {
            Ledger::where('id', $ledgerId)->update([
                'ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::SETTLED),
                'invoice_no' => $invoiceNo, 'settled_at' => now(),
            ]);
            $booking->update([
                'booking_status_id' => Lookup::id(LookupType::APARTMENT_BOOKING_STATUS, ApartmentBookingStatus::CHECKED_OUT),
                'checked_out_at' => now(),
            ]);

            // Unit goes DIRTY, not straight to AVAILABLE — mirrors Hotel's room
            // checkout: a turnover cleaning task is the only path back to
            // AVAILABLE (see ApartmentHousekeepingService::complete()).
            $booking->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::DIRTY)]);
            HousekeepingTask::create([
                'unit_id' => $booking->unit_id,
                'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING),
                'checklist' => collect($booking->unit->unitType->cleaning_checklist)
                    ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                'booking_id' => $booking->id,
            ]);
        });

        AuditLog::record('apartment_booking.checked_out', $booking, [
            'invoice_no' => $invoiceNo, 'total' => $result['grand_total'],
        ]);

        return array_merge($this->billing->present(Ledger::findOrFail($ledgerId)), ['invoice_no' => $invoiceNo]);
    }

    /**
     * @return array{ok: bool, refund_pct: float|int, refunded: int}
     */
    public function cancel(Booking $booking, string $reason, string $refundMethodCode, int $staffId): array
    {
        $booking->loadMissing(['ledger', 'customer', 'status']);

        if (! in_array($booking->status->code, [ApartmentBookingStatus::CONFIRMED, ApartmentBookingStatus::PENDING], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a {$booking->status->code} booking.",
            ]);
        }

        $rules = Settings::json('apartment.cancellation_rules', []);
        $daysUntil = (int) round(
            ($booking->check_in->copy()->startOfDay()->timestamp - now()->startOfDay()->timestamp) / 86400
        );
        $rule = collect($rules)->sortByDesc('daysBefore')->first(fn ($r) => $daysUntil >= $r['daysBefore']);
        $refundPct = $rule['refundPct'] ?? 0;

        $refunded = 0;
        if ($booking->ledger) {
            $totals = $this->billing->totals($booking->ledger);
            $paidNet = $totals['paid'] - $totals['refunded'];
            $refunded = (int) round($paidNet * $refundPct / 100);

            if ($refunded > 0) {
                $this->billing->recordPayment([
                    'ledger_id' => $booking->ledger->id, 'method' => $refundMethodCode, 'amount' => $refunded,
                    'kind' => PaymentKind::REFUND,
                    'reason' => "Cancellation policy: {$refundPct}% refund ({$daysUntil} days before check-in). {$reason}",
                    'staff_id' => $staffId,
                ]);
            }

            $booking->ledger->update(['ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::VOID)]);
        }

        $booking->update([
            'booking_status_id' => Lookup::id(LookupType::APARTMENT_BOOKING_STATUS, ApartmentBookingStatus::CANCELLED),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        AuditLog::record('apartment_booking.cancelled', $booking, [
            'reason' => $reason, 'refund_pct' => $refundPct, 'refunded' => $refunded,
        ]);

        return ['ok' => true, 'refund_pct' => $refundPct, 'refunded' => $refunded];
    }

    private function isStaleCheckoutLine(LedgerLine $line): bool
    {
        if (in_array($line->source->code, [ApartmentLineSource::SERVICE_CHARGE, ApartmentLineSource::VAT], true)) {
            return true;
        }

        return $line->source->code === ApartmentLineSource::SURCHARGE && $line->description === 'Late check-out surcharge';
    }
}
