<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\ChargeLaundryRequest;
use App\Http\Requests\Hotel\StoreLaundryItemRequest;
use App\Http\Requests\Hotel\UpdateLaundryItemRequest;
use App\Models\Hotel\LaundryItem;
use App\Models\Hotel\ReservationRoom;
use App\Services\AuditLog;
use App\Services\Hotel\LaundryService;
use App\Support\Lookups\ReservationStatus;
use Illuminate\Http\JsonResponse;

class LaundryController extends Controller
{
    public function __construct(private readonly LaundryService $laundry) {}

    public function index(): JsonResponse
    {
        return response()->json(['laundry_items' => LaundryItem::query()->orderBy('name')->get()]);
    }

    /**
     * Rooms eligible for a laundry charge — checked-in guests only. A
     * purpose-built endpoint (rather than reusing the full `GET /rooms`
     * board) so a laundry-only staff member doesn't need `hotel_rooms.access`
     * just to see who to charge.
     */
    public function rooms(): JsonResponse
    {
        $rooms = ReservationRoom::query()
            ->whereHas('reservation', fn ($q) => $q->statusCode(ReservationStatus::CHECKED_IN))
            ->whereHas('room')
            ->with(['room:id,number', 'reservation.guest:id,name'])
            ->get()
            ->map(fn (ReservationRoom $rr) => [
                'id' => $rr->room->id,
                'number' => $rr->room->number,
                'guest_name' => $rr->reservation->guest->name,
            ])
            ->sortBy('number')
            ->values();

        return response()->json(['rooms' => $rooms]);
    }

    public function store(StoreLaundryItemRequest $request): JsonResponse
    {
        $item = LaundryItem::create($request->validated());

        AuditLog::record('laundry_item.created', $item, ['name' => $item->name]);

        return response()->json(['message' => "\"{$item->name}\" created.", 'laundry_item' => $item], 201);
    }

    public function update(UpdateLaundryItemRequest $request, LaundryItem $laundryItem): JsonResponse
    {
        $laundryItem->update($request->validated());

        AuditLog::record('laundry_item.updated', $laundryItem, ['name' => $laundryItem->name]);

        return response()->json(['message' => 'Laundry item updated.', 'laundry_item' => $laundryItem]);
    }

    public function charge(ChargeLaundryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->laundry->chargeToRoom($data['room_id'], $data['items'], $data['note'] ?? null, $request->user()->id);

        return response()->json($result, 201);
    }
}
