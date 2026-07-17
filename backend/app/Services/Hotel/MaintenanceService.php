<?php

namespace App\Services\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\Room;
use App\Models\Hotel\Venue;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\RealtimeEvent;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance ticket lifecycle. Ported from the Node app's routes/maintenance.ts.
 * Any staff can log an issue; resolving one can return a room to service —
 * always via DIRTY (needs cleaning first), never straight to AVAILABLE, and
 * never touches a room that is currently OCCUPIED.
 */
class MaintenanceService
{
    public function logIssue(?Room $room, ?Venue $venue, string $description, bool $takeRoomOutOfService, int $staffId): MaintenanceIssue
    {
        $room?->loadMissing('status');

        $issue = MaintenanceIssue::create([
            'room_id' => $room?->id,
            'venue_id' => $venue?->id,
            'description' => $description,
            'maintenance_status_id' => Lookup::id(LookupType::MAINTENANCE_STATUS, MaintenanceStatus::OPEN),
            'logged_by_id' => $staffId,
        ]);

        if ($room && $takeRoomOutOfService && $room->status->code !== RoomStatus::OCCUPIED) {
            $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::MAINTENANCE)]);
            broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['room_id' => $room->id]));
        }

        AuditLog::record('maintenance.logged', $issue, ['description' => $description]);

        return $issue->load(['room:id,number', 'venue:id,name', 'loggedBy:id,name', 'status']);
    }

    public function updateStatus(MaintenanceIssue $issue, string $status, ?string $resolutionNotes, bool $returnRoomToService): MaintenanceIssue
    {
        $issue->loadMissing('room.status');

        DB::transaction(function () use ($issue, $status, $resolutionNotes, $returnRoomToService) {
            $issue->update([
                'maintenance_status_id' => Lookup::id(LookupType::MAINTENANCE_STATUS, $status),
                'resolution_notes' => $resolutionNotes,
                'resolved_at' => $status === MaintenanceStatus::RESOLVED ? now() : null,
            ]);

            // Resolving can return the room to DIRTY (needs cleaning before
            // sale — the housekeeping checklist gate still applies).
            if ($status === MaintenanceStatus::RESOLVED && $returnRoomToService
                && $issue->room_id && $issue->room->status->code === RoomStatus::MAINTENANCE) {
                $issue->room->loadMissing('roomType');
                $issue->room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::DIRTY)]);

                HousekeepingTask::create([
                    'room_id' => $issue->room_id,
                    'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING),
                    'checklist' => collect($issue->room->roomType->cleaning_checklist)
                        ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                    'notes' => "Post-maintenance clean: {$issue->description}",
                ]);
                broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['room_id' => $issue->room_id]));
            }
        });

        AuditLog::record('maintenance.updated', $issue, ['status' => $status]);

        return $issue->load(['room:id,number', 'venue:id,name', 'loggedBy:id,name', 'status']);
    }
}
