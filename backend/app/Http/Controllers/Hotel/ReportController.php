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

    /** Branded A4 PDF of the daily report. */
    public function dailyPdf(Request $request): Response
    {
        $date = $request->query('date', today()->toDateString());

        return $this->pdf->dailyReport($this->reports->computeDaily($date), ['title' => 'DAILY OPERATIONS REPORT']);
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

    /** Branded A4 PDF of a stored night-audit snapshot. */
    public function nightAuditPdf(NightAudit $nightAudit): Response
    {
        $nightAudit->loadMissing('runBy:id,name');

        return $this->pdf->dailyReport($nightAudit->data, ['title' => 'NIGHT AUDIT SNAPSHOT', 'run_by' => $nightAudit->runBy->name]);
    }

    /** Monthly performance: per-day revenue + occupancy. */
    public function monthly(Request $request): JsonResponse
    {
        return response()->json($this->reports->computeMonthly($request->query('month', today()->format('Y-m'))));
    }

    /** Branded A4 PDF of the monthly report. */
    public function monthlyPdf(Request $request): Response
    {
        return $this->pdf->monthlyReport($this->reports->computeMonthly($request->query('month', today()->format('Y-m'))));
    }

    /** POS sales report for a range: category totals, best sellers, method breakdown. */
    public function pos(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(6)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computePos($from, $to));
    }

    /** Branded A4 PDF of the POS sales report. */
    public function posPdf(Request $request): Response
    {
        $from = $request->query('from', today()->subDays(6)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return $this->pdf->posReport($this->reports->computePos($from, $to));
    }
}
