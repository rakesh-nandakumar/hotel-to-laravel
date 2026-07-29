<?php

namespace App\Services\Hotel;

use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\OrderItemModifier;
use App\Models\TillSession;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use Carbon\CarbonImmutable;

/**
 * Restaurant-domain reports that live alongside the Hotel/POS code (Order,
 * OrderItem, MenuItem all live under App\Models\Hotel — this service follows
 * that same namespace convention rather than inventing a new one). POS Sales
 * itself stays in ReportService/computePos() — it's the one report that
 * simply changed which module's permission gates it, not where it's computed.
 */
class RestaurantReportService
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function dayRange(string $from, string $to): array
    {
        return ['start' => CarbonImmutable::parse($from)->startOfDay(), 'end' => CarbonImmutable::parse($to)->addDay()->startOfDay()];
    }

    /**
     * Revenue/qty per menu item, category mix, and slow movers (the bottom
     * of the same ranking the POS Sales report's best-sellers comes from).
     *
     * @return array<string, mixed>
     */
    public function computeMenuPerformance(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);

        $orderItems = OrderItem::query()
            ->where('voided', false)
            ->whereHas('order', fn ($q) => $q->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])->whereBetween('created_at', [$start, $end]))
            ->with('menuItem.category')
            ->get();

        $byItem = [];
        $byCategory = [];
        foreach ($orderItems as $item) {
            $category = $item->menuItem?->category?->name ?? 'Uncategorized';
            $byItem[$item->name] ??= ['qty' => 0, 'amount' => 0, 'category' => $category];
            $byItem[$item->name]['qty'] += $item->qty;
            $byItem[$item->name]['amount'] += $item->amount;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + $item->amount;
        }

        $ranked = collect($byItem)->map(fn (array $v, string $name) => ['name' => $name, ...$v])->sortByDesc('qty')->values();

        return [
            'from' => $from,
            'to' => $to,
            'total_revenue' => (int) $orderItems->sum('amount'),
            'by_category' => $byCategory,
            'best_sellers' => $ranked->take(10)->all(),
            'slow_movers' => $ranked->sortBy('qty')->take(10)->values()->all(),
        ];
    }

    /**
     * Modifier/add-on attach rate and the upsell revenue they contribute.
     *
     * @return array<string, mixed>
     */
    public function computeModifierAttachRate(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);
        $settledInRange = fn ($q) => $q->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])->whereBetween('created_at', [$start, $end]);

        $orderItems = OrderItem::query()->where('voided', false)->whereHas('order', $settledInRange)->withCount('modifiers')->get();
        $withModifiers = $orderItems->filter(fn (OrderItem $i) => $i->modifiers_count > 0)->count();

        $modifiers = OrderItemModifier::query()
            ->whereHas('orderItem', fn ($q) => $q->where('voided', false)->whereHas('order', $settledInRange))
            ->get();

        $byModifier = [];
        foreach ($modifiers as $m) {
            $byModifier[$m->name] ??= ['count' => 0, 'revenue' => 0];
            $byModifier[$m->name]['count']++;
            $byModifier[$m->name]['revenue'] += $m->price_delta;
        }

        return [
            'from' => $from,
            'to' => $to,
            'order_items' => $orderItems->count(),
            'order_items_with_modifiers' => $withModifiers,
            'attach_rate_pct' => $orderItems->count() ? round($withModifiers / $orderItems->count() * 100, 1) : 0,
            'modifier_revenue' => (int) $modifiers->sum('price_delta'),
            'by_modifier' => $byModifier,
        ];
    }

    /**
     * Discount totals by reason/authorizer, and void rate/reasons.
     *
     * @return array<string, mixed>
     */
    public function computeDiscountsVoids(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);

        $discountedOrders = Order::query()->where('discount', '>', 0)
            ->whereBetween('created_at', [$start, $end])
            ->with('discountBy:id,name')
            ->get();

        $byReason = [];
        $byAuthorizer = [];
        foreach ($discountedOrders as $o) {
            $reason = $o->discount_reason ?: 'Not specified';
            $byReason[$reason] = ($byReason[$reason] ?? 0) + $o->discount;
            $authorizer = $o->discountBy->name ?? 'Unknown';
            $byAuthorizer[$authorizer] = ($byAuthorizer[$authorizer] ?? 0) + $o->discount;
        }

        $itemsInRange = OrderItem::query()->whereHas('order', fn ($q) => $q->whereBetween('created_at', [$start, $end]));
        $voidedItems = (clone $itemsInRange)->where('voided', true)->get();

        $voidByReason = [];
        foreach ($voidedItems as $v) {
            $reason = $v->void_reason ?: 'Not specified';
            $voidByReason[$reason] = ($voidByReason[$reason] ?? 0) + 1;
        }

        return [
            'from' => $from,
            'to' => $to,
            'total_discount' => (int) $discountedOrders->sum('discount'),
            'discount_by_reason' => $byReason,
            'discount_by_authorizer' => $byAuthorizer,
            'voided_items_count' => $voidedItems->count(),
            'void_rate_pct' => ($total = $itemsInRange->count()) ? round($voidedItems->count() / $total * 100, 1) : 0,
            'void_by_reason' => $voidByReason,
        ];
    }

    /**
     * Sales per table/area and per server (staff who created the order).
     *
     * @return array<string, mixed>
     */
    public function computeTableServerPerformance(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);

        $orders = Order::query()->statusIn([OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM])
            ->whereBetween('created_at', [$start, $end])
            ->with('diningTable:id,table_no', 'staff:id,name')
            ->get();

        $byTable = [];
        $byServer = [];
        foreach ($orders as $o) {
            if ($o->diningTable) {
                $key = $o->diningTable->table_no;
                $byTable[$key] ??= ['orders' => 0, 'revenue' => 0];
                $byTable[$key]['orders']++;
                $byTable[$key]['revenue'] += $o->total;
            }
            $server = $o->staff->name ?? 'Unknown';
            $byServer[$server] ??= ['orders' => 0, 'revenue' => 0];
            $byServer[$server]['orders']++;
            $byServer[$server]['revenue'] += $o->total;
        }
        foreach ([&$byTable, &$byServer] as &$group) {
            foreach ($group as &$row) {
                $row['avg_order_value'] = $row['orders'] ? (int) round($row['revenue'] / $row['orders']) : 0;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'total_orders' => $orders->count(),
            'total_revenue' => (int) $orders->sum('total'),
            'by_table' => $byTable,
            'by_server' => $byServer,
        ];
    }

    /**
     * Delivery order volume/revenue by rider, status funnel, and average
     * fulfillment time (created → delivered) — needs the dispatched_at/
     * delivered_at columns added alongside this report.
     *
     * @return array<string, mixed>
     */
    public function computeDeliveryPerformance(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);

        $orders = Order::query()
            ->whereHas('type', fn ($q) => $q->where('code', OrderType::DELIVERY))
            ->whereBetween('created_at', [$start, $end])
            ->with('deliveryStatus', 'deliveryRider:id,name')
            ->get();

        $byRider = [];
        foreach ($orders as $o) {
            $rider = $o->deliveryRider->name ?? 'Unassigned';
            $byRider[$rider] ??= ['orders' => 0, 'revenue' => 0];
            $byRider[$rider]['orders']++;
            $byRider[$rider]['revenue'] += $o->total;
        }

        $delivered = $orders->filter(fn (Order $o) => $o->delivered_at !== null);

        return [
            'from' => $from,
            'to' => $to,
            'total_orders' => $orders->count(),
            'total_revenue' => (int) $orders->sum('total'),
            'by_status' => $orders->groupBy(fn (Order $o) => $o->deliveryStatus->code ?? 'unknown')->map->count(),
            'by_rider' => $byRider,
            'delivered_count' => $delivered->count(),
            'avg_fulfillment_minutes' => $delivered->count() ? (int) round($delivered->avg(fn (Order $o) => $o->created_at->diffInMinutes($o->delivered_at))) : 0,
        ];
    }

    /**
     * Kitchen ticket time by station: prep time (kot_started_at → kot_ready_at)
     * and pickup time (kot_ready_at → served_at) — needs the kot_started_at/
     * kot_ready_at/served_at columns added alongside this report.
     *
     * @return array<string, mixed>
     */
    public function computeKitchenTicketTime(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);

        $orders = Order::query()->whereBetween('created_at', [$start, $end])
            ->whereNotNull('kot_started_at')
            ->with('items.menuItem.category.kitchenStation')
            ->get();

        $withPrepTime = $orders->filter(fn (Order $o) => $o->kot_ready_at !== null);
        $byStation = [];
        foreach ($withPrepTime as $o) {
            $prepMinutes = $o->kot_started_at->diffInMinutes($o->kot_ready_at);
            $stations = $o->items->map(fn ($i) => $i->menuItem?->category?->kitchenStation?->code)->filter()->unique();
            foreach ($stations->isEmpty() ? collect(['unassigned']) : $stations as $station) {
                $byStation[$station] ??= ['tickets' => 0, 'total_minutes' => 0];
                $byStation[$station]['tickets']++;
                $byStation[$station]['total_minutes'] += $prepMinutes;
            }
        }
        foreach ($byStation as &$row) {
            $row['avg_minutes'] = $row['tickets'] ? round($row['total_minutes'] / $row['tickets'], 1) : 0;
            unset($row['total_minutes']);
        }

        $withPickupTime = $orders->filter(fn (Order $o) => $o->kot_ready_at !== null && $o->served_at !== null);

        return [
            'from' => $from,
            'to' => $to,
            'tickets' => $orders->count(),
            'avg_prep_minutes' => $withPrepTime->count() ? round($withPrepTime->avg(fn (Order $o) => $o->kot_started_at->diffInMinutes($o->kot_ready_at)), 1) : 0,
            'avg_pickup_minutes' => $withPickupTime->count() ? round($withPickupTime->avg(fn (Order $o) => $o->kot_ready_at->diffInMinutes($o->served_at)), 1) : 0,
            'by_station' => $byStation,
        ];
    }

    /**
     * Per-shift sales drill-down (collected net, payment count, cash
     * variance) across a date range — a historical view alongside the Daily
     * report's single-day cash-drawer reconciliation.
     *
     * @return array<string, mixed>
     */
    public function computeShiftSales(string $from, string $to): array
    {
        ['start' => $start, 'end' => $end] = $this->dayRange($from, $to);

        $shifts = TillSession::query()->with('staff:id,name', 'payments.kind')
            ->whereBetween('opened_at', [$start, $end])
            ->get();

        $rows = $shifts->map(function (TillSession $s) {
            $collected = (int) $s->payments->filter(fn ($p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount')
                - (int) $s->payments->filter(fn ($p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');

            return [
                'id' => $s->id, 'staff' => $s->staff->name ?? '—', 'opened_at' => $s->opened_at, 'closed_at' => $s->closed_at,
                'collected' => $collected, 'payment_count' => $s->payments->count(), 'variance' => $s->variance,
            ];
        })->values();

        return ['from' => $from, 'to' => $to, 'shifts' => $rows, 'total_collected' => (int) $rows->sum('collected')];
    }

    /**
     * Theoretical recipe cost vs. price per menu item — needs
     * ingredients.unit_cost, added alongside this report; items without a
     * cost set on every recipe ingredient show `cost: null` rather than a
     * misleading 0% margin.
     *
     * @return array<string, mixed>
     */
    public function computeFoodCost(): array
    {
        $items = MenuItem::query()->where('active', true)->with('recipe.ingredient')->get();

        $rows = $items->map(function (MenuItem $item) {
            $hasAllCosts = $item->recipe->isNotEmpty() && $item->recipe->every(fn ($r) => $r->ingredient->unit_cost !== null);
            $cost = $hasAllCosts ? (int) round($item->recipe->sum(fn ($r) => $r->qty * $r->ingredient->unit_cost)) : null;
            $foodCostPct = $cost !== null && $item->price > 0 ? round($cost / $item->price * 100, 1) : null;

            return ['id' => $item->id, 'name' => $item->name, 'price' => $item->price, 'cost' => $cost, 'food_cost_pct' => $foodCostPct];
        })->values();

        $costed = $rows->filter(fn (array $r) => $r['cost'] !== null);

        return [
            'items' => $rows,
            'avg_food_cost_pct' => $costed->count() ? round($costed->avg('food_cost_pct'), 1) : null,
            'items_missing_cost' => $rows->count() - $costed->count(),
        ];
    }
}
