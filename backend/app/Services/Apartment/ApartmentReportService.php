<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Booking;
use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\Lease;
use App\Models\Apartment\MaintenanceIssue;
use App\Models\Apartment\Payment;
use App\Models\Apartment\Sale;
use App\Models\Apartment\Unit;
use App\Support\Lookups\ApartmentBookingStatus;
use App\Support\Lookups\ApartmentLeaseStatus;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\TaskStatus;

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
}
