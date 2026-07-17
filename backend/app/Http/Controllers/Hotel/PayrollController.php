<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\GeneratePayrollRunRequest;
use App\Http\Requests\Hotel\UpdatePayrollLineRequest;
use App\Http\Requests\Hotel\UpdateStaffPayRequest;
use App\Models\Hotel\PayrollLine;
use App\Models\Hotel\PayrollRun;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Hotel\PayrollService;
use App\Services\Hotel\Pdf\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly PdfService $pdf) {}

    public function staffPay(): JsonResponse
    {
        $staff = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'base_salary', 'ot_hourly_rate', 'monthly_allowance', 'epf_enabled', 'epf_number']);

        return response()->json(['staff' => $staff]);
    }

    public function updateStaffPay(UpdateStaffPayRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        AuditLog::record('payroll.salary_updated', $user, ['name' => $user->name]);

        return response()->json(['ok' => true]);
    }

    public function runs(Request $request): JsonResponse
    {
        $query = PayrollRun::query()->with(['runBy:id,name', 'status', 'lines:id,run_id,net_pay,paid'])->orderByDesc('month');

        if ($request->has('page')) {
            $paginated = $query->paginate($request->integer('page_size', 25))->withQueryString();
            $paginated->getCollection()->transform(fn (PayrollRun $r) => $this->withRunTotals($r));

            return response()->json(['runs' => $paginated]);
        }

        return response()->json(['runs' => $query->get()->map(fn (PayrollRun $r) => $this->withRunTotals($r))]);
    }

    public function generateRun(GeneratePayrollRunRequest $request): JsonResponse
    {
        $run = $this->payroll->generateRun($request->validated('month'), $request->user()->id);

        return response()->json(['run' => $run], 201);
    }

    public function showRun(PayrollRun $run): JsonResponse
    {
        $run->load([
            'runBy:id,name', 'status',
            'lines' => fn ($q) => $q->with(['user:id,name,epf_number,ot_hourly_rate,epf_enabled', 'user.roles:id,name']),
        ]);

        return response()->json(['run' => $run]);
    }

    public function deleteRun(PayrollRun $run): JsonResponse
    {
        $this->payroll->deleteRun($run);

        return response()->json(['ok' => true]);
    }

    public function updateLine(UpdatePayrollLineRequest $request, PayrollLine $line): JsonResponse
    {
        $data = $request->validated();

        $line = $this->payroll->updateLine($line, $data['ot_hours'] ?? null, $data['bonus'] ?? null, $data['deduction'] ?? null, $data['deduction_note'] ?? null);

        return response()->json(['line' => $line]);
    }

    public function finalizeRun(PayrollRun $run): JsonResponse
    {
        return response()->json(['run' => $this->payroll->finalizeRun($run)]);
    }

    public function markLinePaid(PayrollLine $line): JsonResponse
    {
        return response()->json(['line' => $this->payroll->markLinePaid($line)]);
    }

    /** CSV export of a run. */
    public function exportRun(PayrollRun $run): Response
    {
        $run->load(['lines.user:id,name,epf_number']);
        $money = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        $rows = ['Staff,Role,EPF No,Worked Hrs,OT Hrs,Basic,OT Pay,Allowance,Bonus,Gross,EPF 8%,Deduction,Net Pay,EPF 12% (employer),ETF 3% (employer),Paid'];
        foreach ($run->lines as $line) {
            $rows[] = "\"{$line->user->name}\",,".($line->user->epf_number ?? '').",{$line->worked_hours},{$line->ot_hours},"
                .$money($line->base_salary).','.$money($line->ot_pay).','.$money($line->allowance).','.$money($line->bonus).','
                .$money($line->gross).','.$money($line->epf_employee).','.$money($line->deduction).','.$money($line->net_pay).','
                .$money($line->epf_employer).','.$money($line->etf).','.($line->paid ? 'YES' : 'no');
        }

        return response(implode("\n", $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=payroll-{$run->month}.csv",
        ]);
    }

    /** Branded payslip PDF (A4). */
    public function payslip(PayrollLine $line): Response
    {
        return $this->pdf->payslip($line);
    }

    private function withRunTotals(PayrollRun $run): PayrollRun
    {
        $run->setAttribute('total_net', (int) $run->lines->sum('net_pay'));
        $run->setAttribute('paid_count', $run->lines->where('paid', true)->count());
        $run->setAttribute('line_count', $run->lines->count());
        $run->unsetRelation('lines');

        return $run;
    }
}
