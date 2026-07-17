<?php

namespace App\Services\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\Payment;
use App\Models\Hotel\Reservation;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Settings;
use App\Support\Lookups\DiningMode;
use App\Support\Lookups\KotStatus;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\RealtimeEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS order lifecycle — creation, KOT, discount, settlement, charge-to-room,
 * void, refund. Ported from the Node app's routes/orders.ts + lib/pos.ts.
 */
class OrderService
{
    private const WITH_FULL = [
        'items', 'room:id,number', 'reservation:id,code,guest_id', 'reservation.guest:id,name', 'staff:id,name', 'payments',
        'status', 'type', 'kotStatus', 'diningMode',
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BillingService $billing,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $staffId): Order
    {
        if (! empty($data['client_key'])) {
            $existing = Order::query()->where('client_key', $data['client_key'])->first();
            if ($existing) {
                return $existing->load(self::WITH_FULL);
            }
        }

        // Room service is always dine-in — takeaway only applies to walk-ins.
        $diningMode = $data['type'] === OrderType::ROOM_GUEST ? DiningMode::DINE_IN : ($data['dining_mode'] ?? DiningMode::DINE_IN);

        $reservationId = null;
        if ($data['type'] === OrderType::ROOM_GUEST) {
            $reservationId = $this->reservations->findCheckedInReservationForRoom($data['room_id'])->id;
        }

        $menuItems = MenuItem::query()->whereIn('id', collect($data['items'])->pluck('menu_item_id'))->get()->keyBy('id');
        foreach ($data['items'] as $line) {
            $menuItem = $menuItems->get($line['menu_item_id']);
            if (! $menuItem || ! $menuItem->active) {
                throw ValidationException::withMessages(['items' => 'Menu item not found.']);
            }
            if ($menuItem->sold_out) {
                throw ValidationException::withMessages(['items' => "\"{$menuItem->name}\" is marked sold out."]);
            }
        }

        try {
            $order = DB::transaction(function () use ($data, $diningMode, $reservationId, $menuItems, $staffId) {
                $order = Order::create([
                    'client_key' => $data['client_key'] ?? null,
                    'order_type_id' => Lookup::id(LookupType::ORDER_TYPE, $data['type']),
                    'dining_mode_id' => Lookup::id(LookupType::DINING_MODE, $diningMode),
                    'order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::OPEN),
                    'kot_status_id' => Lookup::id(LookupType::KOT_STATUS, KotStatus::NEW),
                    'room_id' => $data['room_id'] ?? null,
                    'reservation_id' => $reservationId,
                    'customer_name' => $data['customer_name'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'staff_id' => $staffId,
                    'discount' => 0,
                ]);

                foreach ($data['items'] as $line) {
                    $menuItem = $menuItems->get($line['menu_item_id']);
                    $order->items()->create([
                        'menu_item_id' => $menuItem->id, 'name' => $menuItem->name, 'qty' => $line['qty'],
                        'unit_price' => $menuItem->price, 'amount' => $menuItem->price * $line['qty'],
                        'notes' => $line['notes'] ?? null,
                    ]);
                }

                foreach ($data['items'] as $line) {
                    $this->inventory->deductStock($menuItems->get($line['menu_item_id']), $line['qty']);
                }
                $soldOut = $this->inventory->autoSoldOutSweep($menuItems->keys()->all());
                if ($soldOut !== []) {
                    broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['sold_out' => $soldOut]));
                }

                return $this->recompute($order);
            });
        } catch (InsufficientStockException $e) {
            $this->markSoldOutAfterFailure($e, $menuItems);
        }

