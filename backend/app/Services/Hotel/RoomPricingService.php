<?php

namespace App\Services\Hotel;

use App\Models\Hotel\RoomType;
use App\Services\Settings;
use Carbon\CarbonInterface;

/**
 * Dynamic nightly-rate waterfall, ported from the Node app's lib/booking.ts
 * (nightlyRate): seasonal override → public holiday → configured weekend day
 * → weekday rate. Shared by the Rooms module (rate previews) and, later,
 * Reservations (booking/checkout pricing) — do not duplicate this logic.
 */
class RoomPricingService
{
    /**
     * Every night of the stay [checkIn, checkOut) as Y-m-d strings.
     *
     * @return list<string>
     */
    public function nights(CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $dates = [];
        $date = $checkIn->copy()->startOfDay();
        $end = $checkOut->copy()->startOfDay();

        while ($date->lt($end)) {
            $dates[] = $date->toDateString();
            $date = $date->copy()->addDay();
        }

        return $dates;
    }

    public function nightlyRate(RoomType $roomType, CarbonInterface $date): int
    {
        $dateStr = $date->toDateString();

        $seasonal = $roomType->relationLoaded('seasonalRates')
            ? $roomType->seasonalRates
            : $roomType->seasonalRates()->get();

        $override = $seasonal->first(
            fn ($rate) => $dateStr >= $rate->start_date->toDateString() && $dateStr <= $rate->end_date->toDateString(),
        );

        if ($override) {
            return $override->rate;
        }

        if ($this->isPublicHoliday($dateStr) || $this->isWeekendDay($date)) {
            return $roomType->weekend_rate;
        }

        return $roomType->weekday_rate;
    }

    private function isPublicHoliday(string $dateStr): bool
    {
        return in_array($dateStr, Settings::json('pricing.public_holidays'), true);
    }

    private function isWeekendDay(CarbonInterface $date): bool
    {
        $weekendDays = Settings::json('pricing.weekend_days', [0, 6]);

        return in_array($date->dayOfWeek, $weekendDays, true);
    }
}
