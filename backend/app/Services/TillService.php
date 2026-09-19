<?php

namespace App\Services;

use App\Models\Apartment\Payment as ApartmentPayment;
use App\Models\Hotel\Payment as HotelPayment;
use App\Models\Lookup;
use App\Models\Till;
use App\Models\TillMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\TillMovementType;
use App\Support\Lookups\TillSessionStatus;
use App\Support\TillActivityCategory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Till lifecycle + the append-only cash-movement ledger. This is the single
 * source of truth for physical cash — a session's balance is always the sum
 * of its till_movements rows, never derived from sales reports (see the
 * class doc on TillMovement). Every module's payment recording
 * (Hotel\BillingService, Apartment\ApartmentBillingService) records a cash
 * movement through recordMovement() so cash from any source lands in exactly
 * one ledger.
 */
class TillService
{
    /**
     * The till's last closing count is a *suggestion* for the opening balance,
     * never a rule — the drawer is counted fresh at every open, and an operator
     * may legitimately open with a different amount (an overnight safe drop, a
     * float top-up, a short count). So a differing opening_balance is accepted;
     * it only has to say why, and both the carried-over figure and the
     * resulting variance are stored so that decision stays auditable.
     *
     * The reason is enforced here rather than in OpenTillRequest because the
     * comparison value (the last closing count) isn't known until this query
     * runs.
     */
    public function openTill(int $tillId, int $staffId, int $openingBalance, ?string $reason = null): TillSession
    {
        $till = Till::query()->findOrFail($tillId);
        if (! $till->is_active) {
            throw ValidationException::withMessages(['till_id' => 'This till is inactive.']);
        }

        if ($this->currentSessionForStaff($staffId)) {
            throw ValidationException::withMessages(['till' => 'You already have an open till session — close it first.']);
        }

        $carried = $this->lastClosingBalance($till->id);
        $variance = $carried === null ? null : $openingBalance - $carried;
        $hasVariance = $variance !== null && $variance !== 0;

        if ($hasVariance && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when the opening balance differs from the last closing balance.']);
        }

        return DB::transaction(function () use ($till, $staffId, $openingBalance, $carried, $variance, $hasVariance, $reason) {
            $session = TillSession::create([
                'till_id' => $till->id,
                'status_id' => Lookup::id(LookupType::TILL_SESSION_STATUS, TillSessionStatus::OPEN),
                'opened_by' => $staffId,
                'opening_cash' => $openingBalance,
                'carried_opening_cash' => $carried,
                'opening_variance' => $variance,
                'opening_reason' => $hasVariance ? $reason : null,
            ]);

            $this->recordMovement(
                $session, TillMovementType::OPENING_BALANCE, $openingBalance, $staffId,
                reason: $hasVariance ? $reason : 'Till opened',
            );

            AuditLog::record('till.opened', $session, [
                'till' => $till->name,
                'opening_balance' => $openingBalance,
                'carried_opening_cash' => $carried,
                'opening_variance' => $variance,
            ]);

            return $session;
        });
    }

    /**
     * Close and reconcile. Counting the drawer is optional: a null
     * $countedAmount means the operator accepted the ledger's own expected
     * figure, which closes at zero variance by definition. A counted figure
     * that differs is a claimed variance — recorded as its own
     * CLOSING_ADJUSTMENT movement so the ledger's running total still matches
     * the physical drawer at the moment of close, and always carrying the
     * closer's own reason (never a canned label) so it means something in a
     * reconciliation dispute later.
     */
    public function closeTill(TillSession $session, ?int $countedAmount, ?string $reason, ?string $notes, User $actor): TillSession
    {
        if ($session->closed_at) {
            throw ValidationException::withMessages(['till' => 'Till session is not open.']);
        }
        if ($session->opened_by !== $actor->id && ! $actor->hasPermissionTo('till.close_any')) {
            abort(403, 'Not your till session.');
        }

        $expected = $this->expectedBalance($session);
        $counted = $countedAmount ?? $expected;
        $variance = $counted - $expected;

        if ($variance !== 0 && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when the counted cash differs from the expected balance.']);
        }

        return DB::transaction(function () use ($session, $counted, $expected, $variance, $reason, $notes, $actor) {
            if ($variance !== 0) {
                $this->recordMovement($session, TillMovementType::CLOSING_ADJUSTMENT, $variance, $actor->id, reason: $reason);
            }

            $session->update([
                'status_id' => Lookup::id(LookupType::TILL_SESSION_STATUS, TillSessionStatus::CLOSED),
                'closed_by' => $actor->id,
                'closing_cash' => $counted,
                'expected_cash' => $expected,
                'variance' => $variance,
                'notes' => $notes,
                'closed_at' => now(),
            ]);

            AuditLog::record('till.closed', $session, ['expected_cash' => $expected, 'closing_cash' => $counted, 'variance' => $variance]);

            return $session;
        });
    }

