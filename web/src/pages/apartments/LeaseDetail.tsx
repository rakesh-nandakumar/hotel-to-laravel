import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Ban, RefreshCw, Plus, Trash2, Zap } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, lkr, fmtDate, fmtDateTime, toCents } from "../../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor } from "../../components/ui";
import { SplitPay, ReasonModal } from "../POS";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";

type Lookup = { id: number; code: string; name: string };

type Detail = {
  id: number; code: string; status: Lookup;
  start_date: string; end_date: string | null; monthly_rent: number; rent_due_day: number;
  security_deposit: number; notice_period_days: number; auto_renew: boolean; notes: string | null;
  customer: { id: number; name: string; phone: string | null; email: string | null };
  unit: { id: number; unit_no: string; unit_type: { name: string }; status?: Lookup; property: { name: string } | null };
  utility_readings: { id: number; utility_type: Lookup; period_month: string; previous_reading: number; current_reading: number; amount: number }[];
};
type LedgerLineT = { id: number; source: Lookup; description: string; amount: number; voided: boolean; created_at: string; staff: { name: string } | null };
type PaymentT = { id: number; kind: Lookup; method: Lookup; amount: number; reference: string | null; reason: string | null; created_at: string; staff: { name: string } };
type LedgerT = { id: number; status: Lookup; invoice_no: string | null; lines: LedgerLineT[]; payments: PaymentT[]; total: number; paid: number; refunded: number; balance: number };

