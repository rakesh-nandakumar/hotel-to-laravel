import { useMemo, useState } from "react";
import {
  BarChart3, ChevronLeft, ChevronRight, Download, TrendingUp, TrendingDown,
  Play, Calendar, Trophy, Wallet, FileText,
} from "lucide-react";
import { post, openPdf } from "../lib/api";
import { useFetch, usePagedFetch, lkr, todayStr, fmtDate } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Tabs, Pagination } from "../components/ui";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type Daily = {
  date: string;
  occupancy: { total_rooms: number; occupied_rooms: number; pct: number };
  revenue_by_source: Record<string, number>;
  walkin_pos_revenue: number;
  total_charges_posted: number;
  payments: { by_method: Record<string, number>; collected: number; refunded: number; net: number };
  cash_collected: number;
  pos: { by_category: Record<string, number>; best_sellers: { name: string; qty: number; amount: number }[]; order_count: number };
  shifts: { staff: string; opening_cash: number; closing_cash: number | null; expected_cash: number | null; variance: number | null }[];
};
type Monthly = { month: string; days: { date: string; revenue: number; occupancy_pct: number }[]; total_revenue: number; avg_occupancy: number };
type PosReport = { from: string; to: string; by_category: Record<string, number>; best_sellers: { name: string; qty: number; amount: number }[]; payment_method_breakdown: Record<string, number>; total_sales: number };
type Audit = { id: number; business_date: string; run_at: string; run_by: { id: number; name: string }; data: Daily };

// ── small date helpers (string dates, no timezone surprises) ─────────────────
const shiftDate = (dateStr: string, days: number) => {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
};
const shiftMonth = (month: string, delta: number) => {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
};

