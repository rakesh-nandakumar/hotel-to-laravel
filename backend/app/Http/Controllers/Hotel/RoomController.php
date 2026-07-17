<?php

namespace App\Http\Controllers\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreRoomRequest;
use App\Http\Requests\Hotel\UpdateRoomRequest;
use App\Http\Requests\Hotel\UpdateRoomStatusRequest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\Room;
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
            ->with(['roomType:id,name', 'status', 'branch:id,name'])
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

        $room = Room::create($data);

        AuditLog::record('room.created', $room, ['number' => $room->number]);

        return response()->json(['message' => "Room \"{$room->number}\" created.", 'room' => $room->load(['roomType', 'status'])], 201);
    }

    public function update(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        $room->update($request->validated());

        AuditLog::record('room.updated', $room, ['number' => $room->number]);

        return response()->json(['message' => 'Room updated.', 'room' => $room->load(['roomType', 'status'])]);
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
