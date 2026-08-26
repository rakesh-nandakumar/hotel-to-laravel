<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\RunNightAuditRequest;
use App\Models\Hotel\NightAudit;
use App\Services\Hotel\Pdf\PdfService;
use App\Services\Hotel\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports, private readonly PdfService $pdf) {}

    /** Live owner dashboard — room status, today's arrivals/departures, today's revenue. */
    public function dashboard(): JsonResponse
    {
        return response()->json($this->reports->dashboard());
    }

    public function daily(Request $request): JsonResponse
    {
        return response()->json($this->reports->computeDaily($request->query('date', today()->toDateString())));
    }

    /** Branded A4 daily report. ?output=html|pdf. */
    public function dailyPdf(Request $request): Response
    {
        $date = $request->query('date', today()->toDateString());

        return $this->pdf->dailyReport($this->reports->computeDaily($date), ['title' => 'DAILY OPERATIONS REPORT'], $request->query('output') === 'html');
    }

    /** Night audit: computes + permanently stores the day's snapshot. */
    public function runNightAudit(RunNightAuditRequest $request): JsonResponse
    {
        $nightAudit = $this->reports->runNightAudit($request->validated('date'), $request->user()->id);

        return response()->json($nightAudit, 201);
    }

    public function nightAuditIndex(Request $request): JsonResponse
    {
        $query = NightAudit::query()->with('runBy:id,name')->latest('business_date');

        if ($request->has('page')) {
            return response()->json(['night_audits' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['night_audits' => $query->limit(60)->get()]);
    }

    /** Branded A4 night-audit snapshot. ?output=html|pdf. */
    public function nightAuditPdf(Request $request, NightAudit $nightAudit): Response
    {
        $nightAudit->loadMissing('runBy:id,name');

        return $this->pdf->dailyReport(
            $nightAudit->data,
            ['title' => 'NIGHT AUDIT SNAPSHOT', 'run_by' => $nightAudit->runBy->name],
            $request->query('output') === 'html',
        );
    }

    /** Monthly performance: per-day revenue + occupancy. */
    public function monthly(Request $request): JsonResponse
    {
        return response()->json($this->reports->computeMonthly($request->query('month', today()->format('Y-m'))));
    }

    /** Branded A4 monthly report. ?output=html|pdf. */
    public function monthlyPdf(Request $request): Response
    {
        return $this->pdf->monthlyReport(
            $this->reports->computeMonthly($request->query('month', today()->format('Y-m'))),
            $request->query('output') === 'html',
        );
    }

    /** POS sales report for a range: category totals, best sellers, method breakdown. */
    public function pos(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(6)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computePos($from, $to));
    }

    /** Branded A4 POS sales report. ?output=html|pdf. */
    public function posPdf(Request $request): Response
    {
        $from = $request->query('from', today()->subDays(6)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return $this->pdf->posReport($this->reports->computePos($from, $to), $request->query('output') === 'html');
    }

    /** RevPAR / ADR / occupancy over a date range. */
    public function revpar(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeRevPar($from, $to));
    }

    /** Reservations & revenue by booking channel. */
    public function channelMix(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeChannelMix($from, $to));
    }

    /** Cancelled reservations & no-shows over a date range. */
    public function cancellations(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeCancellations($from, $to));
    }

    /** Top guests by spend, repeat-guest rate, loyalty points issued/redeemed. */
    public function guestLoyalty(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeGuestLoyalty($from, $to));
    }

    /** Corporate account AR snapshot — outstanding balance, aging, credit utilization. */
    public function corporateAr(): JsonResponse
    {
        return response()->json($this->reports->computeCorporateAr());
    }

    /** Housekeeping & maintenance SLA — counts by status, avg turnaround/resolution time. */
    public function opsSla(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeOpsSla($from, $to));
    }

    /** Payroll labor-cost breakdown for one month, plus a month-over-month trend. */
    public function payrollCost(Request $request): JsonResponse
    {
        return response()->json($this->reports->computePayrollCost($request->query('month', today()->format('Y-m'))));
    }

    /** Venue/banquet bookings & revenue over a date range. */
    public function venues(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeVenues($from, $to));
    }

    /** Laundry revenue over a date range. */
    public function laundry(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeLaundry($from, $to));
    }
}
