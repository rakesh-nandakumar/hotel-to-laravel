<?php

namespace App\Services\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Room;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\RealtimeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Housekeeping task lifecycle. Ported from the Node app's routes/housekeeping.ts.
 * THE GATE: `complete()` is the only path from room status DIRTY → AVAILABLE
 * — `RoomController::updateStatus()` blocks that transition directly (Module 1).
 */
class HousekeepingService
{
    /** Manager creates an ad-hoc cleaning task (checkout tasks are auto-created — see Module 4). */
    public function createTask(Room $room, ?int $assignedToId, ?string $notes): HousekeepingTask
    {
        $room->loadMissing('roomType', 'status');

        $task = DB::transaction(function () use ($room, $assignedToId, $notes) {
            $task = HousekeepingTask::create([
                'room_id' => $room->id,
                'assigned_to_id' => $assignedToId,
                'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING),
                'checklist' => collect($room->roomType->cleaning_checklist)
                    ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                'notes' => $notes,
            ]);

            if ($room->status->code === RoomStatus::AVAILABLE) {
                $room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::DIRTY)]);
            }

            return $task;
        });

        return $task->load(['room', 'assignedTo:id,name', 'status']);
    }

    public function assign(HousekeepingTask $task, ?int $assignedToId): HousekeepingTask
    {
        $task->update([
            'assigned_to_id' => $assignedToId,
            'task_status_id' => Lookup::id(LookupType::TASK_STATUS, $assignedToId ? TaskStatus::IN_PROGRESS : TaskStatus::PENDING),
        ]);

        return $task->load(['room', 'assignedTo:id,name', 'status']);
    }

    /**
     * @param  list<array{item: string, done: bool}>  $checklist
     */
    public function updateChecklist(HousekeepingTask $task, array $checklist, int $staffId): HousekeepingTask
    {
        $task->loadMissing('status');
        if ($task->status->code === TaskStatus::DONE) {
            throw ValidationException::withMessages(['task' => 'Task already completed.']);
        }

        $task->update([
            'checklist' => $checklist,
            'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::IN_PROGRESS),
            'assigned_to_id' => $task->assigned_to_id ?? $staffId,
        ]);

        return $task->load(['room', 'assignedTo:id,name', 'status']);
    }

    /**
     * Submit the completed checklist. Only flips the room to AVAILABLE if it
     * is currently DIRTY — maintenance keeps priority over housekeeping.
     *
     * @param  list<array{item: string, done: bool}>|null  $checklist
     * @return array{ok: bool, room_status: string}
     */
    public function complete(HousekeepingTask $task, ?array $checklist, ?string $notes, int $staffId): array
    {
        $task->loadMissing('room.status', 'status');
        if ($task->status->code === TaskStatus::DONE) {
            throw ValidationException::withMessages(['task' => 'Task already completed.']);
        }

        $checklist ??= $task->checklist;
        $unfinished = collect($checklist)->reject(fn ($c) => $c['done']);
        if ($unfinished->isNotEmpty()) {
            $shown = $unfinished->take(3)->pluck('item')->implode('; ').($unfinished->count() > 3 ? '…' : '');
            throw ValidationException::withMessages([
                'checklist' => "Checklist incomplete — {$unfinished->count()} item(s) remaining: {$shown}",
            ]);
        }

        $roomWasDirty = $task->room->status->code === RoomStatus::DIRTY;

        DB::transaction(function () use ($task, $checklist, $notes, $staffId, $roomWasDirty) {
            $task->update([
                'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::DONE),
                'checklist' => $checklist,
                'completed_at' => now(),
                'notes' => $notes,
                'assigned_to_id' => $task->assigned_to_id ?? $staffId,
            ]);

            if ($roomWasDirty) {
                $task->room->update(['room_status_id' => Lookup::id(LookupType::ROOM_STATUS, RoomStatus::AVAILABLE)]);
            }
        });

        AuditLog::record('housekeeping.completed', $task, ['room' => $task->room->number]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['room_id' => $task->room_id]));

        return ['ok' => true, 'room_status' => $roomWasDirty ? RoomStatus::AVAILABLE : $task->room->status->code];
    }
}
