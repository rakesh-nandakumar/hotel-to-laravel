<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\CancelVenueBookingRequest;
use App\Http\Requests\Hotel\StoreVenueBookingRequest;
use App\Http\Requests\Hotel\UpdateVenueBookingRequest;
use App\Models\Hotel\VenueBooking;
use App\Services\Hotel\BillingService;
use App\Services\Hotel\VenueBookingService;
use App\Support\Lookups\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueBookingController extends Controller
{
    public function __construct(
        private readonly VenueBookingService $bookings,
        private readonly BillingService $billing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = VenueBooking::query()->with(['venue:id,name,max_capacity', 'folio:id,folio_status_id,invoice_no', 'folio.status', 'status'])->orderBy('date');

        if ($request->has('page')) {
            $paginated = $query->paginate($request->integer('page_size', 25))->withQueryString();
            $paginated->getCollection()->transform(fn (VenueBooking $b) => $this->withFolioTotals($b));

            return response()->json(['bookings' => $paginated]);
        }

        $bookings = $query->get()->map(fn (VenueBooking $b) => $this->withFolioTotals($b));

        return response()->json(['bookings' => $bookings]);
    }

    public function store(StoreVenueBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->createBooking($request->validated(), $request->user()->id);

        return response()->json(['message' => "Venue booking \"{$booking->code}\" created.", 'booking' => $booking], 201);
    }

    public function show(VenueBooking $booking): JsonResponse
    {
        $booking->load(['venue', 'guest', 'status', 'durationType']);
        $folio = $booking->folio;

        return response()->json([
            'booking' => $booking,
            'folio' => $folio ? $this->billing->present($folio) : null,
        ]);
    }

    public function update(UpdateVenueBookingRequest $request, VenueBooking $booking): JsonResponse
    {
        return response()->json(['message' => 'Venue booking updated.', 'booking' => $this->bookings->updateBooking($booking, $request->validated())]);
    }

    public function confirm(Request $request, VenueBooking $booking): JsonResponse
    {
        return response()->json(['booking' => $this->bookings->confirmBooking($booking, $request->user()->id)]);
    }

    public function complete(Request $request, VenueBooking $booking): JsonResponse
    {
        return response()->json(['invoice_no' => $this->bookings->completeBooking($booking, $request->user()->id)]);
    }

    public function cancel(CancelVenueBookingRequest $request, VenueBooking $booking): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->bookings->cancelBooking(
            $booking, $data['reason'], $data['refund_method'] ?? PaymentMethod::CASH, $request->user()->id,
        ));
    }

    private function withFolioTotals(VenueBooking $booking): VenueBooking
    {
        if ($booking->folio) {
            $totals = $this->billing->totals($booking->folio);
            $booking->setAttribute('total', $totals['total']);
            $booking->setAttribute('paid', $totals['paid'] - $totals['refunded']);
            $booking->setAttribute('balance', $totals['balance']);
        } else {
            $booking->setAttribute('total', 0)->setAttribute('paid', 0)->setAttribute('balance', 0);
        }

        return $booking;
    }
}
