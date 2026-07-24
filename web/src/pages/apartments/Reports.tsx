import { useNavigate } from "react-router-dom";
import { useFetch, lkr, fmtDate } from "../../lib/util";
import { Card, Empty, ErrorText, Stat } from "../../components/ui";

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

export default function ApartmentReports() {
  const { data, error } = useFetch<Dashboard>("/apartments/reports/dashboard");
  const nav = useNavigate();

  if (error) return <ErrorText error={error} />;
  if (!data) return <Empty text="Loading…" />;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Apartments Reports</h1>

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
