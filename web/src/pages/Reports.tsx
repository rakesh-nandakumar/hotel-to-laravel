import { useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  BarChart3, ChevronLeft, ChevronRight, Download, TrendingUp, TrendingDown,
  Play, Calendar, Trophy, Wallet, FileText, Gauge, Share2, XCircle, Heart,
  Building2, ClipboardCheck, PartyPopper, Shirt,
} from "lucide-react";
import { post, openPdf } from "../lib/api";
import { useFetch, usePagedFetch, lkr, todayStr, fmtDate, downloadCsv } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Pagination, ReportGrid, ReportDef, SimpleTable, DateRangeBar, Stat } from "../components/ui";
import { useAuth } from "../lib/auth";
import { useBranding } from "../lib/branding";
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

const HOTEL_REPORTS: ReportDef[] = [
  { key: "daily", label: "Daily Operations", description: "Occupancy, revenue by source, payments, POS best-sellers, shift variance.", icon: <Calendar size={18} />, permission: "hotel_reports.daily" },
  { key: "monthly", label: "Monthly Performance", description: "Daily revenue & occupancy trend across a month.", icon: <BarChart3 size={18} />, permission: "hotel_reports.monthly" },
  { key: "night_audit", label: "Night Audit", description: "Permanent daily snapshots — revenue, occupancy, cash reconciliation.", icon: <Trophy size={18} />, permission: "hotel_reports.night_audit_view" },
  { key: "revpar", label: "RevPAR & ADR", description: "Average daily rate, revenue per available room, occupancy trend.", icon: <Gauge size={18} />, permission: "hotel_reports.revpar" },
  { key: "channel_mix", label: "Booking Channel Mix", description: "Reservations & revenue by booking source.", icon: <Share2 size={18} />, permission: "hotel_reports.channel_mix" },
  { key: "cancellations", label: "Cancellations & No-shows", description: "Cancelled reservations by reason, no-show tracking.", icon: <XCircle size={18} />, permission: "hotel_reports.cancellations" },
  { key: "guest_loyalty", label: "Guest & Loyalty", description: "Top guests by spend, repeat-guest rate, loyalty points.", icon: <Heart size={18} />, permission: "hotel_reports.guest_loyalty" },
  { key: "corporate_ar", label: "Corporate Accounts (AR)", description: "Outstanding balances, aging, credit utilization.", icon: <Building2 size={18} />, permission: "hotel_reports.corporate_ar" },
  { key: "ops_sla", label: "Housekeeping & Maintenance", description: "Task turnaround & resolution time by status.", icon: <ClipboardCheck size={18} />, permission: "hotel_reports.ops_sla" },
  { key: "payroll_cost", label: "Payroll Cost", description: "Labor cost, OT, EPF/ETF/APIT — by month.", icon: <Wallet size={18} />, permission: "hotel_reports.payroll_cost" },
  { key: "venues", label: "Venues & Banquets", description: "Bookings, revenue and utilization by venue.", icon: <PartyPopper size={18} />, permission: "hotel_reports.venues" },
  { key: "laundry", label: "Laundry Revenue", description: "Revenue by laundry item over a date range.", icon: <Shirt size={18} />, permission: "hotel_reports.laundry" },
];

