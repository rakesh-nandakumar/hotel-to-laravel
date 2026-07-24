import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Plus, Search } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, usePagedFetch, lkr, todayStr, fmtDate, toCents, useSettings } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination, statusColor } from "../../components/ui";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";

type Lookup = { id: number; code: string; name: string };

const STATUS_OPTIONS = ["pending", "confirmed", "checked_in", "checked_out", "cancelled", "no_show"];
const CHANNEL_OPTIONS = [
  { code: "direct", label: "Direct" },
  { code: "airbnb", label: "Airbnb" },
  { code: "booking_com", label: "Booking.com" },
  { code: "agent", label: "Agent" },
  { code: "walk_in", label: "Walk-in" },
];
const PAY_METHOD_OPTIONS = ["cash", "card", "lankaqr", "bank_transfer"];

type BookingRow = {
  id: number; code: string; check_in: string; check_out: string;
  status: Lookup; channel: Lookup; customer: { id: number; name: string; phone: string | null };
  unit: { id: number; unit_no: string };
};
type AvailUnit = {
  id: number; unit_no: string; property: string | null;
  unit_type: { id: number; name: string; max_occupancy: number };
  nightly_rate: number; rate_basis: string; stay_total: number; night_count: number;
};
type CustomerLite = { id: number; name: string; phone: string | null };

