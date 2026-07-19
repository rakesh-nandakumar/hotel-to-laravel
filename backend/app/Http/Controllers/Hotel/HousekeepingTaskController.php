<?php

namespace App\Http\Controllers\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\AssignHousekeepingTaskRequest;
use App\Http\Requests\Hotel\CompleteHousekeepingTaskRequest;
use App\Http\Requests\Hotel\StoreHousekeepingTaskRequest;
use App\Http\Requests\Hotel\UpdateHousekeepingChecklistRequest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Room;
use App\Services\Hotel\HousekeepingService;
use App\Support\Lookups\TaskStatus;
use App\Support\RealtimeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HousekeepingTaskController extends Controller
{
    public function __construct(private readonly HousekeepingService $housekeeping) {}

    public function index(Request $request): JsonResponse
    {
        $query = HousekeepingTask::query()->with(['status', 'room:id,number,room_status_id', 'room.status', 'room.roomType:id,name', 'assignedTo:id,name']);

        if ($request->boolean('mine')) {
            $query->where('assigned_to_id', $request->user()->id);
        }
        if (! $request->boolean('all')) {
            $query->whereHas('status', fn ($q) => $q->where('code', '!=', TaskStatus::DONE));
        }

        if ($request->has('page')) {
            return response()->json(['tasks' => $query->latest()->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['tasks' => $query->oldest()->get()]);
    }

    public function store(StoreHousekeepingTaskRequest $request): JsonResponse
    {
        $data = $request->validated();
        $room = Room::query()->findOrFail($data['room_id']);

        $task = $this->housekeeping->createTask($room, $data['assigned_to_id'] ?? null, $data['notes'] ?? null);
        broadcast(new RealtimeUpdate(RealtimeEvent::ROOMS, ['room_id' => $room->id]));

        return response()->json(['task' => $task], 201);
    }

    public function assign(AssignHousekeepingTaskRequest $request, HousekeepingTask $task): JsonResponse
    {
        return response()->json(['task' => $this->housekeeping->assign($task, $request->validated('assigned_to_id'))]);
    }

    public function updateChecklist(UpdateHousekeepingChecklistRequest $request, HousekeepingTask $task): JsonResponse
    {
        return response()->json([
            'task' => $this->housekeeping->updateChecklist($task, $request->validated('checklist'), $request->user()->id),
        ]);
    }

    public function complete(CompleteHousekeepingTaskRequest $request, HousekeepingTask $task): JsonResponse
    {
        $data = $request->validated();

        return response()->json(
            $this->housekeeping->complete($task, $data['checklist'] ?? null, $data['notes'] ?? null, $request->user()->id),
        );
    }
}