    /**
     * Append one ledger row. `$amount` is unsigned for every type except
     * CLOSING_ADJUSTMENT (whose sign is the variance direction and is passed
     * through as-is) — IN types are forced positive, OUT types forced
     * negative, so callers never have to remember the sign convention.
     */
    public function recordMovement(
        TillSession $session,
        string $type,
        int $amount,
        int $performedBy,
        ?string $reason = null,
        ?string $reference = null,
        ?string $notes = null,
        ?int $approvedBy = null,
        ?Model $source = null,
    ): TillMovement {
        if ($session->closed_at) {
            throw ValidationException::withMessages(['till' => 'This till session is closed.']);
        }
        if (in_array($type, TillMovementType::REASON_REQUIRED, true) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for this movement.']);
        }

        $signedAmount = match (true) {
            $type === TillMovementType::CLOSING_ADJUSTMENT => $amount,
            in_array($type, TillMovementType::OUT_TYPES, true) => -abs($amount),
            default => abs($amount),
        };

        $movement = new TillMovement([
            'till_session_id' => $session->id,
            'type_id' => Lookup::id(LookupType::TILL_MOVEMENT_TYPE, $type),
            'amount' => $signedAmount,
            'reason' => $reason,
            'reference' => $reference,
            'notes' => $notes,
            'performed_by' => $performedBy,
            'approved_by' => $approvedBy,
        ]);

        if ($source) {
            $movement->source()->associate($source);
        }

        $movement->save();

        return $movement;
    }

    /** Sum of every ledger row for the session — never a derived/estimated figure. */
    public function expectedBalance(TillSession $session): int
    {
        return (int) $session->movements()->sum('amount');
    }

    /**
     * The full close-out picture for a session: where every rupee in the drawer
     * came from, grouped by the activity that produced it, and what the drawer
     * is therefore expected to hold.
     *
     * Built from the ledger in a single pass, so the figures on screen add up
     * to exactly the balance the close reconciles against —
     * `opening_balance + net_change === expected_balance`, always. Categories
     * with no activity are omitted rather than listed at zero, so a hotel-only
     * tenant never sees empty apartment lines.
     *
     * @return array{opening_balance: int, collections: list<array{code: string, label: string, count: int, amount: int}>,
     *     collections_total: int, refunds: array{count: int, amount: int}, manual_cash_in: array{count: int, amount: int},
     *     manual_cash_out: list<array{code: string, label: string, count: int, amount: int}>, manual_cash_out_total: int,
     *     adjustments: array{count: int, amount: int}, net_change: int, expected_balance: int, movement_count: int}
     */
    public function sessionSummary(TillSession $session): array
    {
        /** @var EloquentCollection<int, TillMovement> $movements */
        $movements = $session->movements()->with('type:id,code,name,color')->get();
        $categories = $this->categoriseMovementSources($movements);

        $opening = 0;
        $expected = 0;
        $collections = [];   // activity category code => ['count' => int, 'amount' => int]
        $manualOut = [];     // movement type code => ['code' =>, 'label' =>, 'count' =>, 'amount' =>]
        $refunds = ['count' => 0, 'amount' => 0];
        $manualIn = ['count' => 0, 'amount' => 0];
        $adjustments = ['count' => 0, 'amount' => 0];

        foreach ($movements as $movement) {
            $code = $movement->type->code;
            $expected += $movement->amount;

            if ($code === TillMovementType::OPENING_BALANCE) {
                $opening += $movement->amount;
            } elseif ($code === TillMovementType::CLOSING_ADJUSTMENT) {
                $adjustments['count']++;
                $adjustments['amount'] += $movement->amount;
            } elseif ($code === TillMovementType::REFUND) {
                // Must be tested before OUT_TYPES, which REFUND is a member of —
                // a refund is money the guest took back, not a manual withdrawal,
                // and gets its own line on the statement.
                $refunds['count']++;
                $refunds['amount'] += $movement->amount;
            } elseif (in_array($code, TillMovementType::OUT_TYPES, true)) {
                $manualOut[$code] ??= ['code' => $code, 'label' => $movement->type->name, 'count' => 0, 'amount' => 0];
                $manualOut[$code]['count']++;
                $manualOut[$code]['amount'] += $movement->amount;
            } elseif ($movement->source_type === null) {
                // A cash-in with no originating Payment was keyed in by hand — a float top-up.
                $manualIn['count']++;
                $manualIn['amount'] += $movement->amount;
            } else {
                $category = $categories[$movement->source_type.'#'.$movement->source_id] ?? TillActivityCategory::OTHER;
                $collections[$category] ??= ['count' => 0, 'amount' => 0];
                $collections[$category]['count']++;
                $collections[$category]['amount'] += $movement->amount;
            }
        }

        // LABELS is ordered — walking it (rather than $collections) keeps the
        // line order stable no matter what order the cash actually arrived in.
        $collectionLines = [];
        foreach (TillActivityCategory::LABELS as $category => $label) {
            if (isset($collections[$category])) {
                $collectionLines[] = ['code' => $category, 'label' => $label] + $collections[$category];
            }
        }

        $collectionsTotal = array_sum(array_column($collectionLines, 'amount'));
        $manualOutTotal = array_sum(array_column($manualOut, 'amount'));

        return [
            'opening_balance' => $opening,
            'collections' => $collectionLines,
            'collections_total' => $collectionsTotal,
            'refunds' => $refunds,
            'manual_cash_in' => $manualIn,
            'manual_cash_out' => array_values($manualOut),
            'manual_cash_out_total' => $manualOutTotal,
            'adjustments' => $adjustments,
            'net_change' => $collectionsTotal + $refunds['amount'] + $manualIn['amount'] + $manualOutTotal + $adjustments['amount'],
            'expected_balance' => $expected,
            'movement_count' => $movements->count(),
        ];
    }

