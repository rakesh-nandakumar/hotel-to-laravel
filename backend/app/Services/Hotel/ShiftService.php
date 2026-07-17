<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Shift;
use App\Models\User;
use App\Services\AuditLog;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use Illuminate\Validation\ValidationException;

/**
 * Cash-drawer shift lifecycle. Ported from the Node app's routes/shifts.ts.
 */
class ShiftService
{
    public function openShift(int $staffId, int $openingCash): Shift
    {
        $open = Shift::query()->where('staff_id', $staffId)->open()->first();
        if ($open) {
            throw ValidationException::withMessages(['shift' => 'You already have an open shift — close it first.']);
        }

        $shift = Shift::create(['staff_id' => $staffId, 'opening_cash' => $openingCash]);

        AuditLog::record('shift.opened', $shift, ['opening_cash' => $openingCash]);

        return $shift;
    }

    /** Close with counted cash → automatic drawer reconciliation + variance. */
    public function closeShift(Shift $shift, int $closingCash, ?string $notes, User $actor): Shift
    {
        if ($shift->closed_at) {
            throw ValidationException::withMessages(['shift' => 'Shift not open.']);
        }
        if ($shift->staff_id !== $actor->id && ! $actor->hasPermissionTo('hotel_shifts.close_any')) {
            abort(403, 'Not your shift.');
        }

        $cash = $this->cashForShift($shift);
        $expectedCash = $shift->opening_cash + $cash['cash_in'] - $cash['cash_out'];
        $variance = $closingCash - $expectedCash;

        $shift->update([
            'closed_at' => now(), 'closing_cash' => $closingCash,
            'expected_cash' => $expectedCash, 'variance' => $variance, 'notes' => $notes,
        ]);

        AuditLog::record('shift.closed', $shift, ['expected_cash' => $expectedCash, 'closing_cash' => $closingCash, 'variance' => $variance]);

        return $shift;
    }

    /**
     * @return array{cash_in: int, cash_out: int}
     */
    public function cashForShift(Shift $shift): array
    {
        $payments = $shift->payments()->whereHas('method', fn ($q) => $q->where('code', PaymentMethod::CASH))->with('kind')->get();

        $cashIn = (int) $payments->filter(fn ($p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount');
        $cashOut = (int) $payments->filter(fn ($p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');

        return ['cash_in' => $cashIn, 'cash_out' => $cashOut];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentShift(int $staffId): ?array
    {
        $shift = Shift::query()->where('staff_id', $staffId)->open()->first();
        if (! $shift) {
            return null;
        }

        $cash = $this->cashForShift($shift);

        return array_merge($shift->toArray(), $cash, [
            'expected_now' => $shift->opening_cash + $cash['cash_in'] - $cash['cash_out'],
        ]);
    }
}
