<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\StorePropertyRequest;
use App\Http\Requests\Apartment\UpdatePropertyRequest;
use App\Models\Apartment\Property;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;

class PropertyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'properties' => Property::query()
                ->withCount('units')
                ->with('branch:id,name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = Property::create($request->validated());

        AuditLog::record('apartment_property.created', $property, ['name' => $property->name]);

        return response()->json(['message' => "Property \"{$property->name}\" created.", 'property' => $property], 201);
    }

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $property->update($request->validated());

        AuditLog::record('apartment_property.updated', $property, ['name' => $property->name]);

        return response()->json(['message' => 'Property updated.', 'property' => $property]);
    }
}
