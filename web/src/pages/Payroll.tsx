import { useState } from "react";
import { Wallet, Play, Lock, Printer, Download, Trash2, CheckCircle2 } from "lucide-react";
import { api, openPdf, post, put, API_ORIGIN } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, centsToRupees, fmtDate } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Tabs, Pagination } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

type Lookup = { id: number; code: string; name: string; color: string | null };
type StaffPay = { id: number; name: string; base_salary: number; ot_hourly_rate: number; monthly_allowance: number; epf_enabled: boolean; epf_number?: string | null; roles: { id: number; name: string }[] };
type Run = {
  id: number; month: string; created_at: string;
  status: Lookup; run_by: { id: number; name: string };
  total_net: number; paid_count: number; line_count: number;
};
type Line = {
  id: number; base_salary: number; worked_hours: number; ot_hours: number; ot_pay: number; allowance: number;
  bonus: number; deduction: number; deduction_note?: string | null; gross: number; epf_employee: number;
  epf_employer: number; etf: number; net_pay: number; paid: boolean; paid_at?: string | null;
  user: { id: number; name: string; epf_number?: string | null; ot_hourly_rate?: number; epf_enabled?: boolean; roles: { id: number; name: string }[] };
};
type RunDetail = {
  id: number; month: string; created_at: string; finalized_at?: string | null;
  status: Lookup; run_by: { id: number; name: string }; lines: Line[];
};

/** Payroll — Owner only (salaries hidden from Managers). Attendance-driven OT, EPF/ETF from Settings. */
export default function Payroll() {
  const { can } = useAuth();
  const canSalaries = can("hotel_payroll.manage_pay");
  const [tab, setTab] = useState<"runs" | "salaries">("runs");
  const tabs = [
    { id: "runs" as const, label: "Monthly runs" },
    ...(canSalaries ? [{ id: "salaries" as const, label: "Salary setup" }] : []),
  ];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold">
          <Wallet /> Payroll <Badge color="purple">OWNER</Badge>
        </h1>
        <Tabs tabs={tabs} active={tab} onChange={setTab} />
      </div>
      <p className="text-xs text-slate-500">
        Worked hours come from staff attendance; hours over the standard month count as overtime. EPF 8% is deducted from pay; employer EPF 12% + ETF 3% are tracked for statutory reporting (percentages editable in Settings → payroll).
      </p>
      {tab === "salaries" && canSalaries ? <Salaries /> : <Runs />}
    </div>
  );
}