// ── CSV export (client-side — no backend round trip) ─────────────────────────
function downloadCsv(filename: string, rows: (string | number)[][]) {
  const csv = rows.map((r) => r.map((v) => (typeof v === "string" && /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

export default function Reports() {
  const { can } = useAuth();
  const tabs = [
    ...(can("hotel_reports.daily") ? [{ id: "daily" as const, label: "Daily" }] : []),
    ...(can("hotel_reports.monthly") ? [{ id: "monthly" as const, label: "Monthly" }] : []),
    ...(can("hotel_reports.pos") ? [{ id: "pos" as const, label: "POS sales" }] : []),
    ...(can("hotel_reports.night_audit_view") ? [{ id: "audit" as const, label: "Night audit" }] : []),
  ];
  const [tab, setTab] = useState<"daily" | "monthly" | "pos" | "audit">(tabs[0]?.id ?? "daily");
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><BarChart3 /> Reports & Night Audit</h1>
        <Tabs tabs={tabs} active={tab} onChange={setTab} />
      </div>
      {tab === "daily" && can("hotel_reports.daily") && <DailyTab />}
      {tab === "monthly" && can("hotel_reports.monthly") && <MonthlyTab />}
      {tab === "pos" && can("hotel_reports.pos") && <PosTab />}
      {tab === "audit" && can("hotel_reports.night_audit_view") && <AuditTab />}
    </div>
  );
}

// ── shared bits ────────────────────────────────────────────────────────────────
function HeroStat({ label, value, sub, delta }: { label: string; value: React.ReactNode; sub?: string; delta?: number | null }) {
  return (
    <div className="card p-4">
      <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className="mt-1 flex items-baseline gap-2">
        <span className="text-2xl font-extrabold">{value}</span>
        {delta !== undefined && delta !== null && Number.isFinite(delta) && (
          <span className={clsx("flex items-center gap-0.5 text-xs font-bold", delta >= 0 ? "text-emerald-600" : "text-red-600")}>
            {delta >= 0 ? <TrendingUp size={12} /> : <TrendingDown size={12} />}
            {Math.abs(delta)}%
          </span>
        )}
      </div>
      {sub && <div className="mt-0.5 text-xs text-slate-500">{sub}</div>}
    </div>
  );
}

function pctDelta(curr: number, prev: number): number | null {
  if (prev === 0) return curr === 0 ? 0 : null; // avoid misleading "∞%"
  return Math.round(((curr - prev) / prev) * 100);
}

/** Horizontal proportional bar row — magnitude encoded as one hue, light→dark by hover. */
function BarRow({ label, amount, max, rank, colorClass }: { label: string; amount: number; max: number; rank?: number; colorClass?: string }) {
  const pct = max > 0 ? Math.max(2, (amount / max) * 100) : 2;
  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between gap-2 text-sm">
        <span className="flex min-w-0 items-center gap-1.5">
          {rank !== undefined && <span className="w-4 shrink-0 text-right text-[11px] font-black tabular-nums text-slate-300">{rank}</span>}
          <span className="truncate">{label}</span>
        </span>
        <b className="shrink-0 tabular-nums">{lkr(amount)}</b>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div className={clsx("h-full rounded-full transition-all", colorClass ?? "bg-brand-500")} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

const METHOD_COLOR: Record<string, string> = {
  CASH: "bg-emerald-500",
  CARD: "bg-sky-500",
  LANKAQR: "bg-purple-500",
  BANK_TRANSFER: "bg-amber-500",
  CORPORATE_CREDIT: "bg-indigo-500",
  LOYALTY_POINTS: "bg-pink-500",
};

function DailyView({ d, prev, pdfUrl }: { d: Daily; prev?: Daily | null; pdfUrl?: string }) {
  const revMax = Math.max(1, ...Object.values(d.revenue_by_source), d.walkin_pos_revenue);
  const payMax = Math.max(1, ...Object.values(d.payments.by_method));
  const catMax = Math.max(1, ...Object.values(d.pos.by_category));

  const exportCsv = () => {
    const rows: (string | number)[][] = [
      ["Mount View Hotel — Daily Report", d.date],
      [],
      ["Occupancy", `${d.occupancy.pct}%`, `${d.occupancy.occupied_rooms}/${d.occupancy.total_rooms} rooms`],
      ["Charges posted (LKR)", (d.total_charges_posted / 100).toFixed(2)],
      ["Collected net (LKR)", (d.payments.net / 100).toFixed(2)],
      ["Refunded (LKR)", (d.payments.refunded / 100).toFixed(2)],
      ["Cash collected (LKR)", (d.cash_collected / 100).toFixed(2)],
      [],
      ["Revenue by source"],
      ...Object.entries(d.revenue_by_source).map(([k, v]) => [k, (v / 100).toFixed(2)]),
      ...(d.walkin_pos_revenue > 0 ? [["WALK-IN POS", (d.walkin_pos_revenue / 100).toFixed(2)]] : []),
      [],
      ["Payments by method"],
      ...Object.entries(d.payments.by_method).map(([k, v]) => [k, (v / 100).toFixed(2)]),
      [],
      ["POS best sellers", "Qty", "Amount (LKR)"],
      ...d.pos.best_sellers.map((b) => [b.name, b.qty, (b.amount / 100).toFixed(2)]),
    ];
    downloadCsv(`daily-report-${d.date}.csv`, rows);
  };

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <HeroStat label="Occupancy" value={`${d.occupancy.pct}%`} sub={`${d.occupancy.occupied_rooms}/${d.occupancy.total_rooms} rooms`} delta={prev ? pctDelta(d.occupancy.pct, prev.occupancy.pct) : undefined} />
        <HeroStat label="Charges posted" value={lkr(d.total_charges_posted)} delta={prev ? pctDelta(d.total_charges_posted, prev.total_charges_posted) : undefined} />
        <HeroStat label="Collected (net)" value={lkr(d.payments.net)} sub={`refunds ${lkr(d.payments.refunded)}`} delta={prev ? pctDelta(d.payments.net, prev.payments.net) : undefined} />
        <HeroStat label="Cash collected" value={lkr(d.cash_collected)} delta={prev ? pctDelta(d.cash_collected, prev.cash_collected) : undefined} />
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Revenue by source">
          {Object.keys(d.revenue_by_source).length === 0 && d.walkin_pos_revenue === 0 ? (
            <Empty text="No revenue" />
          ) : (
            <div className="space-y-2.5">
              {Object.entries(d.revenue_by_source).map(([k, v]) => (
                <BarRow key={k} label={k} amount={v} max={revMax} />
              ))}
              {d.walkin_pos_revenue > 0 && <BarRow label="WALK-IN POS" amount={d.walkin_pos_revenue} max={revMax} />}
            </div>
          )}
        </Card>
        <Card title="Payments by method">
          {Object.keys(d.payments.by_method).length === 0 ? (
            <Empty text="No payments" />
          ) : (
            <div className="space-y-2.5">
              {Object.entries(d.payments.by_method).map(([k, v]) => (
                <BarRow key={k} label={k} amount={v} max={payMax} colorClass={METHOD_COLOR[k] ?? "bg-slate-400"} />
              ))}
            </div>
          )}
        </Card>
        <Card title="POS best sellers">
          {d.pos.best_sellers.length === 0 ? (
            <Empty text="No POS sales" />
          ) : (
            <div className="space-y-2.5">
              {d.pos.best_sellers.slice(0, 8).map((b, i) => (
                <BarRow key={b.name} label={`${b.name} (${b.qty}×)`} amount={b.amount} max={catMax > 0 ? Math.max(1, ...d.pos.best_sellers.map((x) => x.amount)) : 1} rank={i + 1} />
              ))}
            </div>
          )}
        </Card>
      </div>
      {d.shifts.length > 0 && (
        <Card title="Cash drawer reconciliation">
          <div className="grid gap-2 sm:grid-cols-2">
            {d.shifts.map((s, i) => {
              const v = s.variance ?? 0;
              return (
                <div key={i} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                  <span className="font-semibold">{s.staff}</span>
                  <span className="text-xs text-slate-500">
                    expected {s.expected_cash != null ? lkr(s.expected_cash) : "—"} · counted {s.closing_cash != null ? lkr(s.closing_cash) : "—"}
                  </span>
                  <Badge color={v === 0 ? "green" : "red"}>{v === 0 ? "balanced" : lkr(v)}</Badge>
                </div>
              );
            })}
          </div>
        </Card>
      )}
      <div className="flex gap-2">
        <button className="btn-secondary" onClick={exportCsv}><Download size={14} /> Export CSV</button>
        <button className="btn-secondary" onClick={() => openPdf(pdfUrl ?? `/reports/daily/pdf?date=${d.date}`)}><FileText size={14} /> Download PDF</button>
      </div>
    </div>
  );
}

function DailyTab() {
  const [date, setDate] = useState(todayStr());
  const { data } = useFetch<Daily>(`/reports/daily?date=${date}`, [date]);
  const { data: prev } = useFetch<Daily>(`/reports/daily?date=${shiftDate(date, -1)}`, [date]);
  const isToday = date === todayStr();

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <button className="btn-secondary !px-2.5" onClick={() => setDate(shiftDate(date, -1))}><ChevronLeft size={15} /></button>
        <input type="date" className="input !w-44" max={todayStr()} value={date} onChange={(e) => setDate(e.target.value)} />
        <button className="btn-secondary !px-2.5" disabled={isToday} onClick={() => setDate(shiftDate(date, 1))}><ChevronRight size={15} /></button>
        {!isToday && <button className="btn-ghost text-xs" onClick={() => setDate(todayStr())}>Jump to today</button>}
        <span className="text-xs text-slate-400">vs. {fmtDate(shiftDate(date, -1))}</span>
      </div>
      {data ? <DailyView d={data} prev={prev} /> : <Empty text="Loading…" />}
    </div>
  );
}

function MonthlyTab() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const { data } = useFetch<Monthly>(`/reports/monthly?month=${month}`, [month]);
  const max = Math.max(1, ...(data?.days ?? []).map((d) => d.revenue));
  const isCurrentMonth = month === new Date().toISOString().slice(0, 7);
  const bestDay = useMemo(() => (data?.days ?? []).reduce((best, d) => (d.revenue > (best?.revenue ?? -1) ? d : best), null as Monthly["days"][number] | null), [data]);
  const avgRevenue = data && data.days.length > 0 ? data.total_revenue / data.days.length : 0;

  const exportCsv = () => {
    if (!data) return;
    downloadCsv(`monthly-report-${data.month}.csv`, [
      ["Date", "Revenue (LKR)", "Occupancy %"],
      ...data.days.map((d) => [d.date, (d.revenue / 100).toFixed(2), d.occupancy_pct]),
    ]);
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <button className="btn-secondary !px-2.5" onClick={() => setMonth(shiftMonth(month, -1))}><ChevronLeft size={15} /></button>
        <input type="month" className="input !w-44" value={month} onChange={(e) => setMonth(e.target.value)} />
        <button className="btn-secondary !px-2.5" disabled={isCurrentMonth} onClick={() => setMonth(shiftMonth(month, 1))}><ChevronRight size={15} /></button>
      </div>
      {data && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <HeroStat label="Month revenue" value={lkr(data.total_revenue)} />
            <HeroStat label="Average occupancy" value={`${data.avg_occupancy}%`} />
            <HeroStat label="Best day" value={bestDay ? lkr(bestDay.revenue) : "—"} sub={bestDay ? fmtDate(bestDay.date) : undefined} />
          </div>
          <Card
            title="Daily revenue"
            actions={
              <div className="flex gap-1">
                <button className="btn-ghost !py-1 text-xs" onClick={exportCsv}><Download size={13} /> CSV</button>
                <button className="btn-ghost !py-1 text-xs" onClick={() => openPdf(`/reports/monthly/pdf?month=${data.month}`)}><FileText size={13} /> PDF</button>
              </div>
            }
          >
            <div className="relative flex h-40 items-end gap-1">
              {avgRevenue > 0 && (
                <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-slate-300" style={{ bottom: `${(avgRevenue / max) * 150}px` }}>
                  <span className="absolute -top-4 right-0 text-[9px] font-semibold text-slate-400">avg {lkr(avgRevenue)}</span>
                </div>
              )}
              {data.days.map((d) => (
                <div key={d.date} className="group relative flex-1">
                  <div
                    className={clsx("w-full rounded-t transition group-hover:bg-brand-700", bestDay?.date === d.date ? "bg-emerald-500" : "bg-brand-500")}
                    style={{ height: `${(d.revenue / max) * 150 + 2}px` }}
                  />
                  <div className="absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-2 py-1 text-[10px] text-white group-hover:block">
                    {fmtDate(d.date)}: {lkr(d.revenue)} · {d.occupancy_pct}%
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </>
      )}
      {!data && <Empty text="Loading…" />}
    </div>
  );
}

const RANGE_PRESETS = [
  { label: "7 days", days: 6 },
  { label: "14 days", days: 13 },
  { label: "30 days", days: 29 },
];

function PosTab() {
  const [from, setFrom] = useState(todayStr(-6));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<PosReport>(`/reports/pos?from=${from}&to=${to}`, [from, to]);
  const catMax = Math.max(1, ...Object.values(data?.by_category ?? {}));
  const payMax = Math.max(1, ...Object.values(data?.payment_method_breakdown ?? {}));
  const bestMax = Math.max(1, ...(data?.best_sellers ?? []).map((b) => b.amount));

  const exportCsv = () => {
    if (!data) return;
    downloadCsv(`pos-report-${data.from}-to-${data.to}.csv`, [
      ["Mount View Hotel — POS report", `${data.from} to ${data.to}`],
      [],
      ["Category", "Amount (LKR)"],
      ...Object.entries(data.by_category).map(([k, v]) => [k, (v / 100).toFixed(2)]),
      [],
      ["Payment method", "Amount (LKR)"],
      ...Object.entries(data.payment_method_breakdown).map(([k, v]) => [k, (v / 100).toFixed(2)]),
      [],
      ["Best seller", "Qty", "Amount (LKR)"],
      ...data.best_sellers.map((b) => [b.name, b.qty, (b.amount / 100).toFixed(2)]),
    ]);
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        {RANGE_PRESETS.map((p) => (
          <button key={p.label} className="btn-secondary !py-1.5 text-xs" onClick={() => { setFrom(todayStr(-p.days)); setTo(todayStr()); }}>
            Last {p.label}
          </button>
        ))}
        <input type="date" className="input !w-40" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
        <span className="text-xs text-slate-400">to</span>
        <input type="date" className="input !w-40" value={to} max={todayStr()} onChange={(e) => setTo(e.target.value)} />
      </div>
      {data && (
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="lg:col-span-3">
            <HeroStat label="Total POS sales" value={lkr(data.total_sales)} sub={`${fmtDate(data.from)} → ${fmtDate(data.to)}`} />
          </div>
          <Card title="Sales by category">
            {Object.keys(data.by_category).length === 0 ? (
              <Empty text="—" />
            ) : (
              <div className="space-y-2.5">
                {Object.entries(data.by_category).map(([k, v]) => (
                  <BarRow key={k} label={k} amount={v} max={catMax} />
                ))}
              </div>
            )}
          </Card>
          <Card title="Payment methods">
            {Object.keys(data.payment_method_breakdown).length === 0 ? (
              <Empty text="—" />
            ) : (
              <div className="space-y-2.5">
                {Object.entries(data.payment_method_breakdown).map(([k, v]) => (
                  <BarRow key={k} label={k} amount={v} max={payMax} colorClass={METHOD_COLOR[k] ?? "bg-slate-400"} />
                ))}
              </div>
            )}
          </Card>
          <Card title="Best sellers">
            {data.best_sellers.length === 0 ? (
              <Empty text="—" />
            ) : (
              <div className="space-y-2.5">
                {data.best_sellers.slice(0, 6).map((b, i) => (
                  <BarRow key={b.name} label={`${b.name} (${b.qty}×)`} amount={b.amount} max={bestMax} rank={i + 1} />
                ))}
              </div>
            )}
          </Card>
          <div className="flex gap-2 lg:col-span-3">
            <button className="btn-secondary" onClick={exportCsv}><Download size={14} /> Export CSV</button>
            <button className="btn-secondary" onClick={() => openPdf(`/reports/pos/pdf?from=${data.from}&to=${data.to}`)}><FileText size={14} /> Download PDF</button>
          </div>
        </div>
      )}
      {!data && <Empty text="Loading…" />}
    </div>
  );
}

function AuditTab() {
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Audit>(`/reports/night-audit?page=${page}&page_size=${pageSize}`, "night_audits", [page, pageSize]);
  const audits = data?.rows;
  const [error, setError] = useState("");
  const [runDate, setRunDate] = useState(todayStr());
  const [viewing, setViewing] = useState<Audit | null>(null);
  const [busy, setBusy] = useState(false);

  const run = () => {
    setBusy(true);
    post("/reports/night-audit/run", { date: runDate })
      .then(() => {
        setError("");
        reload();
      })
      .catch((e) => setError(e.message))
      .finally(() => setBusy(false));
  };

  return (
    <div className="space-y-3">
      {can("hotel_reports.night_audit_run") && (
        <Card title="Run night audit">
          <div className="flex flex-wrap items-center gap-2">
            <Calendar size={15} className="text-slate-400" />
            <input type="date" className="input !w-44" max={todayStr()} value={runDate} onChange={(e) => setRunDate(e.target.value)} />
            <button className="btn-primary" disabled={busy} onClick={run}>
              <Play size={14} /> {busy ? "Running…" : `Run audit for ${fmtDate(runDate)}`}
            </button>
            <span className="text-xs text-slate-500">Stores a permanent snapshot: revenue, occupancy, cash collected & drawer variances. Can be re-run for any past date not yet audited.</span>
          </div>
          <ErrorText error={error} />
        </Card>
      )}

      <div className="card divide-y divide-slate-50">
        {(audits ?? []).map((a) => {
          const net = a.data.payments?.net ?? 0;
          const hasVariance = a.data.shifts?.some((s) => (s.variance ?? 0) !== 0);
          return (
            <button key={a.id} className="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" onClick={() => setViewing(viewing?.id === a.id ? null : a)}>
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Wallet size={15} /></span>
              <span className="font-bold">{fmtDate(a.business_date)}</span>
              <span className="text-xs text-slate-400">run by {a.run_by.name}</span>
              <span className="ml-auto flex items-center gap-2">
                <span>net <b className="text-brand-700">{lkr(net)}</b></span>
                <Badge>{a.data.occupancy?.pct ?? 0}% occ.</Badge>
                {hasVariance && <Badge color="amber">cash variance</Badge>}
              </span>
            </button>
          );
        })}
        {(audits ?? []).length === 0 && <Empty text="No night audits run yet" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>
      {viewing && (
        <div>
          <div className="mb-2 flex items-center gap-1.5 text-sm font-bold text-slate-600">
            <Trophy size={15} className="text-amber-500" /> Snapshot — {fmtDate(viewing.business_date)}
          </div>
          <DailyView d={viewing.data} pdfUrl={`/reports/night-audit/${viewing.id}/pdf`} />
        </div>
      )}
    </div>
  );
}
