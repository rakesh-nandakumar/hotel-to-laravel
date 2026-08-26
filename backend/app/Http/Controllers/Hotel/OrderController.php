<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\ApplyOrderDiscountRequest;
use App\Http\Requests\Hotel\AssignDeliveryRiderRequest;
use App\Http\Requests\Hotel\MergeOrdersRequest;
use App\Http\Requests\Hotel\RefundOrderRequest;
use App\Http\Requests\Hotel\SettleOrderRequest;
use App\Http\Requests\Hotel\SplitOrderRequest;
use App\Http\Requests\Hotel\StoreOrderItemsRequest;
use App\Http\Requests\Hotel\StoreOrderRequest;
use App\Http\Requests\Hotel\UpdateOrderDeliveryStatusRequest;
use App\Http\Requests\Hotel\UpdateOrderKotStatusRequest;
use App\Http\Requests\Hotel\VoidOrderItemRequest;
use App\Http\Requests\Hotel\VoidOrderRequest;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Services\Hotel\OrderService;
use App\Services\Hotel\Pdf\PdfService;
use App\Support\Lookups\KotStatus;
use App\Support\Lookups\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    private const WITH_FULL = [
        'items.modifiers', 'items.addOn', 'room:id,number', 'diningTable:id,table_no', 'reservation:id,code,guest_id', 'reservation.guest:id,name',
        'staff:id,name', 'payments.kind', 'payments.method', 'status', 'type', 'kotStatus', 'diningMode', 'deliveryStatus', 'deliveryRider:id,name',
    ];

    public function __construct(private readonly OrderService $orders, private readonly PdfService $pdf) {}

    /** Active POS orders (open tabs + parked + today's finished). */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->string('scope', 'active')->toString();

        $query = Order::query()->with(self::WITH_FULL)->latest();

        if ($scope === 'active') {
            $query->statusIn([OrderStatus::OPEN, OrderStatus::PARKED]);
        } elseif ($scope === 'today') {
            $query->where('created_at', '>=', now()->startOfDay());
        }

        return response()->json(['orders' => $query->limit(100)->get()]);
    }

    /**
     * Kitchen Order Ticket screen — chef's live queue. Only orders carrying at
     * least one send_to_kot (kitchen-routed) item appear; each ticket's items
     * are trimmed to the kitchen-routed lines so direct-fulfill drinks/snacks
     * never clutter the kitchen board.
     */
    public function kot(): JsonResponse
    {
        $orders = Order::query()
            ->with([...self::WITH_FULL, 'items.menuItem.category.kitchenStation'])
            ->whereHas('status', fn ($q) => $q->where('code', '!=', OrderStatus::VOID))
            ->whereHas('kotStatus', fn ($q) => $q->whereIn('code', [KotStatus::NEW, KotStatus::PREPARING, KotStatus::READY]))
            ->whereHas('items', fn ($q) => $q->where('send_to_kot', true)->where('voided', false))
            ->where('created_at', '>=', now()->subDay())
            ->oldest()
            ->get();

        $orders->each(function (Order $order) {
            $order->setRelation('items', $order->items->where('send_to_kot', true)->where('voided', false)->values());
        });

        return response()->json(['orders' => $orders]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['order' => $order->load(self::WITH_FULL)]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->validated(), $request->user()->id);

        return response()->json(['order' => $order], 201);
    }

    /** Add items to a running tab (post-paid walk-in or guest order). */
    public function addItems(StoreOrderItemsRequest $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->addItems($order, $request->validated('items'), $request->user()->id)]);
    }

    public function voidItem(VoidOrderItemRequest $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404, 'Order item not found.');

        return response()->json(['order' => $this->orders->voidItem($order, $item, $request->validated('reason'))]);
    }

    /** KOT status — Chef updates New → Preparing → Ready; reception sees it live. */
    public function updateKotStatus(UpdateOrderKotStatusRequest $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->updateKotStatus($order, $request->validated('status'))]);
    }

    public function park(Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->park($order)]);
    }

    public function resume(Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->resume($order)]);
    }

    public function discount(ApplyOrderDiscountRequest $request, Order $order): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['order' => $this->orders->applyDiscount($order, $data['mode'], $data['value'], $data['reason'], $request->user()->id)]);
    }

    public function settle(SettleOrderRequest $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->settle($order, $request->validated('payments'), $request->user()->id)]);
    }

    public function chargeToRoom(Request $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->chargeToRoom($order, $request->user()->id)]);
    }

    /** Active delivery orders — dispatch board. */
    public function deliveries(): JsonResponse
    {
        $orders = Order::query()->with(self::WITH_FULL)
            ->whereHas('type', fn ($q) => $q->where('code', 'delivery'))
            ->statusIn([OrderStatus::OPEN, OrderStatus::PARKED])
            ->latest()
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function assignDeliveryRider(AssignDeliveryRiderRequest $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->assignDeliveryRider($order, $request->validated('rider_id'))]);
    }

    public function updateDeliveryStatus(UpdateOrderDeliveryStatusRequest $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->updateDeliveryStatus($order, $request->validated('status'))]);
    }

    /** Split into N child bills — e.g. a table wants separate checks. */
    public function split(SplitOrderRequest $request, Order $order): JsonResponse
    {
        return response()->json(['orders' => $this->orders->splitBill($order, $request->validated('groups'), $request->user()->id)]);
    }

    /** Fold other open orders' items into this one — e.g. combining two tabs. */
    public function merge(MergeOrdersRequest $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->mergeOrders($order, $request->validated('order_ids'), $request->user()->id)]);
    }

    public function void(VoidOrderRequest $request, Order $order): JsonResponse
    {
        return response()->json($this->orders->void($order, $request->validated('reason')));
    }

    public function refund(RefundOrderRequest $request, Order $order): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'payment' => $this->orders->refund($order, $data['method'], $data['amount'], $data['reason'], $request->user()->id),
        ], 201);
    }

    /** Branded receipt — ?format=thermal|a4 (default thermal), ?output=html|pdf (default pdf). */
    public function receipt(Request $request, Order $order): Response
    {
        $format = $request->query('format') === 'a4' ? 'a4' : 'thermal';

        return $this->pdf->orderReceipt($order, $format, $request->query('output') === 'html');
    }

    /** Walk-in double slip: bill + numbered collection token (thermal, one print). */
    public function slip(Request $request, Order $order): Response
    {
        return $this->pdf->orderSlip($order, $request->query('output') === 'html');
    }

    public function kotTicket(Request $request, Order $order): Response
    {
        return $this->pdf->kotTicket($order, $request->query('output') === 'html');
    }
}
