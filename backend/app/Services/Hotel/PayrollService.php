<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Attendance;
use App\Models\Hotel\PayrollLine;
use App\Models\Hotel\PayrollRun;
use App\Models\Lookup;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Settings;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PayrollStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll — Owner-only (Manager explicitly excluded). Flow: set salaries per
 * staff → generate a monthly DRAFT run (worked hours pulled from attendance,
 * OT auto-computed beyond standard hours) → adjust OT/bonus/deductions per
 * line → finalize → mark lines paid. Ported from the Node app's routes/payroll.ts.
 */
class PayrollService
{
    /**
     * Default APIT bands if the `payroll.apit_brackets` setting is somehow
     * missing — same Y/A 2025/2026 schedule seeded by SettingsSeeder.
     *
     * @var list<array{width: float|null, rate: float}>
     */
    private const DEFAULT_APIT_BRACKETS = [
        ['width' => 15_000_000, 'rate' => 0],
        ['width' => 100_000_000 / 12, 'rate' => 6],
        ['width' => 50_000_000 / 12, 'rate' => 18],
        ['width' => 50_000_000 / 12, 'rate' => 24],
        ['width' => 50_000_000 / 12, 'rate' => 30],
        ['width' => null, 'rate' => 36],
    ];

    /**
     * Gross Salary = Basic + OT + Allowance + Bonus − Unpaid Leave Deduction.
     * EPF (employee/employer) and ETF are all % of Gross (NOT basic — see
     * business-rule correction in payroll formula review). APIT is Sri
     * Lanka's progressive monthly tax, applied to Gross via {@see calculateApit()}.
     * Net Pay = Gross − EPF Employee − APIT − (Loan + Advance + Other Deduction).
     * Employer Cost = Gross + EPF Employer + ETF (informational, not deducted).
     *
     * @return array{ot_pay: int, gross: int, epf_employee: int, epf_employer: int, etf: int, apit: int, net_pay: int, employer_cost: int}
     */
    public function computeLine(
        int $baseSalary,
        float $otHours,
        int $allowance,
        int $bonus,
        int $unpaidLeaveDeduction,
        int $loan,
        int $advance,
        int $otherDeduction,
        int $otHourlyRate,
        bool $epfEnabled,
    ): array {
        $epfEmpPct = Settings::num('payroll.epf_employee_pct', 8);
        $epfErPct = Settings::num('payroll.epf_employer_pct', 12);
        $etfPct = Settings::num('payroll.etf_pct', 3);
        $apitBrackets = Settings::json('payroll.apit_brackets', self::DEFAULT_APIT_BRACKETS);

        $otPay = (int) round($otHours * $otHourlyRate);
        $gross = $baseSalary + $otPay + $allowance + $bonus - $unpaidLeaveDeduction;
        $epfEmployee = $epfEnabled ? (int) round($gross * $epfEmpPct / 100) : 0;
        $epfEmployer = $epfEnabled ? (int) round($gross * $epfErPct / 100) : 0;
        $etf = $epfEnabled ? (int) round($gross * $etfPct / 100) : 0;
        $apit = $this->calculateApit($gross, $apitBrackets);
        $netPay = $gross - $epfEmployee - $apit - $loan - $advance - $otherDeduction;
        $employerCost = $gross + $epfEmployer + $etf;

        return [
            'ot_pay' => $otPay, 'gross' => $gross, 'epf_employee' => $epfEmployee, 'epf_employer' => $epfEmployer,
            'etf' => $etf, 'apit' => $apit, 'net_pay' => $netPay, 'employer_cost' => $employerCost,
        ];
    }

    /**
     * Sri Lanka APIT — progressive monthly tax. Consumes `$gross` band by band
     * in order (each band's "width" is LKR cents taxed at "rate" %); the last
     * band's width is null, meaning "everything remaining" (unbounded top rate).
     * Only the final total is rounded — matches how ot_pay/EPF round only once,
     * avoiding compounding rounding error across bands.
     *
     * @param  list<array{width: float|null, rate: float}>  $brackets
     */
    private function calculateApit(int $gross, array $brackets): int
    {
        $remaining = (float) $gross;
        $tax = 0.0;

        foreach ($brackets as $band) {
            if ($remaining <= 0) {
                break;
            }

            $width = $band['width'] ?? null;
            $taxable = $width === null ? $remaining : min($remaining, (float) $width);
            $tax += $taxable * $band['rate'] / 100;
            $remaining -= $taxable;
        }

        return (int) round($tax);
    }

