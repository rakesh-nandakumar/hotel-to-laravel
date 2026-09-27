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
        $query = VenueBooking::query()->with(['venue:id,name,max_capacity', 'folio', 'folio.status', 'folio.lines', 'folio.payments.kind', 'folio.payments.method', 'status'])->orderBy('date');

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

    public function recordAdvancePayment(Request $request, VenueBooking $booking): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'method' => 'required|string',
        ]);

        return response()->json($this->bookings->recordAdvancePayment(
            $booking, $validated['amount'], $validated['method'], $request->user()->id,
        ));
    }

    private function withFolioTotals(VenueBooking $booking): VenueBooking
    {
        try {
            if ($booking->folio) {
                $totals = $this->billing->totals($booking->folio);
                $booking->setAttribute('total', $totals['total']);
                $booking->setAttribute('paid', $totals['paid'] - $totals['refunded']);
                $booking->setAttribute('balance', $totals['balance']);
            } else {
                $booking->setAttribute('total', 0)->setAttribute('paid', 0)->setAttribute('balance', 0);
            }
        } catch (\Exception $e) {
            $booking->setAttribute('total', 0)->setAttribute('paid', 0)->setAttribute('balance', 0);
        }

        return $booking;
    }

    public function print(Request $request, VenueBooking $booking)
    {
        $booking->load(['venue', 'status', 'extraCharges']);
        $format = $request->get('format', 'a4');

        if ($request->get('output') === 'html') {
            return view('hotel.pdf.venue-booking', compact('booking', 'format'));
        }

        return response()->streamDownload(function () use ($booking, $format) {
            echo view('hotel.pdf.venue-booking', compact('booking', 'format'))->render();
        }, "venue-booking-{$booking->code}.html");
    }
}
