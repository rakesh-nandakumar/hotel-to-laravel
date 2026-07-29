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
    public function openTill(int $tillId, int $staffId, int $openingBalance): TillSession
    {
        $till = Till::query()->findOrFail($tillId);
        if (! $till->is_active) {
            throw ValidationException::withMessages(['till_id' => 'This till is inactive.']);
        }

        if ($this->currentSessionForStaff($staffId)) {
            throw ValidationException::withMessages(['till' => 'You already have an open till session — close it first.']);
        }

        return DB::transaction(function () use ($till, $staffId, $openingBalance) {
            $session = TillSession::create([
                'till_id' => $till->id,
                'status_id' => Lookup::id(LookupType::TILL_SESSION_STATUS, TillSessionStatus::OPEN),
                'opened_by' => $staffId,
                'opening_cash' => $openingBalance,
            ]);

            $this->recordMovement($session, TillMovementType::OPENING_BALANCE, $openingBalance, $staffId, reason: 'Till opened');

            AuditLog::record('till.opened', $session, ['till' => $till->name, 'opening_balance' => $openingBalance]);

            return $session;
        });
    }

    /**
     * Close with counted cash → automatic reconciliation. Any gap between what
     * the ledger expects and what was actually counted is recorded as its own
     * CLOSING_ADJUSTMENT movement, so the ledger's own running total always
     * matches the counted drawer at the moment of close.
     */
    public function closeTill(TillSession $session, int $countedAmount, ?string $notes, User $actor): TillSession
    {
        if ($session->closed_at) {
            throw ValidationException::withMessages(['till' => 'Till session is not open.']);
        }
        if ($session->opened_by !== $actor->id && ! $actor->hasPermissionTo('till.close_any')) {
            abort(403, 'Not your till session.');
        }

        return DB::transaction(function () use ($session, $countedAmount, $notes, $actor) {
            $expected = $this->expectedBalance($session);
            $variance = $countedAmount - $expected;

            if ($variance !== 0) {
                $this->recordMovement(
                    $session, TillMovementType::CLOSING_ADJUSTMENT, $variance, $actor->id,
                    reason: $variance > 0 ? 'Cash over at close' : 'Cash short at close',
                );
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

    /**
     * @return array<string, mixed>|null
     */
    public function present(int $staffId): ?array
    {
        $session = $this->currentSessionForStaff($staffId);
        if (! $session) {
            return null;
        }

        $session->loadMissing('till:id,name,branch_id', 'status');

        return array_merge($session->toArray(), ['expected_balance' => $this->expectedBalance($session)]);
    }
}