    public function generateRun(string $month, int $staffId): PayrollRun
    {
        if (PayrollRun::query()->where('month', $month)->exists()) {
            throw ValidationException::withMessages(['month' => "Payroll for {$month} already exists."]);
        }

        $standardHours = Settings::num('payroll.standard_monthly_hours', 200);
        $from = Carbon::parse("{$month}-01")->startOfMonth();
        $to = $from->copy()->addMonthNoOverflow();

        return DB::transaction(function () use ($month, $staffId, $standardHours, $from, $to) {
            $run = PayrollRun::create([
                'month' => $month,
                'payroll_status_id' => Lookup::id(LookupType::PAYROLL_STATUS, PayrollStatus::DRAFT),
                'run_by_id' => $staffId,
            ]);

            $staff = User::query()->where('status', User::STATUS_ACTIVE)->get();

            foreach ($staff as $user) {
                $workedHours = round(
                    Attendance::query()
                        ->where('user_id', $user->id)
                        ->whereBetween('clock_in', [$from, $to])
                        ->whereNotNull('clock_out')
                        ->get()
                        ->sum(fn (Attendance $a) => ($a->clock_out->timestamp - $a->clock_in->timestamp) / 3600),
                    2,
                );
                $otHours = max(0, round($workedHours - $standardHours, 2));

                $calc = $this->computeLine($user->base_salary, $otHours, $user->monthly_allowance, 0, 0, 0, 0, 0, $user->ot_hourly_rate, $user->epf_enabled);

                PayrollLine::create(array_merge([
                    'run_id' => $run->id, 'user_id' => $user->id, 'base_salary' => $user->base_salary,
                    'worked_hours' => $workedHours, 'ot_hours' => $otHours, 'allowance' => $user->monthly_allowance,
                ], $calc));
            }

            AuditLog::record('payroll_run.created', $run, ['month' => $month]);

            return $run->load(['lines.user:id,name', 'runBy:id,name', 'status']);
        });
    }

    public function deleteRun(PayrollRun $run): void
    {
        $run->loadMissing('status');
        if ($run->status->code === PayrollStatus::FINALIZED) {
            throw ValidationException::withMessages(['run' => 'Finalized runs cannot be deleted.']);
        }

        AuditLog::record('payroll_run.deleted', $run, ['month' => $run->month]);
        $run->delete();
    }

    public function finalizeRun(PayrollRun $run): PayrollRun
    {
        $run->loadMissing('status');
        if ($run->status->code === PayrollStatus::FINALIZED) {
            throw ValidationException::withMessages(['run' => 'Already finalized.']);
        }

        $run->update(['payroll_status_id' => Lookup::id(LookupType::PAYROLL_STATUS, PayrollStatus::FINALIZED), 'finalized_at' => now()]);

        AuditLog::record('payroll_run.finalized', $run, ['month' => $run->month]);

        return $run->load(['lines.user:id,name', 'runBy:id,name', 'status']);
    }

    /**
     * Adjust a line while its run is DRAFT (OT hours, bonus, deductions).
     */
    public function updateLine(
        PayrollLine $line,
        ?float $otHours,
        ?int $bonus,
        ?int $unpaidLeaveDeduction,
        ?int $loan,
        ?int $advance,
        ?int $otherDeduction,
        ?string $otherDeductionNote,
    ): PayrollLine {
        $line->loadMissing('run.status', 'user');
        if ($line->run->status->code !== PayrollStatus::DRAFT) {
            throw ValidationException::withMessages(['run' => 'Run is finalized — lines are locked.']);
        }

        $otHours ??= $line->ot_hours;
        $bonus ??= $line->bonus;
        $unpaidLeaveDeduction ??= $line->unpaid_leave_deduction;
        $loan ??= $line->loan;
        $advance ??= $line->advance;
        $otherDeduction ??= $line->other_deduction;

        $calc = $this->computeLine(
            $line->base_salary, $otHours, $line->allowance, $bonus,
            $unpaidLeaveDeduction, $loan, $advance, $otherDeduction,
            $line->user->ot_hourly_rate, $line->user->epf_enabled,
        );

        $line->update(array_merge([
            'ot_hours' => $otHours, 'bonus' => $bonus, 'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'loan' => $loan, 'advance' => $advance, 'other_deduction' => $otherDeduction,
            'other_deduction_note' => $otherDeductionNote ?? $line->other_deduction_note,
        ], $calc));

        return $line->fresh(['user:id,name']);
    }

    public function markLinePaid(PayrollLine $line): PayrollLine
    {
        $line->loadMissing('run.status');
        if ($line->run->status->code !== PayrollStatus::FINALIZED) {
            throw ValidationException::withMessages(['run' => 'Finalize the run before paying.']);
        }
        if ($line->paid) {
            throw ValidationException::withMessages(['line' => 'Already marked paid.']);
        }

        $line->update(['paid' => true, 'paid_at' => now()]);

        AuditLog::record('payroll_line.paid', $line, ['net_pay' => $line->net_pay]);

        return $line->fresh();
    }
}
