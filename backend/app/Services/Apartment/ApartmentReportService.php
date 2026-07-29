<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Booking;
use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\Lease;
use App\Models\Apartment\LedgerLine;
use App\Models\Apartment\MaintenanceIssue;
use App\Models\Apartment\Payment;
use App\Models\Apartment\Sale;
use App\Models\Apartment\Unit;
use App\Models\Apartment\UtilityReading;
use App\Support\Lookups\ApartmentBookingStatus;
use App\Support\Lookups\ApartmentLeaseStatus;
use App\Support\Lookups\ApartmentListingType;
use App\Support\Lookups\ApartmentSaleStatus;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\TaskStatus;
use Carbon\CarbonImmutable;

/**
 * Apartments operational dashboard — occupancy, arrears, sales pipeline,
 * revenue, and open ops tickets in one call. Mirrors the shape of the Hotel
 * module's ReportService::dashboard() but scoped to what an apartments
 * manager actually needs day to day, not a line-for-line port.
 */
class ApartmentReportService
{
    public function __construct(private readonly ApartmentBillingService $billing) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $unitsByStatus = Unit::query()
            ->join('lookups', 'lookups.id', '=', 'apartment_units.unit_status_id')
            ->selectRaw('lookups.code as code, count(*) as total')
            ->groupBy('lookups.code')
            ->pluck('total', 'code');

        $today = today()->toDateString();
        $arrivalsToday = Booking::query()
            ->statusIn([ApartmentBookingStatus::CONFIRMED, ApartmentBookingStatus::PENDING])
            ->where('check_in', $today)
            ->with(['customer:id,name', 'unit:id,unit_no'])
            ->get();
        $departuresToday = Booking::query()
            ->statusCode(ApartmentBookingStatus::CHECKED_IN)
            ->where('check_out', $today)
            ->with(['customer:id,name', 'unit:id,unit_no'])
            ->get();

        $activeLeases = Lease::query()
            ->statusIn([ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED])
            ->with(['customer:id,name', 'unit:id,unit_no', 'ledger'])
            ->get();

        $leasesExpiringSoon = $activeLeases
            ->filter(fn (Lease $l) => $l->end_date && $l->end_date->betweenIncluded(today(), today()->addDays(30)))
            ->sortBy('end_date')
            ->values();

        $overdueLeases = $activeLeases
            ->filter(fn (Lease $l) => $l->ledger)
            ->map(fn (Lease $l) => ['lease' => $l, 'balance' => $this->billing->totals($l->ledger)['balance']])
            ->filter(fn (array $row) => $row['balance'] > 0)
            ->map(fn (array $row) => [
                'id' => $row['lease']->id, 'code' => $row['lease']->code,
                'customer' => $row['lease']->customer->name, 'unit' => $row['lease']->unit->unit_no,
                'balance' => $row['balance'],
            ])
            ->values();

        $salesPipeline = Sale::query()
            ->join('lookups', 'lookups.id', '=', 'apartment_sales.sale_status_id')
            ->selectRaw('lookups.code as code, count(*) as total')
            ->groupBy('lookups.code')
            ->pluck('total', 'code');

