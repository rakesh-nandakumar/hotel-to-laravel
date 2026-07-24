<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Lease;
use App\Models\Apartment\LedgerLine;
use App\Models\Apartment\UtilityReading;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\ApartmentLeaseStatus;
use App\Support\Lookups\ApartmentLineSource;
use App\Support\Lookups\LookupType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring lease billing — the piece hotel rooms never needed. Posts one
 * rent charge per active lease per calendar month (idempotent: safe to run
 * twice for the same month without double-charging — see
 * apartment_lease_rent_charges) and turns a meter reading into a ledger line.
 */
class ApartmentLeaseBillingService
{
    /**
     * Called by the scheduled job in routes/console.php (and directly by
     * tests with an explicit $asOf). Only leases whose [start_date, end_date)
     * covers the billed month are charged.
     *
     * @return array{charged: int, skipped_existing: int}
     */
    public function generateMonthlyCharges(?CarbonInterface $asOf = null): array
    {
        $periodMonth = ($asOf ?? now())->copy()->startOfMonth();
        $periodEnd = $periodMonth->copy()->addMonthNoOverflow();

        $leases = Lease::query()
            ->statusIn([ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED])
            ->where('start_date', '<', $periodEnd->toDateString())
            ->where(function ($q) use ($periodMonth) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $periodMonth->toDateString());
            })
            ->with('ledger')
            ->get();

        $charged = 0;
        $skipped = 0;

        foreach ($leases as $lease) {
            if (! $lease->ledger) {
                continue;
            }

            $created = DB::transaction(function () use ($lease, $periodMonth) {
                if ($lease->rentCharges()->whereDate('period_month', $periodMonth->toDateString())->exists()) {
                    return false;
                }

                $line = LedgerLine::create([
                    'ledger_id' => $lease->ledger->id,
                    'line_source_id' => Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::RENT),
                    'description' => 'Rent — '.$periodMonth->format('F Y'),
                    'qty' => 1, 'unit_price' => $lease->monthly_rent, 'amount' => $lease->monthly_rent,
                    'staff_id' => null,
                ]);

                $lease->rentCharges()->create(['period_month' => $periodMonth->toDateString(), 'ledger_line_id' => $line->id]);

                return true;
            });

            if ($created) {
                $charged++;
                AuditLog::record('apartment_lease.rent_charged', $lease, [
                    'period_month' => $periodMonth->toDateString(), 'amount' => $lease->monthly_rent,
                ]);
            } else {
                $skipped++;
            }
        }

        return ['charged' => $charged, 'skipped_existing' => $skipped];
    }

    /**
     * @param  array{utility_type: string, period_month: string, previous_reading: float, current_reading: float, rate_per_unit: int}  $data
     */
    public function recordUtilityReading(Lease $lease, array $data, int $staffId): UtilityReading
    {
        $lease->loadMissing('ledger');
        if (! $lease->ledger) {
            throw ValidationException::withMessages(['lease' => 'Lease has no ledger.']);
        }

        if ($data['current_reading'] < $data['previous_reading']) {
            throw ValidationException::withMessages(['current_reading' => 'Current reading cannot be less than the previous reading.']);
        }

        $periodMonth = Carbon::parse($data['period_month'])->startOfMonth();
        $units = $data['current_reading'] - $data['previous_reading'];
        $amount = (int) round($units * $data['rate_per_unit']);

        return DB::transaction(function () use ($lease, $data, $periodMonth, $units, $amount, $staffId) {
            $line = LedgerLine::create([
                'ledger_id' => $lease->ledger->id,
                'line_source_id' => Lookup::id(LookupType::APARTMENT_LINE_SOURCE, ApartmentLineSource::UTILITY),
                'description' => ucfirst($data['utility_type']).' — '.$periodMonth->format('F Y')." ({$units} units)",
                'qty' => 1, 'unit_price' => $amount, 'amount' => $amount,
                'staff_id' => $staffId,
            ]);

            $reading = UtilityReading::create([
                'lease_id' => $lease->id,
                'utility_type_id' => Lookup::id(LookupType::APARTMENT_UTILITY_TYPE, $data['utility_type']),
                'period_month' => $periodMonth,
                'previous_reading' => $data['previous_reading'],
                'current_reading' => $data['current_reading'],
                'rate_per_unit' => $data['rate_per_unit'],
                'amount' => $amount,
                'ledger_line_id' => $line->id,
                'staff_id' => $staffId,
            ]);

            AuditLog::record('apartment_lease.utility_reading_recorded', $reading, [
                'utility_type' => $data['utility_type'], 'amount' => $amount,
            ]);

            return $reading;
        });
    }
}
