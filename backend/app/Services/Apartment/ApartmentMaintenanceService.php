<?php

namespace App\Services\Apartment;

use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\MaintenanceIssue;
use App\Models\Apartment\Unit;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\TaskStatus;
use Illuminate\Support\Facades\DB;

/**
 * Apartment maintenance ticket lifecycle — mirrors the Hotel module's
 * maintenance service (App\Services\Hotel\MaintenanceService). Any staff can
 * log an issue; resolving one can return a unit to service — always via
 * DIRTY (needs cleaning first), never straight to AVAILABLE, and never
 * touches a unit that is currently OCCUPIED.
 */
class ApartmentMaintenanceService
{
    public function logIssue(Unit $unit, string $description, bool $takeUnitOutOfService, int $staffId): MaintenanceIssue
    {
        $unit->loadMissing('status');

        $issue = MaintenanceIssue::create([
            'unit_id' => $unit->id,
            'description' => $description,
            'maintenance_status_id' => Lookup::id(LookupType::MAINTENANCE_STATUS, MaintenanceStatus::OPEN),
            'logged_by_id' => $staffId,
        ]);

        if ($takeUnitOutOfService && $unit->status->code !== ApartmentUnitStatus::OCCUPIED) {
            $unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::MAINTENANCE)]);
        }

        AuditLog::record('apartment_maintenance.logged', $issue, ['description' => $description]);

        return $issue->load(['unit:id,unit_no', 'loggedBy:id,name', 'status']);
    }

    public function updateStatus(MaintenanceIssue $issue, string $status, ?string $resolutionNotes, bool $returnUnitToService): MaintenanceIssue
    {
        $issue->loadMissing('unit.status');

        DB::transaction(function () use ($issue, $status, $resolutionNotes, $returnUnitToService) {
            $issue->update([
                'maintenance_status_id' => Lookup::id(LookupType::MAINTENANCE_STATUS, $status),
                'resolution_notes' => $resolutionNotes,
                'resolved_at' => $status === MaintenanceStatus::RESOLVED ? now() : null,
            ]);

            // Resolving can return the unit to DIRTY (needs cleaning before
            // sale/rental — the housekeeping checklist gate still applies).
            if ($status === MaintenanceStatus::RESOLVED && $returnUnitToService
                && $issue->unit->status->code === ApartmentUnitStatus::MAINTENANCE) {
                $issue->unit->loadMissing('unitType');
                $issue->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::DIRTY)]);

                HousekeepingTask::create([
                    'unit_id' => $issue->unit_id,
                    'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING),
                    'checklist' => collect($issue->unit->unitType->cleaning_checklist)
                        ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                    'notes' => "Post-maintenance clean: {$issue->description}",
                ]);
            }
        });

        AuditLog::record('apartment_maintenance.updated', $issue, ['status' => $status]);

        return $issue->load(['unit:id,unit_no', 'loggedBy:id,name', 'status']);
    }
}
