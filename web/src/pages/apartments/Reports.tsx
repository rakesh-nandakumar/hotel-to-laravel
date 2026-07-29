import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  BarChart3, ChevronLeft, TrendingUp, Share2, FileWarning, HandCoins, Zap, Wrench,
} from "lucide-react";
import { useFetch, lkr, todayStr, fmtDate } from "../../lib/util";
import { Badge, Card, Empty, ErrorText, ReportGrid, ReportDef, SimpleTable, DateRangeBar, Stat } from "../../components/ui";
import { useAuth } from "../../lib/auth";

const APARTMENT_REPORTS: ReportDef[] = [
  { key: "dashboard", label: "Operations Dashboard", description: "Occupancy, arrivals/departures, arrears, sales pipeline — right now.", icon: <BarChart3 size={18} />, permission: "apartment_reports.dashboard" },
  { key: "occupancy_trend", label: "Occupancy Trend", description: "Occupancy % over time across rental units.", icon: <TrendingUp size={18} />, permission: "apartment_reports.occupancy_trend" },
  { key: "revenue_channel", label: "Rental Revenue & Channel Mix", description: "Bookings & revenue by channel and property.", icon: <Share2 size={18} />, permission: "apartment_reports.revenue_channel" },
  { key: "rent_roll", label: "Rent Roll & Arrears Aging", description: "Active leases, balances, 30/60/90+ aging buckets.", icon: <FileWarning size={18} />, permission: "apartment_reports.rent_roll" },
  { key: "sales_pipeline", label: "Sales Pipeline & Conversion", description: "Funnel counts, conversion rate, days-to-close.", icon: <HandCoins size={18} />, permission: "apartment_reports.sales_pipeline" },
  { key: "utilities", label: "Utilities Consumption & Cost", description: "Usage & cost by utility type and unit.", icon: <Zap size={18} />, permission: "apartment_reports.utilities" },
  { key: "ops_sla", label: "Maintenance & Housekeeping Ops", description: "Turnaround/resolution time, counts by status.", icon: <Wrench size={18} />, permission: "apartment_reports.ops_sla" },
];

