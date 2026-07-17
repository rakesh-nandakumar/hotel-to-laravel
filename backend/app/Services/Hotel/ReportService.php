<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Attendance;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\NightAudit;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\Payment;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\Room;
use App\Models\Hotel\Shift;
use App\Models\Hotel\VenueBooking;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\Lookups\VenueBookingStatus;
use App\Services\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Live dashboard + daily/monthly/POS report computations + the night audit
 * (a permanently stored daily snapshot, one per business date). Ported from
 * the Node app's routes/reports.ts — deliberately keeps every computation in
 * one service, mirroring Node's own single-file structure with shared
 * internal helpers, rather than splitting into one service per report.
 */
class ReportService
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function dayRange(string $date): array
    {
        $start = CarbonImmutable::parse($date)->startOfDay();

        return ['start' => $start, 'end' => $start->addDay()];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange(today()->toDateString());

        $totalRooms = Room::query()->count();
        $roomCounts = Room::query()->join('lookups', 'lookups.id', '=', 'rooms.room_status_id')
            ->selectRaw('lookups.code as code, count(*) as total')
            ->groupBy('lookups.code')
            ->pluck('total', 'code');

        $arrivals = Reservation::query()
            ->statusIn([ReservationStatus::CONFIRMED, ReservationStatus::PENDING])
            ->where('check_in', $start->toDateString())
            ->with(['guest:id,name,loyalty_points,id_number', 'rooms.room:id,number', 'groupBooking:id,reference', 'corporateAccount:id,company_name'])
            ->get();

        $departures = Reservation::query()
            ->statusCode(ReservationStatus::CHECKED_IN)
            ->where('check_out', $start->toDateString())
            ->with(['guest:id,name', 'rooms.room:id,number'])
            ->get();

        $inHouse = Reservation::query()->statusCode(ReservationStatus::CHECKED_IN)->count();
        $venuesToday = VenueBooking::query()
            ->whereHas('status', fn ($q) => $q->whereIn('code', [VenueBookingStatus::CONFIRMED, VenueBookingStatus::INQUIRY]))
            ->where('date', $start->toDateString())
            ->count();
        $staffOnDuty = Attendance::query()->whereNull('clock_out')->count();
        $yesterday = $this->computeDaily($start->subDay()->toDateString());

        $paymentsToday = Payment::query()->with('kind')->whereBetween('created_at', [$start, $end])->get();
        $collected = (int) $paymentsToday->filter(fn (Payment $p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount')
            - (int) $paymentsToday->filter(fn (Payment $p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');
        $chargesPosted = (int) FolioLine::query()->whereBetween('created_at', [$start, $end])->where('voided', false)->sum('amount');
        $posToday = Order::query()
            ->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('coalesce(sum(total), 0) as total, count(*) as cnt')
            ->first();

        $openKots = Order::query()
            ->whereHas('kotStatus', fn ($q) => $q->whereIn('code', ['new', 'preparing']))
            ->whereDoesntHave('status', fn ($q) => $q->where('code', OrderStatus::VOID))
            ->count();
        $pendingHousekeeping = HousekeepingTask::query()->whereHas('status', fn ($q) => $q->where('code', '!=', TaskStatus::DONE))->count();
        $openMaintenance = MaintenanceIssue::query()->whereHas('status', fn ($q) => $q->where('code', '!=', MaintenanceStatus::RESOLVED))->count();
        $lowStock = Ingredient::query()->whereColumn('stock_qty', '<=', 'low_stock_threshold')->count();
        $expiryCutoff = today()->addDays(3);
        $expiringBatches = IngredientBatch::query()->where('qty', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $expiryCutoff)->count();

        return [
            'rooms' => [
                'total' => $totalRooms,
                'occupied' => (int) ($roomCounts[RoomStatus::OCCUPIED] ?? 0),
                'available' => (int) ($roomCounts[RoomStatus::AVAILABLE] ?? 0),
                'dirty' => (int) ($roomCounts[RoomStatus::DIRTY] ?? 0),
                'maintenance' => (int) ($roomCounts[RoomStatus::MAINTENANCE] ?? 0),
                'occupancy_pct' => $totalRooms ? (int) round(($roomCounts[RoomStatus::OCCUPIED] ?? 0) / $totalRooms * 100) : 0,
            ],
            'arrivals' => $arrivals,
            'departures' => $departures,
            'in_house' => $inHouse,
            'venues_today' => $venuesToday,
            'staff_on_duty' => $staffOnDuty,
            'revenue_today' => [
                'collected' => $collected,
                'charges_posted' => $chargesPosted,
                'pos_sales' => (int) ($posToday->total ?? 0),
                'pos_orders' => (int) ($posToday->cnt ?? 0),
            ],
            'yesterday' => [
                'occupancy_pct' => $yesterday['occupancy']['pct'],
                'collected' => $yesterday['payments']['net'],
                'pos_sales' => (int) array_sum($yesterday['pos']['by_category']),
            ],
            'ops' => [
                'open_kots' => $openKots,
                'pending_housekeeping' => $pendingHousekeeping,
                'open_maintenance' => $openMaintenance,
                'low_stock_ingredients' => $lowStock,
                'expiring_batches' => $expiringBatches,
            ],
        ];
    }

    /**
     * Shared daily computation — powers the daily report, its PDF, and the night audit.
     *
     * @return array<string, mixed>
     */
    public function computeDaily(string $date): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($date);

        $lines = FolioLine::query()->with('source')->where('voided', false)->whereBetween('created_at', [$start, $end])->get();
        $revenueBySource = [];
        foreach ($lines as $line) {
            $revenueBySource[$line->source->code] = ($revenueBySource[$line->source->code] ?? 0) + $line->amount;
        }

        $walkinTotal = (int) Order::query()
            ->whereHas('type', fn ($q) => $q->where('code', OrderType::WALKIN))
            ->statusCode(OrderStatus::SETTLED)
            ->whereBetween('settled_at', [$start, $end])
            ->sum('total');

        $payments = Payment::query()->with('method', 'kind')->whereBetween('created_at', [$start, $end])->get();
        $byMethod = [];
        foreach ($payments as $payment) {
            $sign = $payment->kind->code === PaymentKind::REFUND ? -1 : 1;
            $byMethod[$payment->method->code] = ($byMethod[$payment->method->code] ?? 0) + $sign * $payment->amount;
        }
        $refunds = $payments->filter(fn (Payment $p) => $p->kind->code === PaymentKind::REFUND);

        $totalRooms = Room::query()->count();
        $occupied = ReservationRoom::query()
            ->whereHas('reservation', fn ($q) => $q->statusIn([ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT])
                ->where('check_in', '<', $end->toDateString())->where('check_out', '>', $start->toDateString()))
            ->distinct('room_id')
            ->count('room_id');

        $orderItems = OrderItem::query()
            ->where('voided', false)
            ->whereHas('order', fn ($q) => $q->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])->whereBetween('created_at', [$start, $end]))
            ->with('menuItem.category')
            ->get();
        $byCategory = [];
        $byItem = [];
        foreach ($orderItems as $item) {
            $category = $item->menuItem->category->name;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + $item->amount;
            $byItem[$item->name] = [
                'qty' => ($byItem[$item->name]['qty'] ?? 0) + $item->qty,
                'amount' => ($byItem[$item->name]['amount'] ?? 0) + $item->amount,
            ];
        }
        $bestSellers = collect($byItem)
            ->map(fn (array $v, string $name) => ['name' => $name, ...$v])
            ->sortByDesc('qty')
            ->values()
            ->take(10);

        $shifts = Shift::query()->with('staff:id,name')->whereBetween('closed_at', [$start, $end])->get();

        $collected = (int) $payments->filter(fn (Payment $p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount');
        $refunded = (int) $refunds->sum('amount');

        return [
            'date' => $date,
            'occupancy' => [
                'total_rooms' => $totalRooms,
                'occupied_rooms' => $occupied,
                'pct' => $totalRooms ? (int) round($occupied / $totalRooms * 100) : 0,
            ],
            'revenue_by_source' => $revenueBySource,
            'walkin_pos_revenue' => $walkinTotal,
            'total_charges_posted' => (int) $lines->sum('amount') + $walkinTotal,
            'payments' => ['by_method' => $byMethod, 'collected' => $collected, 'refunded' => $refunded, 'net' => $collected - $refunded],
            'cash_collected' => $byMethod[PaymentMethod::CASH] ?? 0,
            'pos' => [
                'by_category' => $byCategory,
                'best_sellers' => $bestSellers->all(),
                'order_count' => $orderItems->pluck('order_id')->unique()->count(),
            ],
            'shifts' => $shifts->map(fn (Shift $s) => [
                'staff' => $s->staff->name,
                'opening_cash' => $s->opening_cash,
                'closing_cash' => $s->closing_cash,
                'expected_cash' => $s->expected_cash,
                'variance' => $s->variance,
            ])->all(),
        ];
    }

    public function runNightAudit(?string $date, int $staffId): NightAudit
    {
        $dateStr = $date ?? today()->toDateString();

        if (NightAudit::query()->whereDate('business_date', $dateStr)->exists()) {
            throw ValidationException::withMessages(['date' => "Night audit for {$dateStr} was already run."]);
        }

        $nightAudit = NightAudit::create([
            'business_date' => $dateStr,
            'data' => $this->computeDaily($dateStr),
            'run_by_id' => $staffId,
        ]);

        AuditLog::record('night_audit.run', $nightAudit, ['date' => $dateStr]);

        return $nightAudit;
    }

    /**
     * Shared monthly computation — powers the monthly report and its PDF.
     *
     * @return array<string, mixed>
     */
    public function computeMonthly(string $month): array
    {
        $daysInMonth = CarbonImmutable::parse("{$month}-01")->daysInMonth;
        $days = [];
        $totalRevenue = 0;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf('%s-%02d', $month, $d);
            if (CarbonImmutable::parse($dateStr)->isAfter(today())) {
                break;
            }

            ['start' => $start, 'end' => $end] = $this->dayRange($dateStr);
            $lineTotal = (int) FolioLine::query()->where('voided', false)->whereBetween('created_at', [$start, $end])->sum('amount');
            $walkinTotal = (int) Order::query()
                ->whereHas('type', fn ($q) => $q->where('code', OrderType::WALKIN))
                ->statusCode(OrderStatus::SETTLED)
                ->whereBetween('settled_at', [$start, $end])
                ->sum('total');

            // Node re-filters CONFIRMED rows by `checkIn < end` again client-side after
            // fetching — redundant, the DB `where` below already guarantees it for
            // every row returned, so it's dropped here rather than ported literally.
            $totalRooms = Room::query()->count();
            $occupiedRooms = ReservationRoom::query()
                ->whereHas('reservation', fn ($q) => $q->statusIn([ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT, ReservationStatus::CONFIRMED])
                    ->where('check_in', '<', $end->toDateString())->where('check_out', '>', $start->toDateString()))
                ->distinct('room_id')
                ->count('room_id');

            $revenue = $lineTotal + $walkinTotal;
            $totalRevenue += $revenue;
            $days[] = [
                'date' => $dateStr,
                'revenue' => $revenue,
                'occupancy_pct' => $totalRooms ? (int) round($occupiedRooms / $totalRooms * 100) : 0,
            ];
        }

        return [
            'month' => $month,
            'days' => $days,
            'total_revenue' => $totalRevenue,
            'avg_occupancy' => count($days) ? (int) round(array_sum(array_column($days, 'occupancy_pct')) / count($days)) : 0,
        ];
    }

    /**
     * Shared POS-range computation — powers the POS sales report and its PDF.
     *
     * @return array<string, mixed>
     */
    public function computePos(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $orderItems = OrderItem::query()
            ->where('voided', false)
            ->whereHas('order', fn ($q) => $q->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])->whereBetween('created_at', [$start, $end]))
            ->with('menuItem.category')
            ->get();

        $byCategory = [];
        $byItem = [];
        foreach ($orderItems as $item) {
            $category = $item->menuItem->category->name;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + $item->amount;
            $byItem[$item->name] = [
                'qty' => ($byItem[$item->name]['qty'] ?? 0) + $item->qty,
                'amount' => ($byItem[$item->name]['amount'] ?? 0) + $item->amount,
            ];
        }

        $payments = Payment::query()->with('method', 'kind')->whereNotNull('order_id')->whereBetween('created_at', [$start, $end])->get();
        $byMethod = [];
        foreach ($payments as $payment) {
            $byMethod[$payment->method->code] = ($byMethod[$payment->method->code] ?? 0)
                + ($payment->kind->code === PaymentKind::REFUND ? -$payment->amount : $payment->amount);
        }

        $bestSellers = collect($byItem)->map(fn (array $v, string $name) => ['name' => $name, ...$v])->sortByDesc('qty')->values()->take(15);

        return [
            'from' => $from,
            'to' => $to,
            'by_category' => $byCategory,
            'best_sellers' => $bestSellers->all(),
            'payment_method_breakdown' => $byMethod,
            'total_sales' => (int) $orderItems->sum('amount'),
        ];
    }
}
