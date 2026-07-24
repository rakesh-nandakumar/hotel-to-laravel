import { useState } from "react";
import { Plus, Search, Building2 } from "lucide-react";
import { post, put } from "../../lib/api";
import { usePagedFetch } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination } from "../../components/ui";
import { useAuth } from "../../lib/auth";

type Customer = {
  id: number; name: string; email: string | null; phone: string | null; id_number: string | null;
  nationality: string | null; is_company: boolean; company_name: string | null; company_reg_no: string | null;
  address: string | null; notes: string | null;
};

export default function Customers() {
  const { can } = useAuth();
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  const { data, reload } = usePagedFetch<Customer>(
    `/apartments/customers?q=${encodeURIComponent(q)}&page=${page}&page_size=${pageSize}`,
    "customers",
    [q, page, pageSize],
  );
  const customers = data?.rows ?? [];
  const [editing, setEditing] = useState<Customer | null>(null);
  const [openNew, setOpenNew] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Customers</h1>
        {can("apartment_customers.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New customer</button>
        )}
      </div>

      <div className="relative min-w-56 flex-1">
        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input className="input !pl-9" placeholder="Search name / phone / email / ID / company…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Name</th><th className="th">Contact</th><th className="th">ID</th><th className="th">Type</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {customers.map((c) => (
              <tr
                key={c.id}
                className={can("apartment_customers.view") ? "cursor-pointer hover:bg-slate-50" : ""}
                onClick={can("apartment_customers.view") ? () => setEditing(c) : undefined}
              >
                <td className="td font-semibold">{c.is_company ? c.company_name || c.name : c.name}</td>
                <td className="td text-xs">{[c.phone, c.email].filter(Boolean).join(" · ") || "—"}</td>
                <td className="td text-xs">{c.id_number ?? "—"}</td>
                <td className="td">{c.is_company ? <Badge color="purple"><Building2 size={10} className="mr-0.5 inline" />Company</Badge> : <Badge color="slate">Individual</Badge>}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {customers.length === 0 && <Empty text="No customers found" />}
        {data && (
          <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />
        )}
      </div>

      {editing && <CustomerEditor customer={editing} onClose={() => { setEditing(null); reload(); }} />}
      {openNew && <CustomerEditor onClose={() => { setOpenNew(false); reload(); }} />}
    </div>
  );
}

function CustomerEditor({ customer, onClose }: { customer?: Customer; onClose: () => void }) {
  const [f, setF] = useState({
    name: customer?.name ?? "", phone: customer?.phone ?? "", email: customer?.email ?? "",
    idNumber: customer?.id_number ?? "", nationality: customer?.nationality ?? "",
    isCompany: customer?.is_company ?? false, companyName: customer?.company_name ?? "", companyRegNo: customer?.company_reg_no ?? "",
    address: customer?.address ?? "", notes: customer?.notes ?? "",
  });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={customer ? "Edit customer" : "New customer"}>
      <label className="mb-3 flex items-center gap-2 text-sm">
        <input type="checkbox" checked={f.isCompany} onChange={(e) => setF({ ...f, isCompany: e.target.checked })} />
        Corporate tenant / buyer
      </label>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={f.isCompany ? "Contact person *" : "Full name *"}><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Phone"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        <Field label="Email"><input className="input" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="ID / passport"><input className="input" value={f.idNumber} onChange={(e) => setF({ ...f, idNumber: e.target.value })} /></Field>
        <Field label="Nationality"><input className="input" value={f.nationality} onChange={(e) => setF({ ...f, nationality: e.target.value })} /></Field>
        {f.isCompany && (
          <>
            <Field label="Company name"><input className="input" value={f.companyName} onChange={(e) => setF({ ...f, companyName: e.target.value })} /></Field>
            <Field label="Company reg. no"><input className="input" value={f.companyRegNo} onChange={(e) => setF({ ...f, companyRegNo: e.target.value })} /></Field>
          </>
        )}
      </div>
      <Field label="Address"><textarea className="input" rows={2} value={f.address} onChange={(e) => setF({ ...f, address: e.target.value })} /></Field>
      <Field label="Notes"><textarea className="input" rows={2} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.name.trim()}
        onClick={() => {
          const body = {
            name: f.name, phone: f.phone, email: f.email, id_number: f.idNumber, nationality: f.nationality,
            is_company: f.isCompany, company_name: f.companyName, company_reg_no: f.companyRegNo,
            address: f.address, notes: f.notes,
          };
          (customer ? put(`/apartments/customers/${customer.id}`, body) : post("/apartments/customers", body))
            .then(onClose)
            .catch((e) => setError(e.message));
        }}
      >
        Save
      </button>
    </Modal>
  );
}
