<?php

namespace App\Services\Hotel\Pdf;

use App\Models\Hotel\Folio;
use App\Models\Hotel\Order;
use App\Models\Hotel\PayrollLine;
use App\Services\Hotel\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Branded documents — two formats everywhere a document supports both:
 * "thermal" (80mm bill printer) and "a4". Ported from the Node app's
 * lib/pdf.ts (PDFKit, drawn programmatically) onto dompdf (HTML/Blade),
 * same information and layout intent, rendered via resources/views/hotel/pdf/*.
 *
 * Every document can also be rendered as plain HTML ($asHtml = true) instead
 * of a dompdf PDF — the browser's own print dialog (window.print(), see
 * web/src/lib/api.ts printDocument()) is far more reliable across browsers
 * than scripting into an embedded PDF viewer. The Blade views are unchanged;
 * only `medium` (passed alongside `format`) tells layout.blade.php which CSS
 * page box to emit.
 */
class PdfService
{
    private const THERMAL_PAPER = [0, 0, 226, 1400];

    public function __construct(private readonly BillingService $billing) {}

    public function orderReceipt(Order $order, string $format, bool $asHtml = false): Response
    {
        $order->load(['items', 'payments.method', 'payments.kind', 'staff:id,name', 'room:id,number', 'type', 'diningMode']);

        return $this->render('hotel.pdf.order-receipt', ['order' => $order], $format, "receipt-{$order->id}", $asHtml);
    }

    /** Walk-in double slip: bill + numbered collection token, one thermal print. */
    public function orderSlip(Order $order, bool $asHtml = false): Response
    {
        $order->load(['items', 'payments.kind', 'staff:id,name', 'room:id,number', 'type', 'diningMode']);

        return $this->render('hotel.pdf.order-slip', ['order' => $order], 'thermal', "order-slip-{$order->id}", $asHtml);
    }

    /** Kitchen Order Ticket — kitchen-only layout: no prices, grouped by station, KOT banner. */
    public function kotTicket(Order $order, bool $asHtml = false): Response
    {
        $order->load(['items.menuItem.category.kitchenStation', 'room:id,number', 'diningTable:id,table_no', 'type', 'diningMode']);

        return $this->render('hotel.pdf.kot-ticket', ['order' => $order], 'thermal', "kot-{$order->id}", $asHtml);
    }

    /** Guest stay (INV-…) or venue event (VNU-…) invoice — ?format=thermal|a4. */
    public function folioInvoice(Folio $folio, string $format, bool $asHtml = false): Response
    {
        $folio->loadMissing([
            'type', 'status',
            'lines' => fn ($q) => $q->notVoided()->oldest()->with('source'),
            'payments' => fn ($q) => $q->oldest()->with(['method', 'kind']),
            'reservation.guest', 'reservation.rooms.room', 'venueBooking.venue',
        ]);
        $totals = $this->billing->totals($folio);

        return $this->render('hotel.pdf.folio-invoice', ['folio' => $folio, 'totals' => $totals], $format, $folio->invoice_no ?? 'proforma', $asHtml);
    }

    public function payslip(PayrollLine $line, bool $asHtml = false): Response
    {
        $line->load(['user.roles', 'run']);
        $safeName = preg_replace('/\W+/', '-', $line->user->name);

        return $this->render('hotel.pdf.payslip', ['line' => $line], 'a4', "payslip-{$line->run->month}-{$safeName}", $asHtml);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{title: string, run_by?: string|null}  $meta
     */
    public function dailyReport(array $data, array $meta, bool $asHtml = false): Response
    {
        return $this->render('hotel.pdf.daily-report', ['data' => $data, 'meta' => $meta], 'a4', "report-{$data['date']}", $asHtml);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function monthlyReport(array $data, bool $asHtml = false): Response
    {
        return $this->render('hotel.pdf.monthly-report', ['data' => $data], 'a4', "monthly-report-{$data['month']}", $asHtml);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function posReport(array $data, bool $asHtml = false): Response
    {
        return $this->render('hotel.pdf.pos-report', ['data' => $data], 'a4', "pos-report-{$data['from']}_{$data['to']}", $asHtml);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data, string $format, string $filename, bool $asHtml): Response
    {
        if ($asHtml) {
            return response()->view($view, [...$data, 'format' => $format, 'medium' => 'browser']);
        }

        return Pdf::loadView($view, [...$data, 'format' => $format, 'medium' => 'pdf'])
            ->setPaper($format === 'thermal' ? self::THERMAL_PAPER : 'a4')
            ->stream("{$filename}.pdf");
    }
}
