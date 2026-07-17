<?php

namespace App\Services\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\Folio;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\GroupBooking;
use App\Models\Hotel\Guest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Package;
use App\Models\Hotel\Payment;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\RoomItemCheck;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\DocumentNumberService;
use App\Services\Settings;
use App\Support\Lookups\CheckKind;
use App\Support\Lookups\FolioStatus;
use App\Support\Lookups\FolioType;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\RealtimeEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reservation lifecycle — booking, check-in, checkout, cancellation. Ported
 * from the Node app's routes/reservations.ts; see phase2-nodejs-business-logic
 * memory for the exact formulas and replay-safety rules this preserves.
 */
class ReservationService
{
    public function __construct(
        private readonly ReservationAvailabilityService $availability,
        private readonly RoomPricingService $pricing,
        private readonly BillingService $billing,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $staffId): Reservation
    {
        $checkIn = Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = Carbon::parse($data['check_out'])->startOfDay();

        return DB::transaction(function () use ($data, $checkIn, $checkOut, $staffId) {
            $guestId = $data['guest_id'] ?? null;
            if (! $guestId) {
                $guestId = Guest::create($data['new_guest'])->id;
            }

            $roomIds = array_column($data['rooms'], 'room_id');
            $freeRooms = $this->availability->assertRoomsAvailable($roomIds, $checkIn, $checkOut);

            $corp = isset($data['corporate_account_id'])
                ? CorporateAccount::query()->findOrFail($data['corporate_account_id'])
                : null;

            $nightList = $this->pricing->nights($checkIn, $checkOut);
            $nightCount = count($nightList);
            $firstNight = Carbon::parse($nightList[0]);

            $roomRates = [];
            $stayTotal = 0;
            foreach ($data['rooms'] as $roomInput) {
                $room = $freeRooms->get($roomInput['room_id']);
                $rate = $roomInput['nightly_rate'] ?? null;

                if ($rate === null) {
                    $rate = $this->pricing->nightlyRate($room->roomType, $firstNight);
                    if ($corp) {
                        $rate = (int) round($rate * (1 - $corp->discount_pct / 100));
                    }
                }

                $roomRates[] = ['room_id' => $roomInput['room_id'], 'nightly_rate' => $rate];
                $stayTotal += $rate * $nightCount;
            }

            $pkg = isset($data['package_id']) ? Package::query()->findOrFail($data['package_id']) : null;
            if ($pkg && $pkg->price_per_person_per_night > 0) {
                $stayTotal += $pkg->price_per_person_per_night * $data['adults'] * $nightCount;
            }

            $depositPct = Settings::num('billing.room_deposit_pct', 20);
            $depositDue = (int) round($stayTotal * $depositPct / 100);

            $group = null;
            if (! empty($data['group'])) {
                $group = GroupBooking::create([
                    'reference' => $this->documentNumbers->next(GroupBooking::class, 'reference', 'GRP-'),
                    'name' => $data['group']['name'],
                    'contact_name' => $data['group']['contact_name'] ?? null,
                    'contact_phone' => $data['group']['contact_phone'] ?? null,
                ]);
            }

            $reservation = Reservation::create([
                'code' => $this->documentNumbers->next(Reservation::class, 'code', 'RSV-'),
                'guest_id' => $guestId,
                'booking_channel_id' => Lookup::id(LookupType::BOOKING_CHANNEL, $data['channel']),
                'reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::CONFIRMED),
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $data['adults'],
                'children' => $data['children'] ?? 0,
                'package_id' => $data['package_id'] ?? null,
                'corporate_account_id' => $data['corporate_account_id'] ?? null,
                'group_booking_id' => $group?->id,
                'notes' => $data['notes'] ?? null,
                'deposit_due' => $depositDue,
            ]);

            $reservation->rooms()->createMany($roomRates);

            $folio = $reservation->folio()->create([
                'folio_type_id' => Lookup::id(LookupType::FOLIO_TYPE, FolioType::GUEST),
                'folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::OPEN),
            ]);

            if (! empty($data['deposit_payment'])) {
                $this->billing->recordPayment([
                    'folio_id' => $folio->id,
                    'method' => $data['deposit_payment']['method'],
                    'amount' => $data['deposit_payment']['amount'],
                    'kind' => PaymentKind::DEPOSIT,
                    'reference' => $data['deposit_payment']['reference'] ?? null,
                    'staff_id' => $staffId,
                    'guest_id_for_loyalty' => $guestId,
                ]);
            }

            AuditLog::record('reservation.created', $reservation, [
                'code' => $reservation->code, 'stay_total' => $stayTotal, 'deposit_due' => $depositDue,
            ]);

            return $reservation->load(['guest', 'folio', 'rooms.room']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function checkIn(Reservation $reservation, array $data, int $staffId): array
    {
        $reservation->loadMissing(['guest', 'package', 'folio', 'status', 'rooms.room.roomType', 'rooms.room.status']);

        if (! in_array($reservation->status->code, [ReservationStatus::CONFIRMED, ReservationStatus::PENDING], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot check in a {$reservation->status->code} reservation.",
            ]);
        }

        $idNumber = trim((string) ($data['id_number'] ?? '')) ?: $reservation->guest->id_number;
        if (! $idNumber) {
            throw ValidationException::withMessages([
                'id_number' => 'Guest ID/passport number is required at check-in.',
            ]);
        }
        if (! empty($data['id_number'])) {
            $reservation->guest->update(['id_number' => trim($data['id_number'])]);
        }

        foreach ($reservation->rooms as $rr) {
            if ($rr->room->status->code !== RoomStatus::AVAILABLE) {
                $suffix = $rr->room->status->code === RoomStatus::DIRTY ? ' until the cleaning checklist is submitted' : '';
                throw ValidationException::withMessages([
                    'rooms' => "Room {$rr->room->number} is {$rr->room->status->code} — cannot check in{$suffix}.",
                ]);
            }
        }

        $folio = $reservation->folio;
        $nightList = $this->pricing->nights($reservation->check_in, $reservation->check_out);

        DB::transaction(function () use ($reservation, $folio, $nightList, $data, $staffId) {
            $roomSourceId = Lookup::id(LookupType::LINE_SOURCE, LineSource::ROOM);
            foreach ($reservation->rooms as $rr) {
                foreach ($nightList as $date) {
                    FolioLine::create([
                        'folio_id' => $folio->id, 'line_source_id' => $roomSourceId,
                        'description' => "Room {$rr->room->number} — {$date}",
                        'qty' => 1, 'unit_price' => $rr->nightly_rate, 'amount' => $rr->nightly_rate,
                        'staff_id' => $staffId,
                    ]);
                }
            }

            if ($reservation->package && $reservation->package->price_per_person_per_night > 0) {
                $packageSourceId = Lookup::id(LookupType::LINE_SOURCE, LineSource::PACKAGE);
                $price = $reservation->package->price_per_person_per_night;
                foreach ($nightList as $date) {
                    FolioLine::create([
                        'folio_id' => $folio->id, 'line_source_id' => $packageSourceId,
                        'description' => "{$reservation->package->name} × {$reservation->adults} pax — {$date}",
                        'qty' => $reservation->adults, 'unit_price' => $price, 'amount' => $price * $reservation->adults,
                        'staff_id' => $staffId,
                    ]);
                }
            }

            if (! empty($data['apply_early_surcharge'])) {
                $amt = (int) Settings::num('billing.early_checkin_surcharge', 0);
                if ($amt > 0) {
                    FolioLine::create([
                        'folio_id' => $folio->id, 'line_source_id' => Lookup::id(LookupType::LINE_SOURCE, LineSource::SURCHARGE),
                        'description' => 'Early check-in surcharge', 'qty' => 1, 'unit_price' => $amt, 'amount' => $amt,
                        'staff_id' => $staffId,
                    ]);
                }
            }

            $occupiedId = Lookup::id(LookupType::ROOM_STATUS, RoomStatus::OCCUPIED);
            foreach ($reservation->rooms as $rr) {
                $rr->room->update(['room_status_id' => $occupiedId]);
            }

            $reservation->update([
                'reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::CHECKED_IN),
                'checked_in_at' => now(),
            ]);
        });

        foreach ($data['item_checks'] ?? [] as $check) {
            RoomItemCheck::create([
                'reservation_id' => $reservation->id, 'room_id' => $check['room_id'],
                'check_kind_id' => Lookup::id(LookupType::CHECK_KIND, CheckKind::CHECK_IN),
                'items' => $check['items'], 'staff_id' => $staffId,
            ]);
        }

        AuditLog::record('reservation.checked_in', $reservation, ['code' => $reservation->code]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['changed' => $reservation->rooms->pluck('room_id')->all()]));

        return $this->billing->present($folio->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutQuote(Reservation $reservation, bool $applyLate): array
    {
        $folio = $reservation->folio;
        if (! $folio) {
            throw ValidationException::withMessages(['reservation' => 'Reservation has no folio.']);
        }

        $totals = $this->billing->totals($folio);
        // 'lines' is already loaded (by totals(), filtered to notVoided+oldest) —
        // loadMissing with a dotted path adds 'source' to those same rows
        // without reapplying/losing that filter.
        $folio->loadMissing(['lines.source', 'lines.staff:id,name', 'type', 'status']);

        $lateAmt = $applyLate ? (int) Settings::num('billing.late_checkout_surcharge', 0) : 0;
        $cleanLines = $folio->lines->reject(fn (FolioLine $line) => $this->isStaleCheckoutLine($line));

        // Order-linked lines were already taxed at POS-order time — only
        // non-order lines feed the folio-level tax base (they still count
        // toward the grand total below).
        $base = (int) $cleanLines->whereNull('order_id')->sum('amount') + $lateAmt;
        $tax = $this->billing->calcTax($base);

        $grandTotal = (int) $cleanLines->sum('amount') + $lateAmt + $tax['service_charge'] + $tax['vat'];

        return [
            'folio' => array_merge($folio->toArray(), $totals),
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
    public function checkout(Reservation $reservation, array $data, int $staffId): array
    {
        $reservation->loadMissing(['guest', 'folio', 'status', 'rooms.room.roomType']);

        if (! $reservation->folio) {
            throw ValidationException::withMessages(['reservation' => 'Reservation has no folio.']);
        }
        if ($reservation->status->code !== ReservationStatus::CHECKED_IN) {
            throw ValidationException::withMessages(['status' => 'Guest is not checked in.']);
        }

        $payments = $data['payments'] ?? [];
        $hasCorporateCredit = collect($payments)->contains(fn ($p) => $p['method'] === PaymentMethod::CORPORATE_CREDIT);
        if ($hasCorporateCredit && ! $reservation->corporate_account_id) {
            throw ValidationException::withMessages([
                'payments' => 'Corporate credit is only available for corporate account bookings.',
            ]);
        }

        $folioId = $reservation->folio->id;
        $lateAmt = ! empty($data['apply_late_surcharge']) ? (int) Settings::num('billing.late_checkout_surcharge', 0) : 0;

        $result = DB::transaction(function () use ($folioId, $lateAmt, $payments, $staffId) {
            $scId = Lookup::id(LookupType::LINE_SOURCE, LineSource::SERVICE_CHARGE);
            $vatId = Lookup::id(LookupType::LINE_SOURCE, LineSource::VAT);
            $surchargeId = Lookup::id(LookupType::LINE_SOURCE, LineSource::SURCHARGE);

            // Retry safety: strip stale folio-level tax/late-surcharge lines left
            // by a previously-interrupted checkout — never taxed twice. Scoped to
            // order_id IS NULL: an order's own SC/VAT lines (from postOrderToFolio)
            // must never be touched here, or a later checkout would silently erase
            // that order's charges from the bill.
            FolioLine::where('folio_id', $folioId)
                ->whereNull('order_id')
                ->where(function ($q) use ($scId, $vatId, $surchargeId) {
                    $q->whereIn('line_source_id', [$scId, $vatId])
                        ->orWhere(function ($q2) use ($surchargeId) {
                            $q2->where('line_source_id', $surchargeId)->where('description', 'Late check-out surcharge');
                        });
                })
                ->delete();

            if ($lateAmt > 0) {
                FolioLine::create([
                    'folio_id' => $folioId, 'line_source_id' => $surchargeId,
                    'description' => 'Late check-out surcharge', 'qty' => 1, 'unit_price' => $lateAmt, 'amount' => $lateAmt,
                    'staff_id' => $staffId,
                ]);
            }

            // Order-linked lines were already taxed at POS-order time — excluded
            // from the folio-level base (they still count toward grand total).
            $base = (int) FolioLine::where('folio_id', $folioId)->where('voided', false)->whereNull('order_id')
                ->whereNotIn('line_source_id', [$scId, $vatId])->sum('amount');
            $tax = $this->billing->calcTax($base);

            if ($tax['service_charge'] > 0) {
                FolioLine::create([
                    'folio_id' => $folioId, 'line_source_id' => $scId,
                    'description' => "Service charge {$tax['service_charge_pct']}%", 'qty' => 1,
                    'unit_price' => $tax['service_charge'], 'amount' => $tax['service_charge'], 'staff_id' => $staffId,
                ]);
            }
            if ($tax['vat'] > 0) {
                FolioLine::create([
                    'folio_id' => $folioId, 'line_source_id' => $vatId,
                    'description' => "VAT {$tax['vat_pct']}%", 'qty' => 1,
                    'unit_price' => $tax['vat'], 'amount' => $tax['vat'], 'staff_id' => $staffId,
                ]);
            }

            $grandTotal = (int) FolioLine::where('folio_id', $folioId)->where('voided', false)->sum('amount');

            $refundKindId = Lookup::id(LookupType::PAYMENT_KIND, PaymentKind::REFUND);
            $paidSoFar = (int) Payment::where('folio_id', $folioId)->where('payment_kind_id', '!=', $refundKindId)->sum('amount')
                - (int) Payment::where('folio_id', $folioId)->where('payment_kind_id', $refundKindId)->sum('amount');
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
                'folio_id' => $folioId, 'method' => $p['method'], 'amount' => $p['amount'],
                'reference' => $p['reference'] ?? null, 'staff_id' => $staffId,
                'guest_id_for_loyalty' => $reservation->guest_id,
            ]);
        }
        if ($result['overpaid'] > 0) {
            $this->billing->recordPayment([
                'folio_id' => $folioId, 'method' => $data['refund_method'] ?? PaymentMethod::CASH,
                'amount' => $result['overpaid'], 'kind' => PaymentKind::REFUND,
                'reason' => 'Deposit/overpayment refund at checkout', 'staff_id' => $staffId,
            ]);
        }

        foreach ($data['item_checks'] ?? [] as $check) {
            RoomItemCheck::create([
                'reservation_id' => $reservation->id, 'room_id' => $check['room_id'],
                'check_kind_id' => Lookup::id(LookupType::CHECK_KIND, CheckKind::CHECK_OUT),
                'items' => $check['items'], 'staff_id' => $staffId,
            ]);
        }

        $invoiceNo = $this->documentNumbers->next(Folio::class, 'invoice_no', 'INV-'.now()->year.'-');

        DB::transaction(function () use ($reservation, $folioId, $invoiceNo) {
            Folio::where('id', $folioId)->update([
                'folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::SETTLED),
                'invoice_no' => $invoiceNo, 'settled_at' => now(),
            ]);
            $reservation->update([
                'reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::CHECKED_OUT),
                'checked_out_at' => now(),
            ]);

            $dirtyId = Lookup::id(LookupType::ROOM_STATUS, RoomStatus::DIRTY);
            $pendingTaskId = Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING);
            foreach ($reservation->rooms as $rr) {
                $rr->room->update(['room_status_id' => $dirtyId]);
                HousekeepingTask::create([
                    'room_id' => $rr->room_id,
                    'task_status_id' => $pendingTaskId,
                    'checklist' => collect($rr->room->roomType->cleaning_checklist)
                        ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                    'reservation_id' => $reservation->id,
                ]);
            }
        });

        $points = $this->billing->accrueLoyalty($reservation->guest_id, $result['grand_total'], 'FOLIO', $folioId, $staffId);
        AuditLog::record('reservation.checked_out', $reservation, [
            'invoice_no' => $invoiceNo, 'total' => $result['grand_total'], 'loyalty_earned' => $points,
        ]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['changed' => $reservation->rooms->pluck('room_id')->all()]));

        return array_merge($this->billing->present(Folio::findOrFail($folioId)), ['invoice_no' => $invoiceNo]);
    }

    /**
     * @return array{ok: bool, refund_pct: float|int, refunded: int}
     */
    public function cancel(Reservation $reservation, string $reason, string $refundMethodCode, int $staffId): array
    {
        $reservation->loadMissing(['folio', 'guest', 'status']);

        if (! in_array($reservation->status->code, [ReservationStatus::CONFIRMED, ReservationStatus::PENDING], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a {$reservation->status->code} reservation.",
            ]);
        }

        $rules = Settings::json('policies.cancellation_rules', []);
        $daysUntil = (int) round(
            ($reservation->check_in->copy()->startOfDay()->timestamp - now()->startOfDay()->timestamp) / 86400
        );
        $rule = collect($rules)->sortByDesc('daysBefore')->first(fn ($r) => $daysUntil >= $r['daysBefore']);
        $refundPct = $rule['refundPct'] ?? 0;

        $refunded = 0;
        if ($reservation->folio) {
            $totals = $this->billing->totals($reservation->folio);
            $paidNet = $totals['paid'] - $totals['refunded'];
            $refunded = (int) round($paidNet * $refundPct / 100);

            if ($refunded > 0) {
                $this->billing->recordPayment([
                    'folio_id' => $reservation->folio->id, 'method' => $refundMethodCode, 'amount' => $refunded,
                    'kind' => PaymentKind::REFUND,
                    'reason' => "Cancellation policy: {$refundPct}% refund ({$daysUntil} days before check-in). {$reason}",
                    'staff_id' => $staffId,
                ]);
            }

            $reservation->folio->update(['folio_status_id' => Lookup::id(LookupType::FOLIO_STATUS, FolioStatus::VOID)]);
        }

        $reservation->update([
            'reservation_status_id' => Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::CANCELLED),
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        AuditLog::record('reservation.cancelled', $reservation, [
            'reason' => $reason, 'refund_pct' => $refundPct, 'refunded' => $refunded,
        ]);

        return ['ok' => true, 'refund_pct' => $refundPct, 'refunded' => $refunded];
    }

    /**
     * Room-guest POS orders and laundry charges must map to a checked-in
     * reservation with an open folio. Shared by `OrderService` and
     * `LaundryService` — ported from Node's `checkedInReservationForRoom()`.
     */
    public function findCheckedInReservationForRoom(int $roomId): Reservation
    {
        $reservationRoom = ReservationRoom::query()
            ->where('room_id', $roomId)
            ->whereHas('reservation', fn ($q) => $q->statusCode(ReservationStatus::CHECKED_IN))
            ->with('reservation.folio', 'reservation.guest')
            ->first();

        if (! $reservationRoom || ! $reservationRoom->reservation->folio) {
            throw ValidationException::withMessages(['room_id' => 'No checked-in guest in that room.']);
        }

        return $reservationRoom->reservation;
    }

    private function isStaleCheckoutLine(FolioLine $line): bool
    {
        // Only folio-level tax lines (order_id null) are ever "stale" — an
        // order's own SC/VAT lines (from postOrderToFolio) are legitimate
        // charges and must never be treated as leftovers to strip.
        if ($line->order_id !== null) {
            return false;
        }

        if (in_array($line->source->code, [LineSource::SERVICE_CHARGE, LineSource::VAT], true)) {
            return true;
        }

        return $line->source->code === LineSource::SURCHARGE && $line->description === 'Late check-out surcharge';
    }
}
