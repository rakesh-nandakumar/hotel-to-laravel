<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Folio;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Payment;
use App\Models\Hotel\Shift;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Settings;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use Illuminate\Validation\ValidationException;

/**
 * Billing core — folio totals, payment recording, loyalty accrual. Ported
 * from the Node app's lib/billing.ts.
 *
 * MONEY INTEGRITY RULE: a folio's total is always the sum of its non-voided
 * line items; payments/refunds are tracked separately, never re-entered
 * manually — see phase2-nodejs-schema memory.
 */
class BillingService
{
    /**
     * @return array{total: int, paid: int, refunded: int, balance: int}
     */
    public function totals(Folio $folio): array
    {
        $folio->loadMissing([
            'lines' => fn ($q) => $q->notVoided()->oldest(),
            'payments' => fn ($q) => $q->oldest(),
            'payments.kind',
        ]);

        $total = (int) $folio->lines->sum('amount');
        $paid = (int) $folio->payments->filter(fn (Payment $p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount');
        $refunded = (int) $folio->payments->filter(fn (Payment $p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');

        return ['total' => $total, 'paid' => $paid, 'refunded' => $refunded, 'balance' => $total - $paid + $refunded];
    }

    /**
     * Folio + its lines/payments merged with computed totals, ready for a
     * JSON response — the Laravel equivalent of Node's folioWithTotals().
     *
     * @return array<string, mixed>
     */
    public function present(Folio $folio): array
    {
        $folio->loadMissing([
            'type', 'status',
            'lines' => fn ($q) => $q->notVoided()->oldest()->with(['source', 'staff:id,name']),
            'payments' => fn ($q) => $q->oldest()->with(['method', 'kind', 'staff:id,name']),
        ]);

        return array_merge($folio->toArray(), $this->totals($folio));
    }

    /**
     * Service-charge + VAT over a base amount — VAT charged on top of service
     * charge, always two separate line items. Node had two independent
     * implementations of this (POS vs. checkout); this is the single one.
     * Percentages default to the configured Settings but can be overridden
     * (e.g. takeaway orders waive service charge — VAT still applies).
     *
     * @return array{service_charge: int, service_charge_pct: float, vat: int, vat_pct: float}
     */
    public function calcTax(int $base, ?float $scPct = null, ?float $vatPct = null): array
    {
        $scPct ??= Settings::num('billing.service_charge_pct', 0);
        $vatPct ??= Settings::num('billing.vat_pct', 0);

        $serviceCharge = (int) round($base * $scPct / 100);
        $vat = (int) round(($base + $serviceCharge) * $vatPct / 100);

        return [
            'service_charge' => $serviceCharge, 'service_charge_pct' => $scPct,
            'vat' => $vat, 'vat_pct' => $vatPct,
        ];
    }

    /**
     * POS order totals: service charge waived for takeaway (no table service,
     * VAT still applies) — callers pass 0 for `$scPct` in that case.
     *
     * @return array{subtotal: int, discount: int, service_charge: int, vat: int, total: int}
     */
    public function calcOrderTotals(int $subtotal, int $discount, float $scPct, float $vatPct): array
    {
        $base = max(0, $subtotal - $discount);
        $tax = $this->calcTax($base, $scPct, $vatPct);

        return [
            'subtotal' => $subtotal, 'discount' => $discount,
            'service_charge' => $tax['service_charge'], 'vat' => $tax['vat'],
            'total' => $base + $tax['service_charge'] + $tax['vat'],
        ];
    }

    /**
     * The single choke point for all payment/refund writes (folios, and
     * later orders + corporate settlements) — offline-replay-safe via
     * idempotency_key, loyalty-redemption-aware, refund-reason-enforced.
     *
     * @param  array{folio_id?: int|null, corporate_account_id?: int|null, method: string, amount: int,
     *     kind?: string, reference?: string|null, reason?: string|null, staff_id: int,
     *     idempotency_key?: string|null, guest_id_for_loyalty?: int|null, order_id?: int|null}  $opts
     */
    public function recordPayment(array $opts): Payment
    {
        $kind = $opts['kind'] ?? PaymentKind::PAYMENT;

        if ($kind === PaymentKind::REFUND && trim((string) ($opts['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for every refund.']);
        }

        if (! empty($opts['idempotency_key'])) {
            $existing = Payment::query()->where('idempotency_key', $opts['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        if ($opts['method'] === PaymentMethod::LOYALTY_POINTS) {
            $this->redeemLoyalty($opts);
        }

        $openShift = Shift::query()->where('staff_id', $opts['staff_id'])->open()->first();

        $payment = Payment::create([
            'folio_id' => $opts['folio_id'] ?? null,
            'order_id' => $opts['order_id'] ?? null,
            'corporate_account_id' => $opts['corporate_account_id'] ?? null,
            'payment_method_id' => Lookup::id(LookupType::PAYMENT_METHOD, $opts['method']),
            'payment_kind_id' => Lookup::id(LookupType::PAYMENT_KIND, $kind),
            'amount' => $opts['amount'],
            'reference' => $opts['reference'] ?? null,
            'reason' => $opts['reason'] ?? null,
            'staff_id' => $opts['staff_id'],
            'shift_id' => $openShift?->id,
            'idempotency_key' => $opts['idempotency_key'] ?? null,
        ]);

        AuditLog::record(
            $kind === PaymentKind::REFUND ? 'payment.refunded' : 'payment.recorded',
            $payment,
            ['method' => $opts['method'], 'amount' => $opts['amount'], 'reason' => $opts['reason'] ?? null],
        );

        return $payment;
    }

    /**
     * Loyalty accrual on settled spend (rooms + restaurant + venues). Earn
     * rate is a Setting; always increments lifetime_spend, only logs a
     * ledger entry if points were actually earned.
     */
    public function accrueLoyalty(int $guestId, int $spentCents, string $refType, ?int $refId, int $staffId): int
    {
        $per1000 = (int) Settings::num('loyalty.points_per_1000lkr', 0);
        $points = intdiv($spentCents, 100000) * $per1000;

        $guest = Guest::query()->findOrFail($guestId);
        $guest->increment('lifetime_spend', $spentCents);

        if ($points > 0) {
            $guest->increment('loyalty_points', $points);
            $guest->loyaltyTransactions()->create([
                'points' => $points,
                'reason' => "Earned on {$refType} spend",
                'ref_type' => $refType,
                'ref_id' => $refId,
                'staff_id' => $staffId,
            ]);
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function redeemLoyalty(array $opts): void
    {
        if (empty($opts['guest_id_for_loyalty'])) {
            throw ValidationException::withMessages(['method' => 'Loyalty payment requires a guest.']);
        }

        $pointValue = Settings::num('loyalty.point_value_cents', 100);
        $pointsNeeded = (int) ceil($opts['amount'] / $pointValue);

        $guest = Guest::query()->findOrFail($opts['guest_id_for_loyalty']);
        if ($guest->loyalty_points < $pointsNeeded) {
            throw ValidationException::withMessages([
                'amount' => "Not enough points: needs {$pointsNeeded}, has {$guest->loyalty_points}.",
            ]);
        }

        $guest->decrement('loyalty_points', $pointsNeeded);
        $guest->loyaltyTransactions()->create([
            'points' => -$pointsNeeded,
            'reason' => $opts['reason'] ?? 'Redeemed against bill',
            'ref_type' => isset($opts['folio_id']) ? 'FOLIO' : 'ORDER',
            'ref_id' => $opts['folio_id'] ?? $opts['order_id'] ?? null,
            'staff_id' => $opts['staff_id'],
        ]);
    }
}
