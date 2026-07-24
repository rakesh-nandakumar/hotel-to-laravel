<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\StoreUnitRequest;
use App\Http\Requests\Apartment\UpdateUnitRequest;
use App\Http\Requests\Apartment\UpdateUnitStatusRequest;
use App\Models\Apartment\Unit;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UnitController extends Controller
{
    /** Direct status edits can never set these — they are the outcome of an
     *  owning workflow (booking check-in/checkout, lease start/end, sale
     *  completion, housekeeping/maintenance) rather than a manual admin action. */
    private const WORKFLOW_ONLY_STATUSES = [
        ApartmentUnitStatus::OCCUPIED,
        ApartmentUnitStatus::DIRTY,
        ApartmentUnitStatus::RESERVED,
        ApartmentUnitStatus::SOLD,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Unit::query()->with(['property:id,name', 'unitType:id,name,nightly_rate,monthly_rate', 'listingType', 'status']);

        if ($status = $request->string('status')->toString()) {
            $query->statusCode($status);
        }
        if ($listingType = $request->string('listing_type')->toString()) {
            $query->listingTypeCode($listingType);
        }
        if ($propertyId = $request->integer('property_id')) {
            $query->where('property_id', $propertyId);
        }

        return response()->json(['units' => $query->orderBy('unit_no')->get()]);
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['listing_type_id'] = Lookup::id(LookupType::APARTMENT_LISTING_TYPE, $data['listing_type']);
        unset($data['listing_type']);
        $data['unit_status_id'] = Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE);

        $unit = Unit::create($data);

        AuditLog::record('apartment_unit.created', $unit, ['unit_no' => $unit->unit_no]);

        return response()->json(['message' => "Unit \"{$unit->unit_no}\" created.", 'unit' => $unit->load(['property', 'unitType', 'listingType', 'status'])], 201);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['listing_type'])) {
            $data['listing_type_id'] = Lookup::id(LookupType::APARTMENT_LISTING_TYPE, $data['listing_type']);
            unset($data['listing_type']);
        }

        $unit->update($data);

        AuditLog::record('apartment_unit.updated', $unit, ['unit_no' => $unit->unit_no]);

        return response()->json(['message' => 'Unit updated.', 'unit' => $unit->load(['property', 'unitType', 'listingType', 'status'])]);
    }

    public function updateStatus(UpdateUnitStatusRequest $request, Unit $unit): JsonResponse
    {
        $newStatus = $request->statusLookup();

        if (in_array($newStatus->code, self::WORKFLOW_ONLY_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Unit status \"{$newStatus->code}\" is set automatically by its booking/lease/sale/housekeeping — it can't be applied directly.",
            ]);
        }

        // DIRTY → AVAILABLE only via the housekeeping checklist-complete action
        // (mirrors Hotel's RoomController::updateStatus() guard).
        if ($newStatus->code === ApartmentUnitStatus::AVAILABLE && $unit->status?->code === ApartmentUnitStatus::DIRTY) {
            throw ValidationException::withMessages([
                'status' => 'Unit can only be marked Available by completing its housekeeping checklist.',
            ]);
        }

        $from = $unit->status?->code;
        $unit->update(['unit_status_id' => $newStatus->id]);

        AuditLog::record('apartment_unit.status_changed', $unit, ['from' => $from, 'to' => $newStatus->code]);

        return response()->json(['message' => 'Unit status updated.', 'unit' => $unit->load('status')]);
    }
}