export default function ApartmentReports() {
  const { can } = useAuth();
  const nav = useNavigate();
  const { reportKey } = useParams<{ reportKey: string }>();
  const visible = APARTMENT_REPORTS.filter((r) => !r.permission || can(r.permission));
  const def = visible.find((r) => r.key === reportKey);

  if (!reportKey) {
    return (
      <div className="space-y-4">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><BarChart3 /> Apartments Reports</h1>
        <ReportGrid reports={visible} onSelect={(key) => nav(`/apartments/reports/${key}`)} />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <button className="btn-ghost text-sm" onClick={() => nav("/apartments/reports")}><ChevronLeft size={14} /> All reports</button>
      {!def ? (
        <ErrorText error="You don't have access to this report." />
      ) : (
        <>
          <h1 className="flex items-center gap-2 text-xl font-extrabold">{def.icon} {def.label}</h1>
          {reportKey === "dashboard" && <DashboardTab />}
          {reportKey === "occupancy_trend" && <OccupancyTrendTab />}
          {reportKey === "revenue_channel" && <RevenueChannelTab />}
          {reportKey === "rent_roll" && <RentRollTab />}
          {reportKey === "sales_pipeline" && <SalesPipelineTab />}
          {reportKey === "utilities" && <UtilitiesTab />}
          {reportKey === "ops_sla" && <OpsSlaTab />}
        </>
      )}
    </div>
  );
}

// ── Operations Dashboard (existing content, now one card among many) ──────────
type Dashboard = {
  units: { total: number; by_status: Record<string, number> };
  bookings: {
    arrivals_today: { id: number; code: string; customer: { name: string }; unit: { unit_no: string } }[];
    departures_today: { id: number; code: string; customer: { name: string }; unit: { unit_no: string } }[];
    checked_in_count: number;
  };
  leases: {
    active_count: number;
    expiring_within_30_days: { id: number; code: string; end_date: string; customer: { name: string }; unit: { unit_no: string } }[];
    overdue: { id: number; code: string; customer: string; unit: string; balance: number }[];
  };
  sales_pipeline: Record<string, number>;
  revenue_this_month: number;
  ops: { pending_housekeeping: number; open_maintenance: number };
};

const STATUS_LABELS: Record<string, string> = {
  available: "Available", occupied: "Occupied", dirty: "Dirty", reserved: "Reserved",
  maintenance: "Maintenance", blocked: "Blocked", sold: "Sold", off_market: "Off Market",
};
const SALE_STAGE_LABELS: Record<string, string> = {
  inquiry: "Inquiry", reserved: "Reserved", agreement_signed: "Agreement Signed", completed: "Completed", cancelled: "Cancelled",
};

function DashboardTab() {
  const { data, error } = useFetch<Dashboard>("/apartments/reports/dashboard");
  const nav = useNavigate();

  if (error) return <ErrorText error={error} />;
  if (!data) return <Empty text="Loading…" />;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Total units" value={data.units.total} />
        <Stat label="Checked in" value={data.bookings.checked_in_count} />
        <Stat label="Active leases" value={data.leases.active_count} />
        <Stat label="Revenue this month" value={lkr(data.revenue_this_month)} color="brand" />
      </div>

      <Card title="Unit inventory by status">
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {Object.entries(data.units.by_status).map(([code, count]) => (
            <div key={code} className="rounded-lg bg-slate-50 p-3 text-center">
              <div className="text-2xl font-extrabold">{count}</div>
              <div className="text-xs text-slate-500">{STATUS_LABELS[code] ?? code}</div>
            </div>
          ))}
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Arrivals today">
          <div className="divide-y divide-slate-50 text-sm">
            {data.bookings.arrivals_today.map((b) => (
              <button key={b.id} className="flex w-full justify-between py-1.5 text-left hover:bg-slate-50" onClick={() => nav(`/apartments/bookings/${b.id}`)}>
                <span>{b.customer.name}</span><span className="text-slate-400">{b.unit.unit_no}</span>
              </button>
            ))}
            {data.bookings.arrivals_today.length === 0 && <Empty text="No arrivals today" />}
          </div>
        </Card>
        <Card title="Departures today">
          <div className="divide-y divide-slate-50 text-sm">
            {data.bookings.departures_today.map((b) => (
              <button key={b.id} className="flex w-full justify-between py-1.5 text-left hover:bg-slate-50" onClick={() => nav(`/apartments/bookings/${b.id}`)}>
                <span>{b.customer.name}</span><span className="text-slate-400">{b.unit.unit_no}</span>
              </button>
            ))}
            {data.bookings.departures_today.length === 0 && <Empty text="No departures today" />}
          </div>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Leases expiring within 30 days">
          <div className="divide-y divide-slate-50 text-sm">
            {data.leases.expiring_within_30_days.map((l) => (
              <button key={l.id} className="flex w-full justify-between py-1.5 text-left hover:bg-slate-50" onClick={() => nav(`/apartments/leases/${l.id}`)}>
                <span>{l.customer.name} — {l.unit.unit_no}</span><span className="text-slate-400">{fmtDate(l.end_date)}</span>
              </button>
            ))}
            {data.leases.expiring_within_30_days.length === 0 && <Empty text="Nothing expiring soon" />}
          </div>
        </Card>
        <Card title="Overdue rent">
          <div className="divide-y divide-slate-50 text-sm">
            {data.leases.overdue.map((l) => (
              <button key={l.id} className="flex w-full justify-between py-1.5 text-left hover:bg-slate-50" onClick={() => nav(`/apartments/leases/${l.id}`)}>
                <span>{l.customer} — {l.unit}</span><span className="font-semibold text-red-600">{lkr(l.balance)}</span>
              </button>
            ))}
            {data.leases.overdue.length === 0 && <Empty text="No arrears 🎉" />}
          </div>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Sales pipeline">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {Object.entries(data.sales_pipeline).map(([code, count]) => (
              <div key={code} className="rounded-lg bg-slate-50 p-3 text-center">
                <div className="text-2xl font-extrabold">{count}</div>
                <div className="text-xs text-slate-500">{SALE_STAGE_LABELS[code] ?? code}</div>
              </div>
            ))}
            {Object.keys(data.sales_pipeline).length === 0 && <Empty text="No sales activity yet" />}
          </div>
        </Card>
        <Card title="Operations">
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Pending housekeeping" value={data.ops.pending_housekeeping} />
            <Stat label="Open maintenance" value={data.ops.open_maintenance} />
          </div>
        </Card>
      </div>
    </div>
  );
}

