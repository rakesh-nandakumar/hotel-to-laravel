<?php

namespace App\Services\Apartment;

use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\Unit;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apartment housekeeping task lifecycle — mirrors the Hotel module's
 * housekeeping service (App\Services\Hotel\HousekeepingService). THE GATE:
 * complete() is the only path from unit status DIRTY → AVAILABLE —
 * UnitController::updateStatus() blocks that transition directly.
 */
class ApartmentHousekeepingService
{
    /** Manager creates an ad-hoc cleaning task (checkout/lease-termination tasks are auto-created by their own services). */
    public function createTask(Unit $unit, ?int $assignedToId, ?string $notes): HousekeepingTask
    {
        $unit->loadMissing('unitType', 'status');

        $task = DB::transaction(function () use ($unit, $assignedToId, $notes) {
            $task = HousekeepingTask::create([
                'unit_id' => $unit->id,
                'assigned_to_id' => $assignedToId,
                'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING),
                'checklist' => collect($unit->unitType->cleaning_checklist)
                    ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                'notes' => $notes,
            ]);

            if ($unit->status->code === ApartmentUnitStatus::AVAILABLE) {
                $unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::DIRTY)]);
            }

            return $task;
        });

        return $task->load(['unit', 'assignedTo:id,name', 'status']);
    }

    public function assign(HousekeepingTask $task, ?int $assignedToId): HousekeepingTask
    {
        $task->update([
            'assigned_to_id' => $assignedToId,
            'task_status_id' => Lookup::id(LookupType::TASK_STATUS, $assignedToId ? TaskStatus::IN_PROGRESS : TaskStatus::PENDING),
        ]);

        return $task->load(['unit', 'assignedTo:id,name', 'status']);
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

        return $task->load(['unit', 'assignedTo:id,name', 'status']);
    }

    /**
     * Submit the completed checklist. Only flips the unit to AVAILABLE if it
     * is currently DIRTY — maintenance keeps priority over housekeeping.
     *
     * @param  list<array{item: string, done: bool}>|null  $checklist
     * @return array{ok: bool, unit_status: string}
     */
    public function complete(HousekeepingTask $task, ?array $checklist, ?string $notes, int $staffId): array
    {
        $task->loadMissing('unit.status', 'status');
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

        $unitWasDirty = $task->unit->status->code === ApartmentUnitStatus::DIRTY;

        DB::transaction(function () use ($task, $checklist, $notes, $staffId, $unitWasDirty) {
            $task->update([
                'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::DONE),
                'checklist' => $checklist,
                'completed_at' => now(),
                'notes' => $notes,
                'assigned_to_id' => $task->assigned_to_id ?? $staffId,
            ]);

            if ($unitWasDirty) {
                $task->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE)]);
            }
        });

        AuditLog::record('apartment_housekeeping.completed', $task, ['unit' => $task->unit->unit_no]);

        return ['ok' => true, 'unit_status' => $unitWasDirty ? ApartmentUnitStatus::AVAILABLE : $task->unit->status->code];
    }
}
