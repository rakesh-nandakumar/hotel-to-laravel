import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Ban, FileCheck2, HandCoins, CheckCircle2, Check, CircleDot, Circle } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, lkr, fmtDate, fmtDateTime, toCents } from "../../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor } from "../../components/ui";
import { SplitPay, ReasonModal } from "../POS";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";
import clsx from "clsx";

type Lookup = { id: number; code: string; name: string };

type Detail = {
  id: number; code: string; status: Lookup; agreed_price: number;
  reserved_until: string | null; agreement_signed_at: string | null; handover_at: string | null; notes: string | null;
  customer: { id: number; name: string; phone: string | null; email: string | null };
  unit: { id: number; unit_no: string; unit_type: { name: string }; status?: Lookup; property: { name: string } | null };
};
type LedgerLineT = { id: number; source: Lookup; description: string; amount: number; voided: boolean; created_at: string; staff: { name: string } | null };
type PaymentT = { id: number; kind: Lookup; method: Lookup; amount: number; reference: string | null; reason: string | null; created_at: string; staff: { name: string } | null };
type LedgerT = { id: number; status: Lookup; invoice_no: string | null; lines: LedgerLineT[]; payments: PaymentT[]; total: number; paid: number; refunded: number; balance: number };

const STEPS = [
  { key: "inquiry", label: "Inquiry" },
  { key: "reserved", label: "Reserved" },
  { key: "agreement_signed", label: "Agreement signed" },
  { key: "completed", label: "Completed" },
] as const;

function StatusStepper({ status }: { status: string }) {
  const idx = STEPS.findIndex((s) => s.key === status);
  const current = idx === -1 ? 0 : idx;
  return (
    <div className="flex items-center">
      {STEPS.map((s, i) => (
        <div key={s.key} className="flex flex-1 items-center last:flex-none">
          <div className="flex items-center gap-1.5">
            {i < current ? <Check size={15} className="rounded-full bg-emerald-500 p-0.5 text-white" /> : i === current ? <CircleDot size={15} className="text-brand-600" /> : <Circle size={15} className="text-slate-300" />}
            <span className={clsx("text-xs font-semibold", i <= current ? "text-slate-700" : "text-slate-400")}>{s.label}</span>
          </div>
          {i < STEPS.length - 1 && <div className={clsx("mx-2 h-0.5 flex-1 rounded", i < current ? "bg-emerald-500" : "bg-slate-200")} />}
        </div>
      ))}
    </div>
  );
}

