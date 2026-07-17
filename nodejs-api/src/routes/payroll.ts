/**
 * Payroll — Owner (and System Admin) only; salaries are not Manager-visible.
 * Flow: set salaries per staff → generate a monthly DRAFT run (worked hours
 * pulled from attendance, OT auto-computed beyond standard hours) → adjust
 * OT/bonus/deductions per line → FINALIZE → mark lines paid → payslip PDFs.
 * EPF/ETF percentages are Settings (statutory Sri Lankan defaults).
 */
import { Router } from "express";
import { z } from "zod";
import dayjs from "dayjs";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { getNum } from "../lib/settings";
import { payslipPdf } from "../lib/pdf";

const router = Router();
router.use(requireRole("OWNER")); // OWNER + SYSTEM_ADMIN (bypass); Manager excluded

// ── Salary setup ──────────────────────────────────────────────────────────────
router.get(
  "/staff-pay",
  asyncHandler(async (_req, res) => {
    res.json(
      await prisma.user.findMany({
        where: { active: true },
        select: { id: true, name: true, role: true, baseSalary: true, otHourlyRate: true, monthlyAllowance: true, epfEnabled: true, epfNumber: true },
        orderBy: { name: "asc" },
      })
    );
  })
);

router.put(
  "/staff-pay/:id",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        baseSalary: z.number().int().min(0).optional(),
        otHourlyRate: z.number().int().min(0).optional(),
        monthlyAllowance: z.number().int().min(0).optional(),
        epfEnabled: z.boolean().optional(),
        epfNumber: z.string().nullable().optional(),
      })
      .parse(req.body);
    const user = await prisma.user.update({ where: { id: req.params.id }, data: body });
    audit(req.user!.id, "PAYROLL_SALARY_UPDATE", "User", user.id);
    res.json({ ok: true });
  })
);

// ── Runs ──────────────────────────────────────────────────────────────────────
async function computeLine(l: { baseSalary: number; otHours: number; allowance: number; bonus: number; deduction: number; otHourlyRate: number; epfEnabled: boolean }) {
  const epfEmpPct = await getNum("payroll.epf_employee_pct", 8);
  const epfErPct = await getNum("payroll.epf_employer_pct", 12);
  const etfPct = await getNum("payroll.etf_pct", 3);
  const otPay = Math.round(l.otHours * l.otHourlyRate);
  const gross = l.baseSalary + otPay + l.allowance + l.bonus;
  const epfEmployee = l.epfEnabled ? Math.round((l.baseSalary * epfEmpPct) / 100) : 0;
  const epfEmployer = l.epfEnabled ? Math.round((l.baseSalary * epfErPct) / 100) : 0;
  const etf = l.epfEnabled ? Math.round((l.baseSalary * etfPct) / 100) : 0;
  const netPay = gross - epfEmployee - l.deduction;
  return { otPay, gross, epfEmployee, epfEmployer, etf, netPay };
}

const runsInclude = { runBy: { select: { name: true } }, lines: { select: { netPay: true, paid: true } } } as const;
const withRunTotals = (runs: { lines: { netPay: number; paid: boolean }[] }[]) =>
  runs.map((r) => ({
    ...r,
    totalNet: r.lines.reduce((s, l) => s + l.netPay, 0),
    paidCount: r.lines.filter((l) => l.paid).length,
    lineCount: r.lines.length,
    lines: undefined,
  }));

router.get(
  "/runs",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.payrollRun.findMany({ include: runsInclude, orderBy: { month: "desc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.payrollRun.count(),
      ]);
      return res.json({ rows: withRunTotals(rows), total, page, pageSize });
    }

    const runs = await prisma.payrollRun.findMany({ include: runsInclude, orderBy: { month: "desc" } });
    res.json(withRunTotals(runs));
  })
);

router.post(
  "/runs",
  asyncHandler(async (req, res) => {
    const { month } = z.object({ month: z.string().regex(/^\d{4}-\d{2}$/) }).parse(req.body);
    const existing = await prisma.payrollRun.findUnique({ where: { month } });
    if (existing) throw new ApiError(409, `Payroll for ${month} already exists (${existing.status})`);

    const standardHours = await getNum("payroll.standard_monthly_hours", 200);
    const from = dayjs(`${month}-01`).toDate();
    const to = dayjs(`${month}-01`).add(1, "month").toDate();
    const staff = await prisma.user.findMany({ where: { active: true } });

    const run = await prisma.payrollRun.create({ data: { month, runById: req.user!.id } });
    for (const u of staff) {
      const attendance = await prisma.attendance.findMany({
        where: { userId: u.id, clockIn: { gte: from, lt: to }, clockOut: { not: null } },
      });
      const workedHours = Math.round(attendance.reduce((s, a) => s + (+a.clockOut! - +a.clockIn) / 3600000, 0) * 100) / 100;
      const otHours = Math.max(0, Math.round((workedHours - standardHours) * 100) / 100);
      const calc = await computeLine({
        baseSalary: u.baseSalary, otHours, allowance: u.monthlyAllowance, bonus: 0, deduction: 0,
        otHourlyRate: u.otHourlyRate, epfEnabled: u.epfEnabled,
      });
      await prisma.payrollLine.create({
        data: {
          runId: run.id, userId: u.id, baseSalary: u.baseSalary, workedHours, otHours,
          allowance: u.monthlyAllowance, ...calc,
        },
      });
    }
    audit(req.user!.id, "PAYROLL_RUN_CREATE", "PayrollRun", run.id, { month });
    res.status(201).json(run);
  })
);