        AuditLog::record('order.created', $order, ['order_no' => $order->id, 'type' => $data['type']]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /**
     * @param  list<array{menu_item_id: int, qty: int, notes?: string}>  $items
     */
    public function addItems(Order $order, array $items, int $staffId): Order
    {
        $order->loadMissing('status');
        if (! in_array($order->status->code, [OrderStatus::OPEN, OrderStatus::PARKED], true)) {
            throw ValidationException::withMessages(['status' => "Order is {$order->status->code}."]);
        }

        $menuItems = MenuItem::query()->whereIn('id', collect($items)->pluck('menu_item_id'))->get()->keyBy('id');
        foreach ($items as $line) {
            $menuItem = $menuItems->get($line['menu_item_id']);
            if (! $menuItem || $menuItem->sold_out) {
                throw ValidationException::withMessages(['items' => '"'.($menuItem->name ?? 'item')."\" is unavailable."]);
            }
        }

        try {
            $order = DB::transaction(function () use ($order, $items, $menuItems) {
                foreach ($items as $line) {
                    $menuItem = $menuItems->get($line['menu_item_id']);
                    $order->items()->create([
                        'menu_item_id' => $menuItem->id, 'name' => $menuItem->name, 'qty' => $line['qty'],
                        'unit_price' => $menuItem->price, 'amount' => $menuItem->price * $line['qty'],
                        'notes' => $line['notes'] ?? null,
                    ]);
                    $this->inventory->deductStock($menuItem, $line['qty']);
                }
                $soldOut = $this->inventory->autoSoldOutSweep($menuItems->keys()->all());
                if ($soldOut !== []) {
                    broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['sold_out' => $soldOut]));
                }

                // New food arrived — the kitchen needs to see it again.
                $order->update([
                    'kot_status_id' => Lookup::id(LookupType::KOT_STATUS, KotStatus::NEW),
                    'order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::OPEN),
                ]);

                return $this->recompute($order);
            });
        } catch (InsufficientStockException $e) {
            $this->markSoldOutAfterFailure($e, $menuItems);
        }

        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /**
     * Void a single line. KOT rules: only at NEW (restocks) or SERVED (no
     * restock — food was consumed); blocked at PREPARING/READY.
     */
    public function voidItem(Order $order, OrderItem $item, string $reason): Order
    {
        $order->loadMissing('status', 'kotStatus');

        if ($item->voided) {
            throw ValidationException::withMessages(['item' => 'Already voided.']);
        }
        if (in_array($order->status->code, [OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM], true)) {
            throw ValidationException::withMessages(['order' => 'Order already settled — use refund instead.']);
        }
        if (in_array($order->kotStatus->code, [KotStatus::PREPARING, KotStatus::READY], true)) {
            $verb = $order->kotStatus->code === KotStatus::PREPARING ? 'preparing' : 'ready to serve';
            throw ValidationException::withMessages([
                'item' => "Cannot void while the kitchen is {$verb} — void before it starts or after it is served.",
            ]);
        }

        $restock = $order->kotStatus->code === KotStatus::NEW;

        $order = DB::transaction(function () use ($order, $item, $reason, $restock) {
            $item->update(['voided' => true, 'void_reason' => $reason]);
            if ($restock) {
                $this->inventory->deductStock($item->menuItem, $item->qty, -1);
            }

            return $this->recompute($order);
        });

        AuditLog::record('order_item.voided', $item, ['reason' => $reason, 'name' => $item->name, 'restocked' => $restock]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    public function updateKotStatus(Order $order, string $status): Order
    {
        $order->update(['kot_status_id' => Lookup::id(LookupType::KOT_STATUS, $status)]);
        $order->loadMissing('kotStatus');

        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, [
            'order_id' => $order->id, 'order_no' => $order->id, 'kot_status' => $status,
        ]));

        return $order->load(self::WITH_FULL);
    }