export default function LeaseDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, reload, error } = useFetch<{ lease: Detail; ledger: LedgerT | null }>(`/apartments/leases/${id}`);
  const { can } = useAuth();
  const toast = useToast();
  const [actErr, setActErr] = useState("");
  const [renewOpen, setRenewOpen] = useState(false);
  const [terminateOpen, setTerminateOpen] = useState(false);
  const [utilityOpen, setUtilityOpen] = useState(false);
  const [addLineOpen, setAddLineOpen] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  const [refundOpen, setRefundOpen] = useState(false);
  const [voidingLine, setVoidingLine] = useState<LedgerLineT | null>(null);
  const nav = useNavigate();

  if (error) return <ErrorText error={error} />;
  if (!data) return <Empty text="Loading…" />;
  const l = data.lease;
  const ledger = data.ledger;
  const canManage = l.status.code === "active" || l.status.code === "renewed";

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <button className="text-xs font-bold text-brand-600" onClick={() => nav("/apartments/leases")}>← Leases</button>
          <h1 className="text-xl font-extrabold">{l.code} <Badge color={statusColor(l.status.code.toUpperCase())}>{l.status.code.toUpperCase()}</Badge></h1>
        </div>
        {canManage && (
          <div className="flex flex-wrap gap-2">
            {can("apartment_leases.utility_reading") && <button className="btn-secondary" onClick={() => setUtilityOpen(true)}><Zap size={16} /> Record utility</button>}
            {can("apartment_leases.renew") && <button className="btn-secondary" onClick={() => setRenewOpen(true)}><RefreshCw size={16} /> Renew</button>}
            {can("apartment_leases.terminate") && <button className="btn-danger" onClick={() => setTerminateOpen(true)}><Ban size={16} /> Terminate</button>}
          </div>
        )}
      </div>
      <ErrorText error={actErr} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Tenant">
          <div className="space-y-1 text-sm">
            <div className="text-base font-bold">{l.customer.name}</div>
            <div>📞 {l.customer.phone ? <a className="text-brand-600 hover:underline" href={`tel:${l.customer.phone}`}>{l.customer.phone}</a> : "—"}</div>
            <div>✉️ {l.customer.email ?? "—"}</div>
          </div>
        </Card>
        <Card title="Term">
          <div className="space-y-1 text-sm">
            <div><b>{fmtDate(l.start_date)}</b> → <b>{l.end_date ? fmtDate(l.end_date) : "open-ended"}</b></div>
            <div>Rent: {lkr(l.monthly_rent)}/month, due day {l.rent_due_day}</div>
            <div>Security deposit: {lkr(l.security_deposit)}</div>
            <div>Notice period: {l.notice_period_days} days {l.auto_renew && <Badge color="blue">auto-renew</Badge>}</div>
            {l.notes && <div className="text-slate-500">📝 {l.notes}</div>}
          </div>
        </Card>
        <Card title="Unit">
          <div className="space-y-1 text-sm">
            <div className="flex items-center justify-between">
              <span><b>{l.unit.unit_no}</b> <span className="text-xs text-slate-400">{l.unit.unit_type.name}</span></span>
              {l.unit.status && <Badge color={statusColor(l.unit.status.code.toUpperCase())}>{l.unit.status.code.toUpperCase()}</Badge>}
            </div>
            {l.unit.property && <div className="text-xs text-slate-400">{l.unit.property.name}</div>}
          </div>
        </Card>
      </div>

      {l.utility_readings.length > 0 && (
        <Card title="Utility readings">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <thead><tr><th className="th">Period</th><th className="th">Type</th><th className="th text-right">Previous</th><th className="th text-right">Current</th><th className="th text-right">Amount</th></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {l.utility_readings.map((r) => (
                  <tr key={r.id}>
                    <td className="td">{fmtDate(r.period_month)}</td>
                    <td className="td"><Badge>{r.utility_type.name}</Badge></td>
                    <td className="td text-right">{r.previous_reading}</td>
                    <td className="td text-right">{r.current_reading}</td>
                    <td className="td text-right font-semibold">{lkr(r.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {ledger && (
        <Card
          title={`Ledger — rent posts automatically each month (${ledger.status.code.toUpperCase()}${ledger.invoice_no ? ` · ${ledger.invoice_no}` : ""})`}
          actions={
            ledger.status.code === "open" && (
              <>
                {can("apartment_ledgers.add_line") && <button className="btn-secondary !py-1" onClick={() => setAddLineOpen(true)}><Plus size={14} /> Add charge</button>}
                {ledger.balance > 0 && can("apartment_ledgers.payment") && <button className="btn-secondary !py-1" onClick={() => setPayOpen(true)}>Take payment</button>}
                {ledger.paid - ledger.refunded > 0 && can("apartment_ledgers.refund") && <button className="btn-ghost !py-1 text-red-600" onClick={() => setRefundOpen(true)}>Refund…</button>}
              </>
            )
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px]">
              <thead><tr><th className="th">When</th><th className="th">Item</th><th className="th">By</th><th className="th text-right">Amount</th><th className="th" /></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {ledger.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="td whitespace-nowrap text-xs text-slate-400">{fmtDateTime(line.created_at)}</td>
                    <td className="td"><Badge>{line.source.code.toUpperCase()}</Badge> {line.description}</td>
                    <td className="td text-xs text-slate-400">{line.staff?.name ?? "System"}</td>
                    <td className="td text-right font-semibold">{lkr(line.amount)}</td>
                    <td className="td text-right">
                      {ledger.status.code === "open" && can("apartment_ledgers.void_line") && (
                        <button className="btn-ghost !p-1.5 text-red-400 hover:!bg-red-50 hover:text-red-600" title="Remove charge" onClick={() => setVoidingLine(line)}>
                          <Trash2 size={13} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {ledger.lines.length === 0 && <Empty text="Rent posts automatically on the 1st of each month" />}
          </div>
          <div className="mt-3 grid gap-1 border-t border-slate-100 pt-3 text-sm sm:ml-auto sm:w-72">
            <div className="flex justify-between font-extrabold"><span>Total</span><span>{lkr(ledger.total)}</span></div>
            <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(ledger.paid)}</span></div>
            {ledger.refunded > 0 && <div className="flex justify-between text-red-600"><span>Refunded</span><span>{lkr(ledger.refunded)}</span></div>}
            <div className="flex justify-between font-bold"><span>Balance</span><span>{lkr(ledger.balance)}</span></div>
          </div>
          {ledger.payments.length > 0 && (
            <div className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
              {ledger.payments.map((p) => (
                <div key={p.id}>
                  {fmtDateTime(p.created_at)} — {p.kind.code.toUpperCase()} {p.method.code.toUpperCase()} {lkr(p.amount)} {p.reference && `(${p.reference})`} by {p.staff.name} {p.reason && `· ${p.reason}`}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {renewOpen && (
        <RenewModal
          lease={l}
          onClose={() => setRenewOpen(false)}
          onDone={(newEnd) => { setRenewOpen(false); toast.success(`Lease renewed to ${newEnd}`, ""); reload(); }}
        />
      )}
      {terminateOpen && (
        <ReasonModal
          title="Terminate lease — unit returns to Available"
          onSubmit={async (reason) => {
            try {
              await post(`/apartments/leases/${l.id}/terminate`, { reason });
              toast.info(`Lease ${l.code} terminated`, reason);
              setTerminateOpen(false);
              reload();
            } catch (e) {
              setActErr((e as Error).message);
              setTerminateOpen(false);
            }
          }}
          onClose={() => setTerminateOpen(false)}
        />
      )}
      {utilityOpen && <UtilityReadingModal leaseId={l.id} onClose={() => setUtilityOpen(false)} onDone={() => { setUtilityOpen(false); reload(); }} />}
      {addLineOpen && ledger && <AddLineModal ledgerId={ledger.id} onClose={() => setAddLineOpen(false)} onDone={() => { setAddLineOpen(false); reload(); }} />}
      {payOpen && ledger && (
        <SplitPay
          due={Math.max(ledger.balance, 0)}
          onDone={async (payments) => {
            try {
              for (const p of payments) {
                await post(`/apartments/ledgers/${ledger.id}/payment`, { method: p.method.toLowerCase(), amount: p.amount, reference: p.reference, idempotency_key: crypto.randomUUID() });
              }
              setPayOpen(false);
              reload();
            } catch (e) {
              setActErr((e as Error).message);
            }
          }}
          onClose={() => setPayOpen(false)}
        />
      )}
      {refundOpen && ledger && (
        <ReasonModal
          title="Refund from ledger — reason required"
          withAmount={ledger.paid - ledger.refunded}
          onSubmit={async (reason, amount, method) => {
            try {
              await post(`/apartments/ledgers/${ledger.id}/refund`, { reason, amount, method: method?.toLowerCase() });
              setRefundOpen(false);
              reload();
            } catch (e) {
              setActErr((e as Error).message);
            }
          }}
          onClose={() => setRefundOpen(false)}
        />
      )}
      {voidingLine && (
        <ReasonModal
          title={`Remove charge — ${voidingLine.description}`}
          onSubmit={async (reason) => {
            try {
              await post(`/apartments/ledgers/lines/${voidingLine.id}/void`, { reason });
              setVoidingLine(null);
              reload();
            } catch (e) {
              setActErr((e as Error).message);
              setVoidingLine(null);
            }
          }}
          onClose={() => setVoidingLine(null)}
        />
      )}
    </div>
  );
}

function RenewModal({ lease, onClose, onDone }: { lease: Detail; onClose: () => void; onDone: (newEnd: string) => void }) {
  const [endDate, setEndDate] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  return (
    <Modal open onClose={onClose} title={`Renew ${lease.code}`}>
      <Field label="New end date *" hint={lease.end_date ? `Current end date: ${fmtDate(lease.end_date)}` : "Currently open-ended"}>
        <input type="date" className="input" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
      </Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!endDate || busy}
        onClick={() => {
          setBusy(true);
          post(`/apartments/leases/${lease.id}/renew`, { end_date: endDate })
            .then(() => onDone(endDate))
            .catch((e) => { setError(e.message); setBusy(false); });
        }}
      >
        {busy ? "Renewing…" : "Renew lease"}
      </button>
    </Modal>
  );
}

function UtilityReadingModal({ leaseId, onClose, onDone }: { leaseId: number; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ utilityType: "electricity", periodMonth: new Date().toISOString().slice(0, 7) + "-01", previous: "", current: "", rate: "" });
  const [error, setError] = useState("");
  const amount = f.current && f.previous ? Math.max(0, parseFloat(f.current) - parseFloat(f.previous)) * toCents(f.rate || "0") : 0;
  return (
    <Modal open onClose={onClose} title="Record utility reading">
      <div className="space-y-3">
        <Field label="Utility">
          <select className="input" value={f.utilityType} onChange={(e) => setF({ ...f, utilityType: e.target.value })}>
            <option value="electricity">Electricity</option>
            <option value="water">Water</option>
            <option value="gas">Gas</option>
          </select>
        </Field>
        <Field label="Billing month"><input type="date" className="input" value={f.periodMonth} onChange={(e) => setF({ ...f, periodMonth: e.target.value })} /></Field>
        <div className="grid grid-cols-2 gap-2">
          <Field label="Previous reading"><input className="input" value={f.previous} onChange={(e) => setF({ ...f, previous: e.target.value })} /></Field>
          <Field label="Current reading"><input className="input" value={f.current} onChange={(e) => setF({ ...f, current: e.target.value })} /></Field>
        </div>
        <Field label="Rate per unit (LKR)"><input className="input" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} /></Field>
        {amount > 0 && <div className="text-sm font-semibold text-brand-700">Charge: {lkr(amount)}</div>}
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={!f.previous || !f.current || !f.rate}
          onClick={() =>
            post(`/apartments/leases/${leaseId}/utility-readings`, {
              utility_type: f.utilityType, period_month: f.periodMonth,
              previous_reading: parseFloat(f.previous), current_reading: parseFloat(f.current), rate_per_unit: toCents(f.rate),
            })
              .then(onDone)
              .catch((e) => setError(e.message))
          }
        >
          Record & post charge
        </button>
      </div>
    </Modal>
  );
}

function AddLineModal({ ledgerId, onClose, onDone }: { ledgerId: number; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ source: "damage", description: "", qty: "1", unitPrice: "" });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="Add charge to ledger">
      <div className="space-y-3">
        <Field label="Type">
          <select className="input" value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })}>
            {[
              { code: "cleaning_fee", label: "CLEANING FEE" },
              { code: "utility", label: "UTILITY" },
              { code: "damage", label: "DAMAGE" },
              { code: "adjustment", label: "ADJUSTMENT" },
              { code: "surcharge", label: "SURCHARGE (LATE RENT)" },
            ].map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
          </select>
        </Field>
        <Field label="Description"><input className="input" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="e.g. Late rent fee — July" /></Field>
        <div className="grid grid-cols-2 gap-2">
          <Field label="Qty"><input className="input" value={f.qty} onChange={(e) => setF({ ...f, qty: e.target.value })} /></Field>
          <Field label="Unit price (LKR)"><input className="input" value={f.unitPrice} onChange={(e) => setF({ ...f, unitPrice: e.target.value })} /></Field>
        </div>
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={!f.description.trim() || toCents(f.unitPrice) <= 0}
          onClick={() =>
            post(`/apartments/ledgers/${ledgerId}/lines`, { source: f.source, description: f.description.trim(), qty: parseFloat(f.qty) || 1, unit_price: toCents(f.unitPrice) })
              .then(onDone)
              .catch((e) => setError(e.message))
          }
        >
          Add charge
        </button>
      </div>
    </Modal>
  );
}
