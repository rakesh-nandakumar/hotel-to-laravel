<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\AssignHousekeepingTaskRequest;
use App\Http\Requests\Apartment\CompleteHousekeepingTaskRequest;
use App\Http\Requests\Apartment\StoreHousekeepingTaskRequest;
use App\Http\Requests\Apartment\UpdateHousekeepingChecklistRequest;
use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\Unit;
use App\Services\Apartment\ApartmentHousekeepingService;
use App\Support\Lookups\TaskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HousekeepingTaskController extends Controller
{
    public function __construct(private readonly ApartmentHousekeepingService $housekeeping) {}

    public function index(Request $request): JsonResponse
    {
        $query = HousekeepingTask::query()->with(['status', 'unit:id,unit_no,unit_status_id,unit_type_id', 'unit.status', 'unit.unitType:id,name', 'assignedTo:id,name']);

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
        $unit = Unit::query()->findOrFail($data['unit_id']);

        $task = $this->housekeeping->createTask($unit, $data['assigned_to_id'] ?? null, $data['notes'] ?? null);

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
