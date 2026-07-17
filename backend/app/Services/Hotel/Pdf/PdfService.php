<?php

namespace App\Services\Hotel\Pdf;

use App\Models\Hotel\Folio;
use App\Models\Hotel\Order;
use App\Models\Hotel\PayrollLine;
use App\Services\Hotel\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Branded PDF documents — two formats everywhere a document supports both:
 * "thermal" (80mm bill printer) and "a4". Ported from the Node app's
 * lib/pdf.ts (PDFKit, drawn programmatically) onto dompdf (HTML/Blade),
 * same information and layout intent, rendered via resources/views/hotel/pdf/*.
 */
class PdfService
{
    private const THERMAL_PAPER = [0, 0, 226, 1400];

    public function __construct(private readonly BillingService $billing) {}

    public function orderReceipt(Order $order, string $format): Response
    {
        $order->load(['items', 'payments.method', 'payments.kind', 'staff:id,name', 'room:id,number', 'type', 'diningMode']);

        return Pdf::loadView('hotel.pdf.order-receipt', ['order' => $order, 'format' => $format])
            ->setPaper($format === 'thermal' ? self::THERMAL_PAPER : 'a4')
            ->stream("receipt-{$order->id}.pdf");
    }

    /** Walk-in double slip: bill + numbered collection token, one thermal print. */
    public function orderSlip(Order $order): Response
    {
        $order->load(['items', 'payments.kind', 'staff:id,name', 'room:id,number', 'type', 'diningMode']);

        return Pdf::loadView('hotel.pdf.order-slip', ['order' => $order, 'format' => 'thermal'])
            ->setPaper(self::THERMAL_PAPER)
            ->stream("order-slip-{$order->id}.pdf");
    }

    public function kotTicket(Order $order): Response
    {
        $order->load(['items', 'room:id,number', 'type', 'diningMode']);

        return Pdf::loadView('hotel.pdf.kot-ticket', ['order' => $order, 'format' => 'thermal'])
            ->setPaper(self::THERMAL_PAPER)
            ->stream("kot-{$order->id}.pdf");
    }

    /** Guest stay (INV-…) or venue event (VNU-…) invoice — ?format=thermal|a4. */
    public function folioInvoice(Folio $folio, string $format): Response
    {
        $folio->loadMissing([
            'type', 'status',
            'lines' => fn ($q) => $q->notVoided()->oldest()->with('source'),
            'payments' => fn ($q) => $q->oldest()->with(['method', 'kind']),
            'reservation.guest', 'reservation.rooms.room', 'venueBooking.venue',
        ]);
        $totals = $this->billing->totals($folio);

        return Pdf::loadView('hotel.pdf.folio-invoice', ['folio' => $folio, 'totals' => $totals, 'format' => $format])
            ->setPaper($format === 'thermal' ? self::THERMAL_PAPER : 'a4')
            ->stream(($folio->invoice_no ?? 'proforma').'.pdf');
    }

    public function payslip(PayrollLine $line): Response
    {
        $line->load(['user.roles', 'run']);
        $safeName = preg_replace('/\W+/', '-', $line->user->name);

        return Pdf::loadView('hotel.pdf.payslip', ['line' => $line, 'format' => 'a4'])
            ->setPaper('a4')
            ->stream("payslip-{$line->run->month}-{$safeName}.pdf");
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{title: string, run_by?: string|null}  $meta
     */
    public function dailyReport(array $data, array $meta): Response
    {
        return Pdf::loadView('hotel.pdf.daily-report', ['data' => $data, 'meta' => $meta, 'format' => 'a4'])
            ->setPaper('a4')
            ->stream("report-{$data['date']}.pdf");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function monthlyReport(array $data): Response
    {
        return Pdf::loadView('hotel.pdf.monthly-report', ['data' => $data, 'format' => 'a4'])
            ->setPaper('a4')
            ->stream("monthly-report-{$data['month']}.pdf");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function posReport(array $data): Response
    {
        return Pdf::loadView('hotel.pdf.pos-report', ['data' => $data, 'format' => 'a4'])
            ->setPaper('a4')
            ->stream("pos-report-{$data['from']}_{$data['to']}.pdf");
    }
}
