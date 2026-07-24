<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\CancelBookingRequest;
use App\Http\Requests\Apartment\CheckInBookingRequest;
use App\Http\Requests\Apartment\CheckoutBookingRequest;
use App\Http\Requests\Apartment\StoreBookingRequest;
use App\Models\Apartment\Booking;
use App\Models\Apartment\Unit;
use App\Services\Apartment\ApartmentAvailabilityService;
use App\Services\Apartment\ApartmentBillingService;
use App\Services\Apartment\ApartmentBookingService;
use App\Services\Apartment\ApartmentPricingService;
use App\Support\Lookups\ApartmentBookingStatus;
use App\Support\Lookups\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private readonly ApartmentAvailabilityService $availability,
        private readonly ApartmentPricingService $pricing,
        private readonly ApartmentBillingService $billing,
        private readonly ApartmentBookingService $bookings,
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
        ]);

        $checkIn = Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = Carbon::parse($data['check_out'])->startOfDay();

        $units = $this->availability->availableUnits($checkIn, $checkOut)->map(function (Unit $unit) use ($checkIn, $checkOut) {
            try {
                $rate = $this->pricing->resolveStayRate($unit->unitType, $checkIn, $checkOut);
            } catch (ValidationException) {
                return null;
            }

            return [
                'id' => $unit->id,
                'unit_no' => $unit->unit_no,
                'property' => $unit->property?->name,
                'unit_type' => ['id' => $unit->unit_type_id, 'name' => $unit->unitType->name, 'max_occupancy' => $unit->unitType->max_occupancy],
                'nightly_rate' => $rate['nightly_rate'],
                'rate_basis' => $rate['rate_basis'],
                'stay_total' => $rate['stay_total'],
                'night_count' => $rate['night_count'],
            ];
        })->filter()->values();

        return response()->json(['units' => $units]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Booking::query()->with([
            'customer:id,name,phone', 'status', 'channel', 'unit:id,unit_no',
        ])->orderByDesc('check_in');

        if ($status = $request->string('status')->toString()) {
            $query->statusCode($status);
        }

        if ($term = $request->string('q')->toString()) {
            $query->where(function (Builder $q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('unit', fn (Builder $u) => $u->where('unit_no', 'like', "%{$term}%"));
            });
        }

        if ($request->has('page')) {
            return response()->json(['bookings' => $query->paginate($request->integer('page_size', 20))->withQueryString()]);
        }

        return response()->json(['bookings' => $query->limit(200)->get()]);
    }

    /** Calendar feed: bookings overlapping [from, to). */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        $rows = Booking::query()
            ->statusIn([
                ApartmentBookingStatus::PENDING, ApartmentBookingStatus::CONFIRMED,
                ApartmentBookingStatus::CHECKED_IN, ApartmentBookingStatus::CHECKED_OUT,
            ])
            ->where('check_in', '<', $data['to'])
            ->where('check_out', '>', $data['from'])
            ->with(['customer:id,name', 'status', 'unit:id,unit_no'])
            ->orderBy('check_in')
            ->get();

        return response()->json(['bookings' => $rows->map(fn (Booking $b) => [
            'id' => $b->id, 'code' => $b->code, 'status' => $b->status->code,
            'check_in' => $b->check_in, 'check_out' => $b->check_out,
            'customer' => $b->customer->name, 'unit_id' => $b->unit_id, 'unit_no' => $b->unit->unit_no,
        ])]);
    }

    public function show(Booking $booking): JsonResponse
    {
        $booking->load(['customer', 'status', 'channel', 'unit.unitType', 'unit.status', 'unit.property']);

        $ledger = $booking->ledger;

        return response()->json([
            'booking' => $booking,
            'ledger' => $ledger ? $this->billing->present($ledger) : null,
        ]);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->create($request->validated(), $request->user()->id);

        return response()->json(['message' => "Booking \"{$booking->code}\" created.", 'booking' => $booking], 201);
    }

    public function checkIn(CheckInBookingRequest $request, Booking $booking): JsonResponse
    {
        $ledger = $this->bookings->checkIn($booking, $request->validated(), $request->user()->id);

        return response()->json(['ledger' => $ledger]);
    }

    public function checkoutQuote(Request $request, Booking $booking): JsonResponse
    {
        return response()->json($this->bookings->checkoutQuote($booking, $request->boolean('late')));
    }

    public function checkout(CheckoutBookingRequest $request, Booking $booking): JsonResponse
    {
        return response()->json($this->bookings->checkout($booking, $request->validated(), $request->user()->id));
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->bookings->cancel(
            $booking, $data['reason'], $data['refund_method'] ?? PaymentMethod::CASH, $request->user()->id,
        ));
    }
}