router.get(
  "/runs/:id",
  asyncHandler(async (req, res) => {
    const run = await prisma.payrollRun.findUnique({
      where: { id: req.params.id },
      include: {
        runBy: { select: { name: true } },
        lines: { include: { user: { select: { name: true, role: true, epfNumber: true, otHourlyRate: true, epfEnabled: true } } }, orderBy: { user: { name: "asc" } } },
      },
    });
    if (!run) throw new ApiError(404, "Payroll run not found");
    res.json(run);
  })
);

router.delete(
  "/runs/:id",
  asyncHandler(async (req, res) => {
    const run = await prisma.payrollRun.findUnique({ where: { id: req.params.id } });
    if (!run) throw new ApiError(404, "Run not found");
    if (run.status === "FINALIZED") throw new ApiError(400, "Finalized runs cannot be deleted");
    await prisma.payrollRun.delete({ where: { id: run.id } });
    audit(req.user!.id, "PAYROLL_RUN_DELETE", "PayrollRun", run.id, { month: run.month });
    res.json({ ok: true });
  })
);

/** Adjust a line while the run is DRAFT (OT hours, bonus, deductions). */
router.put(
  "/lines/:id",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        otHours: z.number().min(0).optional(),
        bonus: z.number().int().min(0).optional(),
        deduction: z.number().int().min(0).optional(),
        deductionNote: z.string().nullable().optional(),
      })
      .parse(req.body);
    const line = await prisma.payrollLine.findUnique({ where: { id: req.params.id }, include: { run: true, user: true } });
    if (!line) throw new ApiError(404, "Payroll line not found");
    if (line.run.status !== "DRAFT") throw new ApiError(400, "Run is finalized — lines are locked");
    const merged = {
      baseSalary: line.baseSalary,
      otHours: body.otHours ?? line.otHours,
      allowance: line.allowance,
      bonus: body.bonus ?? line.bonus,
      deduction: body.deduction ?? line.deduction,
      otHourlyRate: line.user.otHourlyRate,
      epfEnabled: line.user.epfEnabled,
    };
    const calc = await computeLine(merged);
    const updated = await prisma.payrollLine.update({
      where: { id: line.id },
      data: { otHours: merged.otHours, bonus: merged.bonus, deduction: merged.deduction, deductionNote: body.deductionNote ?? line.deductionNote, ...calc },
    });
    res.json(updated);
  })
);

router.post(
  "/runs/:id/finalize",
  asyncHandler(async (req, res) => {
    const run = await prisma.payrollRun.findUnique({ where: { id: req.params.id } });
    if (!run) throw new ApiError(404, "Run not found");
    if (run.status === "FINALIZED") throw new ApiError(400, "Already finalized");
    const updated = await prisma.payrollRun.update({ where: { id: run.id }, data: { status: "FINALIZED", finalizedAt: new Date() } });
    audit(req.user!.id, "PAYROLL_RUN_FINALIZE", "PayrollRun", run.id, { month: run.month });
    res.json(updated);
  })
);

router.post(
  "/lines/:id/mark-paid",
  asyncHandler(async (req, res) => {
    const line = await prisma.payrollLine.findUnique({ where: { id: req.params.id }, include: { run: true } });
    if (!line) throw new ApiError(404, "Line not found");
    if (line.run.status !== "FINALIZED") throw new ApiError(400, "Finalize the run before paying");
    if (line.paid) throw new ApiError(400, "Already marked paid");
    const updated = await prisma.payrollLine.update({ where: { id: line.id }, data: { paid: true, paidAt: new Date() } });
    audit(req.user!.id, "PAYROLL_LINE_PAID", "PayrollLine", line.id, { netPay: line.netPay });
    res.json(updated);
  })
);

/** CSV export of a run. */
router.get(
  "/runs/:id/export",
  asyncHandler(async (req, res) => {
    const run = await prisma.payrollRun.findUnique({
      where: { id: req.params.id },
      include: { lines: { include: { user: { select: { name: true, role: true, epfNumber: true } } } } },
    });
    if (!run) throw new ApiError(404, "Run not found");
    const money = (c: number) => (c / 100).toFixed(2);
    const rows = ["Staff,Role,EPF No,Worked Hrs,OT Hrs,Basic,OT Pay,Allowance,Bonus,Gross,EPF 8%,Deduction,Net Pay,EPF 12% (employer),ETF 3% (employer),Paid"];
    for (const l of run.lines) {
      rows.push(
        `"${l.user.name}",${l.user.role},${l.user.epfNumber ?? ""},${l.workedHours},${l.otHours},${money(l.baseSalary)},${money(l.otPay)},${money(l.allowance)},${money(l.bonus)},${money(l.gross)},${money(l.epfEmployee)},${money(l.deduction)},${money(l.netPay)},${money(l.epfEmployer)},${money(l.etf)},${l.paid ? "YES" : "no"}`
      );
    }
    res.setHeader("Content-Type", "text/csv");
    res.setHeader("Content-Disposition", `attachment; filename=payroll-${run.month}.csv`);
    res.send(rows.join("\n"));
  })
);

/** Branded payslip PDF (A4). */
router.get(
  "/lines/:id/payslip",
  asyncHandler(async (req, res) => {
    await payslipPdf(req.params.id, res);
  })
);

export default router;