export default function Bookings() {
  const { can } = useAuth();
  const canView = can("apartment_bookings.view");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<BookingRow>(
    `/apartments/bookings?q=${encodeURIComponent(q)}&status=${status}&page=${page}&page_size=${pageSize}`,
    "bookings",
    [q, status, page, pageSize],
  );
  const rows = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const nav = useNavigate();

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Bookings</h1>
        {can("apartment_bookings.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New booking</button>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <div className="relative min-w-52 flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-9" placeholder="Search code, customer, unit…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <select className="input !w-44" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s.replace("_", " ").toUpperCase()}</option>)}
        </select>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Code</th><th className="th">Customer</th><th className="th">Unit</th><th className="th">Dates</th><th className="th">Channel</th><th className="th">Status</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {(rows ?? []).map((b) => (
              <tr key={b.id} className={canView ? "cursor-pointer hover:bg-slate-50" : ""} onClick={canView ? () => nav(`/apartments/bookings/${b.id}`) : undefined}>
                <td className="td font-bold">{b.code}</td>
                <td className="td">{b.customer.name}</td>
                <td className="td">{b.unit.unit_no}</td>
                <td className="td whitespace-nowrap">{fmtDate(b.check_in)} → {fmtDate(b.check_out)}</td>
                <td className="td text-xs">{b.channel.name}</td>
                <td className="td"><Badge color={statusColor(b.status.code.toUpperCase())}>{b.status.code.toUpperCase()}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
        {(rows ?? []).length === 0 && <Empty text="No bookings found" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openNew && (
        <NewBooking
          onClose={() => setOpenNew(false)}
          onCreated={(id) => { setOpenNew(false); reload(); nav(`/apartments/bookings/${id}`); }}
        />
      )}
    </div>
  );
}

function NewBooking({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const [checkIn, setCheckIn] = useState(todayStr(1));
  const [checkOut, setCheckOut] = useState(todayStr(2));
  const { data: availResp, loading } = useFetch<{ units: AvailUnit[] }>(`/apartments/bookings/availability?check_in=${checkIn}&check_out=${checkOut}`, [checkIn, checkOut]);
  const avail = availResp?.units;
  const { num } = useSettings();
  const toast = useToast();

  const [unitId, setUnitId] = useState<number | null>(null);
  const [custQ, setCustQ] = useState("");
  const { data: custResp } = useFetch<{ customers: CustomerLite[] }>(custQ.length >= 2 ? `/apartments/customers?q=${encodeURIComponent(custQ)}` : null, [custQ]);
  const customers = custResp?.customers;
  const [customerId, setCustomerId] = useState("");
  const [customerPicked, setCustomerPicked] = useState<CustomerLite | null>(null);
  const [newCustomer, setNewCustomer] = useState({ name: "", phone: "", email: "" });
  const [form, setForm] = useState({ channel: "direct", adults: "1", children: "0", notes: "" });
  const [deposit, setDeposit] = useState({ take: false, method: "cash", amount: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const chosen = (avail ?? []).find((u) => u.id === unitId) ?? null;
  const depositPct = num("apartment.deposit_pct", 20);
  const suggestedDeposit = chosen ? Math.round((chosen.stay_total * depositPct) / 100) : 0;

  const create = async () => {
    setError("");
    if (!unitId) return setError("Select a unit");
    if (!customerId && !newCustomer.name.trim()) return setError("Pick an existing customer or enter a new customer name");
    setBusy(true);
    try {
      const res = await post<{ message: string; booking: { id: number; code: string } }>("/apartments/bookings", {
        customer_id: customerId ? Number(customerId) : undefined,
        new_customer: customerId ? undefined : { name: newCustomer.name.trim(), phone: newCustomer.phone || undefined, email: newCustomer.email || undefined },
        channel: form.channel,
        unit_id: unitId,
        check_in: checkIn,
        check_out: checkOut,
        adults: parseInt(form.adults) || 1,
        children: parseInt(form.children) || 0,
        notes: form.notes || undefined,
        deposit_payment: deposit.take && toCents(deposit.amount) > 0 ? { method: deposit.method, amount: toCents(deposit.amount) } : undefined,
      });
      toast.success(`Booking ${res.booking.code} created`, "");
      onCreated(String(res.booking.id));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title="New apartment booking" wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Check-in"><input type="date" className="input" value={checkIn} onChange={(e) => { setCheckIn(e.target.value); setUnitId(null); }} /></Field>
        <Field label="Check-out"><input type="date" className="input" value={checkOut} onChange={(e) => { setCheckOut(e.target.value); setUnitId(null); }} /></Field>
      </div>

      <div className="mt-3">
        <div className="label">Available units — nightly/weekly/monthly pricing applied automatically</div>
        {loading ? (
          <Empty text="Checking availability…" />
        ) : (
          <div className="grid max-h-56 grid-cols-2 gap-1.5 overflow-y-auto sm:grid-cols-3">
            {(avail ?? []).map((u) => (
              <button
                key={u.id}
                onClick={() => setUnitId(u.id)}
                className={`rounded-lg border p-2 text-left text-xs transition ${unitId === u.id ? "border-brand-500 bg-brand-50" : "border-slate-200 bg-white hover:border-slate-300"}`}
              >
                <div className="text-sm font-extrabold">{u.unit_no}</div>
                <div className="truncate text-slate-500">{u.unit_type.name} · sleeps {u.unit_type.max_occupancy}</div>
                <div className="font-semibold text-brand-600">{lkr(u.stay_total)} / stay ({u.rate_basis})</div>
              </button>
            ))}
            {(avail ?? []).length === 0 && <Empty text="No units free for these dates" />}
          </div>
        )}
      </div>

      <div className="mt-4">
        <div className="label">Customer (returning customers are auto-recognised)</div>
        {customerPicked ? (
          <div className="flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm">
            <span><b>{customerPicked.name}</b> {customerPicked.phone && `· ${customerPicked.phone}`}</span>
            <button className="text-xs font-bold text-red-500" onClick={() => { setCustomerId(""); setCustomerPicked(null); }}>change</button>
          </div>
        ) : (
          <>
            <input className="input" placeholder="Search existing customer by name/phone…" value={custQ} onChange={(e) => setCustQ(e.target.value)} />
            {(customers ?? []).length > 0 && (
              <div className="mt-1 max-h-32 overflow-y-auto rounded-lg border border-slate-200">
                {(customers ?? []).map((c) => (
                  <button key={c.id} className="flex w-full justify-between px-3 py-1.5 text-sm hover:bg-slate-50" onClick={() => { setCustomerId(String(c.id)); setCustomerPicked(c); }}>
                    <span>{c.name} {c.phone && <span className="text-slate-400">· {c.phone}</span>}</span>
                  </button>
                ))}
              </div>
            )}
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              <input className="input" placeholder="…or NEW customer: full name *" value={newCustomer.name} onChange={(e) => setNewCustomer({ ...newCustomer, name: e.target.value })} />
              <input className="input" placeholder="Phone" value={newCustomer.phone} onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })} />
              <input className="input" placeholder="Email" value={newCustomer.email} onChange={(e) => setNewCustomer({ ...newCustomer, email: e.target.value })} />
            </div>
          </>
        )}
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <Field label="Channel">
          <select className="input" value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
            {CHANNEL_OPTIONS.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}
          </select>
        </Field>
        <Field label="Adults"><input className="input" inputMode="numeric" value={form.adults} onChange={(e) => setForm({ ...form, adults: e.target.value })} /></Field>
        <Field label="Children"><input className="input" inputMode="numeric" value={form.children} onChange={(e) => setForm({ ...form, children: e.target.value })} /></Field>
        <Field label="Notes"><input className="input" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
      </div>

      {chosen && (
        <div className="mt-4 rounded-xl bg-slate-50 p-3">
          <div className="flex items-center justify-between text-sm">
            <span className="font-semibold">Estimated stay total</span>
            <span className="text-lg font-extrabold text-brand-700">{lkr(chosen.stay_total)}</span>
          </div>
          <div className="mt-1 text-xs text-slate-500">Deposit ({depositPct}%): <b>{lkr(suggestedDeposit)}</b></div>
          <label className="mt-2 flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" checked={deposit.take} onChange={(e) => setDeposit({ ...deposit, take: e.target.checked, amount: deposit.amount || (suggestedDeposit / 100).toFixed(2) })} />
            Take deposit now
          </label>
          {deposit.take && (
            <div className="mt-2 flex gap-2">
              <select className="input !w-40" value={deposit.method} onChange={(e) => setDeposit({ ...deposit, method: e.target.value })}>
                {PAY_METHOD_OPTIONS.map((m) => <option key={m} value={m}>{m.toUpperCase()}</option>)}
              </select>
              <input className="input" placeholder="Amount (LKR)" value={deposit.amount} onChange={(e) => setDeposit({ ...deposit, amount: e.target.value })} />
            </div>
          )}
        </div>
      )}

      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full !py-3" disabled={busy} onClick={create}>
        {busy ? "Creating…" : "Create booking"}
      </button>
    </Modal>
  );
}