export default function SaleDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, reload, error } = useFetch<{ sale: Detail; ledger: LedgerT | null }>(`/apartments/sales/${id}`);
  const { can } = useAuth();
  const toast = useToast();
  const [actErr, setActErr] = useState("");
  const [reserveOpen, setReserveOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  const [refundOpen, setRefundOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  if (error) return <ErrorText error={error} />;
  if (!data) return <Empty text="Loading…" />;
  const s = data.sale;
  const ledger = data.ledger;

  const simpleAction = async (path: string, successMsg: string) => {
    setBusy(true);
    setActErr("");
    try {
      await post(path, {});
      toast.success(successMsg, "");
      reload();
    } catch (e) {
      setActErr((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <button className="text-xs font-bold text-brand-600" onClick={() => nav("/apartments/sales")}>← Sales</button>
          <h1 className="text-xl font-extrabold">{s.code} <Badge color={statusColor(s.status.code.toUpperCase())}>{s.status.code.toUpperCase()}</Badge></h1>
        </div>
        <div className="flex flex-wrap gap-2">
          {s.status.code === "inquiry" && can("apartment_sales.reserve") && (
            <button className="btn-primary" onClick={() => setReserveOpen(true)}><HandCoins size={16} /> Reserve</button>
          )}
          {s.status.code === "reserved" && can("apartment_sales.sign_agreement") && (
            <button className="btn-primary" disabled={busy} onClick={() => simpleAction(`/apartments/sales/${s.id}/sign-agreement`, "Agreement signed")}>
              <FileCheck2 size={16} /> Sign agreement
            </button>
          )}
          {s.status.code === "agreement_signed" && can("apartment_sales.complete") && (
            <button className="btn-primary" disabled={busy} onClick={() => simpleAction(`/apartments/sales/${s.id}/complete`, "Sale completed — unit sold")}>
              <CheckCircle2 size={16} /> Complete sale
            </button>
          )}
          {["inquiry", "reserved", "agreement_signed"].includes(s.status.code) && can("apartment_sales.cancel") && (
            <button className="btn-danger" onClick={() => setCancelOpen(true)}><Ban size={16} /> Cancel</button>
          )}
        </div>
      </div>
      {!["cancelled"].includes(s.status.code) && <StatusStepper status={s.status.code} />}
      <ErrorText error={actErr} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Buyer">
          <div className="space-y-1 text-sm">
            <div className="text-base font-bold">{s.customer.name}</div>
            <div>📞 {s.customer.phone ? <a className="text-brand-600 hover:underline" href={`tel:${s.customer.phone}`}>{s.customer.phone}</a> : "—"}</div>
            <div>✉️ {s.customer.email ?? "—"}</div>
          </div>
        </Card>
        <Card title="Sale">
          <div className="space-y-1 text-sm">
            <div>Agreed price: <b>{lkr(s.agreed_price)}</b></div>
            {s.reserved_until && <div>Hold expires: {fmtDate(s.reserved_until)}</div>}
            {s.agreement_signed_at && <div>Agreement signed: {fmtDateTime(s.agreement_signed_at)}</div>}
            {s.handover_at && <div>Handed over: {fmtDateTime(s.handover_at)}</div>}
            {s.notes && <div className="text-slate-500">📝 {s.notes}</div>}
          </div>
        </Card>
        <Card title="Unit">
          <div className="space-y-1 text-sm">
            <div className="flex items-center justify-between">
              <span><b>{s.unit.unit_no}</b> <span className="text-xs text-slate-400">{s.unit.unit_type.name}</span></span>
              {s.unit.status && <Badge color={statusColor(s.unit.status.code.toUpperCase())}>{s.unit.status.code.toUpperCase()}</Badge>}
            </div>
            {s.unit.property && <div className="text-xs text-slate-400">{s.unit.property.name}</div>}
          </div>
        </Card>
      </div>

      {ledger && (
        <Card title={`Ledger — payment plan (${ledger.status.code.toUpperCase()}${ledger.invoice_no ? ` · ${ledger.invoice_no}` : ""})`}
          actions={
            ledger.status.code === "open" && (
              <>
                {ledger.balance > 0 && can("apartment_ledgers.payment") && <button className="btn-secondary !py-1" onClick={() => setPayOpen(true)}>Take payment</button>}
                {ledger.paid - ledger.refunded > 0 && can("apartment_ledgers.refund") && <button className="btn-ghost !py-1 text-red-600" onClick={() => setRefundOpen(true)}>Refund…</button>}
              </>
            )
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px]">
              <thead><tr><th className="th">When</th><th className="th">Item</th><th className="th text-right">Amount</th></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {ledger.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="td whitespace-nowrap text-xs text-slate-400">{fmtDateTime(line.created_at)}</td>
                    <td className="td"><Badge>{line.source.code.toUpperCase()}</Badge> {line.description}</td>
                    <td className="td text-right font-semibold">{lkr(line.amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {ledger.lines.length === 0 && <Empty text="No charges yet" />}
          </div>
          <div className="mt-3 grid gap-1 border-t border-slate-100 pt-3 text-sm sm:ml-auto sm:w-72">
            <div className="flex justify-between font-extrabold"><span>Agreed price</span><span>{lkr(ledger.total)}</span></div>
            <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(ledger.paid)}</span></div>
            {ledger.refunded > 0 && <div className="flex justify-between text-red-600"><span>Refunded</span><span>{lkr(ledger.refunded)}</span></div>}
            <div className="flex justify-between font-bold"><span>Balance</span><span>{lkr(ledger.balance)}</span></div>
          </div>
          {ledger.payments.length > 0 && (
            <div className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
              {ledger.payments.map((p) => (
                <div key={p.id}>
                  {fmtDateTime(p.created_at)} — {p.kind.code.toUpperCase()} {p.method.code.toUpperCase()} {lkr(p.amount)} {p.reference && `(${p.reference})`} {p.staff && `by ${p.staff.name}`} {p.reason && `· ${p.reason}`}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {reserveOpen && <ReserveModal saleId={s.id} onClose={() => setReserveOpen(false)} onDone={() => { setReserveOpen(false); reload(); }} />}
      {cancelOpen && (
        <ReasonModal
          title="Cancel sale — deposit forfeiture policy applies automatically"
          onSubmit={async (reason) => {
            try {
              const res = await post<{ ok: boolean; refund_pct: number; refunded: number }>(`/apartments/sales/${s.id}/cancel`, { reason });
              toast.info(`Sale ${s.code} cancelled`, res.refunded > 0 ? `${res.refund_pct}% refunded — LKR ${(res.refunded / 100).toFixed(2)}` : "Deposit forfeited per policy");
              setCancelOpen(false);
              reload();
            } catch (e) {
              setActErr((e as Error).message);
              setCancelOpen(false);
            }
          }}
          onClose={() => setCancelOpen(false)}
        />
      )}
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
    </div>
  );
}

function ReserveModal({ saleId, onClose, onDone }: { saleId: number; onClose: () => void; onDone: () => void }) {
  const [reservedUntil, setReservedUntil] = useState("");
  const [deposit, setDeposit] = useState({ take: false, method: "bank_transfer", amount: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  return (
    <Modal open onClose={onClose} title="Reserve unit — hold from other buyers">
      <Field label="Hold expires" hint="Leave blank to use the default hold period">
        <input type="date" className="input" value={reservedUntil} onChange={(e) => setReservedUntil(e.target.value)} />
      </Field>
      <label className="mt-3 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={deposit.take} onChange={(e) => setDeposit({ ...deposit, take: e.target.checked })} />
        Take reservation deposit now
      </label>
      {deposit.take && (
        <div className="mt-2 flex gap-2">
          <select className="input !w-40" value={deposit.method} onChange={(e) => setDeposit({ ...deposit, method: e.target.value })}>
            {["cash", "card", "lankaqr", "bank_transfer"].map((m) => <option key={m} value={m}>{m.toUpperCase()}</option>)}
          </select>
          <input className="input" placeholder="Amount (LKR)" value={deposit.amount} onChange={(e) => setDeposit({ ...deposit, amount: e.target.value })} />
        </div>
      )}
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={busy}
        onClick={() => {
          setBusy(true);
          post(`/apartments/sales/${saleId}/reserve`, {
            reserved_until: reservedUntil || undefined,
            deposit_payment: deposit.take && toCents(deposit.amount) > 0 ? { method: deposit.method, amount: toCents(deposit.amount) } : undefined,
          })
            .then(onDone)
            .catch((e) => { setError(e.message); setBusy(false); });
        }}
      >
        {busy ? "Reserving…" : "Reserve unit"}
      </button>
    </Modal>
  );
}
