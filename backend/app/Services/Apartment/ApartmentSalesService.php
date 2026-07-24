<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Customer;
use App\Models\Apartment\Ledger;
use App\Models\Apartment\LedgerLine;
use App\Models\Apartment\Sale;
use App\Models\Apartment\Unit;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\DocumentNumberService;
use App\Services\Settings;
use App\Support\Lookups\ApartmentLedgerStatus;
use App\Support\Lookups\ApartmentLineSource;
use App\Support\Lookups\ApartmentListingType;
use App\Support\Lookups\ApartmentSaleStatus;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sale/purchase pipeline for units listed FOR_SALE — inquiry (a lead, unit
 * stays fully available) → reserve (holding deposit locks the unit out of
 * rental/other-buyer availability — see ApartmentAvailabilityService, which
 * already excludes any non-AVAILABLE/OCCUPIED unit_status) → agreement
 * signed → complete (paid in full, unit permanently marked SOLD) — or
 * cancel at any point before completion, applying the configured deposit
 * forfeiture policy.
 */
class ApartmentSalesService
{
    public function __construct(
        private readonly ApartmentBillingService $billing,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $staffId): Sale
    {
        return DB::transaction(function () use ($data, $staffId) {
            $unit = Unit::query()->listingTypeCode(ApartmentListingType::SALE)->findOrFail($data['unit_id']);
            if ($unit->status->code !== ApartmentUnitStatus::AVAILABLE) {
                throw ValidationException::withMessages([
                    'unit_id' => "Unit {$unit->unit_no} is {$unit->status->code} — not available to list a sale against.",
                ]);
            }

            $customerId = $data['customer_id'] ?? null;
            if (! $customerId) {
                $customerId = Customer::create($data['new_customer'])->id;
            }

            $sale = Sale::create([
                'code' => $this->documentNumbers->next(Sale::class, 'code', 'SAL-'),
                'unit_id' => $unit->id,
                'customer_id' => $customerId,
                'sale_status_id' => Lookup::id(LookupType::APARTMENT_SALE_STATUS, ApartmentSaleStatus::INQUIRY),
                'agreed_price' => $data['agreed_price'],
                'notes' => $data['notes'] ?? null,
            ]);

            $ledger = $sale->ledger()->create([
                'ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::OPEN),
            ]);

            LedgerLine::create([
                'ledger_id' => $ledger->id,
                'line_source_id' => Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::INSTALLMENT),
                'description' => "Unit {$unit->unit_no} — agreed purchase price",
                'qty' => 1, 'unit_price' => $sale->agreed_price, 'amount' => $sale->agreed_price,
                'staff_id' => $staffId,
            ]);

            AuditLog::record('apartment_sale.created', $sale, ['code' => $sale->code, 'agreed_price' => $sale->agreed_price]);

            return $sale->load(['customer', 'unit.unitType', 'status', 'ledger']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reserve(Sale $sale, array $data, int $staffId): Sale
    {
        $sale->loadMissing(['status', 'unit', 'ledger']);
        if ($sale->status->code !== ApartmentSaleStatus::INQUIRY) {
            throw ValidationException::withMessages(['status' => "Cannot reserve a {$sale->status->code} sale."]);
        }

        $holdDays = (int) Settings::num('apartment.sale_reservation_hold_days', 14);
        $reservedUntil = ! empty($data['reserved_until']) ? Carbon::parse($data['reserved_until']) : now()->addDays($holdDays);

        DB::transaction(function () use ($sale, $data, $reservedUntil, $staffId) {
            $sale->update([
                'sale_status_id' => Lookup::id(LookupType::APARTMENT_SALE_STATUS, ApartmentSaleStatus::RESERVED),
                'reserved_until' => $reservedUntil,
            ]);

            $sale->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::RESERVED)]);

            if (! empty($data['deposit_payment'])) {
                $this->billing->recordPayment([
                    'ledger_id' => $sale->ledger->id,
                    'method' => $data['deposit_payment']['method'],
                    'amount' => $data['deposit_payment']['amount'],
                    'kind' => PaymentKind::DEPOSIT,
                    'reference' => $data['deposit_payment']['reference'] ?? null,
                    'staff_id' => $staffId,
                ]);
            }
        });

        AuditLog::record('apartment_sale.reserved', $sale, ['reserved_until' => $reservedUntil->toDateString()]);

        return $sale->fresh(['customer', 'unit', 'status']);
    }

