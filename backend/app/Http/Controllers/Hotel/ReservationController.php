<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\CancelReservationRequest;
use App\Http\Requests\Hotel\CheckInReservationRequest;
use App\Http\Requests\Hotel\CheckoutReservationRequest;
use App\Http\Requests\Hotel\StoreReservationItemCheckRequest;
use App\Http\Requests\Hotel\StoreReservationRequest;
use App\Http\Requests\Hotel\UpdateReservationBillToRequest;
use App\Http\Requests\Hotel\UpdateReservationRequest;
use App\Models\Hotel\GroupBooking;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\ReservationRoom;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomItemCheck;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Hotel\BillingService;
use App\Services\Hotel\ReservationAvailabilityService;
use App\Services\Hotel\ReservationService;
use App\Services\Hotel\RoomPricingService;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\ReservationStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availability,
        private readonly RoomPricingService $pricing,
        private readonly BillingService $billing,
        private readonly ReservationService $reservations,
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ]);

        $checkIn = Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = Carbon::parse($data['check_out'])->startOfDay();
        $nightList = $this->pricing->nights($checkIn, $checkOut);

        $rooms = $this->availability->availableRooms($checkIn, $checkOut)->map(function (Room $room) use ($nightList) {
            $perNight = collect($nightList)->map(fn (string $date) => [
                'date' => $date,
                'rate' => $this->pricing->nightlyRate($room->roomType, Carbon::parse($date)),
            ]);

            return [
                'id' => $room->id,
                'number' => $room->number,
                'room_type' => ['id' => $room->room_type_id, 'name' => $room->roomType->name, 'max_occupancy' => $room->roomType->max_occupancy],
                'nights' => $perNight,
                'stay_total' => $perNight->sum('rate'),
            ];
        });

        return response()->json(['rooms' => $rooms->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Reservation::query()->with([
            'guest:id,name,phone,loyalty_points', 'status', 'channel',
            'rooms.room:id,number', 'package:id,code,name',
            'groupBooking:id,reference,name', 'corporateAccount:id,company_name',
        ])->orderByDesc('check_in');

        if ($status = $request->string('status')->toString()) {
            $query->statusCode($status);
        }

        if ($term = $request->string('q')->toString()) {
            $query->where(function (Builder $q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhereHas('guest', fn (Builder $g) => $g->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('rooms.room', fn (Builder $r) => $r->where('number', 'like', "%{$term}%"));
            });
        }

        if ($request->has('page')) {
            return response()->json(['reservations' => $query->paginate($request->integer('page_size', 20))->withQueryString()]);
        }

        return response()->json(['reservations' => $query->limit(200)->get()]);
    }

    public function groups(): JsonResponse
    {
        return response()->json(['groups' => GroupBooking::query()
            ->with([
                'reservations.guest:id,name',
                'reservations.status',
                'reservations.rooms.room:id,number',
                'reservations.rooms.billToGuest:id,name',
                'reservations.folio:id,reservation_id,folio_status_id',
                'reservations.folio.status',
            ])
            ->latest()
            ->get(),
        ]);
    }

    /** Consolidated group invoice — one reference, all rooms/charges together. */
    public function groupInvoice(GroupBooking $groupBooking): JsonResponse
    {
        $groupBooking->load(['reservations.folio', 'reservations.guest', 'reservations.rooms.room', 'reservations.rooms.billToGuest']);

        $folios = $groupBooking->reservations
            ->filter(fn (Reservation $r) => $r->folio)
            ->map(fn (Reservation $r) => $this->billing->present($r->folio));

        return response()->json([
            'group' => [
                'id' => $groupBooking->id, 'reference' => $groupBooking->reference,
                'name' => $groupBooking->name, 'contact_name' => $groupBooking->contact_name,
            ],
            'folios' => $folios->values(),
            'grand_total' => $folios->sum('total'),
            'total_paid' => $folios->sum('paid'),
            'balance' => $folios->sum('balance'),
        ]);
    }

    /** Calendar/tape-chart feed: reservations overlapping [from, to). */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        $rows = Reservation::query()
            ->statusIn([
                ReservationStatus::PENDING, ReservationStatus::CONFIRMED,
                ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT,
            ])
            ->where('check_in', '<', $data['to'])
            ->where('check_out', '>', $data['from'])
            ->with(['guest:id,name', 'status', 'rooms:id,reservation_id,room_id', 'groupBooking:id,reference'])
            ->orderBy('check_in')
            ->get();

        return response()->json(['reservations' => $rows->map(fn (Reservation $r) => [
            'id' => $r->id, 'code' => $r->code, 'status' => $r->status->code,
            'check_in' => $r->check_in, 'check_out' => $r->check_out,
            'guest' => $r->guest->name, 'group' => $r->groupBooking?->reference,
            'room_ids' => $r->rooms->pluck('room_id'),
        ])]);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load([
            'guest', 'package', 'groupBooking', 'corporateAccount', 'status', 'channel',
            'rooms.room.roomType', 'rooms.room.status', 'rooms.billToGuest:id,name',
            'roomItemChecks' => fn ($q) => $q->latest(),
            'roomItemChecks.kind',
        ]);

        $folio = $reservation->folio;

        return response()->json([
            'reservation' => $reservation,
            'folio' => $folio ? $this->billing->present($folio) : null,
        ]);
    }

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservations->create($request->validated(), $request->user()->id);

        return response()->json([
            'message' => "Reservation \"{$reservation->code}\" created.",
            'reservation' => $reservation,
        ], 201);
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $reservation->update($request->validated());

        AuditLog::record('reservation.updated', $reservation, ['code' => $reservation->code]);

        return response()->json(['message' => 'Reservation updated.', 'reservation' => $reservation]);
    }

    /** Group option: bill an individual room's charges to a specific guest. */
    public function billTo(UpdateReservationBillToRequest $request, ReservationRoom $reservationRoom): JsonResponse
    {
        $reservationRoom->update($request->validated());

        return response()->json(['message' => 'Bill-to guest updated.', 'reservation_room' => $reservationRoom]);
    }

    public function checkIn(CheckInReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $folio = $this->reservations->checkIn($reservation, $request->validated(), $request->user()->id);

        return response()->json(['folio' => $folio]);
    }

    /** Preview: consolidated bill incl. VAT + service charge as separate lines. */
    public function checkoutQuote(Request $request, Reservation $reservation): JsonResponse
    {
        return response()->json($this->reservations->checkoutQuote($reservation, $request->boolean('late')));
    }

    public function checkout(CheckoutReservationRequest $request, Reservation $reservation): JsonResponse
    {
        return response()->json($this->reservations->checkout($reservation, $request->validated(), $request->user()->id));
    }

    /** Cancellation — refund policy from Settings enforced automatically. */
    public function cancel(CancelReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->reservations->cancel(
            $reservation, $data['reason'], $data['refund_method'] ?? PaymentMethod::CASH, $request->user()->id,
        ));
    }

    /** Standalone room item check (either kind) — e.g. re-verify during stay. */
    public function itemCheck(StoreReservationItemCheckRequest $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validated();

        $check = RoomItemCheck::create([
            'reservation_id' => $reservation->id,
            'room_id' => $data['room_id'],
            'check_kind_id' => Lookup::id(LookupType::CHECK_KIND, $data['kind']),
            'items' => $data['items'],
            'staff_id' => $request->user()->id,
        ]);

        return response()->json(['item_check' => $check], 201);
    }
}
