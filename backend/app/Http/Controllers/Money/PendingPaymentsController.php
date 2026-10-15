<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Models\Hotel\Folio;
use App\Models\Hotel\Notification;
use App\Models\Hotel\Payment;
use App\Models\Hotel\Reservation;
use App\Models\Lookup;
use App\Services\TillService;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\TillMovementType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PendingPaymentsController extends Controller
{
    public function __construct(
        private readonly TillService $till,
    ) {}

    public function index(Request $request): View
    {
        $query = Folio::query()
            ->with(['type', 'status', 'reservation', 'venueBooking', 'payments'])
            ->whereHas('type', fn ($q) => $q->where('code', 'guest'))
            ->whereHas('status', fn ($q) => $q->where('code', 'active'))
            ->whereNull('settled_at')
            ->orderBy('created_at', 'desc');

        // Filter by payment method if specified
        if ($request->filled('payment_method')) {
            $query->whereHas('payments', fn ($q) => $q->where('payment_method', $request->payment_method));
        }

        $folios = $query->get();

        // Calculate balance due for each folio
        $pendingPayments = $folios->map(function ($folio) {
            $totalCharges = $folio->lines()->where('voided', false)->sum('amount');
            $totalPayments = $folio->payments()->sum('amount');
            $balanceDue = $totalCharges - $totalPayments;

            // Only include if there's a balance due
            if ($balanceDue <= 0) {
                return null;
            }

            return [
                'id' => $folio->id,
                'invoice_no' => $folio->invoice_no,
                'type' => $folio->type->name ?? 'Guest',
                'guest_name' => $folio->reservation?->guest_name ?? $folio->venueBooking?->client_name ?? 'Unknown',
                'reservation_code' => $folio->reservation?->code,
                'venue_booking_code' => $folio->venueBooking?->code,
                'total_charges' => $totalCharges,
                'total_payments' => $totalPayments,
                'balance_due' => $balanceDue,
                'credit_payments' => $folio->payments()->where('payment_method', 'CREDIT')->sum('amount'),
                'created_at' => $folio->created_at,
            ];
        })->filter();

        return view('money.pending-payments.index', [
            'pendingPayments' => $pendingPayments,
            'paymentMethods' => Payment::distinct()->pluck('payment_method'),
        ]);
    }

    public function creditPaymentsCount(): JsonResponse
    {
        $count = Notification::where('type', 'credit_payment')->count();

        return response()->json(['count' => $count]);
    }

    public function fulfillCreditPayment(Request $request): JsonResponse
    {
        $notificationId = $request->input('notification_id');
        $paymentMethod = $request->input('payment_method');
        $reference = $request->input('reference');

        $notification = Notification::findOrFail($notificationId);

        if ($notification->type !== 'credit_payment') {
            return response()->json(['error' => 'Not a credit payment notification'], 400);
        }

        if ($notification->ref_type !== 'reservation') {
            return response()->json(['error' => 'Notification does not reference a reservation'], 400);
        }

        $reservation = Reservation::findOrFail($notification->ref_id);

        if (! $reservation->folio) {
            return response()->json(['error' => 'Reservation has no folio - cannot fulfill credit payment. The reservation may not have been checked out yet.'], 400);
        }

        // Find CREDIT payments for this reservation's folio
        $allPayments = Payment::where('folio_id', $reservation->folio->id)
            ->with('method')
            ->get();

        $creditPayment = null;
        foreach ($allPayments as $payment) {
            if ($payment->method && strtoupper($payment->method->code) === 'CREDIT') {
                $creditPayment = $payment;
                break;
            }
        }

        if (! $creditPayment) {
            return response()->json(['error' => 'This reservation was not paid with CREDIT. Please check out a reservation with CREDIT payment method to create a credit payment notification.'], 400);
        }

        // Update the CREDIT payment to the actual payment method
        $creditPayment->update([
            'payment_method_id' => Lookup::id(LookupType::PAYMENT_METHOD, strtolower($paymentMethod)),
            'reference' => $reference,
            'staff_id' => auth()->id(),
        ]);

        // If the new payment method is CASH, record the till movement now
        if (strtolower($paymentMethod) === PaymentMethod::CASH) {
            $tillSession = $this->till->currentSessionForStaff(auth()->id());
            if ($tillSession) {
                $this->till->recordMovement(
                    $tillSession,
                    TillMovementType::CASH_IN,
                    $creditPayment->amount,
                    auth()->id(),
                    reason: 'Credit payment fulfilled',
                    source: $creditPayment,
                );
            }
        }

        // Delete the notification after fulfillment
        $notification->delete();

        return response()->json(['success' => true]);
    }
}