        $monthStart = today()->startOfMonth();
        $paymentsThisMonth = Payment::query()->with('kind')->where('created_at', '>=', $monthStart)->get();
        $collectedThisMonth = (int) $paymentsThisMonth->filter(fn (Payment $p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount')
            - (int) $paymentsThisMonth->filter(fn (Payment $p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');

        return [
            'units' => [
                'total' => Unit::query()->count(),
                'by_status' => $unitsByStatus,
            ],
            'bookings' => [
                'arrivals_today' => $arrivalsToday,
                'departures_today' => $departuresToday,
                'checked_in_count' => Booking::query()->statusCode(ApartmentBookingStatus::CHECKED_IN)->count(),
            ],
            'leases' => [
                'active_count' => $activeLeases->count(),
                'expiring_within_30_days' => $leasesExpiringSoon,
                'overdue' => $overdueLeases,
            ],
            'sales_pipeline' => $salesPipeline,
            'revenue_this_month' => $collectedThisMonth,
            'ops' => [
                'pending_housekeeping' => HousekeepingTask::query()->statusCode(TaskStatus::PENDING)->count()
                    + HousekeepingTask::query()->statusCode(TaskStatus::IN_PROGRESS)->count(),
                'open_maintenance' => MaintenanceIssue::query()->whereHas('status', fn ($q) => $q->where('code', '!=', MaintenanceStatus::RESOLVED))->count(),
            ],
        ];
    }

    /**
     * Day-by-day occupied-unit count & occupancy % across rental units —
     * a unit counts as occupied if either a checked-in booking or an
     * active/renewed lease covers it that day (the two never overlap on the
     * same unit, per the module's core "reserved/leased can't also be
     * rented" guard, but a union avoids ever double-counting either way).
     *
     * @return array<string, mixed>
     */
    public function computeOccupancyTrend(string $from, string $to): array
    {
        $totalUnits = Unit::query()->listingTypeCode(ApartmentListingType::RENTAL)->count();
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $series = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $nextDay = $cursor->addDay()->toDateString();

            $bookedUnitIds = Booking::query()
                ->statusIn([ApartmentBookingStatus::CHECKED_IN, ApartmentBookingStatus::CHECKED_OUT])
                ->where('check_in', '<', $nextDay)->where('check_out', '>', $date)
                ->pluck('unit_id');
            $leasedUnitIds = Lease::query()
                ->statusIn([ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED])
                ->where('start_date', '<=', $date)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
                ->pluck('unit_id');

            $occupied = $bookedUnitIds->merge($leasedUnitIds)->unique()->count();
            $series[] = ['date' => $date, 'occupied_units' => $occupied, 'occupancy_pct' => $totalUnits ? (int) round($occupied / $totalUnits * 100) : 0];
        }

        return [
            'from' => $from,
            'to' => $to,
            'total_rental_units' => $totalUnits,
            'series' => $series,
            'avg_occupancy_pct' => count($series) ? (int) round(collect($series)->avg('occupancy_pct')) : 0,
        ];
    }

    /**
     * Bookings & revenue by channel (join+groupBy, same pattern as the Hotel
     * module's channel-mix report) and by property.
     *
     * @return array<string, mixed>
     */
    public function computeRentalRevenueChannelMix(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $bookingCounts = Booking::query()
            ->join('lookups', 'lookups.id', '=', 'apartment_bookings.channel_id')
            ->whereBetween('apartment_bookings.created_at', [$start, $end])
            ->selectRaw('lookups.code as code, count(*) as total')
            ->groupBy('lookups.code')
            ->pluck('total', 'code');

        $revenueByChannel = LedgerLine::query()
            ->join('apartment_ledgers', 'apartment_ledgers.id', '=', 'apartment_ledger_lines.ledger_id')
            ->join('apartment_bookings', 'apartment_bookings.id', '=', 'apartment_ledgers.booking_id')
            ->join('lookups', 'lookups.id', '=', 'apartment_bookings.channel_id')
            ->where('apartment_ledger_lines.voided', false)
            ->whereBetween('apartment_ledger_lines.created_at', [$start, $end])
            ->selectRaw('lookups.code as code, coalesce(sum(apartment_ledger_lines.amount),0) as revenue')
            ->groupBy('lookups.code')
            ->pluck('revenue', 'code');

        $byChannel = [];
        foreach (array_unique([...$bookingCounts->keys(), ...$revenueByChannel->keys()]) as $code) {
            $byChannel[$code] = ['bookings' => (int) ($bookingCounts[$code] ?? 0), 'revenue' => (int) ($revenueByChannel[$code] ?? 0)];
        }

        $byProperty = Booking::query()
            ->join('apartment_units', 'apartment_units.id', '=', 'apartment_bookings.unit_id')
            ->leftJoin('apartment_properties', 'apartment_properties.id', '=', 'apartment_units.property_id')
            ->whereBetween('apartment_bookings.created_at', [$start, $end])
            ->selectRaw("coalesce(apartment_properties.name, 'Ungrouped') as property, count(*) as bookings")
            ->groupBy('property')
            ->pluck('bookings', 'property');

        return [
            'from' => $from,
            'to' => $to,
            'total_bookings' => (int) $bookingCounts->sum(),
            'by_channel' => $byChannel,
            'by_property' => $byProperty,
        ];
    }

    /**
     * Rent roll snapshot for every active/renewed lease: monthly rent,
     * current ledger balance, and a 30/60/90+ aging bucket based on the
     * oldest still-unpaid ledger line — a "right now" balance-sheet view,
     * not date-ranged (mirrors Hotel's Corporate AR report).
     *
     * @return array<string, mixed>
     */
    public function computeRentRoll(): array
    {
        $leases = Lease::query()
            ->statusIn([ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED])
            ->with(['customer:id,name', 'unit:id,unit_no', 'ledger'])
            ->get();

        $rows = $leases->map(function (Lease $lease) {
            $balance = 0;
            $daysOutstanding = 0;
            if ($lease->ledger) {
                $balance = $this->billing->totals($lease->ledger)['balance'];
                if ($balance > 0) {
                    $oldestUnpaid = LedgerLine::query()->where('ledger_id', $lease->ledger->id)->where('voided', false)->oldest()->value('created_at');
                    $daysOutstanding = $oldestUnpaid ? CarbonImmutable::parse($oldestUnpaid)->diffInDays(now()) : 0;
                }
            }
            $bucket = $balance <= 0 ? 'current' : ($daysOutstanding <= 30 ? '0-30' : ($daysOutstanding <= 60 ? '31-60' : ($daysOutstanding <= 90 ? '61-90' : '90+')));

            return [
                'id' => $lease->id, 'code' => $lease->code, 'customer' => $lease->customer->name, 'unit' => $lease->unit->unit_no,
                'monthly_rent' => $lease->monthly_rent, 'balance' => $balance, 'aging_bucket' => $bucket, 'days_outstanding' => $daysOutstanding,
            ];
        })->sortByDesc('balance')->values();

        return [
            'leases' => $rows,
            'total_monthly_rent' => (int) $rows->sum('monthly_rent'),
            'total_arrears' => (int) $rows->sum('balance'),
            'by_bucket' => $rows->groupBy('aging_bucket')->map(fn ($g) => (int) $g->sum('balance')),
        ];
    }

    /**
     * Sales funnel counts, conversion rate (completed / total), and average
     * days-to-close for sales created within the range.
     *
     * @return array<string, mixed>
     */
    public function computeSalesPipeline(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $sales = Sale::query()->whereBetween('created_at', [$start, $end])->with('status')->get();
        $completed = $sales->filter(fn (Sale $s) => $s->status->code === ApartmentSaleStatus::COMPLETED);
        $completedWithHandover = $completed->filter(fn (Sale $s) => $s->handover_at !== null);

        return [
            'from' => $from,
            'to' => $to,
            'total_sales' => $sales->count(),
            'by_status' => $sales->groupBy(fn (Sale $s) => $s->status->code)->map->count(),
            'conversion_rate_pct' => $sales->count() ? round($completed->count() / $sales->count() * 100, 1) : 0,
            'avg_days_to_close' => $completedWithHandover->count() ? round($completedWithHandover->avg(fn (Sale $s) => $s->created_at->diffInDays($s->handover_at)), 1) : 0,
            'total_pipeline_value' => (int) $sales->sum('agreed_price'),
            'completed_value' => (int) $completed->sum('agreed_price'),
            'cancelled_count' => $sales->filter(fn (Sale $s) => $s->status->code === ApartmentSaleStatus::CANCELLED)->count(),
        ];
    }

    /**
     * Utility consumption/cost by type and by unit over a date range.
     *
     * @return array<string, mixed>
     */
    public function computeUtilities(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $readings = UtilityReading::query()->whereBetween('created_at', [$start, $end])->with('utilityType', 'lease.unit:id,unit_no')->get();

        $byType = [];
        $byUnit = [];
        foreach ($readings as $r) {
            $type = $r->utilityType->code ?? 'unknown';
            $byType[$type] ??= ['consumption' => 0, 'amount' => 0];
            $byType[$type]['consumption'] += max(0, $r->current_reading - $r->previous_reading);
            $byType[$type]['amount'] += $r->amount;

            $unit = $r->lease->unit->unit_no ?? 'Unknown';
            $byUnit[$unit] = ($byUnit[$unit] ?? 0) + $r->amount;
        }

        return [
            'from' => $from,
            'to' => $to,
            'total_amount' => (int) $readings->sum('amount'),
            'by_type' => $byType,
            'by_unit' => $byUnit,
        ];
    }

    /**
     * Housekeeping & maintenance SLA — counts by status plus average
     * turnaround/resolution time, mirroring the Hotel module's Ops SLA report.
     *
     * @return array<string, mixed>
     */
    public function computeOpsSla(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $hkTasks = HousekeepingTask::query()->whereBetween('created_at', [$start, $end])->with('status')->get();
        $hkCompleted = $hkTasks->filter(fn (HousekeepingTask $t) => $t->completed_at !== null);

        $maint = MaintenanceIssue::query()->whereBetween('created_at', [$start, $end])->with('status')->get();
        $maintResolved = $maint->filter(fn (MaintenanceIssue $m) => $m->resolved_at !== null);

        return [
            'from' => $from,
            'to' => $to,
            'housekeeping' => [
                'total' => $hkTasks->count(),
                'by_status' => $hkTasks->groupBy(fn (HousekeepingTask $t) => $t->status->code)->map->count(),
                'completed' => $hkCompleted->count(),
                'avg_turnaround_minutes' => $hkCompleted->count() ? (int) round($hkCompleted->avg(fn (HousekeepingTask $t) => $t->created_at->diffInMinutes($t->completed_at))) : 0,
            ],
            'maintenance' => [
                'total' => $maint->count(),
                'by_status' => $maint->groupBy(fn (MaintenanceIssue $m) => $m->status->code)->map->count(),
                'resolved' => $maintResolved->count(),
                'avg_resolution_hours' => $maintResolved->count() ? round($maintResolved->avg(fn (MaintenanceIssue $m) => $m->created_at->diffInHours($m->resolved_at)), 1) : 0,
            ],
        ];
    }
}