    public function signAgreement(Sale $sale, int $staffId): Sale
    {
        $sale->loadMissing('status');
        if ($sale->status->code !== ApartmentSaleStatus::RESERVED) {
            throw ValidationException::withMessages(['status' => "Cannot sign an agreement for a {$sale->status->code} sale."]);
        }

        $sale->update([
            'sale_status_id' => Lookup::id(LookupType::APARTMENT_SALE_STATUS, ApartmentSaleStatus::AGREEMENT_SIGNED),
            'agreement_signed_at' => now(),
        ]);

        AuditLog::record('apartment_sale.agreement_signed', $sale, ['code' => $sale->code]);

        return $sale->fresh(['customer', 'unit', 'status']);
    }

    public function complete(Sale $sale, int $staffId): Sale
    {
        $sale->loadMissing(['status', 'unit', 'ledger']);
        if ($sale->status->code !== ApartmentSaleStatus::AGREEMENT_SIGNED) {
            throw ValidationException::withMessages(['status' => "Cannot complete a {$sale->status->code} sale — the agreement must be signed first."]);
        }

        $totals = $this->billing->totals($sale->ledger);
        if ($totals['balance'] > 0) {
            throw ValidationException::withMessages([
                'ledger' => 'Sale price is not fully paid — balance LKR '.number_format($totals['balance'] / 100, 2).' remaining.',
            ]);
        }

        $invoiceNo = $this->documentNumbers->next(Ledger::class, 'invoice_no', 'APT-SAL-'.now()->year.'-');

        DB::transaction(function () use ($sale, $invoiceNo) {
            $sale->update([
                'sale_status_id' => Lookup::id(LookupType::APARTMENT_SALE_STATUS, ApartmentSaleStatus::COMPLETED),
                'handover_at' => now(),
            ]);
            $sale->ledger->update([
                'ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::SETTLED),
                'invoice_no' => $invoiceNo, 'settled_at' => now(),
            ]);
            $sale->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::SOLD)]);
        });

        AuditLog::record('apartment_sale.completed', $sale, ['invoice_no' => $invoiceNo]);

        return $sale->fresh(['customer', 'unit', 'status', 'ledger']);
    }

    /**
     * @return array{ok: bool, refund_pct: float|int, refunded: int}
     */
    public function cancel(Sale $sale, string $reason, ?int $staffId): array
    {
        $sale->loadMissing(['status', 'unit', 'ledger']);
        if (! in_array($sale->status->code, [ApartmentSaleStatus::INQUIRY, ApartmentSaleStatus::RESERVED, ApartmentSaleStatus::AGREEMENT_SIGNED], true)) {
            throw ValidationException::withMessages(['status' => "Cannot cancel a {$sale->status->code} sale."]);
        }

        $forfeitPct = Settings::num('apartment.sale_deposit_forfeit_pct', 100);
        $refundPct = 100 - $forfeitPct;

        $refunded = 0;
        if ($sale->ledger) {
            $totals = $this->billing->totals($sale->ledger);
            $paidNet = $totals['paid'] - $totals['refunded'];
            $refunded = (int) round($paidNet * $refundPct / 100);

            if ($refunded > 0) {
                $this->billing->recordPayment([
                    'ledger_id' => $sale->ledger->id, 'method' => 'cash', 'amount' => $refunded,
                    'kind' => PaymentKind::REFUND,
                    'reason' => "Sale cancelled: {$forfeitPct}% deposit forfeit. {$reason}",
                    'staff_id' => $staffId,
                ]);
            }

            $sale->ledger->update(['ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::VOID)]);
        }

        DB::transaction(function () use ($sale, $reason) {
            $sale->update([
                'sale_status_id' => Lookup::id(LookupType::APARTMENT_SALE_STATUS, ApartmentSaleStatus::CANCELLED),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            if ($sale->unit->status->code === ApartmentUnitStatus::RESERVED) {
                $sale->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE)]);
            }
        });

        AuditLog::record('apartment_sale.cancelled', $sale, ['reason' => $reason, 'refund_pct' => $refundPct, 'refunded' => $refunded]);

        return ['ok' => true, 'refund_pct' => $refundPct, 'refunded' => $refunded];
    }

    /**
     * Scheduled sweep: auto-cancel RESERVED sales whose hold has expired
     * with no signed agreement — frees the unit back to AVAILABLE without
     * waiting on staff to notice.
     *
     * @return int number of holds released
     */
    public function releaseExpiredHolds(): int
    {
        $expired = Sale::query()
            ->statusCode(ApartmentSaleStatus::RESERVED)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now()->startOfDay()->toDateString())
            ->get();

        foreach ($expired as $sale) {
            $this->cancel($sale, 'Reservation hold expired with no signed agreement.', null);
        }

        return $expired->count();
    }
}
