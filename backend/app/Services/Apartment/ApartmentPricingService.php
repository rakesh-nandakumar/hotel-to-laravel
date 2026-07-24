<?php

namespace App\Services\Apartment;

use App\Models\Apartment\UnitType;
use App\Services\Settings;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Nightly/weekly/monthly rate resolution for apartment stays — the
 * Apartments-module counterpart of the Hotel module's room-pricing service
 * (App\Services\Hotel\RoomPricingService), extended with the long-stay
 * tiering hotel rooms don't need: a stay long enough to clear the configured
 * weekly/monthly threshold is priced off that tier's rate (prorated to a
 * nightly-equivalent) instead of the nightly rate, with seasonal overrides
 * only applying to genuinely nightly-basis stays.
 */
class ApartmentPricingService
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

    /**
     * @return array{nightly_rate: int, rate_basis: string, stay_total: int, night_count: int}
     */
    public function resolveStayRate(UnitType $unitType, CarbonInterface $checkIn, CarbonInterface $checkOut): array
    {
        $nightCount = count($this->nights($checkIn, $checkOut));
        if ($nightCount < $unitType->min_nights) {
            throw ValidationException::withMessages([
                'check_in' => "\"{$unitType->name}\" requires a minimum stay of {$unitType->min_nights} night(s).",
            ]);
        }

        $monthlyThreshold = (int) Settings::num('apartment.monthly_stay_threshold_nights', 28);
        $weeklyThreshold = (int) Settings::num('apartment.weekly_stay_threshold_nights', 7);

        if ($nightCount >= $monthlyThreshold && $unitType->monthly_rate) {
            $nightlyRate = (int) round($unitType->monthly_rate / 30);
            $rateBasis = 'monthly';
        } elseif ($nightCount >= $weeklyThreshold && $unitType->weekly_rate) {
            $nightlyRate = (int) round($unitType->weekly_rate / 7);
            $rateBasis = 'weekly';
        } else {
            $nightlyRate = $this->seasonalOrBaseRate($unitType, $checkIn);
            $rateBasis = 'nightly';
        }

        if ($nightlyRate <= 0) {
            throw ValidationException::withMessages([
                'unit_type' => "\"{$unitType->name}\" has no rate configured for a {$nightCount}-night stay.",
            ]);
        }

        return [
            'nightly_rate' => $nightlyRate,
            'rate_basis' => $rateBasis,
            'stay_total' => $nightlyRate * $nightCount,
            'night_count' => $nightCount,
        ];
    }

    private function seasonalOrBaseRate(UnitType $unitType, CarbonInterface $firstNight): int
    {
        $dateStr = $firstNight->toDateString();

        $seasonal = $unitType->relationLoaded('seasonalRates')
            ? $unitType->seasonalRates
            : $unitType->seasonalRates()->get();

        $override = $seasonal->first(
            fn ($rate) => $dateStr >= $rate->start_date->toDateString() && $dateStr <= $rate->end_date->toDateString(),
        );

        return $override ? $override->rate : (int) $unitType->nightly_rate;
    }
}
