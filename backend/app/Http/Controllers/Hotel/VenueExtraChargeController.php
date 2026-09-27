<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Hotel\VenueBooking;
use App\Models\Hotel\VenueExtraCharge;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueExtraChargeController extends Controller
{
    public function index(Request $request, VenueBooking $booking): JsonResponse
    {
        $charges = $booking->extraCharges()->orderBy('sort_order')->get();

        return response()->json(['charges' => $charges]);
    }

    public function store(Request $request, VenueBooking $booking): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|integer|min:0',
            'charge_type' => 'nullable|string|max:50',
            'is_percentage' => 'nullable|boolean',
        ]);

        $charge = $booking->extraCharges()->create([
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'charge_type' => $validated['charge_type'] ?? 'misc',
            'is_percentage' => $validated['is_percentage'] ?? false,
            'sort_order' => $booking->extraCharges()->count(),
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('venue_extra_charge.created', $charge, [
            'booking_code' => $booking->code,
            'description' => $charge->description,
            'amount' => $charge->amount,
        ]);

        return response()->json(['message' => 'Extra charge added.', 'charge' => $charge], 201);
    }

    public function update(Request $request, VenueExtraCharge $charge): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'sometimes|string|max:255',
            'amount' => 'sometimes|integer|min:0',
            'charge_type' => 'sometimes|nullable|string|max:50',
            'is_percentage' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $charge->update($validated);

        AuditLog::record('venue_extra_charge.updated', $charge, [
            'description' => $charge->description,
            'amount' => $charge->amount,
        ]);

        return response()->json(['message' => 'Extra charge updated.', 'charge' => $charge]);
    }

    public function destroy(VenueExtraCharge $charge): JsonResponse
    {
        $booking = $charge->venueBooking;
        $charge->delete();

        AuditLog::record('venue_extra_charge.deleted', $charge, [
            'booking_code' => $booking->code,
            'description' => $charge->description,
        ]);

        return response()->json(['message' => 'Extra charge removed.']);
    }
}
