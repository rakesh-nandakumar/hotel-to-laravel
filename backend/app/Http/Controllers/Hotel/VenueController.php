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

    public function update(UpdateVenueRequest $request, Venue $venue): JsonResponse
    {
        $venue->update($request->validated());

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
