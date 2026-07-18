import { useState } from "react";
import { Plus } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, centsToRupees, fmtDate } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

type Acc = {
  id: number; company_name: string; contact_name: string | null; phone: string | null; email: string | null;
  discount_pct: number; credit_limit: number; active: boolean;
  outstanding: number;
};
type Statement = {
  month: string;
  charges: { id: number; date: string; amount: number; reservation?: string; guest?: string; invoice_no?: string }[];
  settlements: { id: number; created_at: string; amount: number; method: string; reference?: string }[];
  total_charges: number;
  total_settled: number;
};

export default function Corporate() {
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Acc>(`/corporate?page=${page}&page_size=${pageSize}`, "corporate_accounts", [page, pageSize]);
  const accounts = data?.rows;
  const [edit, setEdit] = useState<Acc | "new" | null>(null);
  const [statementFor, setStatementFor] = useState<Acc | null>(null);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Corporate / Travel-Agent Accounts</h1>
        {can("hotel_corporate.create") && <button className="btn-primary" onClick={() => setEdit("new")}><Plus size={16} /> New account</button>}
      </div>
      <p className="text-xs text-slate-500">Negotiated rates apply automatically to bookings; stays can be charged to CORPORATE_CREDIT and settled month-end.</p>
      <div className="grid gap-3 md:grid-cols-2">
        {(accounts ?? []).map((a) => (
          <Card
            key={a.id}
            title={a.company_name}
            actions={
              <>
                <button className="btn-secondary !py-1 text-xs" onClick={() => setStatementFor(a)}>Statement</button>
                {can("hotel_corporate.edit") && <button className="btn-ghost !py-1 text-xs" onClick={() => setEdit(a)}>Edit</button>}
              </>
            }
          >
            <div className="grid grid-cols-2 gap-1 text-sm">
              <div><span className="text-slate-400">Contact:</span> {a.contact_name ?? "—"}</div>
              <div><span className="text-slate-400">Discount:</span> {a.discount_pct}%</div>
              <div><span className="text-slate-400">Credit limit:</span> {a.credit_limit ? lkr(a.credit_limit) : "Unlimited"}</div>
              <div>
                <span className="text-slate-400">Outstanding:</span>{" "}
                <b className={a.outstanding > 0 ? "text-red-600" : "text-emerald-600"}>{lkr(a.outstanding)}</b>
              </div>
            </div>
            {!a.active && <Badge color="red">Inactive</Badge>}
          </Card>
        ))}
        {(accounts ?? []).length === 0 && <Empty text="No corporate accounts yet" />}
      </div>
      {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}

      {edit && <AccEditor acc={edit === "new" ? null : edit} onClose={() => { setEdit(null); reload(); }} />}
      {statementFor && <StatementModal acc={statementFor} onClose={() => { setStatementFor(null); reload(); }} />}
    </div>
  );
}

