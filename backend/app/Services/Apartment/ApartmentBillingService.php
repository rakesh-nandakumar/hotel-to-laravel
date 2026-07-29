<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Ledger;
use App\Models\Apartment\Payment;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Settings;
use App\Services\TillService;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\TillMovementType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing core for the Apartments module — ledger totals, payment recording.
 * Deliberately mirrors the Hotel module's billing service's
 * (App\Services\Hotel\BillingService) method shapes so the two modules stay
 * easy to reason about side by side, but is its own implementation over the
 * Apartments module's own tables (no shared Folio/Payment rows with Hotel —
 * see the apartments-module plan).
 *
 * MONEY INTEGRITY RULE: a ledger's total is always the sum of its non-voided
 * line items; payments/refunds are tracked separately, never re-entered
 * manually.
 */
class ApartmentBillingService
{
    public function __construct(private readonly TillService $till) {}

    /**
     * @return array{total: int, paid: int, refunded: int, balance: int}
     */
    public function totals(Ledger $ledger): array
    {
        $ledger->loadMissing([
            'lines' => fn ($q) => $q->notVoided()->oldest(),
            'payments' => fn ($q) => $q->oldest(),
            'payments.kind',
        ]);

        $total = (int) $ledger->lines->sum('amount');
        $paid = (int) $ledger->payments->filter(fn (Payment $p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount');
        $refunded = (int) $ledger->payments->filter(fn (Payment $p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');

        return ['total' => $total, 'paid' => $paid, 'refunded' => $refunded, 'balance' => $total - $paid + $refunded];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Ledger $ledger): array
    {
        $ledger->loadMissing([
            'status',
            'lines' => fn ($q) => $q->notVoided()->oldest()->with(['source', 'staff:id,name']),
            'payments' => fn ($q) => $q->oldest()->with(['method', 'kind', 'staff:id,name']),
        ]);

        return array_merge($ledger->toArray(), $this->totals($ledger));
    }

    /**
     * Service-charge + VAT over a base amount — always two separate line
     * items, percentages default to Settings but can be overridden.
     *
     * @return array{service_charge: int, service_charge_pct: float, vat: int, vat_pct: float}
     */
    public function calcTax(int $base, ?float $scPct = null, ?float $vatPct = null): array
    {
        $scPct ??= Settings::num('apartment.service_charge_pct', 0);
        $vatPct ??= Settings::num('apartment.vat_pct', 0);

        $serviceCharge = (int) round($base * $scPct / 100);
        $vat = (int) round(($base + $serviceCharge) * $vatPct / 100);

        return [
            'service_charge' => $serviceCharge, 'service_charge_pct' => $scPct,
            'vat' => $vat, 'vat_pct' => $vatPct,
        ];
    }

    /**
     * The single choke point for all Apartments-module payment/refund writes.
     *
     * @param  array{ledger_id: int, method: string, amount: int, kind?: string,
     *     reference?: string|null, reason?: string|null, staff_id: int|null, idempotency_key?: string|null}  $opts
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

        // Cash can't move without a till open to record it in — checked before any
        // write so a blocked cash payment never leaves a dangling Payment row. A
        // null staff_id means this was posted by the system (e.g. the expired
        // sale-hold sweep, see ApartmentSalesService::releaseExpiredHolds()) —
        // there's no operator to attribute a till session to, so it's skipped
        // entirely rather than blocked.
        $tillSession = $opts['staff_id'] !== null ? $this->till->currentSessionForStaff($opts['staff_id']) : null;
        if ($opts['method'] === PaymentMethod::CASH && $opts['staff_id'] !== null && ! $tillSession) {
            throw ValidationException::withMessages(['method' => 'Open a till before accepting or refunding cash.']);
        }

        $payment = DB::transaction(function () use ($opts, $kind, $tillSession) {
            $payment = Payment::create([
                'ledger_id' => $opts['ledger_id'],
                'payment_method_id' => Lookup::id(LookupType::PAYMENT_METHOD, $opts['method']),
                'payment_kind_id' => Lookup::id(LookupType::PAYMENT_KIND, $kind),
                'amount' => $opts['amount'],
                'reference' => $opts['reference'] ?? null,
                'reason' => $opts['reason'] ?? null,
                'staff_id' => $opts['staff_id'],
                'till_session_id' => $tillSession?->id,
                'idempotency_key' => $opts['idempotency_key'] ?? null,
            ]);

            if ($opts['method'] === PaymentMethod::CASH && $tillSession) {
                $this->till->recordMovement(
                    $tillSession,
                    $kind === PaymentKind::REFUND ? TillMovementType::REFUND : TillMovementType::CASH_IN,
                    $opts['amount'],
                    $opts['staff_id'],
                    reason: $kind === PaymentKind::REFUND ? ($opts['reason'] ?? null) : null,
                    source: $payment,
                );
            }

            return $payment;
        });

        AuditLog::record(
            $kind === PaymentKind::REFUND ? 'apartment_payment.refunded' : 'apartment_payment.recorded',
            $payment,
            ['method' => $opts['method'], 'amount' => $opts['amount'], 'reason' => $opts['reason'] ?? null],
        );

        return $payment;
    }
}
