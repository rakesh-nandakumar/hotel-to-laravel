<?php

namespace App\Services\Hotel;

use App\Models\Hotel\FolioLine;
use App\Models\Hotel\LaundryItem;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use Illuminate\Validation\ValidationException;

/**
 * Charge laundry to a checked-in guest's folio — one auditable LAUNDRY line
 * per item type. Ported from the Node app's routes/laundry.ts.
 */
class LaundryService
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * @param  list<array{laundry_item_id: int, qty: int}>  $items
     * @return array{ok: bool, reservation: string, guest: string, total: int, lines: int}
     */
    public function chargeToRoom(int $roomId, array $items, ?string $note, int $staffId): array
    {
        $reservation = $this->reservations->findCheckedInReservationForRoom($roomId);

        $priceList = LaundryItem::query()->whereIn('id', collect($items)->pluck('laundry_item_id'))->get()->keyBy('id');
        $laundrySourceId = Lookup::id(LookupType::LINE_SOURCE, LineSource::LAUNDRY);

        $total = 0;
        $lineCount = 0;
        foreach ($items as $line) {
            $item = $priceList->get($line['laundry_item_id']);
            if (! $item || ! $item->active) {
                throw ValidationException::withMessages(['items' => 'Laundry item not found.']);
            }

            $amount = $item->price * $line['qty'];
            FolioLine::create([
                'folio_id' => $reservation->folio->id,
                'line_source_id' => $laundrySourceId,
                'description' => "Laundry — {$item->name} × {$line['qty']}".($note ? " ({$note})" : ''),
                'qty' => $line['qty'], 'unit_price' => $item->price, 'amount' => $amount,
                'staff_id' => $staffId,
            ]);
            $total += $amount;
            $lineCount++;
        }

        AuditLog::record('laundry.charged', $reservation->folio, ['room' => $roomId, 'total' => $total, 'items' => $lineCount]);

        return ['ok' => true, 'reservation' => $reservation->code, 'guest' => $reservation->guest->name, 'total' => $total, 'lines' => $lineCount];
    }
}
