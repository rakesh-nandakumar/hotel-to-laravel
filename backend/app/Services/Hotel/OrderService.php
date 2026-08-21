<?php

namespace App\Services\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Models\Hotel\AddOn;
use App\Models\Hotel\DiningTable;
use App\Models\Hotel\FolioLine;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\MenuItemModifier;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\OrderItemModifier;
use App\Models\Hotel\Payment;
use App\Models\Hotel\Reservation;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Settings;
use App\Support\Lookups\DeliveryStatus;
use App\Support\Lookups\DiningMode;
use App\Support\Lookups\KotStatus;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\TableStatus;
use App\Support\RealtimeEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS order lifecycle — creation, KOT, discount, settlement, charge-to-room,
 * void, refund. Ported from the Node app's routes/orders.ts + lib/pos.ts.
 */
class OrderService
{
    private const WITH_FULL = [
        'items.modifiers', 'items.addOn', 'room:id,number', 'diningTable:id,table_no', 'reservation:id,code,guest_id', 'reservation.guest:id,name',
        'staff:id,name', 'payments.kind', 'payments.method', 'status', 'type', 'kotStatus', 'diningMode', 'deliveryStatus', 'deliveryRider:id,name',
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BillingService $billing,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $staffId): Order
    {
        if (! empty($data['client_key'])) {
            $existing = Order::query()->where('client_key', $data['client_key'])->first();
            if ($existing) {
                return $existing->load(self::WITH_FULL);
            }
        }

        // Room service and delivery are never "dine-in" in the table sense —
        // takeaway/delivery share the no-service-charge tax treatment (recompute()).
        $diningMode = $data['type'] === OrderType::ROOM_GUEST ? DiningMode::DINE_IN : ($data['dining_mode'] ?? DiningMode::DINE_IN);

        $reservationId = null;
        if ($data['type'] === OrderType::ROOM_GUEST) {
            $reservationId = $this->reservations->findCheckedInReservationForRoom($data['room_id'])->id;
        }

        $table = null;
        if (! empty($data['dining_table_id'])) {
            $table = DiningTable::query()->findOrFail($data['dining_table_id']);
            if ($table->status->code !== TableStatus::FREE) {
                throw ValidationException::withMessages([
                    'dining_table_id' => "Table {$table->table_no} is already {$table->status->code}.",
                ]);
            }
        }

        $menuItems = MenuItem::query()->whereIn('id', collect($data['items'])->pluck('menu_item_id')->filter())->get()->keyBy('id');
        $addOns = AddOn::query()->whereIn('id', collect($data['items'])->pluck('add_on_id')->filter())->get()->keyBy('id');
        $products = Ingredient::query()->whereIn('id', collect($data['items'])->pluck('product_id')->filter())->get()->keyBy('id');
        foreach ($data['items'] as $line) {
            if (isset($line['add_on_id'])) {
                $addOn = $addOns->get($line['add_on_id']);
                if (! $addOn || ! $addOn->active) {
                    throw ValidationException::withMessages(['items' => 'Add-on not found.']);
                }

                continue;
            }

            if (isset($line['product_id'])) {
                $product = $products->get($line['product_id']);
                if (! $product || ! $product->active) {
                    throw ValidationException::withMessages(['items' => 'Product not found.']);
                }
                if ($product->stock_qty <= 0) {
                    throw ValidationException::withMessages(['items' => "\"{$product->name}\" is out of stock."]);
                }

                continue;
            }

            $menuItem = $menuItems->get($line['menu_item_id']);
            if (! $menuItem || ! $menuItem->active) {
                throw ValidationException::withMessages(['items' => 'Menu item not found.']);
            }
            if ($menuItem->sold_out) {
                throw ValidationException::withMessages(['items' => "\"{$menuItem->name}\" is marked sold out."]);
            }
        }

        try {
            $order = DB::transaction(function () use ($data, $diningMode, $reservationId, $menuItems, $addOns, $products, $table, $staffId) {
                $order = Order::create([
                    'client_key' => $data['client_key'] ?? null,
                    'order_type_id' => Lookup::id(LookupType::ORDER_TYPE, $data['type']),
                    'dining_mode_id' => Lookup::id(LookupType::DINING_MODE, $diningMode),
                    'order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::OPEN),
                    'kot_status_id' => Lookup::id(LookupType::KOT_STATUS, KotStatus::NEW),
                    'room_id' => $data['room_id'] ?? null,
                    'dining_table_id' => $table?->id,
                    'delivery_address' => $data['type'] === OrderType::DELIVERY ? $data['delivery_address'] : null,
                    'delivery_phone' => $data['type'] === OrderType::DELIVERY ? ($data['delivery_phone'] ?? null) : null,
                    'delivery_status_id' => $data['type'] === OrderType::DELIVERY
                        ? Lookup::id(LookupType::DELIVERY_STATUS, DeliveryStatus::PENDING)
                        : null,
                    'reservation_id' => $reservationId,
                    'customer_name' => $data['customer_name'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'placed_via_qr' => $data['placed_via_qr'] ?? false,
                    'notes' => $data['notes'] ?? null,
                    'staff_id' => $staffId,
                    'discount' => 0,
                ]);

                $menuItemIds = [];
                $addOnIds = [];
                $productIds = [];
                foreach ($data['items'] as $line) {
                    if (isset($line['add_on_id'])) {
                        $addOn = $addOns->get($line['add_on_id']);
                        $item = $this->addAddOnItem($order, $addOn, $line);
                        $this->inventory->deductAddOn($addOn, $line['qty'], 1, $item);
                        $addOnIds[] = $addOn->id;

                        continue;
                    }

                    if (isset($line['product_id'])) {
                        $product = $products->get($line['product_id']);
                        $item = $this->addProductItem($order, $product, $line);
                        $this->inventory->deductProduct($product, $line['qty'], 1, $item);
                        $productIds[] = $product->id;

                        continue;
                    }

                    $menuItem = $menuItems->get($line['menu_item_id']);
                    $item = $this->addOrderItem($order, $menuItem, $line);
                    $this->inventory->deductStock($menuItem, $line['qty'], 1, $item);
                    $menuItemIds[] = $menuItem->id;
                }

                if ($table) {
                    $table->update(['table_status_id' => Lookup::id(LookupType::TABLE_STATUS, TableStatus::OCCUPIED)]);
                }

                $soldOut = $this->inventory->autoSoldOutSweep($menuItemIds, $addOnIds, $productIds);
                if ($soldOut !== []) {
                    broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['sold_out' => $soldOut]));
                }

                return $this->recompute($order);
            });
        } catch (InsufficientStockException $e) {
            $this->markSoldOutAfterFailure($e, $menuItems, $addOns, $products);
        }

