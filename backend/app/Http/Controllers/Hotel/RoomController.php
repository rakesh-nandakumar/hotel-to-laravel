<?php

namespace App\Http\Controllers\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreRoomRequest;
use App\Http\Requests\Hotel\StoreSeasonalRateRequest;
use App\Http\Requests\Hotel\UpdateRoomRequest;
use App\Http\Requests\Hotel\UpdateRoomStatusRequest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Hotel\SeasonalRate;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\CurrentContext;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\RealtimeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function __construct(private readonly CurrentContext $context) {}

    /**
     * The live room board — every room enriched with its current occupant
     * (if any), whether it has a housekeeping task pending, and any open
     * maintenance issues. Ported from the Node app's dedicated GET /rooms/board;
     * folded into the one existing `hotel_rooms.access`-gated endpoint here
     * instead of a second route, since every caller needs this same shape.
     */
    public function index(): JsonResponse
    {
        $rooms = Room::query()
            ->with(['seasonalRates' => fn ($q) => $q->orderBy('start_date'), 'status', 'branch:id,name'])
            ->orderBy('number')
            ->get();

        $occupants = ReservationRoom::query()
            ->whereHas('reservation', fn ($q) => $q->statusCode(ReservationStatus::CHECKED_IN))
            ->with(['reservation:id,code,check_out,guest_id', 'reservation.guest:id,name'])
            ->get()
            ->keyBy('room_id');

        $pendingHousekeepingRoomIds = HousekeepingTask::query()
            ->whereHas('status', fn ($q) => $q->where('code', '!=', TaskStatus::DONE))
            ->pluck('room_id')
            ->unique();

        $openIssuesByRoom = MaintenanceIssue::query()
            ->whereHas('status', fn ($q) => $q->where('code', '!=', MaintenanceStatus::RESOLVED))
            ->with('status')
            ->get(['id', 'room_id', 'description', 'maintenance_status_id'])
            ->groupBy('room_id');

        $rooms->each(function (Room $room) use ($occupants, $pendingHousekeepingRoomIds, $openIssuesByRoom) {
            $reservationRoom = $occupants->get($room->id);

            $room->occupant = $reservationRoom ? [
                'id' => $reservationRoom->reservation->id,
                'code' => $reservationRoom->reservation->code,
                'check_out' => $reservationRoom->reservation->check_out,
                'guest' => ['name' => $reservationRoom->reservation->guest->name],
            ] : null;
            $room->pending_housekeeping = $pendingHousekeepingRoomIds->contains($room->id);
            $room->open_issues = ($openIssuesByRoom->get($room->id) ?? collect())
                ->map(fn (MaintenanceIssue $issue) => [
                    'id' => $issue->id,
                    'description' => $issue->description,
                    'status' => $issue->status->code,
                ])
                ->values();
        });

        return response()->json(['rooms' => $rooms]);
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['branch_id'] ??= $this->context->branchId();
        $data['room_status_id'] = Lookup::id(LookupType::ROOM_STATUS, RoomStatus::AVAILABLE);

        // Back-compat: if a legacy room_type_id is supplied without explicit
        // pricing fields, hydrate those fields from the type so the room is
        // self-contained going forward. New rooms should send max_occupancy
        // etc. directly and may omit room_type_id entirely.
        if (! empty($data['room_type_id']) && (! isset($data['weekday_rate']) || ! isset($data['max_occupancy']))) {
            $type = RoomType::find($data['room_type_id']);
            if ($type) {
                $data['name'] ??= $type->name;
                $data['max_occupancy'] ??= $type->max_occupancy;
                $data['bed_config'] ??= $type->bed_config;
                $data['weekday_rate'] ??= $type->weekday_rate;
                $data['weekend_rate'] ??= $type->weekend_rate;
                $data['item_checklist'] ??= $type->item_checklist;
                $data['cleaning_checklist'] ??= $type->cleaning_checklist;
                $data['amenities'] ??= $type->amenities;
            }
        }

        $room = Room::create($data);

        // If the source type had seasonal rates, clone them onto the new room
        if (! empty($data['room_type_id'])) {
            $typeRates = SeasonalRate::where('room_type_id', $data['room_type_id'])->get();
            foreach ($typeRates as $rate) {
                $room->seasonalRates()->create([
                    'tenant_id' => $room->tenant_id ?? $rate->tenant_id,
                    'name' => $rate->name,
                    'start_date' => $rate->start_date,
                    'end_date' => $rate->end_date,
                    'rate' => $rate->rate,
                ]);
            }
        }

        AuditLog::record('room.created', $room, ['number' => $room->number]);

        return response()->json(['message' => "Room \"{$room->number}\" created.", 'room' => $room->load(['seasonalRates', 'status'])], 201);
    }

    public function update(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        $room->update($request->validated());

        AuditLog::record('room.updated', $room, ['number' => $room->number]);

        return response()->json(['message' => 'Room updated.', 'room' => $room->load(['seasonalRates', 'status'])]);
    }

    public function storeSeasonalRate(StoreSeasonalRateRequest $request, Room $room): JsonResponse
    {
        $rate = $room->seasonalRates()->create($request->validated());

        AuditLog::record('room.seasonal_rate_added', $room, [
            'name' => $rate->name,
            'rate' => $rate->rate,
        ]);

        return response()->json(['message' => 'Seasonal rate added.', 'seasonal_rate' => $rate], 201);
    }

    public function destroySeasonalRate(Request $request, SeasonalRate $seasonalRate): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo('hotel_rooms.edit')) {
            abort(403);
        }

        $seasonalRate->delete();

        AuditLog::record('room.seasonal_rate_removed', $seasonalRate);

        return response()->json(['message' => 'Seasonal rate removed.']);
    }

    /**
     * Direct status edits deliberately cannot perform two transitions that
     * must go through their owning workflow instead:
     *  - DIRTY → AVAILABLE only via the housekeeping checklist-complete action.
     *  - OCCUPIED → AVAILABLE only via reservation checkout.
     * (Ported from the Node app's rooms.ts status guard — see phase2-nodejs-business-logic memory.)
     */
    public function updateStatus(UpdateRoomStatusRequest $request, Room $room): JsonResponse
    {
        $newStatus = $request->statusLookup();
        $currentCode = $room->status?->code;

        if ($newStatus->code === RoomStatus::AVAILABLE && $currentCode === RoomStatus::DIRTY) {
            throw ValidationException::withMessages([
                'status' => 'Room can only be marked Available by completing its housekeeping checklist.',
            ]);
        }

        if ($newStatus->code === RoomStatus::AVAILABLE && $currentCode === RoomStatus::OCCUPIED) {
            throw ValidationException::withMessages([
                'status' => 'Guest is checked in — check out first.',
            ]);
        }

        $from = $currentCode;
        $room->update(['room_status_id' => $newStatus->id]);

        AuditLog::record('room.status_changed', $room, ['from' => $from, 'to' => $newStatus->code]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['room_id' => $room->id, 'status' => $newStatus->code]));

        return response()->json(['message' => 'Room status updated.', 'room' => $room->load('status')]);
    }
}
