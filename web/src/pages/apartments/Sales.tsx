import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Plus, Search } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, usePagedFetch, lkr, fmtDate, toCents } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination, statusColor } from "../../components/ui";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";

type Lookup = { id: number; code: string; name: string };
const STATUS_OPTIONS = ["inquiry", "reserved", "agreement_signed", "completed", "cancelled"];

type SaleRow = {
  id: number; code: string; agreed_price: number; created_at: string;
  status: Lookup; customer: { id: number; name: string; phone: string | null };
  unit: { id: number; unit_no: string };
};
type UnitLite = { id: number; unit_no: string; unit_type: { id: number; name: string }; sale_price: number | null };
type CustomerLite = { id: number; name: string; phone: string | null };

export default function Sales() {
  const { can } = useAuth();
  const canView = can("apartment_sales.view");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<SaleRow>(
    `/apartments/sales?q=${encodeURIComponent(q)}&status=${status}&page=${page}&page_size=${pageSize}`,
    "sales",
    [q, status, page, pageSize],
  );
  const rows = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const nav = useNavigate();

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Sales</h1>
        {can("apartment_sales.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New sale inquiry</button>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <div className="relative min-w-52 flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-9" placeholder="Search code, buyer, unit…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <select className="input !w-48" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s.replace("_", " ").toUpperCase()}</option>)}
        </select>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Code</th><th className="th">Buyer</th><th className="th">Unit</th><th className="th">Created</th><th className="th text-right">Agreed price</th><th className="th">Status</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {(rows ?? []).map((s) => (
              <tr key={s.id} className={canView ? "cursor-pointer hover:bg-slate-50" : ""} onClick={canView ? () => nav(`/apartments/sales/${s.id}`) : undefined}>
                <td className="td font-bold">{s.code}</td>
                <td className="td">{s.customer.name}</td>
                <td className="td">{s.unit.unit_no}</td>
                <td className="td whitespace-nowrap">{fmtDate(s.created_at)}</td>
                <td className="td text-right">{lkr(s.agreed_price)}</td>
                <td className="td"><Badge color={statusColor(s.status.code.toUpperCase())}>{s.status.code.toUpperCase()}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
        {(rows ?? []).length === 0 && <Empty text="No sales found" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openNew && (
        <NewSale onClose={() => setOpenNew(false)} onCreated={(id) => { setOpenNew(false); reload(); nav(`/apartments/sales/${id}`); }} />
      )}
    </div>
  );
}

function NewSale({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const toast = useToast();
  const { data: unitsResp } = useFetch<{ units: UnitLite[] }>("/apartments/units?listing_type=sale&status=available");
  const units = unitsResp?.units;
  const [unitId, setUnitId] = useState("");

  const [custQ, setCustQ] = useState("");
  const { data: custResp } = useFetch<{ customers: CustomerLite[] }>(custQ.length >= 2 ? `/apartments/customers?q=${encodeURIComponent(custQ)}` : null, [custQ]);
  const customers = custResp?.customers;
  const [customerId, setCustomerId] = useState("");
  const [customerPicked, setCustomerPicked] = useState<CustomerLite | null>(null);
  const [newCustomer, setNewCustomer] = useState({ name: "", phone: "", email: "" });

  const [agreedPrice, setAgreedPrice] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const create = async () => {
    setError("");
    if (!unitId) return setError("Select a unit");
    if (!customerId && !newCustomer.name.trim()) return setError("Pick an existing buyer or enter a new one");
    if (!agreedPrice) return setError("Enter the agreed price");
    setBusy(true);
    try {
      const res = await post<{ message: string; sale: { id: number; code: string } }>("/apartments/sales", {
        customer_id: customerId ? Number(customerId) : undefined,
        new_customer: customerId ? undefined : { name: newCustomer.name.trim(), phone: newCustomer.phone || undefined, email: newCustomer.email || undefined },
        unit_id: Number(unitId),
        agreed_price: toCents(agreedPrice),
        notes: notes || undefined,
      });
      toast.success(`Sale ${res.sale.code} created`, "");
      onCreated(String(res.sale.id));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title="New sale inquiry">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Unit for sale *">
          <select className="input" value={unitId} onChange={(e) => {
            setUnitId(e.target.value);
            const u = (units ?? []).find((x) => x.id === Number(e.target.value));
            if (u?.sale_price) setAgreedPrice((u.sale_price / 100).toFixed(2));
          }}>
            <option value="">— select available unit —</option>
            {(units ?? []).map((u) => <option key={u.id} value={u.id}>{u.unit_no} — {u.unit_type.name}</option>)}
          </select>
        </Field>
        <Field label="Agreed price (LKR) *"><input className="input" value={agreedPrice} onChange={(e) => setAgreedPrice(e.target.value)} /></Field>
      </div>

      <div className="mt-4">
        <div className="label">Buyer (returning customers are auto-recognised)</div>
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
              <input className="input" placeholder="…or NEW buyer: full name *" value={newCustomer.name} onChange={(e) => setNewCustomer({ ...newCustomer, name: e.target.value })} />
              <input className="input" placeholder="Phone" value={newCustomer.phone} onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })} />
            </div>
          </>
        )}
      </div>

      <div className="mt-3">
        <Field label="Notes"><textarea className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
      </div>

      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full !py-3" disabled={busy} onClick={create}>
        {busy ? "Creating…" : "Create inquiry"}
      </button>
    </Modal>
  );
}