function AccEditor({ acc, onClose }: { acc: Acc | null; onClose: () => void }) {
  const [f, setF] = useState({
    companyName: acc?.company_name ?? "", contactName: acc?.contact_name ?? "", phone: acc?.phone ?? "",
    email: acc?.email ?? "", discountPct: String(acc?.discount_pct ?? 0), creditLimit: acc ? centsToRupees(acc.credit_limit) : "0",
  });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={acc ? "Edit corporate account" : "New corporate account"}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Company *"><input className="input" value={f.companyName} onChange={(e) => setF({ ...f, companyName: e.target.value })} /></Field>
        <Field label="Contact person"><input className="input" value={f.contactName} onChange={(e) => setF({ ...f, contactName: e.target.value })} /></Field>
        <Field label="Phone"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        <Field label="Email"><input className="input" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="Negotiated discount %"><input className="input" value={f.discountPct} onChange={(e) => setF({ ...f, discountPct: e.target.value })} /></Field>
        <Field label="Credit limit (LKR, 0 = unlimited)"><input className="input" value={f.creditLimit} onChange={(e) => setF({ ...f, creditLimit: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.companyName.trim()}
        onClick={() => {
          const body = {
            company_name: f.companyName, contact_name: f.contactName || undefined, phone: f.phone || undefined,
            email: f.email || undefined, discount_pct: parseFloat(f.discountPct) || 0, credit_limit: toCents(f.creditLimit),
          };
          (acc ? put(`/corporate/${acc.id}`, body) : post("/corporate", body)).then(onClose).catch((e) => setError(e.message));
        }}
      >
        Save
      </button>
    </Modal>
  );
}

function StatementModal({ acc, onClose }: { acc: Acc; onClose: () => void }) {
  const toast = useToast();
  const { can } = useAuth();
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const { data: st, reload } = useFetch<Statement>(`/corporate/${acc.id}/statement?month=${month}`, [month]);
  const [settle, setSettle] = useState({ amount: "", method: "bank_transfer", reference: "" });
  const [error, setError] = useState("");

  return (
    <Modal open onClose={onClose} title={`${acc.company_name} — month-end statement`} wide>
      <input type="month" className="input !w-44" value={month} onChange={(e) => setMonth(e.target.value)} />
      {st && (
        <>
          <Card title={`Credit charges — ${st.month}`} className="mt-3">
            <div className="divide-y divide-slate-50 text-sm">
              {st.charges.map((c) => (
                <div key={c.id} className="flex justify-between py-1.5">
                  <span>{fmtDate(c.date)} · {c.reservation} — {c.guest} {c.invoice_no && <span className="text-xs text-slate-400">({c.invoice_no})</span>}</span>
                  <b>{lkr(c.amount)}</b>
                </div>
              ))}
              {st.charges.length === 0 && <Empty text="No credit charges this month" />}
            </div>
            <div className="mt-2 flex justify-between border-t border-slate-100 pt-2 text-sm font-extrabold">
              <span>Total charges</span><span>{lkr(st.total_charges)}</span>
            </div>
            <div className="flex justify-between text-sm text-emerald-700">
              <span>Settled this month</span><span>{lkr(st.total_settled)}</span>
            </div>
            <div className="flex justify-between text-sm font-bold">
              <span>Account outstanding (all time)</span><span>{lkr(acc.outstanding)}</span>
            </div>
          </Card>
          {can("hotel_corporate.edit") && <Card title="Record settlement payment" className="mt-3">
            <div className="flex flex-wrap gap-2">
              <input className="input !w-36" placeholder="Amount LKR" value={settle.amount} onChange={(e) => setSettle({ ...settle, amount: e.target.value })} />
              <select className="input !w-44" value={settle.method} onChange={(e) => setSettle({ ...settle, method: e.target.value })}>
                {[
                  { code: "bank_transfer", label: "BANK_TRANSFER" },
                  { code: "cash", label: "CASH" },
                  { code: "card", label: "CARD" },
                  { code: "lankaqr", label: "LANKAQR" },
                ].map((m) => <option key={m.code} value={m.code}>{m.label}</option>)}
              </select>
              <input className="input !w-36" placeholder="Reference" value={settle.reference} onChange={(e) => setSettle({ ...settle, reference: e.target.value })} />
              <button
                className="btn-primary"
                disabled={toCents(settle.amount) <= 0}
                onClick={() =>
                  post(`/corporate/${acc.id}/settle`, { amount: toCents(settle.amount), method: settle.method, reference: settle.reference || undefined })
                    .then(() => {
                      toast.success(`${acc.company_name} settlement recorded`, lkr(toCents(settle.amount)));
                      setSettle({ amount: "", method: "bank_transfer", reference: "" });
                      reload();
                    })
                    .catch((e) => setError(e.message))
                }
              >
                Record
              </button>
            </div>
            <ErrorText error={error} />
          </Card>}
        </>
      )}
    </Modal>
  );
}
