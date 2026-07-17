<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreMaintenanceIssueRequest;
use App\Http\Requests\Hotel\UpdateMaintenanceIssueRequest;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\Room;
use App\Models\Hotel\Venue;
use App\Services\Hotel\MaintenanceService;
use App\Support\Lookups\MaintenanceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceIssueController extends Controller
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaintenanceIssue::query()->with(['room:id,number', 'venue:id,name', 'loggedBy:id,name', 'status']);

        if (! $request->boolean('all')) {
            $query->whereHas('status', fn ($q) => $q->where('code', '!=', MaintenanceStatus::RESOLVED));
        }

        if ($request->has('page')) {
            return response()->json(['issues' => $query->latest()->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['issues' => $query->latest()->get()]);
    }

    public function venueOptions(): JsonResponse
    {
        return response()->json(['venues' => Venue::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(StoreMaintenanceIssueRequest $request): JsonResponse
    {
        $data = $request->validated();
        $room = isset($data['room_id']) ? Room::query()->findOrFail($data['room_id']) : null;
        $venue = isset($data['venue_id']) ? Venue::query()->findOrFail($data['venue_id']) : null;

        $issue = $this->maintenance->logIssue($room, $venue, $data['description'], $data['take_room_out_of_service'] ?? false, $request->user()->id);

        return response()->json(['issue' => $issue], 201);
    }

    public function update(UpdateMaintenanceIssueRequest $request, MaintenanceIssue $issue): JsonResponse
    {
        $data = $request->validated();

        $issue = $this->maintenance->updateStatus($issue, $data['status'], $data['resolution_notes'] ?? null, $data['return_room_to_service'] ?? false);

        return response()->json(['issue' => $issue]);
    }
}
