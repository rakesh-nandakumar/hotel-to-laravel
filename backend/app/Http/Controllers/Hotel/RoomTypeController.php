<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreRoomTypeRequest;
use App\Http\Requests\Hotel\StoreSeasonalRateRequest;
use App\Http\Requests\Hotel\UpdateRoomTypeRequest;
use App\Models\Hotel\RoomType;
use App\Models\Hotel\SeasonalRate;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'room_types' => RoomType::query()
                ->with([
                    'rooms:id,number,room_type_id',
                    'seasonalRates' => fn ($q) => $q->orderBy('start_date'),
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreRoomTypeRequest $request): JsonResponse
    {
        $roomType = RoomType::create($request->validated());

        AuditLog::record('room_type.created', $roomType, ['name' => $roomType->name]);

        return response()->json(['message' => "Room type \"{$roomType->name}\" created.", 'room_type' => $roomType], 201);
    }

    public function update(UpdateRoomTypeRequest $request, RoomType $roomType): JsonResponse
    {
        $roomType->update($request->validated());

        AuditLog::record('room_type.updated', $roomType, ['name' => $roomType->name]);

        return response()->json(['message' => 'Room type updated.', 'room_type' => $roomType]);
    }

    public function storeSeasonalRate(StoreSeasonalRateRequest $request, RoomType $roomType): JsonResponse
    {
        $rate = $roomType->seasonalRates()->create($request->validated());

        AuditLog::record('room_type.seasonal_rate_added', $roomType, [
            'name' => $rate->name,
            'rate' => $rate->rate,
        ]);

        return response()->json(['message' => 'Seasonal rate added.', 'seasonal_rate' => $rate], 201);
    }

    public function destroySeasonalRate(Request $request, SeasonalRate $seasonalRate): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo('hotel_room_types.edit')) {
            abort(403);
        }

        $seasonalRate->delete();

        AuditLog::record('room_type.seasonal_rate_removed', $seasonalRate);

        return response()->json(['message' => 'Seasonal rate removed.']);
    }
}
