<?php

namespace App\Services;

use App\Models\Lookup;
use App\Models\Till;
use App\Models\TillMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\TillMovementType;
use App\Support\Lookups\TillSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Till lifecycle + the append-only cash-movement ledger. This is the single
 * source of truth for physical cash — a session's balance is always the sum
 * of its till_movements rows, never derived from sales reports (see the
 * class doc on TillMovement). Every module's payment recording
 * (Hotel\BillingService, Apartment\ApartmentBillingService) calls
 * recordCashMovementForPayment() so cash from any source lands in exactly
 * one ledger.
 */
class TillService
{
    /**
     * The opening balance normally carries over as-is from the till's last
     * closing count. A different opening_balance is a claimed variance
     * (theft, an uncounted top-up, ...) and must come with a reason —
     * enforced here rather than in OpenTillRequest because the comparison
     * value (the last closing count) isn't known until this query runs.
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

        $lastClosing = $this->lastClosingBalance($till->id);
        $hasVariance = $lastClosing !== null && $openingBalance !== $lastClosing;

        if ($hasVariance && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when the opening balance differs from the last closing balance.']);
        }

        return DB::transaction(function () use ($till, $staffId, $openingBalance, $hasVariance, $reason) {
            $session = TillSession::create([
                'till_id' => $till->id,
                'status_id' => Lookup::id(LookupType::TILL_SESSION_STATUS, TillSessionStatus::OPEN),
                'opened_by' => $staffId,
                'opening_cash' => $openingBalance,
            ]);

            $this->recordMovement(
                $session, TillMovementType::OPENING_BALANCE, $openingBalance, $staffId,
                reason: $hasVariance ? $reason : 'Till opened',
            );

            AuditLog::record('till.opened', $session, ['till' => $till->name, 'opening_balance' => $openingBalance]);

            return $session;
        });
    }

    /**
     * Close with counted cash → automatic reconciliation. Any gap between what
     * the ledger expects and what was actually counted is recorded as its own
     * CLOSING_ADJUSTMENT movement, so the ledger's own running total always
     * matches the counted drawer at the moment of close. A variance must
     * always come with the closer's own reason — never a canned label — so
     * it means something in a reconciliation dispute later.
     */
    public function closeTill(TillSession $session, int $countedAmount, ?string $reason, ?string $notes, User $actor): TillSession
    {
        if ($session->closed_at) {
            throw ValidationException::withMessages(['till' => 'Till session is not open.']);
        }
        if ($session->opened_by !== $actor->id && ! $actor->hasPermissionTo('till.close_any')) {
            abort(403, 'Not your till session.');
        }

        $expected = $this->expectedBalance($session);
        $variance = $countedAmount - $expected;

        if ($variance !== 0 && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required when the counted cash differs from the expected balance.']);
        }

        return DB::transaction(function () use ($session, $countedAmount, $expected, $variance, $reason, $notes, $actor) {
            if ($variance !== 0) {
                $this->recordMovement($session, TillMovementType::CLOSING_ADJUSTMENT, $variance, $actor->id, reason: $reason);
            }

            $session->update([
                'status_id' => Lookup::id(LookupType::TILL_SESSION_STATUS, TillSessionStatus::CLOSED),
                'closed_by' => $actor->id,
                'closing_cash' => $countedAmount,
                'expected_cash' => $expected,
                'variance' => $variance,
                'notes' => $notes,
                'closed_at' => now(),
            ]);

            AuditLog::record('till.closed', $session, ['expected_cash' => $expected, 'closing_cash' => $countedAmount, 'variance' => $variance]);

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

    public function currentSessionForStaff(int $staffId): ?TillSession
    {
        return TillSession::query()->where('opened_by', $staffId)->open()->first();
    }

    /** The counted cash from this till's most recent closed session — the reference the next open compares against. Null if it's never been closed. */
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
     * instead of one per till.
     *
     * @param  list<int>  $tillIds
     * @return array<int, int> till_id => last closing_cash, omitted for a till never closed
     */
    public function lastClosingBalances(array $tillIds): array
    {
        return TillSession::query()
            ->whereIn('till_id', $tillIds)
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->get(['till_id', 'closing_cash'])
            ->unique('till_id')
            ->pluck('closing_cash', 'till_id')
            ->map(fn ($value) => (int) $value)
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
}
