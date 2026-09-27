<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\UpdateVenueRequest;
use App\Models\Hotel\Venue;
use App\Models\Hotel\VenueBooking;
use App\Services\AuditLog;
use App\Support\Lookups\VenueBookingStatus;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['venues' => Venue::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'max_capacity' => 'required|integer|min:1',
            'hourly_rate' => 'required|integer|min:0',
            'half_day_rate' => 'required|integer|min:0',
            'full_day_rate' => 'required|integer|min:0',
            'facilities' => 'array',
            'facilities.*' => 'string',
            'active' => 'boolean',
            // New package pricing fields
            'hall_type' => 'nullable|in:luxury,basic',
            'luxury_hall_charge' => 'nullable|integer|min:0',
            'basic_hall_charge' => 'nullable|integer|min:0',
            'per_plate_starting_price' => 'nullable|integer|min:0',
            'hall_only_per_person' => 'nullable|integer|min:0',
            'dj_included' => 'nullable|boolean',
            'bar_charges_included' => 'nullable|boolean',
            'byod_allowed' => 'nullable|boolean',
            'use_package_pricing' => 'nullable|boolean',
        ]);

        $venue = Venue::create([
            'name' => $validated['name'],
            'max_capacity' => $validated['max_capacity'],
            'hourly_rate' => $validated['hourly_rate'],
            'half_day_rate' => $validated['half_day_rate'],
            'full_day_rate' => $validated['full_day_rate'],
            'facilities' => $validated['facilities'] ?? [],
            'active' => $validated['active'] ?? true,
            // Package pricing fields
            'hall_type' => $validated['hall_type'] ?? null,
            'luxury_hall_charge' => $validated['luxury_hall_charge'] ?? 45000,
            'basic_hall_charge' => $validated['basic_hall_charge'] ?? 40000,
            'per_plate_starting_price' => $validated['per_plate_starting_price'] ?? 1950,
            'hall_only_per_person' => $validated['hall_only_per_person'] ?? 500,
            'dj_included' => $validated['dj_included'] ?? true,
            'bar_charges_included' => $validated['bar_charges_included'] ?? true,
            'byod_allowed' => $validated['byod_allowed'] ?? true,
            'use_package_pricing' => $validated['use_package_pricing'] ?? false,
        ]);

        AuditLog::record('venue.created', $venue, ['name' => $venue->name]);

        return response()->json(['message' => 'Venue created.', 'venue' => $venue], 201);
    }

    public function update(UpdateVenueRequest $request, Venue $venue): JsonResponse
    {
        $validated = $request->validated();

        // Handle package pricing toggle - if switching to package pricing, set defaults
        if (isset($validated['use_package_pricing']) && $validated['use_package_pricing']) {
            $validated['hall_type'] = $validated['hall_type'] ?? 'basic';
            $validated['luxury_hall_charge'] = $validated['luxury_hall_charge'] ?? 45000;
            $validated['basic_hall_charge'] = $validated['basic_hall_charge'] ?? 40000;
            $validated['per_plate_starting_price'] = $validated['per_plate_starting_price'] ?? 1950;
            $validated['hall_only_per_person'] = $validated['hall_only_per_person'] ?? 500;
        }

        $venue->update($validated);

        AuditLog::record('venue.updated', $venue, ['name' => $venue->name]);

        return response()->json(['message' => 'Venue updated.', 'venue' => $venue]);
    }

    /** Availability: bookings for a venue in a date range. */
    public function calendar(Request $request, Venue $venue): JsonResponse
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : now();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $from->copy()->addDays(60);

        $bookings = VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('status', fn ($q) => $q->whereIn('code', [VenueBookingStatus::INQUIRY, VenueBookingStatus::CONFIRMED]))
            ->orderBy('date')
            ->with('status')
            ->get();

        return response()->json(['bookings' => $bookings]);
    }
}
