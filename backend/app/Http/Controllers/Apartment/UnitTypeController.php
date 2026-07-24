<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\StoreSeasonalRateRequest;
use App\Http\Requests\Apartment\StoreUnitTypeRequest;
use App\Http\Requests\Apartment\UpdateUnitTypeRequest;
use App\Models\Apartment\SeasonalRate;
use App\Models\Apartment\UnitType;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'unit_types' => UnitType::query()
                ->with([
                    'units:id,unit_no,unit_type_id',
                    'seasonalRates' => fn ($q) => $q->orderBy('start_date'),
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreUnitTypeRequest $request): JsonResponse
    {
        $unitType = UnitType::create($request->validated());

        AuditLog::record('apartment_unit_type.created', $unitType, ['name' => $unitType->name]);

        return response()->json(['message' => "Unit type \"{$unitType->name}\" created.", 'unit_type' => $unitType], 201);
    }

    public function update(UpdateUnitTypeRequest $request, UnitType $unitType): JsonResponse
    {
        $unitType->update($request->validated());

        AuditLog::record('apartment_unit_type.updated', $unitType, ['name' => $unitType->name]);

        return response()->json(['message' => 'Unit type updated.', 'unit_type' => $unitType]);
    }

    public function storeSeasonalRate(StoreSeasonalRateRequest $request, UnitType $unitType): JsonResponse
    {
        $rate = $unitType->seasonalRates()->create($request->validated());

        AuditLog::record('apartment_unit_type.seasonal_rate_added', $unitType, [
            'name' => $rate->name,
            'rate' => $rate->rate,
        ]);

        return response()->json(['message' => 'Seasonal rate added.', 'seasonal_rate' => $rate], 201);
    }

    public function destroySeasonalRate(Request $request, SeasonalRate $seasonalRate): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo('apartment_unit_types.edit')) {
            abort(403);
        }

        $seasonalRate->delete();

        AuditLog::record('apartment_unit_type.seasonal_rate_removed', $seasonalRate);

        return response()->json(['message' => 'Seasonal rate removed.']);
    }
}