function Salaries() {
  const { data, reload } = useFetch<{ staff: StaffPay[] }>("/payroll/staff-pay");
  const staff = data?.staff;
  const [error, setError] = useState("");
  const [saved, setSaved] = useState("");

  const save = (id: number, field: string, value: number | boolean | string | null) =>
    put(`/payroll/staff-pay/${id}`, { [field]: value })
      .then(() => {
        setError("");
        setSaved(id + field);
        setTimeout(() => setSaved(""), 1200);
        reload();
      })
      .catch((e) => setError(e.message));

  return (
    <div className="card overflow-x-auto">
      <ErrorText error={error} />
      <table className="w-full min-w-[860px]">
        <thead className="border-b border-slate-100">
          <tr>
            <th className="th">Staff</th><th className="th">Basic salary (LKR/month)</th><th className="th">OT rate (LKR/hour)</th>
            <th className="th">Allowance (LKR)</th><th className="th">EPF</th><th className="th">EPF number</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-50">
          {(staff ?? []).map((s) => (
            <tr key={s.id}>
              <td className="td">
                <div className="font-semibold">{s.name}</div>
                {s.roles[0] && <div className="text-[10px] uppercase text-slate-400">({s.roles.map((r) => r.name).join(", ")})</div>}
              </td>
              {(["base_salary", "ot_hourly_rate", "monthly_allowance"] as const).map((f) => (
                <td key={f} className="td">
                  <input
                    className="input !w-32 text-right"
                    defaultValue={centsToRupees(s[f])}
                    onBlur={(e) => toCents(e.target.value) !== s[f] && save(s.id, f, toCents(e.target.value))}
                  />
                  {saved === s.id + f && <span className="ml-1 text-xs text-emerald-600">✓</span>}
                </td>
              ))}
              <td className="td">
                <button
                  className={`rounded-full px-2.5 py-1 text-xs font-bold ${s.epf_enabled ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-400"}`}
                  onClick={() => save(s.id, "epf_enabled", !s.epf_enabled)}
                >
                  {s.epf_enabled ? "ON" : "OFF"}
                </button>
              </td>
              <td className="td">
                <input className="input !w-28" defaultValue={s.epf_number ?? ""} placeholder="—" onBlur={(e) => (e.target.value || null) !== (s.epf_number ?? null) && save(s.id, "epf_number", e.target.value || null)} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {(staff ?? []).length === 0 && <Empty text="No active staff" />}
      <p className="px-4 pb-3 text-[11px] text-slate-400">Edit and click away to save. Salaries are visible to the Owner and System Admin only.</p>
    </div>
  );
}

function Runs() {
  const toast = useToast();
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Run>(`/payroll/runs?page=${page}&page_size=${pageSize}`, "runs", [page, pageSize]);
  const runs = data?.rows;
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [error, setError] = useState("");
  const [openRun, setOpenRun] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <input type="month" className="input !w-44" value={month} onChange={(e) => setMonth(e.target.value)} />
        {can("hotel_payroll.generate") && (
          <button
            className="btn-primary"
            disabled={busy}
            onClick={() => {
              setBusy(true);
              setError("");
              post<{ run: { id: number } }>("/payroll/runs", { month })
                .then((r) => {
                  toast.success(`Payroll generated for ${month}`);
                  reload();
                  setOpenRun(r.run.id);
                })
                .catch((e) => setError(e.message))
                .finally(() => setBusy(false));
            }}
          >
            <Play size={15} /> {busy ? "Generating…" : `Generate payroll for ${month}`}
          </button>
        )}
      </div>
      <ErrorText error={error} />
      <div className="card divide-y divide-slate-50">
        {(runs ?? []).map((r) => (
          <button key={r.id} className="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" onClick={() => setOpenRun(r.id)}>
            <span className="text-base font-extrabold">{r.month}</span>
            <Badge color={r.status.code === "finalized" ? "green" : "amber"}>{r.status.code.toUpperCase()}</Badge>
            <span className="text-xs text-slate-400">by {r.run_by.name} · {fmtDate(r.created_at)}</span>
            <span className="ml-auto font-bold">{lkr(r.total_net)}</span>
            <Badge color={r.paid_count === r.line_count ? "green" : "slate"}>{r.paid_count}/{r.line_count} paid</Badge>
          </button>
        ))}
        {(runs ?? []).length === 0 && <Empty text="No payroll runs yet — pick a month and generate" />}
      </div>
      {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      {openRun && <RunModal runId={openRun} onClose={() => { setOpenRun(null); reload(); }} />}
    </div>
  );
}

function RunModal({ runId, onClose }: { runId: number; onClose: () => void }) {
  const toast = useToast();
  const { can } = useAuth();
  const { data, reload } = useFetch<{ run: RunDetail }>(`/payroll/runs/${runId}`);
  const run = data?.run;
  const [error, setError] = useState("");
  const [editing, setEditing] = useState<Line | null>(null);

  if (!run) return null;
  const draft = run.status.code === "draft";
  const totalNet = run.lines.reduce((s, l) => s + l.net_pay, 0);

  const act = (fn: () => Promise<unknown>, successMsg?: string) =>
    fn()
      .then(() => {
        setError("");
        if (successMsg) toast.success(successMsg, `Payroll ${run.month}`);
        reload();
      })
      .catch((e) => setError((e as Error).message));

  const exportCsv = async () => {
    const res = await fetch(`${API_ORIGIN}/api/payroll/runs/${run.id}/export`, { credentials: "include" });
    if (!res.ok) {
      setError("Could not export CSV");
      return;
    }
    const blob = await res.blob();
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `payroll-${run.month}.csv`;
    a.click();
  };

  return (
    <Modal open onClose={onClose} title={`Payroll ${run.month} — ${run.status.name}`} wide>
      <div className="mb-3 flex flex-wrap gap-2">
        {draft ? (
          <>
            {can("hotel_payroll.finalize") && (
              <button className="btn-primary" onClick={() => act(() => post(`/payroll/runs/${run.id}/finalize`), "Payroll finalized")}>
                <Lock size={15} /> Finalize (locks lines)
              </button>
            )}
            {can("hotel_payroll.delete_run") && (
              <button className="btn-danger" onClick={() => act(() => api(`/payroll/runs/${run.id}`, { method: "DELETE" })).then(onClose)}>
                <Trash2 size={15} /> Delete draft
              </button>
            )}
          </>
        ) : (
          <Badge color="green">Finalized {run.finalized_at ? fmtDate(run.finalized_at) : ""}</Badge>
        )}
        {can("hotel_payroll.export") && <button className="btn-secondary" onClick={exportCsv}><Download size={15} /> CSV</button>}
        <span className="ml-auto self-center text-sm font-extrabold">Total net: {lkr(totalNet)}</span>
      </div>
      <ErrorText error={error} />
      <div className="overflow-x-auto">
        <table className="w-full min-w-[880px] text-sm">
          <thead className="border-b border-slate-100">
            <tr>
              <th className="th">Staff</th><th className="th text-right">Hrs</th><th className="th text-right">OT hrs</th>
              <th className="th text-right">Basic</th><th className="th text-right">OT pay</th><th className="th text-right">Allow.</th>
              <th className="th text-right">Bonus</th><th className="th text-right">EPF 8%</th><th className="th text-right">Deduct.</th>
              <th className="th text-right">Net pay</th><th className="th" />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {run.lines.map((l) => (
              <tr key={l.id} className={l.paid ? "bg-emerald-50/40" : ""}>
                <td className="td">
                  <div className="font-semibold">{l.user.name}</div>
                  <div className="text-[10px] uppercase text-slate-400">
                    {l.user.roles[0] ? `(${l.user.roles.map((r) => r.name).join(", ")}) · ` : ""}
                    {l.user.epf_number ? `EPF ${l.user.epf_number}` : "—"}
                  </div>
                </td>
                <td className="td text-right">{l.worked_hours}</td>
                <td className="td text-right">{l.ot_hours}</td>
                <td className="td text-right">{lkr(l.base_salary)}</td>
                <td className="td text-right">{lkr(l.ot_pay)}</td>
                <td className="td text-right">{lkr(l.allowance)}</td>
                <td className="td text-right">{lkr(l.bonus)}</td>
                <td className="td text-right text-red-600">-{lkr(l.epf_employee)}</td>
                <td className="td text-right text-red-600">{l.deduction > 0 ? `-${lkr(l.deduction)}` : "—"}</td>
                <td className="td text-right font-extrabold">{lkr(l.net_pay)}</td>
                <td className="td whitespace-nowrap text-right">
                  {draft && can("hotel_payroll.adjust_line") && <button className="btn-ghost !py-1 text-xs" onClick={() => setEditing(l)}>Adjust</button>}
                  {!draft && !l.paid && can("hotel_payroll.mark_paid") && (
                    <button className="btn-primary !py-1 text-xs" onClick={() => act(() => post(`/payroll/lines/${l.id}/mark-paid`), `${l.user.name} marked paid — ${lkr(l.net_pay)}`)}>
                      <CheckCircle2 size={13} /> Mark paid
                    </button>
                  )}
                  {!draft && l.paid && <Badge color="green">PAID</Badge>}
                  {can("hotel_payroll.payslip") && (
                    <button className="btn-ghost !py-1 text-xs" title="Payslip PDF" onClick={() => openPdf(`/payroll/lines/${l.id}/payslip`)}>
                      <Printer size={13} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-2 text-[11px] text-slate-400">Employer contributions (not deducted): EPF 12% {lkr(run.lines.reduce((s, l) => s + l.epf_employer, 0))} · ETF 3% {lkr(run.lines.reduce((s, l) => s + l.etf, 0))}</p>

      {editing && (
        <AdjustLine
          line={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            reload();
          }}
        />
      )}
    </Modal>
  );
}

function AdjustLine({ line, onClose, onSaved }: { line: Line; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    otHours: String(line.ot_hours),
    bonus: centsToRupees(line.bonus),
    deduction: centsToRupees(line.deduction),
    deductionNote: line.deduction_note ?? "",
  });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={`Adjust — ${line.user.name}`}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="OT hours" hint={`Attendance shows ${line.worked_hours} h worked`}>
          <input className="input" value={f.otHours} onChange={(e) => setF({ ...f, otHours: e.target.value })} />
        </Field>
        <Field label="Bonus (LKR)"><input className="input" value={f.bonus} onChange={(e) => setF({ ...f, bonus: e.target.value })} /></Field>
        <Field label="Deduction (LKR)" hint="Advances, no-pay leave etc."><input className="input" value={f.deduction} onChange={(e) => setF({ ...f, deduction: e.target.value })} /></Field>
        <Field label="Deduction note"><input className="input" value={f.deductionNote} onChange={(e) => setF({ ...f, deductionNote: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        onClick={() =>
          put(`/payroll/lines/${line.id}`, {
            ot_hours: parseFloat(f.otHours) || 0,
            bonus: toCents(f.bonus),
            deduction: toCents(f.deduction),
            deduction_note: f.deductionNote || null,
          })
            .then(onSaved)
            .catch((e) => setError(e.message))
        }
      >
        Save (recalculates EPF & net pay)
      </button>
    </Modal>
  );
}