        AuditLog::record('order.created', $order, ['order_no' => $order->id, 'type' => $data['type']]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /**
     * @param  list<array{menu_item_id?: int, add_on_id?: int, qty: int, notes?: string}>  $items
     */
    public function addItems(Order $order, array $items, ?int $staffId): Order
    {
        $order->loadMissing('status');
        if (! in_array($order->status->code, [OrderStatus::OPEN, OrderStatus::PARKED], true)) {
            throw ValidationException::withMessages(['status' => "Order is {$order->status->code}."]);
        }

        $menuItems = MenuItem::query()->whereIn('id', collect($items)->pluck('menu_item_id')->filter())->get()->keyBy('id');
        $addOns = AddOn::query()->whereIn('id', collect($items)->pluck('add_on_id')->filter())->get()->keyBy('id');
        $products = Ingredient::query()->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');
        foreach ($items as $line) {
            if (isset($line['add_on_id'])) {
                $addOn = $addOns->get($line['add_on_id']);
                if (! $addOn || ! $addOn->active) {
                    throw ValidationException::withMessages(['items' => 'Add-on not found.']);
                }

                continue;
            }

            if (isset($line['product_id'])) {
                $product = $products->get($line['product_id']);
                if (! $product || ! $product->active || $product->stock_qty <= 0) {
                    throw ValidationException::withMessages(['items' => '"'.($product->name ?? 'product').'" is unavailable.']);
                }

                continue;
            }

            $menuItem = $menuItems->get($line['menu_item_id']);
            if (! $menuItem || $menuItem->sold_out) {
                throw ValidationException::withMessages(['items' => '"'.($menuItem->name ?? 'item').'" is unavailable.']);
            }
        }

        try {
            $order = DB::transaction(function () use ($order, $items, $menuItems, $addOns, $products) {
                $menuItemIds = [];
                $addOnIds = [];
                $productIds = [];
                foreach ($items as $line) {
                    if (isset($line['add_on_id'])) {
                        $addOn = $addOns->get($line['add_on_id']);
                        $item = $this->addAddOnItem($order, $addOn, $line);
                        $this->inventory->deductAddOn($addOn, $line['qty'], 1, $item);
                        $addOnIds[] = $addOn->id;

                        continue;
                    }

                    if (isset($line['product_id'])) {
                        $product = $products->get($line['product_id']);
                        $item = $this->addProductItem($order, $product, $line);
                        $this->inventory->deductProduct($product, $line['qty'], 1, $item);
                        $productIds[] = $product->id;

                        continue;
                    }

                    $menuItem = $menuItems->get($line['menu_item_id']);
                    $item = $this->addOrderItem($order, $menuItem, $line);
                    $this->inventory->deductStock($menuItem, $line['qty'], 1, $item);
                    $menuItemIds[] = $menuItem->id;
                }
                $soldOut = $this->inventory->autoSoldOutSweep($menuItemIds, $addOnIds, $productIds);
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
            $this->markSoldOutAfterFailure($e, $menuItems, $addOns, $products);
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
                $this->restockItem($item);
            }

            return $this->recompute($order);
        });

        AuditLog::record('order_item.voided', $item, ['reason' => $reason, 'name' => $item->name, 'restocked' => $restock]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    public function updateKotStatus(Order $order, string $status): Order
    {
        // Stamped once, the first time each stage is reached — never overwritten
        // on later re-visits — so the Kitchen Ticket Time report measures real
        // prep duration even if a ticket bounces between statuses.
        $timestamps = match ($status) {
            KotStatus::PREPARING => ['kot_started_at' => $order->kot_started_at ?? now()],
            KotStatus::READY => ['kot_ready_at' => $order->kot_ready_at ?? now()],
            KotStatus::SERVED => ['served_at' => $order->served_at ?? now()],
            default => [],
        };

        $order->update(['kot_status_id' => Lookup::id(LookupType::KOT_STATUS, $status), ...$timestamps]);
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
        $this->freeTableToCleaning($order);

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
                    $this->restockItem($item);
                }
            }
            $order->update(['order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::VOID), 'void_reason' => $reason]);
            $this->freeTableToCleaning($order);
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

    /** Dispatch — assigns/reassigns the rider; bumps PENDING straight to OUT_FOR_DELIVERY. */
    public function assignDeliveryRider(Order $order, int $riderId): Order
    {
        $order->loadMissing('type', 'deliveryStatus');
        if ($order->type->code !== OrderType::DELIVERY) {
            throw ValidationException::withMessages(['order' => 'Not a delivery order.']);
        }

        $update = ['delivery_rider_id' => $riderId];
        if ($order->deliveryStatus?->code === DeliveryStatus::PENDING) {
            $update['delivery_status_id'] = Lookup::id(LookupType::DELIVERY_STATUS, DeliveryStatus::OUT_FOR_DELIVERY);
        }
        $order->update($update);

        AuditLog::record('order.delivery_rider_assigned', $order, ['rider_id' => $riderId]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ORDERS, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    public function updateDeliveryStatus(Order $order, string $status): Order
    {
        $order->loadMissing('type');
        if ($order->type->code !== OrderType::DELIVERY) {
            throw ValidationException::withMessages(['order' => 'Not a delivery order.']);
        }

        // Stamped once, first time reached — same idempotent approach as the KOT
        // timestamps above — so the Delivery Performance report measures real
        // fulfillment duration even if a status gets corrected/re-applied.
        $timestamps = match ($status) {
            DeliveryStatus::OUT_FOR_DELIVERY => ['dispatched_at' => $order->dispatched_at ?? now()],
            DeliveryStatus::DELIVERED => ['delivered_at' => $order->delivered_at ?? now()],
            default => [],
        };

        $order->update(['delivery_status_id' => Lookup::id(LookupType::DELIVERY_STATUS, $status), ...$timestamps]);

        AuditLog::record('order.delivery_status_changed', $order, ['status' => $status]);
        broadcast(new RealtimeUpdate(RealtimeEvent::ORDERS, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /**
     * Divide an open order's items into N new child orders — e.g. a table of
     * 4 wants 4 separate bills. Every non-voided item must end up in exactly
     * one group; the original is marked SPLIT and left with nothing owing
     * (its items now belong to the children), so `sum(child.total)` always
     * equals what the parent's total was immediately before the split.
     *
     * @param  list<list<int>>  $groups  each inner list is a set of order_item ids
     * @return Collection<int, Order>
     */
    public function splitBill(Order $order, array $groups, int $staffId): Collection
    {
        $order->loadMissing('status', 'items');

        if (! in_array($order->status->code, [OrderStatus::OPEN, OrderStatus::PARKED], true)) {
            throw ValidationException::withMessages(['order' => "Cannot split a {$order->status->code} order."]);
        }

        $liveItemIds = $order->items->where('voided', false)->pluck('id');
        $groupedIds = collect($groups)->flatten();

        if ($groupedIds->count() !== $groupedIds->unique()->count()) {
            throw ValidationException::withMessages(['groups' => 'Each item can only appear in one split group.']);
        }
        if ($groupedIds->sort()->values()->all() !== $liveItemIds->sort()->values()->all()) {
            throw ValidationException::withMessages(['groups' => 'Every item on the bill must be assigned to exactly one split group.']);
        }

        $children = DB::transaction(function () use ($order, $groups, $staffId) {
            $children = collect($groups)->map(function (array $itemIds) use ($order, $staffId) {
                $child = Order::create([
                    'parent_order_id' => $order->id,
                    'order_type_id' => $order->order_type_id,
                    'dining_mode_id' => $order->dining_mode_id,
                    'order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::OPEN),
                    'kot_status_id' => $order->kot_status_id,
                    'room_id' => $order->room_id,
                    'dining_table_id' => $order->dining_table_id,
                    'reservation_id' => $order->reservation_id,
                    'customer_name' => $order->customer_name,
                    'staff_id' => $staffId,
                    'discount' => 0,
                ]);

                OrderItem::whereIn('id', $itemIds)->update(['order_id' => $child->id]);

                return $this->recompute($child);
            });

            $order->update(['order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::SPLIT)]);
            $this->recompute($order);

            return $children;
        });

        AuditLog::record('order.split', $order, ['child_order_ids' => $children->pluck('id')->all()]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $children->map(fn (Order $c) => $c->load(self::WITH_FULL));
    }

    /**
     * Fold one or more other open orders' items into $order (e.g. a table
     * asks to combine separate tabs into one bill). The folded-in orders are
     * marked MERGED and point back at $order via parent_order_id; if any of
     * them was holding its own table, that table frees up to CLEANING since
     * its tab no longer exists independently.
     */
    public function mergeOrders(Order $order, array $sourceOrderIds, int $staffId): Order
    {
        $order->loadMissing('status');
        if (! in_array($order->status->code, [OrderStatus::OPEN, OrderStatus::PARKED], true)) {
            throw ValidationException::withMessages(['order' => "Cannot merge into a {$order->status->code} order."]);
        }

        $sources = Order::query()->whereIn('id', $sourceOrderIds)->where('id', '!=', $order->id)
            ->with('status', 'diningTable')->get();

        foreach ($sources as $source) {
            if (! in_array($source->status->code, [OrderStatus::OPEN, OrderStatus::PARKED], true)) {
                throw ValidationException::withMessages(['order_ids' => "Order #{$source->id} is {$source->status->code} — cannot merge it."]);
            }
        }

        DB::transaction(function () use ($order, $sources) {
            foreach ($sources as $source) {
                OrderItem::where('order_id', $source->id)->update(['order_id' => $order->id]);
                $source->update([
                    'order_status_id' => Lookup::id(LookupType::ORDER_STATUS, OrderStatus::MERGED),
                    'parent_order_id' => $order->id,
                ]);
                $this->freeTableToCleaning($source);
            }

            $this->recompute($order);
        });

        AuditLog::record('order.merged', $order, ['merged_order_ids' => $sources->pluck('id')->all(), 'staff_id' => $staffId]);
        broadcast(new RealtimeUpdate(RealtimeEvent::KOT, ['order_id' => $order->id]));

        return $order->load(self::WITH_FULL);
    }

    /** Recompute order money fields from its non-voided items + current tax settings. */
    public function recompute(Order $order): Order
    {
        $order->loadMissing('items', 'diningMode', 'type');

        $subtotal = (int) $order->items->where('voided', false)->sum('amount');
        // Takeaway and delivery are exempt from service charge (no table service) — VAT still applies.
        $noServiceCharge = $order->diningMode->code === DiningMode::TAKEAWAY || $order->type->code === OrderType::DELIVERY;
        $scPct = $noServiceCharge ? 0.0 : Settings::num('billing.service_charge_pct', 0);
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
     * Create one order item priced off the menu item plus any chosen
     * modifiers, and snapshot those modifiers onto it — shared by create()
     * and addItems() so the pricing/validation logic exists in exactly one place.
     *
     * @param  array{menu_item_id: int, qty: int, notes?: string, modifier_ids?: list<int>}  $line
     */
    private function addOrderItem(Order $order, MenuItem $menuItem, array $line): OrderItem
    {
        $modifiers = $this->resolveModifiers($menuItem, $line['modifier_ids'] ?? []);
        $unitPrice = $menuItem->price + (int) $modifiers->sum('price_delta');

        $item = $order->items()->create([
            'menu_item_id' => $menuItem->id, 'name' => $menuItem->name, 'qty' => $line['qty'],
            'unit_price' => $unitPrice, 'amount' => $unitPrice * $line['qty'],
            'send_to_kot' => true,
            'notes' => $line['notes'] ?? null,
        ]);

        foreach ($modifiers as $modifier) {
            OrderItemModifier::create([
                'order_item_id' => $item->id, 'menu_item_modifier_id' => $modifier->id,
                'name' => $modifier->name, 'price_delta' => $modifier->price_delta,
            ]);
        }

        return $item;
    }

    /**
     * Create a standalone add-on order line — its own price, routing, and
     * stock snapshot, fully independent of any parent item.
     *
     * @param  array{add_on_id: int, qty: int, notes?: string}  $line
     */
    private function addAddOnItem(Order $order, AddOn $addOn, array $line): OrderItem
    {
        return $order->items()->create([
            'add_on_id' => $addOn->id, 'name' => $addOn->name, 'qty' => $line['qty'],
            'unit_price' => $addOn->price, 'amount' => $addOn->price * $line['qty'],
            'send_to_kot' => true,
            'notes' => $line['notes'] ?? null,
        ]);
    }

    /**
     * Create a standalone product order line — a directly-sellable, no-recipe
     * stock item (bottled drink, packaged snack). Never routes to the
     * kitchen; priced off its selling_price.
     *
     * @param  array{product_id: int, qty: int, notes?: string}  $line
     */
    private function addProductItem(Order $order, Ingredient $product, array $line): OrderItem
    {
        return $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name, 'qty' => $line['qty'],
            'unit_price' => $product->selling_price, 'amount' => $product->selling_price * $line['qty'],
            'send_to_kot' => false,
            'notes' => $line['notes'] ?? null,
        ]);
    }

    /** Reverse an order item's inventory deduction — menu items, add-ons, or products. */
    private function restockItem(OrderItem $item): void
    {
        $item->loadMissing('menuItem', 'addOn', 'product');
        if ($item->addOn) {
            $this->inventory->deductAddOn($item->addOn, $item->qty, -1, $item);
        } elseif ($item->product) {
            $this->inventory->deductProduct($item->product, $item->qty, -1, $item);
        } elseif ($item->menuItem) {
            $this->inventory->deductStock($item->menuItem, $item->qty, -1, $item);
        }
    }

    /**
     * Validate the chosen modifier ids belong to this menu item, respect
     * each group's max_select, and satisfy every required group.
     *
     * @param  list<int>  $modifierIds
     * @return Collection<int, MenuItemModifier>
     */
    private function resolveModifiers(MenuItem $menuItem, array $modifierIds): Collection
    {
        $groups = $menuItem->modifierGroups()->with('modifiers')->get();
        if ($groups->isEmpty()) {
            return collect();
        }

        $allowedIds = $groups->flatMap(fn ($g) => $g->modifiers->pluck('id'));
        $selected = MenuItemModifier::query()->whereIn('id', $modifierIds)->where('active', true)->get();

        if ($selected->pluck('id')->diff($allowedIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => "One or more modifiers don't belong to \"{$menuItem->name}\"."]);
        }

        foreach ($groups as $group) {
            $chosenInGroup = $selected->whereIn('id', $group->modifiers->pluck('id'));
            if ($group->is_required && $chosenInGroup->isEmpty()) {
                throw ValidationException::withMessages(['items' => "\"{$menuItem->name}\" requires a choice for \"{$group->name}\"."]);
            }
            if ($chosenInGroup->count() > $group->max_select) {
                throw ValidationException::withMessages(['items' => "\"{$group->name}\" allows at most {$group->max_select} choice(s)."]);
            }
        }

        return $selected;
    }

    /** Dine-in orders holding a table free it to CLEANING once settled/voided — staff marks it Free after wiping down, mirroring Room's turnover pattern. */
    private function freeTableToCleaning(Order $order): void
    {
        $order->loadMissing('diningTable');
        if (! $order->diningTable) {
            return;
        }

        $order->diningTable->update(['table_status_id' => Lookup::id(LookupType::TABLE_STATUS, TableStatus::CLEANING)]);
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
        // Add-on lines are never minibar — they're kitchen/front-of-house additions.
        $minibar = (int) $live->filter(fn (OrderItem $i) => $i->add_on_id === null && $i->menuItem?->category?->is_minibar)->sum('amount');
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
     * @param  Collection<int, MenuItem>  $menuItems
     * @param  Collection<int, AddOn>  $addOns
     * @param  Collection<int, Ingredient>  $products
     */
    private function markSoldOutAfterFailure(InsufficientStockException $e, $menuItems, $addOns, $products): never
    {
        if ($e->addOnId || $e->productId) {
            throw ValidationException::withMessages([
                'items' => $e->getMessage().' can\'t be made right now.',
            ]);
        }

        $name = $menuItems->get($e->menuItemId)?->name ?? 'Item';
        MenuItem::query()->where('id', $e->menuItemId)->update(['sold_out' => true]);
        broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['sold_out' => [$name]]));

        throw ValidationException::withMessages([
            'items' => $e->getMessage().' — "'.$name.'" is now marked SOLD OUT.',
        ]);
    }
}
