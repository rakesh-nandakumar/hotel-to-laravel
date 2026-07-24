<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Booking;
use App\Models\Apartment\Lease;
use App\Models\Apartment\Unit;
use App\Models\Lookup;
use App\Support\Lookups\ApartmentBookingStatus;
use App\Support\Lookups\ApartmentLeaseStatus;
use App\Support\Lookups\ApartmentListingType;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Rental availability for apartment units — mirrors the Hotel module's
 * room-availability service (App\Services\Hotel\ReservationAvailabilityService)
 * but single-unit per booking (no ReservationRoom-style join table needed)
 * and with two extra gates the hotel side doesn't need: a unit must be
 * listed for RENTAL and not RESERVED/SOLD (held by, or completed through,
 * the sales pipeline — see the sales service landing in M4), and a window
 * must be free of BOTH short-stay bookings AND long-term leases — the two
 * rental sub-modules share one calendar per unit so they can never double-book it.
 */
class ApartmentAvailabilityService
{
    /**
     * @return Collection<int, Unit>
     */
    public function availableUnits(CarbonInterface $checkIn, CarbonInterface $checkOut, ?int $excludeBookingId = null): Collection
    {
        $busyBookingStatuses = [ApartmentBookingStatus::PENDING, ApartmentBookingStatus::CONFIRMED, ApartmentBookingStatus::CHECKED_IN];

        $busyUnitIds = Booking::query()
            ->statusIn($busyBookingStatuses)
            ->where('check_in', '<', $checkOut->toDateString())
            ->where('check_out', '>', $checkIn->toDateString())
            ->when($excludeBookingId, fn (Builder $q) => $q->where('id', '!=', $excludeBookingId))
            ->pluck('unit_id')
            ->merge($this->leaseBusyUnitIds($checkIn, $checkOut));

        // DIRTY only blocks immediate check-in (guarded in ApartmentBookingService::checkIn()),
        // not a future booking — same as Hotel's "not MAINTENANCE" room-availability rule.
        $rentableStatusIds = [
            Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
            Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::OCCUPIED),
            Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::DIRTY),
        ];

        return Unit::query()
            ->listingTypeCode(ApartmentListingType::RENTAL)
            ->whereIn('unit_status_id', $rentableStatusIds)
            ->whereNotIn('id', $busyUnitIds)
            ->with(['unitType.seasonalRates', 'property:id,name'])
            ->orderBy('unit_no')
            ->get();
    }

    public function assertUnitAvailable(int $unitId, CarbonInterface $checkIn, CarbonInterface $checkOut, ?int $excludeBookingId = null): Unit
    {
        $free = $this->availableUnits($checkIn, $checkOut, $excludeBookingId)->keyBy('id');

        if (! $free->has($unitId)) {
            $unit = Unit::find($unitId);

            throw ValidationException::withMessages([
                'unit_id' => 'Unit '.($unit->unit_no ?? $unitId).' is not available for those dates.',
            ]);
        }

        return $free->get($unitId);
    }

    /**
     * Lease-side counterpart of {@see assertUnitAvailable()} — a unit must be
     * free of every other active lease AND every short-stay booking over the
     * proposed lease window. `$endDate` may be null (open-ended/month-to-month),
     * treated as unbounded.
     */
    public function assertUnitAvailableForLease(int $unitId, CarbonInterface $startDate, ?CarbonInterface $endDate, ?int $excludeLeaseId = null): Unit
    {
        $unit = Unit::query()
            ->listingTypeCode(ApartmentListingType::RENTAL)
            ->whereIn('unit_status_id', [
                Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::AVAILABLE),
                Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::OCCUPIED),
            ])
            ->find($unitId);

        if (! $unit) {
            throw ValidationException::withMessages(['unit_id' => 'This unit is not available for a lease.']);
        }

        $overlapsLease = Lease::query()
            ->where('unit_id', $unitId)
            ->statusIn([ApartmentLeaseStatus::DRAFT, ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED])
            ->when($excludeLeaseId, fn (Builder $q) => $q->where('id', '!=', $excludeLeaseId))
            ->where(function (Builder $q) use ($startDate) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $startDate->toDateString());
            })
            ->when($endDate, fn (Builder $q) => $q->where('start_date', '<', $endDate->toDateString()))
            ->exists();

        if ($overlapsLease) {
            throw ValidationException::withMessages(['unit_id' => "Unit {$unit->unit_no} already has a lease over that period."]);
        }

        $overlapsBooking = Booking::query()
            ->where('unit_id', $unitId)
            ->statusIn([ApartmentBookingStatus::PENDING, ApartmentBookingStatus::CONFIRMED, ApartmentBookingStatus::CHECKED_IN])
            ->where('check_in', '<', $endDate?->toDateString() ?? '9999-12-31')
            ->where('check_out', '>', $startDate->toDateString())
            ->exists();

        if ($overlapsBooking) {
            throw ValidationException::withMessages(['unit_id' => "Unit {$unit->unit_no} has a short-stay booking over that period."]);
        }

        return $unit;
    }

    /**
     * @return Collection<int, int>
     */
    private function leaseBusyUnitIds(CarbonInterface $checkIn, CarbonInterface $checkOut): Collection
    {
        return Lease::query()
            ->statusIn([ApartmentLeaseStatus::DRAFT, ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED])
            ->where('start_date', '<', $checkOut->toDateString())
            ->where(function (Builder $q) use ($checkIn) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $checkIn->toDateString());
            })
            ->pluck('unit_id');
    }
}
