<?php

namespace App\Services\Hotel;

use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\Order;
use App\Models\Hotel\QrOrderingPoint;
use App\Services\Settings;
use App\Support\Lookups\DiningMode;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\TableStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unauthenticated guest-facing QR ordering — a guest scans the QR code on
 * their room desk or restaurant table and lands here. Sits entirely outside
 * the auth/permission system, mirroring PublicService. Order creation itself
 * is delegated to OrderService so a QR order is a normal Order from the
 * moment it exists — same KOT/POS/billing pipeline staff already use.
 */
class QrOrderingService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function menuFor(string $token): array
    {
        $point = $this->resolvePoint($token);
        $this->assertOrderable($point);

        $currentOrder = $point->dining_table_id ? $this->openTableOrder($point->dining_table_id) : null;

        return [
            'point' => ['type' => $point->room_id ? 'room' : 'table', 'label' => $this->label($point)],
            'branding' => [
                'name' => Settings::str('hotel.name', 'Mount View Hotel'),
                'logo' => Settings::str('hotel.logo_url', ''),
            ],
            'theme' => [
                'welcome_message' => Settings::str('qr_ordering.welcome_message', 'Scan. Browse. Order.'),
                'accent_color' => Settings::str('qr_ordering.accent_color', '#0462d3'),
                'banner_image' => Settings::str('qr_ordering.banner_image', ''),
                'show_item_images' => Settings::bool('qr_ordering.show_item_images', true),
                'show_descriptions' => Settings::bool('qr_ordering.show_descriptions', true),
                'collect_customer_name' => Settings::bool('qr_ordering.collect_customer_name', true),
                'collect_customer_phone' => Settings::bool('qr_ordering.collect_customer_phone', false),
                'footer_note' => Settings::str('qr_ordering.footer_note', ''),
            ],
            'current_order' => $currentOrder ? $this->summarize($currentOrder) : null,
            'categories' => $this->activeMenu(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function placeOrder(string $token, array $data): Order
    {
        $point = $this->resolvePoint($token);
        $this->assertOrderable($point);

        return $point->room_id ? $this->placeRoomOrder($point, $data) : $this->placeTableOrder($point, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function orderStatus(string $token, Order $order): array
    {
        $point = $this->resolvePoint($token);

        $belongsToPoint = $point->room_id
            ? $order->room_id === $point->room_id
            : $order->dining_table_id === $point->dining_table_id;

        if (! $belongsToPoint) {
            throw new NotFoundHttpException('Order not found.');
        }

        return $this->summarize($order);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function placeRoomOrder(QrOrderingPoint $point, array $data): Order
    {
        $reservation = $this->reservations->findCheckedInReservationForRoom($point->room_id);

        return $this->orders->create([
            'type' => OrderType::ROOM_GUEST,
            'room_id' => $point->room_id,
            'items' => $data['items'],
            'customer_name' => $reservation->guest->name,
            'notes' => $data['notes'] ?? null,
            'client_key' => $data['client_key'] ?? null,
            'placed_via_qr' => true,
        ], null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function placeTableOrder(QrOrderingPoint $point, array $data): Order
    {
        $table = $point->diningTable()->with('status')->firstOrFail();

        if ($table->status->code === TableStatus::FREE) {
            // Only asked when this request opens the tab — OrderService::addItems()
            // (the OCCUPIED branch below) has no customer_name/phone field to put it in.
            if (Settings::bool('qr_ordering.collect_customer_name', true) && empty($data['customer_name'])) {
                throw ValidationException::withMessages(['customer_name' => 'Please tell us your name.']);
            }
            if (Settings::bool('qr_ordering.collect_customer_phone', false) && empty($data['customer_phone'])) {
                throw ValidationException::withMessages(['customer_phone' => 'Please share a contact number.']);
            }

            return $this->orders->create([
                'type' => OrderType::WALKIN,
                'dining_mode' => DiningMode::DINE_IN,
                'dining_table_id' => $table->id,
                'items' => $data['items'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'client_key' => $data['client_key'] ?? null,
                'placed_via_qr' => true,
            ], null);
        }

        if ($table->status->code === TableStatus::OCCUPIED) {
            $open = $this->openTableOrder($table->id);
            if (! $open) {
                throw ValidationException::withMessages(['token' => 'Please ask a staff member to assist with your order.']);
            }

            return $this->orders->addItems($open, $data['items'], null);
        }

        throw ValidationException::withMessages(['token' => "This table isn't available for ordering right now."]);
    }

    private function resolvePoint(string $token): QrOrderingPoint
    {
        $point = QrOrderingPoint::query()->with('room', 'diningTable.status')->where('token', $token)->first();

        if (! $point) {
            throw new NotFoundHttpException('This ordering link is no longer valid.');
        }

        return $point;
    }

    /** Global kill switch, per-point toggle, checked-in-guest (room) / table-ready (table). */
    private function assertOrderable(QrOrderingPoint $point): void
    {
        if (! $point->enabled || ! Settings::bool('qr_ordering.enabled', true)) {
            throw ValidationException::withMessages(['token' => "Ordering isn't available here right now — please contact staff."]);
        }

        if ($point->room_id) {
            // Throws a friendly ValidationException if no one is checked in.
            $this->reservations->findCheckedInReservationForRoom($point->room_id);

            return;
        }

        if (in_array($point->diningTable->status->code, [TableStatus::RESERVED, TableStatus::CLEANING], true)) {
            throw ValidationException::withMessages(['token' => "This table isn't ready for ordering yet — please contact staff."]);
        }
    }

    private function label(QrOrderingPoint $point): string
    {
        return $point->room_id ? "Room {$point->room->number}" : "Table {$point->diningTable->table_no}";
    }

    private function openTableOrder(int $tableId): ?Order
    {
        return Order::query()->where('dining_table_id', $tableId)->statusIn([OrderStatus::OPEN, OrderStatus::PARKED])->latest()->first();
    }

    /**
     * Active, in-stock menu for guest ordering — unlike the staff POS grid
     * (MenuItemController::full()), sold-out items are left out entirely
     * rather than shown greyed out, since a guest has no one to ask "is this
     * really unavailable?".
     */
    private function activeMenu(): Collection
    {
        return MenuCategory::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->with([
                'items' => fn ($q) => $q->where('active', true)->where('sold_out', false)->orderBy('item_no')->orderBy('name')
                    ->with(['modifierGroups' => fn ($g) => $g->orderBy('sort_order')->with(['modifiers' => fn ($m) => $m->where('active', true)->orderBy('sort_order')])]),
            ])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Order $order): array
    {
        $order->loadMissing(['items' => fn ($q) => $q->where('voided', false), 'status', 'kotStatus']);

        return [
            'id' => $order->id,
            'status' => $order->status->code,
            'kot_status' => $order->kotStatus->code,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->name, 'qty' => $item->qty, 'amount' => $item->amount,
            ])->values(),
            'total' => $order->total,
        ];
    }
}
