import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Plus, Search } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, usePagedFetch, lkr, todayStr, fmtDate, toCents } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination, statusColor } from "../../components/ui";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";

type Lookup = { id: number; code: string; name: string };
const STATUS_OPTIONS = ["draft", "active", "renewed", "terminated", "ended"];
const PAY_METHOD_OPTIONS = ["cash", "card", "lankaqr", "bank_transfer"];

type LeaseRow = {
  id: number; code: string; start_date: string; end_date: string | null; monthly_rent: number;
  status: Lookup; customer: { id: number; name: string; phone: string | null };
  unit: { id: number; unit_no: string };
};
type UnitLite = { id: number; unit_no: string; unit_type: { id: number; name: string; monthly_rate: number | null } };
type CustomerLite = { id: number; name: string; phone: string | null };

export default function Leases() {
  const { can } = useAuth();
  const canView = can("apartment_leases.view");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<LeaseRow>(
    `/apartments/leases?q=${encodeURIComponent(q)}&status=${status}&page=${page}&page_size=${pageSize}`,
    "leases",
    [q, status, page, pageSize],
  );
  const rows = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const nav = useNavigate();

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Leases</h1>
        {can("apartment_leases.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New lease</button>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <div className="relative min-w-52 flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-9" placeholder="Search code, customer, unit…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <select className="input !w-44" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s.toUpperCase()}</option>)}
        </select>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Code</th><th className="th">Customer</th><th className="th">Unit</th><th className="th">Term</th><th className="th text-right">Monthly rent</th><th className="th">Status</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {(rows ?? []).map((l) => (
              <tr key={l.id} className={canView ? "cursor-pointer hover:bg-slate-50" : ""} onClick={canView ? () => nav(`/apartments/leases/${l.id}`) : undefined}>
                <td className="td font-bold">{l.code}</td>
                <td className="td">{l.customer.name}</td>
                <td className="td">{l.unit.unit_no}</td>
                <td className="td whitespace-nowrap">{fmtDate(l.start_date)} → {l.end_date ? fmtDate(l.end_date) : "open-ended"}</td>
                <td className="td text-right">{lkr(l.monthly_rent)}</td>
                <td className="td"><Badge color={statusColor(l.status.code.toUpperCase())}>{l.status.code.toUpperCase()}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
        {(rows ?? []).length === 0 && <Empty text="No leases found" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openNew && (
        <NewLease onClose={() => setOpenNew(false)} onCreated={(id) => { setOpenNew(false); reload(); nav(`/apartments/leases/${id}`); }} />
      )}
    </div>
  );
}

function NewLease({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const toast = useToast();
  const { data: unitsResp } = useFetch<{ units: UnitLite[] }>("/apartments/units?listing_type=rental&status=available");
  const units = unitsResp?.units;
  const [unitId, setUnitId] = useState("");
  const chosenUnit = (units ?? []).find((u) => u.id === Number(unitId));

  const [custQ, setCustQ] = useState("");
  const { data: custResp } = useFetch<{ customers: CustomerLite[] }>(custQ.length >= 2 ? `/apartments/customers?q=${encodeURIComponent(custQ)}` : null, [custQ]);
  const customers = custResp?.customers;
  const [customerId, setCustomerId] = useState("");
  const [customerPicked, setCustomerPicked] = useState<CustomerLite | null>(null);
  const [newCustomer, setNewCustomer] = useState({ name: "", phone: "", email: "" });

  const [form, setForm] = useState({
    startDate: todayStr(1), endDate: "", monthlyRent: "", rentDueDay: "1",
    securityDeposit: "", noticePeriodDays: "30", autoRenew: false, notes: "",
  });
  const [deposit, setDeposit] = useState({ take: false, method: "bank_transfer", amount: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const create = async () => {
    setError("");
    if (!unitId) return setError("Select a unit");
    if (!customerId && !newCustomer.name.trim()) return setError("Pick an existing customer or enter a new customer name");
    if (!form.monthlyRent) return setError("Enter the monthly rent");
    setBusy(true);
    try {
      const res = await post<{ message: string; lease: { id: number; code: string } }>("/apartments/leases", {
        customer_id: customerId ? Number(customerId) : undefined,
        new_customer: customerId ? undefined : { name: newCustomer.name.trim(), phone: newCustomer.phone || undefined, email: newCustomer.email || undefined },
        unit_id: Number(unitId),
        start_date: form.startDate,
        end_date: form.endDate || undefined,
        monthly_rent: toCents(form.monthlyRent),
        rent_due_day: parseInt(form.rentDueDay) || 1,
        security_deposit: form.securityDeposit ? toCents(form.securityDeposit) : undefined,
        notice_period_days: parseInt(form.noticePeriodDays) || 30,
        auto_renew: form.autoRenew,
        notes: form.notes || undefined,
        deposit_payment: deposit.take && toCents(deposit.amount) > 0 ? { method: deposit.method, amount: toCents(deposit.amount) } : undefined,
      });
      toast.success(`Lease ${res.lease.code} created`, "");
      onCreated(String(res.lease.id));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title="New apartment lease" wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Unit *">
          <select className="input" value={unitId} onChange={(e) => {
            setUnitId(e.target.value);
            const u = (units ?? []).find((x) => x.id === Number(e.target.value));
            if (u?.unit_type.monthly_rate) setForm((f) => ({ ...f, monthlyRent: (u.unit_type.monthly_rate! / 100).toFixed(2) }));
          }}>
            <option value="">— select available unit —</option>
            {(units ?? []).map((u) => <option key={u.id} value={u.id}>{u.unit_no} — {u.unit_type.name}</option>)}
          </select>
        </Field>
        <Field label="Monthly rent (LKR) *"><input className="input" value={form.monthlyRent} onChange={(e) => setForm({ ...form, monthlyRent: e.target.value })} /></Field>
        <Field label="Start date"><input type="date" className="input" value={form.startDate} onChange={(e) => setForm({ ...form, startDate: e.target.value })} /></Field>
        <Field label="End date" hint="Leave blank for open-ended / month-to-month"><input type="date" className="input" value={form.endDate} onChange={(e) => setForm({ ...form, endDate: e.target.value })} /></Field>
        <Field label="Rent due day of month"><input className="input" inputMode="numeric" value={form.rentDueDay} onChange={(e) => setForm({ ...form, rentDueDay: e.target.value })} /></Field>
        <Field label="Notice period (days)"><input className="input" inputMode="numeric" value={form.noticePeriodDays} onChange={(e) => setForm({ ...form, noticePeriodDays: e.target.value })} /></Field>
        <Field label="Security deposit (LKR)"><input className="input" value={form.securityDeposit} onChange={(e) => setForm({ ...form, securityDeposit: e.target.value })} /></Field>
        <Field label="Notes"><input className="input" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
      </div>
      <label className="mt-2 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={form.autoRenew} onChange={(e) => setForm({ ...form, autoRenew: e.target.checked })} />
        Auto-renew at term end
      </label>

      <div className="mt-4">
        <div className="label">Tenant (returning customers are auto-recognised)</div>
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
              <input className="input" placeholder="…or NEW tenant: full name *" value={newCustomer.name} onChange={(e) => setNewCustomer({ ...newCustomer, name: e.target.value })} />
              <input className="input" placeholder="Phone" value={newCustomer.phone} onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })} />
            </div>
          </>
        )}
      </div>

      {form.securityDeposit && toCents(form.securityDeposit) > 0 && (
        <div className="mt-4 rounded-xl bg-slate-50 p-3">
          <label className="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" checked={deposit.take} onChange={(e) => setDeposit({ ...deposit, take: e.target.checked, amount: deposit.amount || form.securityDeposit })} />
            Take security deposit now
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
        {busy ? "Creating…" : "Create lease"}
      </button>
    </Modal>
  );
}