    public function currentSessionForStaff(int $staffId): ?TillSession
    {
        return TillSession::query()->where('opened_by', $staffId)->open()->first();
    }

    /** The counted cash from this till's most recent closed session — the amount the next open pre-fills with and compares against. Null if it's never been closed. */
    public function lastClosingBalance(int $tillId): ?int
    {
        $value = TillSession::query()
            ->where('till_id', $tillId)
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->value('closing_cash');

        return $value === null ? null : (int) $value;
    }

    /**
     * Batched form of lastClosingBalance() for a till listing — one query
     * instead of one per till, and carrying who counted it and when so the
     * open-till screen can show the operator where its suggested amount came
     * from rather than presenting a bare number to accept on trust.
     *
     * @param  list<int>  $tillIds
     * @return array<int, array{cash: int, closed_at: string|null, closed_by: string|null}> keyed by till_id, omitted for a till never closed
     */
    public function lastClosings(array $tillIds): array
    {
        return TillSession::query()
            ->with('closedBy:id,name')
            ->whereIn('till_id', $tillIds)
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->get(['id', 'till_id', 'closing_cash', 'closed_at', 'closed_by'])
            ->unique('till_id')
            ->mapWithKeys(fn (TillSession $session) => [$session->till_id => [
                'cash' => (int) $session->closing_cash,
                'closed_at' => $session->closed_at?->toIso8601String(),
                'closed_by' => $session->closedBy?->name,
            ]])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(int $staffId): ?array
    {
        $session = $this->currentSessionForStaff($staffId);
        if (! $session) {
            return null;
        }

        $session->loadMissing('till:id,name', 'status');

        return array_merge($session->toArray(), ['expected_balance' => $this->expectedBalance($session)]);
    }

    /**
     * Resolve every movement's originating Payment to an activity category —
     * two queries for the whole session however many movements it holds,
     * rather than a morphTo load per row.
     *
     * @param  EloquentCollection<int, TillMovement>  $movements
     * @return array<string, string> "{source_type}#{source_id}" => activity category code
     */
    private function categoriseMovementSources(EloquentCollection $movements): array
    {
        $idsByType = $movements
            ->filter(fn (TillMovement $movement) => $movement->source_type !== null)
            ->groupBy('source_type')
            ->map(fn ($group) => $group->pluck('source_id')->unique()->all());

        $categories = [];

        $hotelIds = $idsByType[HotelPayment::class] ?? [];
        if ($hotelIds !== []) {
            HotelPayment::query()
                ->with('folio.type:id,code')
                ->whereIn('id', $hotelIds)
                ->get(['id', 'folio_id', 'order_id', 'corporate_account_id'])
                ->each(function (HotelPayment $payment) use (&$categories): void {
                    $categories[HotelPayment::class.'#'.$payment->id] = TillActivityCategory::forHotelPayment($payment);
                });
        }

        $apartmentIds = $idsByType[ApartmentPayment::class] ?? [];
        if ($apartmentIds !== []) {
            ApartmentPayment::query()
                ->with('ledger:id,booking_id,lease_id,sale_id')
                ->whereIn('id', $apartmentIds)
                ->get(['id', 'ledger_id'])
                ->each(function (ApartmentPayment $payment) use (&$categories): void {
                    $categories[ApartmentPayment::class.'#'.$payment->id] = TillActivityCategory::forApartmentPayment($payment);
                });
        }

        return $categories;
    }
}
