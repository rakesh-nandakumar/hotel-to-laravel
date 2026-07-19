<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\SubmitPreCheckInRequest;
use App\Http\Requests\Hotel\SubmitVenueInquiryRequest;
use App\Models\Hotel\Venue;
use App\Services\Hotel\PublicService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated guest-facing endpoints — branding, online pre-check-in,
 * and the venue inquiry form. Ported from the Node app's routes/public.ts.
 * Sits entirely outside the auth/permission system, matching Node's
 * zero-middleware mount of public.ts.
 */
class PublicController extends Controller
{
    public function __construct(private readonly PublicService $public) {}

    public function branding(): JsonResponse
    {
        return response()->json([
            'name' => Settings::str('hotel.name', 'Mount View Hotel, Badulla'),
            'tagline' => Settings::str('hotel.tagline', 'Hospitality Management System'),
            'login_tagline' => Settings::str('hotel.login_tagline', 'Hospitality Management System'),
            'logo' => Settings::str('hotel.logo_url', ''),
            'address' => Settings::str('hotel.address', ''),
            'phone' => Settings::str('hotel.phone', ''),
            'email' => Settings::str('hotel.email', ''),
            'check_in_time' => Settings::str('frontdesk.check_in_time', '14:00'),
            'check_out_time' => Settings::str('frontdesk.check_out_time', '12:00'),
            'usd_rate' => Settings::num('currency.usd_rate', 300),
            'theme_primary' => Settings::str('theme.primary', '#0462d3'),
            'theme_secondary' => Settings::str('theme.secondary', '#3783f0'),
            'theme_sidebar' => Settings::str('theme.sidebar', '#0c182a'),
        ]);
    }

    /** Guest looks up their booking by code, then submits pre-arrival details. */
    public function preCheckIn(SubmitPreCheckInRequest $request): JsonResponse
    {
        $this->public->preCheckIn($request->validated());

        return response()->json(['ok' => true, 'message' => 'Pre-check-in received — see you soon!']);
    }

    public function venues(): JsonResponse
    {
        $venues = Venue::query()->where('active', true)->orderBy('name')
            ->get(['id', 'name', 'max_capacity', 'facilities', 'hourly_rate', 'half_day_rate', 'full_day_rate']);

        return response()->json($venues);
    }

    /** Venue inquiry from an outside customer — recorded as an INQUIRY for the manager. */
    public function venueInquiry(SubmitVenueInquiryRequest $request): JsonResponse
    {
        $booking = $this->public->venueInquiry($request->validated());

        return response()->json([
            'ok' => true,
            'reference' => $booking->code,
            'message' => 'Inquiry received — our events team will contact you shortly.',
        ], 201);
    }
}
