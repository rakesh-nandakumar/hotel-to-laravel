<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreDiningAreaRequest;
use App\Http\Requests\Hotel\UpdateDiningAreaRequest;
use App\Models\Hotel\DiningArea;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;

class DiningAreaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'dining_areas' => DiningArea::query()->withCount('tables')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreDiningAreaRequest $request): JsonResponse
    {
        $area = DiningArea::create($request->validated());

        AuditLog::record('dining_area.created', $area, ['name' => $area->name]);

        return response()->json(['message' => "Area \"{$area->name}\" created.", 'dining_area' => $area], 201);
    }

    public function update(UpdateDiningAreaRequest $request, DiningArea $diningArea): JsonResponse
    {
        $diningArea->update($request->validated());

        AuditLog::record('dining_area.updated', $diningArea, ['name' => $diningArea->name]);

        return response()->json(['message' => 'Area updated.', 'dining_area' => $diningArea]);
    }
}