// ── new reports ──────────────────────────────────────────────────────────────
function useApartmentDateRange() {
  const [from, setFrom] = useState(todayStr(-29));
  const [to, setTo] = useState(todayStr());
  return { from, setFrom, to, setTo };
}

type OccupancyTrend = { from: string; to: string; total_rental_units: number; avg_occupancy_pct: number; series: { date: string; occupied_units: number; occupancy_pct: number }[] };

function OccupancyTrendTab() {
  const { from, setFrom, to, setTo } = useApartmentDateRange();
  const { data } = useFetch<OccupancyTrend>(`/apartments/reports/occupancy-trend?from=${from}&to=${to}`, [from, to]);
  const max = Math.max(1, ...(data?.series ?? []).map((d) => d.occupancy_pct));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Total rental units" value={data.total_rental_units} />
            <Stat label="Average occupancy" value={`${data.avg_occupancy_pct}%`} color="brand" />
          </div>
          <Card title="Daily occupancy">
            <div className="flex h-32 items-end gap-1">
              {data.series.map((d) => (
                <div key={d.date} className="group relative flex-1">
                  <div className="w-full rounded-t bg-brand-500 transition group-hover:bg-brand-700" style={{ height: `${(d.occupancy_pct / max) * 110 + 2}px` }} />
                  <div className="absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-2 py-1 text-[10px] text-white group-hover:block">
                    {fmtDate(d.date)}: {d.occupancy_pct}% ({d.occupied_units} units)
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

type RevenueChannel = { from: string; to: string; total_bookings: number; by_channel: Record<string, { bookings: number; revenue: number }>; by_property: Record<string, number> };

function RevenueChannelTab() {
  const { from, setFrom, to, setTo } = useApartmentDateRange();
  const { data } = useFetch<RevenueChannel>(`/apartments/reports/revenue-channel?from=${from}&to=${to}`, [from, to]);
  const channelRows = Object.entries(data?.by_channel ?? {}).map(([code, v]) => ({ code, ...v }));
  const propertyRows = Object.entries(data?.by_property ?? {}).map(([property, bookings]) => ({ property, bookings }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="lg:col-span-2"><Stat label="Total bookings" value={data.total_bookings} color="brand" /></div>
          <Card title="By channel">
            <SimpleTable
              columns={[{ key: "code", label: "Channel" }, { key: "bookings", label: "Bookings", align: "right" }, { key: "revenue", label: "Revenue", align: "right", render: (r) => lkr(r.revenue) }]}
              rows={channelRows} rowKey={(r) => r.code} empty="No bookings in this range"
            />
          </Card>
          <Card title="By property">
            <SimpleTable columns={[{ key: "property", label: "Property" }, { key: "bookings", label: "Bookings", align: "right" }]} rows={propertyRows} rowKey={(r) => r.property} empty="No bookings in this range" />
          </Card>
        </div>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type RentRoll = {
  leases: { id: number; code: string; customer: string; unit: string; monthly_rent: number; balance: number; aging_bucket: string; days_outstanding: number }[];
  total_monthly_rent: number; total_arrears: number; by_bucket: Record<string, number>;
};

function RentRollTab() {
  const nav = useNavigate();
  const { data } = useFetch<RentRoll>("/apartments/reports/rent-roll");

  return (
    <div className="space-y-3">
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Monthly rent roll" value={lkr(data.total_monthly_rent)} />
            <Stat label="Total arrears" value={lkr(data.total_arrears)} color="brand" />
            {Object.entries(data.by_bucket).filter(([b]) => b !== "current").map(([bucket, amt]) => (
              <Stat key={bucket} label={`Aged ${bucket} days`} value={lkr(amt)} />
            ))}
          </div>
          <Card title="Active leases">
            <SimpleTable
              columns={[
                { key: "customer", label: "Tenant" },
                { key: "unit", label: "Unit" },
                { key: "monthly_rent", label: "Monthly rent", align: "right", render: (r) => lkr(r.monthly_rent) },
                { key: "balance", label: "Balance", align: "right", render: (r) => lkr(r.balance) },
                { key: "aging_bucket", label: "Aging", align: "center", render: (r) => <Badge color={r.aging_bucket === "current" ? "green" : r.aging_bucket === "90+" ? "red" : "amber"}>{r.aging_bucket}</Badge> },
              ]}
              rows={data.leases}
              rowKey={(r) => r.id}
              empty="No active leases"
            />
            {data.leases.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-2">
                {data.leases.map((l) => (
                  <button key={l.id} className="btn-ghost !py-1 text-xs" onClick={() => nav(`/apartments/leases/${l.id}`)}>{l.code} →</button>
                ))}
              </div>
            )}
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type SalesPipeline = {
  from: string; to: string; total_sales: number; by_status: Record<string, number>; conversion_rate_pct: number;
  avg_days_to_close: number; total_pipeline_value: number; completed_value: number; cancelled_count: number;
};

function SalesPipelineTab() {
  const { from, setFrom, to, setTo } = useApartmentDateRange();
  const { data } = useFetch<SalesPipeline>(`/apartments/reports/sales-pipeline?from=${from}&to=${to}`, [from, to]);

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Sales created" value={data.total_sales} />
            <Stat label="Conversion rate" value={`${data.conversion_rate_pct}%`} />
            <Stat label="Avg days to close" value={data.avg_days_to_close} />
            <Stat label="Completed value" value={lkr(data.completed_value)} color="brand" />
          </div>
          <Card title="Funnel by stage">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
              {Object.entries(data.by_status).map(([code, count]) => (
                <div key={code} className="rounded-lg bg-slate-50 p-3 text-center"><div className="text-xl font-extrabold">{count}</div><div className="text-xs text-slate-500">{SALE_STAGE_LABELS[code] ?? code}</div></div>
              ))}
              {Object.keys(data.by_status).length === 0 && <Empty text="No sales activity in this range" />}
            </div>
          </Card>
        </>
      ) : <Empty text="Loading…" />}
    </div>
  );
}

type Utilities = { from: string; to: string; total_amount: number; by_type: Record<string, { consumption: number; amount: number }>; by_unit: Record<string, number> };

function UtilitiesTab() {
  const { from, setFrom, to, setTo } = useApartmentDateRange();
  const { data } = useFetch<Utilities>(`/apartments/reports/utilities?from=${from}&to=${to}`, [from, to]);
  const typeRows = Object.entries(data?.by_type ?? {}).map(([type, v]) => ({ type, ...v }));
  const unitRows = Object.entries(data?.by_unit ?? {}).map(([unit, amount]) => ({ unit, amount }));

  return (
    <div className="space-y-3">
      <DateRangeBar from={from} to={to} onFrom={setFrom} onTo={setTo} maxTo={todayStr()} />
      {data ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="lg:col-span-2"><Stat label="Total utility cost" value={lkr(data.total_amount)} color="brand" /></div>
          <Card title="By utility type">
            <SimpleTable
              columns={[{ key: "type", label: "Type" }, { key: "consumption", label: "Consumption", align: "right" }, { key: "amount", label: "Cost", align: "right", render: (r) => lkr(r.amount) }]}
              rows={typeRows} rowKey={(r) => r.type} empty="No readings in this range"
            />
          </Card>
          <Card title="By unit">
            <SimpleTable columns={[{ key: "unit", label: "Unit" }, { key: "amount", label: "Cost", align: "right", render: (r) => lkr(r.amount) }]} rows={unitRows} rowKey={(r) => r.unit} empty="No readings in this range" />
          </Card>
        </div>
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
  const { from, setFrom, to, setTo } = useApartmentDateRange();
  const { data } = useFetch<OpsSla>(`/apartments/reports/ops-sla?from=${from}&to=${to}`, [from, to]);

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