export default function Reports() {
  const { can } = useAuth();
  const nav = useNavigate();
  const { reportKey } = useParams<{ reportKey: string }>();
  const visible = HOTEL_REPORTS.filter((r) => !r.permission || can(r.permission));
  const def = visible.find((r) => r.key === reportKey);

  if (!reportKey) {
    return (
      <div className="space-y-4">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><BarChart3 /> Reports</h1>
        <ReportGrid reports={visible} onSelect={(key) => nav(`/reports/${key}`)} />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <button className="btn-ghost text-sm" onClick={() => nav("/reports")}><ChevronLeft size={14} /> All reports</button>
      {!def ? (
        <ErrorText error="You don't have access to this report." />
      ) : (
        <>
          <h1 className="flex items-center gap-2 text-xl font-extrabold">{def.icon} {def.label}</h1>
          {reportKey === "daily" && <DailyTab />}
          {reportKey === "monthly" && <MonthlyTab />}
          {reportKey === "night_audit" && <AuditTab />}
          {reportKey === "revpar" && <RevParTab />}
          {reportKey === "channel_mix" && <ChannelMixTab />}
          {reportKey === "cancellations" && <CancellationsTab />}
          {reportKey === "guest_loyalty" && <GuestLoyaltyTab />}
          {reportKey === "corporate_ar" && <CorporateArTab />}
          {reportKey === "ops_sla" && <OpsSlaTab />}
          {reportKey === "payroll_cost" && <PayrollCostTab />}
          {reportKey === "venues" && <VenuesTab />}
          {reportKey === "laundry" && <LaundryTab />}
        </>
      )}
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
  const { branding } = useBranding();
  const revMax = Math.max(1, ...Object.values(d.revenue_by_source), d.walkin_pos_revenue);
  const payMax = Math.max(1, ...Object.values(d.payments.by_method));
  const catMax = Math.max(1, ...Object.values(d.pos.by_category));

  const exportCsv = () => {
    const rows: (string | number)[][] = [
      [`${branding.name} — Daily Report`, d.date],
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

// ── new reports ──────────────────────────────────────────────────────────────

type RevPar = {
  from: string; to: string; total_rooms: number; available_room_nights: number; room_nights_sold: number;
  room_revenue: number; adr: number; revpar: number; occupancy_pct: number;
  series: { date: string; room_revenue: number; occupied_rooms: number; adr: number; revpar: number }[];
};

function RevParTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<RevPar>(`/reports/revpar?from=${from}&to=${to}`, [from, to]);
  const max = Math.max(1, ...(data?.series ?? []).map((d) => d.revpar));

  const exportCsv = () => {
    if (!data) return;
    downloadCsv(`revpar-${data.from}-to-${data.to}.csv`, [
      ["Date", "Room revenue (LKR)", "Occupied rooms", "ADR (LKR)", "RevPAR (LKR)"],
      ...data.series.map((d) => [d.date, (d.room_revenue / 100).toFixed(2), d.occupied_rooms, (d.adr / 100).toFixed(2), (d.revpar / 100).toFixed(2)]),
    ]);
  };

  return (
    <div className="space-y-3">
      <DateRangeBar
        from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()}
        presets={[7, 14, 30].map((n) => ({ label: `${n} days`, onClick: () => { setFrom(todayStr(-(n - 1))); setTo(todayStr()); } }))}
      />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="ADR" value={lkr(data.adr)} sub="Average Daily Rate" />
            <Stat label="RevPAR" value={lkr(data.revpar)} sub="Revenue per available room" />
            <Stat label="Occupancy" value={`${data.occupancy_pct}%`} sub={`${data.room_nights_sold}/${data.available_room_nights} room-nights`} />
            <Stat label="Room revenue" value={lkr(data.room_revenue)} color="brand" />
          </div>
          <Card title="Daily RevPAR trend" actions={<button className="btn-ghost !py-1 text-xs" onClick={exportCsv}><Download size={13} /> CSV</button>}>
            <div className="flex h-32 items-end gap-1">
              {data.series.map((d) => (
                <div key={d.date} className="group relative flex-1">
                  <div className="w-full rounded-t bg-brand-500 transition group-hover:bg-brand-700" style={{ height: `${(d.revpar / max) * 110 + 2}px` }} />
                  <div className="absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-2 py-1 text-[10px] text-white group-hover:block">
                    {fmtDate(d.date)}: RevPAR {lkr(d.revpar)} · ADR {lkr(d.adr)}
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type ChannelMix = { from: string; to: string; by_channel: Record<string, { reservations: number; revenue: number }>; total_reservations: number };

function ChannelMixTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<ChannelMix>(`/reports/channel-mix?from=${from}&to=${to}`, [from, to]);
  const rows = Object.entries(data?.by_channel ?? {}).map(([code, v]) => ({ code, ...v }));

  const exportCsv = () => downloadCsv(`channel-mix-${from}-to-${to}.csv`, [["Channel", "Reservations", "Revenue (LKR)"], ...rows.map((r) => [r.code, r.reservations, (r.revenue / 100).toFixed(2)])]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      <Card title="Reservations & revenue by channel" actions={<button className="btn-ghost !py-1 text-xs" onClick={exportCsv}><Download size={13} /> CSV</button>}>
        <SimpleTable
          columns={[
            { key: "code", label: "Channel" },
            { key: "reservations", label: "Reservations", align: "right" },
            { key: "revenue", label: "Revenue", align: "right", render: (r) => lkr(r.revenue) },
          ]}
          rows={rows}
          rowKey={(r) => r.code}
          empty="No reservations in this range"
        />
      </Card>
    </div>
  );
}

type Cancellations = {
  from: string; to: string; cancelled_count: number; no_show_count: number; total_reservations: number; cancellation_rate_pct: number;
  by_reason: Record<string, number>;
  cancelled: { id: number; code: string; guest: string; channel: string | null; check_in: string; cancelled_at: string; reason: string | null }[];
  no_shows: { id: number; code: string; guest: string; channel: string | null; check_in: string }[];
};

function CancellationsTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<Cancellations>(`/reports/cancellations?from=${from}&to=${to}`, [from, to]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Cancelled" value={data.cancelled_count} />
            <Stat label="No-shows" value={data.no_show_count} />
            <Stat label="Cancellation rate" value={`${data.cancellation_rate_pct}%`} />
            <Stat label="Total reservations" value={data.total_reservations} />
          </div>
          <Card title="Cancelled reservations">
            <SimpleTable
              columns={[
                { key: "code", label: "Code" }, { key: "guest", label: "Guest" },
                { key: "check_in", label: "Check-in", render: (r) => fmtDate(r.check_in) },
                { key: "reason", label: "Reason", render: (r) => r.reason ?? "Not specified" },
              ]}
              rows={data.cancelled}
              rowKey={(r) => r.id}
              empty="No cancellations in this range"
            />
          </Card>
          <Card title="No-shows">
            <SimpleTable
              columns={[{ key: "code", label: "Code" }, { key: "guest", label: "Guest" }, { key: "check_in", label: "Check-in", render: (r) => fmtDate(r.check_in) }]}
              rows={data.no_shows}
              rowKey={(r) => r.id}
              empty="No no-shows in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type GuestLoyalty = {
  from: string; to: string;
  top_guests: { guest_id: number; name: string; spend: number; stays: number }[];
  distinct_guests: number; repeat_guests: number; repeat_rate_pct: number;
  loyalty_points_issued: number; loyalty_points_redeemed: number;
};

function GuestLoyaltyTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<GuestLoyalty>(`/reports/guest-loyalty?from=${from}&to=${to}`, [from, to]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Guests who stayed" value={data.distinct_guests} />
            <Stat label="Repeat rate" value={`${data.repeat_rate_pct}%`} sub={`${data.repeat_guests} repeat guests`} />
            <Stat label="Loyalty points issued" value={data.loyalty_points_issued.toLocaleString()} />
            <Stat label="Loyalty points redeemed" value={data.loyalty_points_redeemed.toLocaleString()} />
          </div>
          <Card title="Top guests by spend">
            <SimpleTable
              columns={[
                { key: "name", label: "Guest" },
                { key: "stays", label: "Stays", align: "right" },
                { key: "spend", label: "Spend", align: "right", render: (r) => lkr(r.spend) },
              ]}
              rows={data.top_guests}
              rowKey={(r) => r.guest_id}
              empty="No spend in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type CorporateAr = {
  accounts: { id: number; company_name: string; credit_limit: number; charged: number; paid: number; balance: number; credit_utilization_pct: number; aging_bucket: string; days_outstanding: number }[];
  total_outstanding: number;
  by_bucket: Record<string, number>;
};

function CorporateArTab() {
  const { data } = useFetch<CorporateAr>(`/reports/corporate-ar`);

  return (
    <div className="space-y-3">
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Total outstanding" value={lkr(data.total_outstanding)} color="brand" />
            {Object.entries(data.by_bucket).filter(([b]) => b !== "current").map(([bucket, amt]) => (
              <Stat key={bucket} label={`Aged ${bucket} days`} value={lkr(amt)} />
            ))}
          </div>
          <Card title="Corporate accounts">
            <SimpleTable
              columns={[
                { key: "company_name", label: "Company" },
                { key: "balance", label: "Balance", align: "right", render: (r) => lkr(r.balance) },
                { key: "credit_utilization_pct", label: "Credit used", align: "right", render: (r) => `${r.credit_utilization_pct}%` },
                { key: "aging_bucket", label: "Aging", align: "center", render: (r) => <Badge color={r.aging_bucket === "current" ? "green" : r.aging_bucket === "90+" ? "red" : "amber"}>{r.aging_bucket}</Badge> },
              ]}
              rows={data.accounts}
              rowKey={(r) => r.id}
              empty="No active corporate accounts"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type OpsSla = {
  from: string; to: string;
  housekeeping: { total: number; by_status: Record<string, number>; completed: number; avg_turnaround_minutes: number };
  maintenance: { total: number; by_status: Record<string, number>; resolved: number; avg_resolution_hours: number };
};

function OpsSlaTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<OpsSla>(`/reports/ops-sla?from=${from}&to=${to}`, [from, to]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Housekeeping">
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Tasks" value={data.housekeeping.total} />
              <Stat label="Avg turnaround" value={`${data.housekeeping.avg_turnaround_minutes}m`} />
            </div>
            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
              {Object.entries(data.housekeeping.by_status).map(([code, count]) => (
                <div key={code} className="rounded-lg bg-slate-50 p-3 text-center"><div className="text-xl font-extrabold">{count}</div><div className="text-xs text-slate-500">{code}</div></div>
              ))}
            </div>
          </Card>
          <Card title="Maintenance">
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Issues" value={data.maintenance.total} />
              <Stat label="Avg resolution" value={`${data.maintenance.avg_resolution_hours}h`} />
            </div>
            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
              {Object.entries(data.maintenance.by_status).map(([code, count]) => (
                <div key={code} className="rounded-lg bg-slate-50 p-3 text-center"><div className="text-xl font-extrabold">{count}</div><div className="text-xs text-slate-500">{code}</div></div>
              ))}
            </div>
          </Card>
        </div>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type PayrollCost = {
  month: string; found: boolean; status?: string; staff_count?: number;
  totals?: { base_salary: number; ot_pay: number; allowance: number; bonus: number; gross: number; epf_employee: number; epf_employer: number; etf: number; apit: number; net_pay: number; employer_cost: number };
  by_staff?: { user: string; gross: number; net_pay: number; employer_cost: number }[];
  trend: { month: string; employer_cost: number; net_pay: number }[];
};

function PayrollCostTab() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const { data } = useFetch<PayrollCost>(`/reports/payroll-cost?month=${month}`, [month]);
  const max = Math.max(1, ...(data?.trend ?? []).map((t) => t.employer_cost));

  return (
    <div className="space-y-3">
      <input type="month" className="input !w-44" value={month} onChange={(e) => setMonth(e.target.value)} />
      {data?.found && data.totals && (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Staff" value={data.staff_count} />
            <Stat label="Gross pay" value={lkr(data.totals.gross)} />
            <Stat label="Net pay" value={lkr(data.totals.net_pay)} />
            <Stat label="Employer cost" value={lkr(data.totals.employer_cost)} color="brand" />
          </div>
          <Card title="By staff">
            <SimpleTable
              columns={[
                { key: "user", label: "Staff" },
                { key: "gross", label: "Gross", align: "right", render: (r) => lkr(r.gross) },
                { key: "net_pay", label: "Net", align: "right", render: (r) => lkr(r.net_pay) },
                { key: "employer_cost", label: "Employer cost", align: "right", render: (r) => lkr(r.employer_cost) },
              ]}
              rows={data.by_staff ?? []}
              rowKey={(r) => r.user}
            />
          </Card>
        </>
      )}
      {data && !data.found && <Empty text={`No payroll run found for ${month}`} />}
      {data && data.trend.length > 0 && (
        <Card title="Employer cost trend">
          <div className="flex h-32 items-end gap-1">
            {data.trend.map((t) => (
              <div key={t.month} className="group relative flex-1">
                <div className="w-full rounded-t bg-brand-500" style={{ height: `${(t.employer_cost / max) * 110 + 2}px` }} />
                <div className="absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-2 py-1 text-[10px] text-white group-hover:block">{t.month}: {lkr(t.employer_cost)}</div>
              </div>
            ))}
          </div>
        </Card>
      )}
      {!data && <Empty text="Loading…" />}
    </div>
  );
}

type Venues = {
  from: string; to: string; total_bookings: number; total_hours: number; total_guest_count: number; revenue: number;
  by_venue: Record<string, { bookings: number; hours: number }>;
  by_status: Record<string, number>;
};

function VenuesTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr(30));
  const { data } = useFetch<Venues>(`/reports/venues?from=${from}&to=${to}`, [from, to]);
  const rows = Object.entries(data?.by_venue ?? {}).map(([name, v]) => ({ name, ...v }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Bookings" value={data.total_bookings} />
            <Stat label="Guest count" value={data.total_guest_count} />
            <Stat label="Hours booked" value={data.total_hours} />
            <Stat label="Revenue" value={lkr(data.revenue)} color="brand" />
          </div>
          <Card title="By venue">
            <SimpleTable
              columns={[{ key: "name", label: "Venue" }, { key: "bookings", label: "Bookings", align: "right" }, { key: "hours", label: "Hours", align: "right" }]}
              rows={rows}
              rowKey={(r) => r.name}
              empty="No venue bookings in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type Laundry = { from: string; to: string; total_revenue: number; total_items: number; by_item: Record<string, { qty: number; amount: number }> };

function LaundryTab() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  const { data } = useFetch<Laundry>(`/reports/laundry?from=${from}&to=${to}`, [from, to]);
  const rows = Object.entries(data?.by_item ?? {}).map(([name, v]) => ({ name, ...v }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Revenue" value={lkr(data.total_revenue)} color="brand" />
            <Stat label="Items processed" value={data.total_items} />
          </div>
          <Card title="By item">
            <SimpleTable
              columns={[{ key: "name", label: "Item" }, { key: "qty", label: "Qty", align: "right" }, { key: "amount", label: "Revenue", align: "right", render: (r) => lkr(r.amount) }]}
              rows={rows}
              rowKey={(r) => r.name}
              empty="No laundry charges in this range"
            />
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}
