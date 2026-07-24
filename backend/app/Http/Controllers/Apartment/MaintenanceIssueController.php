<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\StoreMaintenanceIssueRequest;
use App\Http\Requests\Apartment\UpdateMaintenanceIssueRequest;
use App\Models\Apartment\MaintenanceIssue;
use App\Models\Apartment\Unit;
use App\Services\Apartment\ApartmentMaintenanceService;
use App\Support\Lookups\MaintenanceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceIssueController extends Controller
{
    public function __construct(private readonly ApartmentMaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaintenanceIssue::query()->with(['unit:id,unit_no', 'loggedBy:id,name', 'status']);

        if (! $request->boolean('all')) {
            $query->whereHas('status', fn ($q) => $q->where('code', '!=', MaintenanceStatus::RESOLVED));
        }

        if ($request->has('page')) {
            return response()->json(['issues' => $query->latest()->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['issues' => $query->latest()->get()]);
    }

    public function store(StoreMaintenanceIssueRequest $request): JsonResponse
    {
        $data = $request->validated();
        $unit = Unit::query()->findOrFail($data['unit_id']);

        $issue = $this->maintenance->logIssue($unit, $data['description'], $data['take_unit_out_of_service'] ?? false, $request->user()->id);

        return response()->json(['issue' => $issue], 201);
    }

    public function update(UpdateMaintenanceIssueRequest $request, MaintenanceIssue $issue): JsonResponse
    {
        $data = $request->validated();

        $issue = $this->maintenance->updateStatus($issue, $data['status'], $data['resolution_notes'] ?? null, $data['return_unit_to_service'] ?? false);

        return response()->json(['issue' => $issue]);
    }
}
