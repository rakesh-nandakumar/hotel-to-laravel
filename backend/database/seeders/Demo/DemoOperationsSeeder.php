<?php

namespace Database\Seeders\Demo;

use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\Room;
use App\Models\Hotel\Venue;
use App\Models\User;
use App\Services\Hotel\HousekeepingService;
use App\Services\Hotel\MaintenanceService;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\RoomStatus;
use Illuminate\Database\Seeder;

/**
 * Ad-hoc housekeeping tasks and maintenance tickets — separate from the
 * automatic post-checkout turnover cleans DemoReservationsSeeder already
 * completed, so the Housekeeping/Maintenance boards show genuinely
 * pending/in-progress work rather than everything sitting at "done".
 */
class DemoOperationsSeeder extends Seeder
{
    public function run(): void
    {
        if (MaintenanceIssue::query()->count() > 0) {
            return; // already seeded — MaintenanceIssue is only ever written here
        }

        $housekeeping = app(HousekeepingService::class);
        $maintenance = app(MaintenanceService::class);

        $housekeeperId = User::query()->where('email', 'housekeeper@vellix.lk')->value('id');
        $staffIds = User::query()->where('status', User::STATUS_ACTIVE)->pluck('id')->all();

        $this->seedAdHocHousekeeping($housekeeping, $housekeeperId);
        $this->seedMaintenance($maintenance, $staffIds);
    }

    private function seedAdHocHousekeeping(HousekeepingService $housekeeping, ?int $housekeeperId): void
    {
        $notes = [
            'Deep clean requested by manager ahead of upcoming group booking.',
            'Pre-arrival inspection clean.',
            'Routine weekly deep clean.',
            'Guest reported dust on ceiling fan — recheck requested.',
            'Minibar restock + full clean.',
        ];

        $rooms = Room::query()
            ->whereHas('status', fn ($q) => $q->where('code', RoomStatus::AVAILABLE))
            ->inRandomOrder()->limit(5)->get();

        foreach ($rooms as $i => $room) {
            $task = $housekeeping->createTask($room, $i % 2 === 0 ? $housekeeperId : null, $notes[$i % count($notes)]);

            if ($i % 2 === 0 && $housekeeperId) {
                // Half-finished checklist — a housekeeper is genuinely mid-task.
                $checklist = $task->checklist;
                $doneCount = intdiv(count($checklist), 2);
                foreach ($checklist as $idx => &$row) {
                    $row['done'] = $idx < $doneCount;
                }
                unset($row);

                $housekeeping->updateChecklist($task, $checklist, $housekeeperId);
            }
        }
    }

    private function seedMaintenance(MaintenanceService $maintenance, array $staffIds): void
    {
        $roomIssues = [
            'Air conditioning not cooling properly',
            'Bathroom tap leaking',
            'TV remote not working',
            'WiFi signal weak in this room',
            'Door lock sticking',
            'Light bulb blown in bathroom',
        ];

        $rooms = Room::query()->inRandomOrder()->limit(6)->get();
        foreach ($rooms as $i => $room) {
            $issue = $maintenance->logIssue($room, null, $roomIssues[$i % count($roomIssues)], $i % 3 === 0, $this->pick($staffIds));

            // logIssue()'s return eager-loads room with only id/number selected, so
            // room_status_id is missing on that instance — re-fetch clean before
            // updateStatus() touches $issue->room->status (same object, same
            // process, unlike real usage where these come from separate requests).
            $issue = MaintenanceIssue::find($issue->id);

            if ($i % 3 === 1) {
                $maintenance->updateStatus($issue, MaintenanceStatus::IN_PROGRESS, null, false);
            } elseif ($i % 3 === 2) {
                $maintenance->updateStatus($issue, MaintenanceStatus::RESOLVED, 'Fixed by maintenance team.', true);
            }
        }

        $venueIssues = ['Sound system needs servicing', 'Air conditioning unit noisy', 'Stage lighting flickering'];
        $venues = Venue::query()->inRandomOrder()->limit(2)->get();
        foreach ($venues as $i => $venue) {
            $issue = $maintenance->logIssue(null, $venue, $venueIssues[$i % count($venueIssues)], false, $this->pick($staffIds));
            if ($i === 0) {
                $maintenance->updateStatus(MaintenanceIssue::find($issue->id), MaintenanceStatus::RESOLVED, 'Serviced by external contractor.', false);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function pick(array $ids): mixed
    {
        return $ids[array_rand($ids)];
    }
}
