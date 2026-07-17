<?php

namespace App\Services\Hotel;

use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\Room;
use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ported from the Node app's lib/booking.ts availableRooms(): rooms free for
 * the whole [checkIn, checkOut) window and physically sellable (not in
 * MAINTENANCE — a DIRTY room can still be booked ahead, just not checked
 * into today; that's a separate guard in ReservationService::checkIn()).
 */
class ReservationAvailabilityService
{
    /**
     * @return Collection<int, Room>
     */
    public function availableRooms(CarbonInterface $checkIn, CarbonInterface $checkOut, ?int $excludeReservationId = null): Collection
    {
        $busyStatuses = [ReservationStatus::PENDING, ReservationStatus::CONFIRMED, ReservationStatus::CHECKED_IN];

        $busyRoomIds = ReservationRoom::query()
            ->whereHas('reservation', function (Builder $query) use ($checkIn, $checkOut, $excludeReservationId, $busyStatuses) {
                $query->statusIn($busyStatuses)
                    ->where('check_in', '<', $checkOut->toDateString())
                    ->where('check_out', '>', $checkIn->toDateString());

                if ($excludeReservationId) {
                    $query->where('id', '!=', $excludeReservationId);
                }
            })
            ->pluck('room_id');

        $maintenanceId = Lookup::id(LookupType::ROOM_STATUS, RoomStatus::MAINTENANCE);

        return Room::query()
            ->where('room_status_id', '!=', $maintenanceId)
            ->whereNotIn('id', $busyRoomIds)
            ->with(['roomType.seasonalRates'])
            ->orderBy('number')
            ->get();
    }

    /**
     * @param  list<int>  $roomIds
     * @return Collection<int, Room>
     */
    public function assertRoomsAvailable(array $roomIds, CarbonInterface $checkIn, CarbonInterface $checkOut, ?int $excludeReservationId = null): Collection
    {
        $free = $this->availableRooms($checkIn, $checkOut, $excludeReservationId)->keyBy('id');

        foreach ($roomIds as $roomId) {
            if (! $free->has($roomId)) {
                $room = Room::find($roomId);

                throw ValidationException::withMessages([
                    'rooms' => 'Room '.($room->number ?? $roomId).' is not available for those dates.',
                ]);
            }
        }

        return $free;
    }
}