    public function park(Order $order): Order
    {
        $order->update(['order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::PARKED)]);

        return $order->load(self::WITH_FULL);
    }

    public function resume(Order $order): Order
    {
        $order->update(['order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::OPEN)]);

        return $order->load(self::WITH_FULL);
    }

    public function applyDiscount(Order $order, string $mode, float $value, string $reason, int $staffId): Order
    {
        $order->loadMissing('status');
        if (in_array($order->status->code, [OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM], true)) {
            throw ValidationException::withMessages(['order' => 'Order already settled.']);
        }

        $subtotal = (int) $order->items()->where('voided', false)->sum('amount');
        $discount = $mode === 'PCT'
            ? (int) round($subtotal * min($value, 100) / 100)
            : min((int) round($value), $subtotal);

        $order = DB::transaction(function () use ($order, $discount, $reason, $staffId) {
            $order->update(['discount' => $discount, 'discount_reason' => $reason, 'discount_by_id' => $staffId]);

            return $this->recompute($order);
        });

        AuditLog::record('order.discount_applied', $order, ['mode' => $mode, 'value' => $value, 'discount' => $discount, 'reason' => $reason]);

        return $order->load(self::WITH_FULL);
    }

    /**
     * @param  list<array{method: string, amount: int, reference?: string, idempotency_key?: string}>  $payments
     */
    public function settle(Order $order, array $payments, int $staffId): Order
    {
        $order->loadMissing('status', 'payments', 'reservation');

        if (in_array($order->status->code, [OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM], true)) {
            $allReplayed = collect($payments)->every(
                fn ($p) => ! empty($p['idempotency_key']) && $order->payments->contains('idempotency_key', $p['idempotency_key']),
            );
            if ($allReplayed) {
                return $order->load(self::WITH_FULL);
            }
            throw ValidationException::withMessages(['order' => 'Order already settled.']);
        }

        if (collect($payments)->contains(fn ($p) => $p['method'] === PaymentMethod::CORPORATE_CREDIT)) {
            throw ValidationException::withMessages(['payments' => 'Corporate credit applies to room folios only.']);
        }

        $paidAlready = $this->orderPaid($order);
        $newSum = (int) collect($payments)->sum('amount');
        if ($paidAlready + $newSum !== $order->total) {
            throw ValidationException::withMessages([
                'payments' => 'Split payments must total LKR '.number_format(($order->total - $paidAlready) / 100, 2).'.',
            ]);
        }

        foreach ($payments as $p) {
            $this->billing->recordPayment([
                'order_id' => $order->id, 'method' => $p['method'], 'amount' => $p['amount'],
                'reference' => $p['reference'] ?? null, 'idempotency_key' => $p['idempotency_key'] ?? null,
                'staff_id' => $staffId, 'guest_id_for_loyalty' => $order->reservation?->guest_id,
            ]);
        }

        $order->update(['order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::SETTLED), 'settled_at' => now()]);

        if ($order->reservation?->guest_id) {
            $this->billing->accrueLoyalty($order->reservation->guest_id, $order->total, 'ORDER', $order->id, $staffId);
        }

        AuditLog::record('order.settled', $order, ['total' => $order->total, 'methods' => collect($payments)->pluck('method')->all()]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ORDERS, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /** Charge a room-guest order to the guest folio — flows into unified checkout. */
    public function chargeToRoom(Order $order, int $staffId): Order
    {
        $order->loadMissing('status', 'type');

        if (in_array($order->status->code, [OrderStatus::SETTLED, OrderStatus::CHARGED_TO_ROOM], true)) {
            throw ValidationException::withMessages(['order' => 'Order already settled.']);
        }
        if ($order->type->code !== OrderType::ROOM_GUEST || ! $order->room_id) {
            throw ValidationException::withMessages(['order' => 'Not a room-guest order.']);
        }

        $reservation = $this->reservations->findCheckedInReservationForRoom($order->room_id);

        DB::transaction(function () use ($order, $reservation, $staffId) {
            $fresh = $this->recompute($order); // lock in current VAT/SC
            $this->postOrderToFolio($fresh, $reservation, $staffId);
            $order->update([
                'order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::CHARGED_TO_ROOM),
                'reservation_id' => $reservation->id, 'settled_at' => now(),
            ]);
        });

        AuditLog::record('order.charged_to_room', $order, ['reservation' => $reservation->code]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ORDERS, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /**
     * Void an entire order. KOT rules: only when NEW (restocks) or SERVED
     * (no restock). Payments must be refunded first.
     *
     * @return array{ok: bool, restocked: bool}
     */
    public function void(Order $order, string $reason): array
    {
        $order->loadMissing('items', 'status', 'kotStatus');

        if ($order->status->code === OrderStatus::CHARGED_TO_ROOM) {
            throw ValidationException::withMessages(['order' => 'Charged to room — void the folio lines instead.']);
        }
        if ($this->orderPaid($order) > 0) {
            throw ValidationException::withMessages(['order' => 'Order has payments — refund them first.']);
        }
        if (in_array($order->kotStatus->code, [KotStatus::PREPARING, KotStatus::READY], true)) {
            $verb = $order->kotStatus->code === KotStatus::PREPARING ? 'preparing' : 'ready to serve';
            throw ValidationException::withMessages([
                'order' => "Cannot void while the kitchen is {$verb} — wait until served or void before it starts.",
            ]);
        }

        $restock = $order->kotStatus->code === KotStatus::NEW;

        DB::transaction(function () use ($order, $reason, $restock) {
            if ($restock) {
                foreach ($order->items->where('voided', false) as $item) {
                    $this->inventory->deductStock($item->menuItem, $item->qty, -1);
                }
            }
            $order->update(['order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::VOID), 'void_reason' => $reason]);
        });

        AuditLog::record('order.voided', $order, ['reason' => $reason, 'restocked' => $restock]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return ['ok' => true, 'restocked' => $restock];
    }

    public function refund(Order $order, string $method, int $amount, string $reason, int $staffId): Payment
    {
        if ($amount > $this->orderPaid($order)) {
            throw ValidationException::withMessages(['amount' => 'Refund exceeds amount paid.']);
        }

        return $this->billing->recordPayment([
            'order_id' => $order->id, 'method' => $method, 'amount' => $amount,
            'kind' => PaymentKind::REFUND, 'reason' => $reason, 'staff_id' => $staffId,
        ]);
    }

    /** Recompute order money fields from its non-voided items + current tax settings. */
    public function recompute(Order $order): Order
    {
        $order->loadMissing('items', 'diningMode');

        $subtotal = (int) $order->items->where('voided', false)->sum('amount');
        // Takeaway is exempt from service charge (no table service) — VAT still applies.
        $scPct = $order->diningMode->code === DiningMode::TAKEAWAY ? 0.0 : Settings::num('billing.service_charge_pct', 0);
        $vatPct = Settings::num('billing.vat_pct', 0);

        $totals = $this->billing->calcOrderTotals($subtotal, $order->discount, $scPct, $vatPct);

        $order->update([
            'subtotal' => $totals['subtotal'], 'service_charge' => $totals['service_charge'],
            'vat' => $totals['vat'], 'total' => $totals['total'],
        ]);

        return $order;
    }

    private function orderPaid(Order $order): int
    {
        $order->loadMissing('payments.kind');

        return (int) $order->payments->filter(fn (Payment $p) => $p->kind->code !== PaymentKind::REFUND)->sum('amount')
            - (int) $order->payments->filter(fn (Payment $p) => $p->kind->code === PaymentKind::REFUND)->sum('amount');
    }

    /**
     * Post a finished order to the guest's room folio as auditable line
     * items: restaurant/minibar split, discount, then its own SC + VAT lines
     * (all tagged with order_id so folio checkout never taxes them again).
     */
    private function postOrderToFolio(Order $order, Reservation $reservation, int $staffId): void
    {
        $order->loadMissing('items.menuItem.category');
        $live = $order->items->where('voided', false);
        $minibar = (int) $live->filter(fn (OrderItem $i) => $i->menuItem->category->is_minibar)->sum('amount');
        $restaurant = (int) $live->sum('amount') - $minibar;

        $folioId = $reservation->folio->id;
        $make = function (string $source, string $description, int $amount) use ($folioId, $order, $staffId) {
            FolioLine::create([
                'folio_id' => $folioId, 'order_id' => $order->id,
                'line_source_id' => Lookup::id(LookupType::LINE_SOURCE, $source),
                'description' => $description, 'qty' => 1, 'unit_price' => $amount, 'amount' => $amount,
                'staff_id' => $staffId,
            ]);
        };

        if ($restaurant > 0) {
            $make(LineSource::RESTAURANT, "Restaurant Order #{$order->id}", $restaurant);
        }
        if ($minibar > 0) {
            $make(LineSource::MINIBAR, "Minibar Order #{$order->id}", $minibar);
        }
        if ($order->discount > 0) {
            $desc = "Discount on Order #{$order->id}".($order->discount_reason ? " ({$order->discount_reason})" : '');
            $make(LineSource::DISCOUNT, $desc, -$order->discount);
        }
        if ($order->service_charge > 0) {
            $make(LineSource::SERVICE_CHARGE, "Service charge — Order #{$order->id}", $order->service_charge);
        }
        if ($order->vat > 0) {
            $make(LineSource::VAT, "VAT — Order #{$order->id}", $order->vat);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MenuItem>  $menuItems
     */
    private function markSoldOutAfterFailure(InsufficientStockException $e, $menuItems): never
    {
        $name = $menuItems->get($e->menuItemId)?->name ?? 'Item';
        MenuItem::query()->where('id', $e->menuItemId)->update(['sold_out' => true]);
        broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['sold_out' => [$name]]));

        throw ValidationException::withMessages([
            'items' => $e->getMessage()." — \"{$name}\" is now marked SOLD OUT.",
        ]);
    }
}
