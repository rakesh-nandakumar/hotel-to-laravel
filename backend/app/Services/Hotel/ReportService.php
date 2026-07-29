<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Attendance;
use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\Guest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\LoyaltyTransaction;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\NightAudit;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\Payment;
use App\Models\Hotel\PayrollRun;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\Room;
use App\Models\Hotel\VenueBooking;
use App\Models\Lookup;
use App\Models\TillSession;
use App\Services\AuditLog;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\Lookups\VenueBookingStatus;
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

        // Filtered by settled_at (when the sale was actually completed), not
        // created_at (when the order/tab was opened) — a POS order can sit open
        // for a while before settlement, and payments below are already keyed off
        // their own timestamp (≈ settlement time), so this must match or "cash
        // collected" and "POS sales" silently disagree for the same day.
        $orderItems = OrderItem::query()
            ->where('voided', false)
            ->whereHas('order', fn ($q) => $q->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])->whereBetween('settled_at', [$start, $end]))
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

        $shifts = TillSession::query()->with('staff:id,name')->whereBetween('closed_at', [$start, $end])->get();

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
            'shifts' => $shifts->map(fn (TillSession $s) => [
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

        // See computeDaily()'s identical comment: settled_at (when the sale
        // completed), not created_at (when the tab was opened).
        $orderItems = OrderItem::query()
            ->where('voided', false)
            ->whereHas('order', fn ($q) => $q->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])->whereBetween('settled_at', [$start, $end]))
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

    /**
     * RevPAR/ADR: day-by-day room revenue & occupied-room count over a range,
     * mirroring computeMonthly()'s per-day loop, then rolled up into the two
     * headline hotel-industry KPIs (ADR = revenue / rooms sold, RevPAR =
     * revenue / available room-nights).
     *
     * @return array<string, mixed>
     */
    public function computeRevPar(string $from, string $to): array
    {
        $totalRooms = Room::query()->count();
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $series = [];
        $roomRevenueTotal = 0;
        $roomNightsSoldTotal = 0;

        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addDay()) {
            ['start' => $dayStart, 'end' => $dayEnd] = $this->dayRange($cursor->toDateString());

            $roomRevenue = (int) FolioLine::query()->where('voided', false)
                ->whereHas('source', fn ($q) => $q->where('code', LineSource::ROOM))
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->sum('amount');

            $occupied = ReservationRoom::query()
                ->whereHas('reservation', fn ($q) => $q->statusIn([ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT])
                    ->where('check_in', '<', $dayEnd->toDateString())->where('check_out', '>', $dayStart->toDateString()))
                ->distinct('room_id')
                ->count('room_id');

            $roomRevenueTotal += $roomRevenue;
            $roomNightsSoldTotal += $occupied;
            $series[] = [
                'date' => $cursor->toDateString(),
                'room_revenue' => $roomRevenue,
                'occupied_rooms' => $occupied,
                'adr' => $occupied ? (int) round($roomRevenue / $occupied) : 0,
                'revpar' => $totalRooms ? (int) round($roomRevenue / $totalRooms) : 0,
            ];
        }

        $availableRoomNights = $totalRooms * count($series);

        return [
            'from' => $from,
            'to' => $to,
            'total_rooms' => $totalRooms,
            'available_room_nights' => $availableRoomNights,
            'room_nights_sold' => $roomNightsSoldTotal,
            'room_revenue' => $roomRevenueTotal,
            'adr' => $roomNightsSoldTotal ? (int) round($roomRevenueTotal / $roomNightsSoldTotal) : 0,
            'revpar' => $availableRoomNights ? (int) round($roomRevenueTotal / $availableRoomNights) : 0,
            'occupancy_pct' => $availableRoomNights ? (int) round($roomNightsSoldTotal / $availableRoomNights * 100) : 0,
            'series' => $series,
        ];
    }

    /**
     * Reservation count & room revenue per booking channel — join+groupBy
     * mirrors the Lookup-aggregation pattern already used elsewhere (e.g.
     * ApartmentReportService::dashboard()'s unit-status breakdown).
     *
     * @return array<string, mixed>
     */
    public function computeChannelMix(string $from, string $to): array
    {
        ['start' => $start] = $this->dayRange($from);
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $reservationCounts = Reservation::query()
            ->join('lookups', 'lookups.id', '=', 'reservations.booking_channel_id')
            ->whereBetween('reservations.created_at', [$start, $end])
            ->selectRaw('lookups.code as code, count(*) as total')
            ->groupBy('lookups.code')
            ->pluck('total', 'code');

        $revenueByChannel = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->join('lookups', 'lookups.id', '=', 'reservations.booking_channel_id')
            ->where('folio_lines.voided', false)
            ->whereBetween('folio_lines.created_at', [$start, $end])
            ->selectRaw('lookups.code as code, coalesce(sum(folio_lines.amount),0) as revenue')
            ->groupBy('lookups.code')
            ->pluck('revenue', 'code');

        $byChannel = [];
        foreach (array_unique([...$reservationCounts->keys(), ...$revenueByChannel->keys()]) as $code) {
            $byChannel[$code] = [
                'reservations' => (int) ($reservationCounts[$code] ?? 0),
                'revenue' => (int) ($revenueByChannel[$code] ?? 0),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'by_channel' => $byChannel,
            'total_reservations' => (int) $reservationCounts->sum(),
        ];
    }

    /**
     * Cancelled reservations (by reason) and no-shows within a range, plus
     * the cancellation rate against all reservations created in that range.
     *
     * @return array<string, mixed>
     */
    public function computeCancellations(string $from, string $to): array
    {
        ['start' => $start] = $this->dayRange($from);
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $cancelled = Reservation::query()
            ->statusCode(ReservationStatus::CANCELLED)
            ->whereBetween('cancelled_at', [$start, $end])
            ->with('guest:id,name', 'channel')
            ->get();
        $noShows = Reservation::query()
            ->statusCode(ReservationStatus::NO_SHOW)
            ->whereBetween('updated_at', [$start, $end])
            ->with('guest:id,name', 'channel')
            ->get();

        $byReason = [];
        foreach ($cancelled as $r) {
            $reason = $r->cancel_reason ?: 'Not specified';
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }

        $totalReservations = Reservation::query()->whereBetween('created_at', [$start, $end])->count();

        return [
            'from' => $from,
            'to' => $to,
            'cancelled_count' => $cancelled->count(),
            'no_show_count' => $noShows->count(),
            'total_reservations' => $totalReservations,
            'cancellation_rate_pct' => $totalReservations ? round($cancelled->count() / $totalReservations * 100, 1) : 0,
            'by_reason' => $byReason,
            'cancelled' => $cancelled->map(fn (Reservation $r) => [
                'id' => $r->id, 'code' => $r->code, 'guest' => $r->guest->name ?? '—',
                'channel' => $r->channel->code ?? null, 'check_in' => $r->check_in, 'cancelled_at' => $r->cancelled_at, 'reason' => $r->cancel_reason,
            ])->values(),
            'no_shows' => $noShows->map(fn (Reservation $r) => [
                'id' => $r->id, 'code' => $r->code, 'guest' => $r->guest->name ?? '—',
                'channel' => $r->channel->code ?? null, 'check_in' => $r->check_in,
            ])->values(),
        ];
    }

    /**
     * Top guests by spend, repeat-guest rate among guests who stayed in the
     * range, and loyalty points issued/redeemed.
     *
     * @return array<string, mixed>
     */
    public function computeGuestLoyalty(string $from, string $to): array
    {
        ['start' => $start] = $this->dayRange($from);
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $topGuests = FolioLine::query()
            ->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->join('guests', 'guests.id', '=', 'reservations.guest_id')
            ->where('folio_lines.voided', false)
            ->whereBetween('folio_lines.created_at', [$start, $end])
            ->groupBy('guests.id', 'guests.name')
            ->selectRaw('guests.id as guest_id, guests.name as name, coalesce(sum(folio_lines.amount),0) as spend, count(distinct reservations.id) as stays')
            ->orderByDesc('spend')
            ->limit(15)
            ->get();

        // Half-open range (>= from, < to+1) rather than whereBetween: 'check_in' is
        // a date cast, and under SQLite (used in tests) it's stored with a
        // "00:00:00" time suffix, so a same-day whereBetween([$from, $from]) would
        // fail its own upper bound on a lexicographic string compare.
        $staysInRange = Reservation::query()
            ->statusIn([ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT])
            ->where('check_in', '>=', $from)
            ->where('check_in', '<', CarbonImmutable::parse($to)->addDay()->toDateString())
            ->get();
        $distinctGuestIds = $staysInRange->pluck('guest_id')->filter()->unique();
        $repeatGuests = Guest::query()->whereIn('id', $distinctGuestIds)->withCount('reservations')
            ->get()->filter(fn (Guest $g) => $g->reservations_count > 1)->count();

        $loyalty = LoyaltyTransaction::query()->whereBetween('created_at', [$start, $end])->get();

        return [
            'from' => $from,
            'to' => $to,
            'top_guests' => $topGuests,
            'distinct_guests' => $distinctGuestIds->count(),
            'repeat_guests' => $repeatGuests,
            'repeat_rate_pct' => $distinctGuestIds->count() ? round($repeatGuests / $distinctGuestIds->count() * 100, 1) : 0,
            'loyalty_points_issued' => (int) $loyalty->where('points', '>', 0)->sum('points'),
            'loyalty_points_redeemed' => (int) abs($loyalty->where('points', '<', 0)->sum('points')),
        ];
    }

    /**
     * Corporate account AR snapshot: outstanding balance, credit utilization,
     * and a simple aging bucket (based on the oldest checked-out reservation
     * still carrying a balance) per active account. A snapshot, not a
     * date-ranged report — AR is a "right now" balance sheet concern.
     *
     * @return array<string, mixed>
     */
    public function computeCorporateAr(): array
    {
        // Mirrors CorporateAccountController::index()'s outstanding calc exactly:
        // "charged" = CORPORATE_CREDIT-method payments recorded against the
        // account's reservations' folios (billing the stay to the company);
        // "settled" = standalone Payment rows with corporate_account_id set
        // directly (the company's own month-end settlement, via ::settle()).
        $accounts = CorporateAccount::query()->active()->get();
        $accountIds = $accounts->pluck('id');
        $corporateCreditMethodId = Lookup::id(LookupType::PAYMENT_METHOD, PaymentMethod::CORPORATE_CREDIT);

        $charges = Payment::query()
            ->join('folios', 'folios.id', '=', 'payments.folio_id')
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->where('payments.payment_method_id', $corporateCreditMethodId)
            ->whereIn('reservations.corporate_account_id', $accountIds)
            ->selectRaw('reservations.corporate_account_id as account_id, sum(payments.amount) as total')
            ->groupBy('reservations.corporate_account_id')
            ->pluck('total', 'account_id');

        $settlements = Payment::query()
            ->whereIn('corporate_account_id', $accountIds)
            ->selectRaw('corporate_account_id as account_id, sum(amount) as total')
            ->groupBy('corporate_account_id')
            ->pluck('total', 'account_id');

        $oldestCharge = Payment::query()
            ->join('folios', 'folios.id', '=', 'payments.folio_id')
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->where('payments.payment_method_id', $corporateCreditMethodId)
            ->whereIn('reservations.corporate_account_id', $accountIds)
            ->selectRaw('reservations.corporate_account_id as account_id, min(payments.created_at) as oldest')
            ->groupBy('reservations.corporate_account_id')
            ->pluck('oldest', 'account_id');

        $rows = $accounts->map(function (CorporateAccount $acc) use ($charges, $settlements, $oldestCharge) {
            $charged = (int) ($charges[$acc->id] ?? 0);
            $paid = (int) ($settlements[$acc->id] ?? 0);
            $balance = $charged - $paid;

            $daysOutstanding = $balance > 0 && isset($oldestCharge[$acc->id])
                ? CarbonImmutable::parse($oldestCharge[$acc->id])->diffInDays(now())
                : 0;
            $bucket = $balance <= 0 ? 'current' : ($daysOutstanding <= 30 ? '0-30' : ($daysOutstanding <= 60 ? '31-60' : ($daysOutstanding <= 90 ? '61-90' : '90+')));

            return [
                'id' => $acc->id,
                'company_name' => $acc->company_name,
                'credit_limit' => $acc->credit_limit,
                'charged' => $charged,
                'paid' => $paid,
                'balance' => $balance,
                'credit_utilization_pct' => $acc->credit_limit ? round(max(0, $balance) / $acc->credit_limit * 100, 1) : 0,
                'aging_bucket' => $bucket,
                'days_outstanding' => $daysOutstanding,
            ];
        })->sortByDesc('balance')->values();

        return [
            'accounts' => $rows,
            'total_outstanding' => (int) $rows->sum('balance'),
            'by_bucket' => $rows->groupBy('aging_bucket')->map(fn ($g) => (int) $g->sum('balance')),
        ];
    }

    /**
     * Housekeeping & maintenance SLA: task/issue counts by status plus
     * average turnaround (housekeeping, in minutes) and resolution time
     * (maintenance, in hours) within a range.
     *
     * @return array<string, mixed>
     */
    public function computeOpsSla(string $from, string $to): array
    {
        ['start' => $start] = $this->dayRange($from);
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

    /**
     * Labor-cost breakdown for one payroll run (by month), plus a trend
     * series across every run so far for a month-over-month chart.
     *
     * @return array<string, mixed>
     */
    public function computePayrollCost(string $month): array
    {
        $run = PayrollRun::query()->where('month', $month)->with(['lines.user:id,name', 'status'])->first();

        $trend = PayrollRun::query()->orderBy('month')->with('lines')->get()->map(fn (PayrollRun $r) => [
            'month' => $r->month,
            'employer_cost' => (int) $r->lines->sum('employer_cost'),
            'net_pay' => (int) $r->lines->sum('net_pay'),
        ])->values();

        if (! $run) {
            return ['month' => $month, 'found' => false, 'trend' => $trend];
        }

        $lines = $run->lines;

        return [
            'month' => $month,
            'found' => true,
            'status' => $run->status->code ?? null,
            'staff_count' => $lines->count(),
            'totals' => [
                'base_salary' => (int) $lines->sum('base_salary'),
                'ot_pay' => (int) $lines->sum('ot_pay'),
                'allowance' => (int) $lines->sum('allowance'),
                'bonus' => (int) $lines->sum('bonus'),
                'gross' => (int) $lines->sum('gross'),
                'epf_employee' => (int) $lines->sum('epf_employee'),
                'epf_employer' => (int) $lines->sum('epf_employer'),
                'etf' => (int) $lines->sum('etf'),
                'apit' => (int) $lines->sum('apit'),
                'net_pay' => (int) $lines->sum('net_pay'),
                'employer_cost' => (int) $lines->sum('employer_cost'),
            ],
            'by_staff' => $lines->map(fn ($l) => [
                'user' => $l->user->name ?? '—', 'gross' => $l->gross, 'net_pay' => $l->net_pay, 'employer_cost' => $l->employer_cost,
            ])->values(),
            'trend' => $trend,
        ];
    }

    /**
     * Venue/banquet bookings & revenue over a date range (by event date, not
     * created_at — a booking made weeks ago for a date in-range still counts).
     *
     * @return array<string, mixed>
     */
    public function computeVenues(string $from, string $to): array
    {
        // Half-open range, not whereBetween — see the comment in
        // computeGuestLoyalty() for why: 'date' is a date-cast column and
        // SQLite (tests) stores it with a "00:00:00" suffix.
        $upperBound = CarbonImmutable::parse($to)->addDay()->toDateString();

        $bookings = VenueBooking::query()
            ->where('date', '>=', $from)->where('date', '<', $upperBound)
            ->with('venue:id,name', 'status')
            ->get();

        $revenue = (int) FolioLine::query()->where('voided', false)
            ->whereHas('folio.venueBooking', fn ($q) => $q->where('date', '>=', $from)->where('date', '<', $upperBound))
            ->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'total_bookings' => $bookings->count(),
            'total_hours' => (float) $bookings->sum('hours'),
            'total_guest_count' => (int) $bookings->sum('guest_count'),
            'revenue' => $revenue,
            'by_venue' => $bookings->groupBy(fn (VenueBooking $b) => $b->venue->name ?? 'Unknown')->map(fn ($g) => [
                'bookings' => $g->count(), 'hours' => (float) $g->sum('hours'),
            ]),
            'by_status' => $bookings->groupBy(fn (VenueBooking $b) => $b->status->code ?? 'unknown')->map->count(),
        ];
    }

    /**
     * Laundry revenue over a date range — laundry has no dedicated order
     * table (see LaundryController::charge()), it posts straight to
     * FolioLine with source=laundry, so that's the source of truth here too.
     *
     * @return array<string, mixed>
     */
    public function computeLaundry(string $from, string $to): array
    {
        ['start' => $start] = $this->dayRange($from);
        $end = CarbonImmutable::parse($to)->addDay()->startOfDay();

        $lines = FolioLine::query()->where('voided', false)
            ->whereHas('source', fn ($q) => $q->where('code', LineSource::LAUNDRY))
            ->whereBetween('created_at', [$start, $end])
            ->get();

        // FolioLine has no laundry_item_id (laundry charges are generic charge
        // lines, not their own table — see LaundryService::chargeToRoom()), so
        // the item name is recovered from its "Laundry — {name} × {qty}" format
        // rather than grouping by the raw description (which also embeds qty
        // and an optional note, and would never aggregate two charges of the
        // same item into one row).
        $byItem = [];
        foreach ($lines as $line) {
            $itemName = preg_match('/^Laundry — (.+?) × \d+/u', $line->description, $m) ? $m[1] : $line->description;
            $byItem[$itemName] ??= ['qty' => 0, 'amount' => 0];
            $byItem[$itemName]['qty'] += $line->qty;
            $byItem[$itemName]['amount'] += $line->amount;
        }

        return [
            'from' => $from,
            'to' => $to,
            'total_revenue' => (int) $lines->sum('amount'),
            'total_items' => (float) $lines->sum('qty'),
            'by_item' => $byItem,
        ];
    }
}
