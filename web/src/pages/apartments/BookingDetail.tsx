import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { LogIn, LogOut, Ban, Plus, Trash2, Check, CircleDot, Circle } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, lkr, fmtDate, fmtDateTime, toCents, useSettings } from "../../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor } from "../../components/ui";
import { SplitPay, ReasonModal } from "../POS";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";
import clsx from "clsx";

type Lookup = { id: number; code: string; name: string };

type Detail = {
  id: number; code: string; status: Lookup; channel: Lookup;
  check_in: string; check_out: string; adults: number; children: number;
  nightly_rate: number; rate_basis: string; deposit_due: number; notes: string | null;
  customer: { id: number; name: string; phone: string | null; email: string | null; id_number: string | null };
  unit: { id: number; unit_no: string; unit_type: { name: string }; status?: Lookup; property: { name: string } | null };
};
type LedgerLineT = { id: number; source: Lookup; description: string; amount: number; voided: boolean; created_at: string; staff: { name: string } };
type PaymentT = { id: number; kind: Lookup; method: Lookup; amount: number; reference: string | null; reason: string | null; created_at: string; staff: { name: string } };
type LedgerT = { id: number; status: Lookup; invoice_no: string | null; lines: LedgerLineT[]; payments: PaymentT[]; total: number; paid: number; refunded: number; balance: number };
type CheckoutQuote = {
  ledger: { id: number; status: Lookup; invoice_no: string | null; total: number; paid: number; refunded: number; balance: number };
  lines: LedgerLineT[];
  late_surcharge: number; service_charge: number; service_charge_pct: number;
  vat: number; vat_pct: number; grand_total: number; balance_due: number;
};

const STEPS = [
  { key: "pending", label: "Booked" },
  { key: "confirmed", label: "Confirmed" },
  { key: "checked_in", label: "Checked in" },
  { key: "checked_out", label: "Checked out" },
] as const;

function StatusStepper({ status }: { status: string }) {
  const idx = STEPS.findIndex((s) => s.key === status);
  const current = idx === -1 ? 1 : idx;
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

export default function BookingDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, reload, error } = useFetch<{ booking: Detail; ledger: LedgerT | null }>(`/apartments/bookings/${id}`);
  const { can } = useAuth();
  const toast = useToast();
  const [actErr, setActErr] = useState("");
  const [checkinOpen, setCheckinOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [addLineOpen, setAddLineOpen] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  const [refundOpen, setRefundOpen] = useState(false);
  const [voidingLine, setVoidingLine] = useState<LedgerLineT | null>(null);
  const nav = useNavigate();

  if (error) return <ErrorText error={error} />;
  if (!data) return <Empty text="Loading…" />;
  const b = data.booking;
  const l = data.ledger;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <button className="text-xs font-bold text-brand-600" onClick={() => nav("/apartments/bookings")}>← Bookings</button>
          <h1 className="text-xl font-extrabold">{b.code} <Badge color={statusColor(b.status.code.toUpperCase())}>{b.status.code.toUpperCase()}</Badge></h1>
        </div>
        <div className="flex flex-wrap gap-2">
          {(b.status.code === "confirmed" || b.status.code === "pending") && (
            <>
              {can("apartment_bookings.check_in") && <button className="btn-primary" onClick={() => setCheckinOpen(true)}><LogIn size={16} /> Check in</button>}
              {can("apartment_bookings.cancel") && <button className="btn-danger" onClick={() => setCancelOpen(true)}><Ban size={16} /> Cancel</button>}
            </>
          )}
          {b.status.code === "checked_in" && can("apartment_bookings.checkout") && (
            <button className="btn-primary" onClick={() => setCheckoutOpen(true)}><LogOut size={16} /> Check out</button>
          )}
        </div>
      </div>
      {b.status.code !== "cancelled" && <StatusStepper status={b.status.code} />}
      <ErrorText error={actErr} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Customer">
          <div className="space-y-1 text-sm">
            <div className="text-base font-bold">{b.customer.name}</div>
            <div>📞 {b.customer.phone ? <a className="text-brand-600 hover:underline" href={`tel:${b.customer.phone}`}>{b.customer.phone}</a> : "—"}</div>
            <div>✉️ {b.customer.email ?? "—"}</div>
            <div>🪪 {b.customer.id_number ?? <span className="font-semibold text-amber-600">ID required at check-in</span>}</div>
          </div>
        </Card>
        <Card title="Stay">
          <div className="space-y-1 text-sm">
            <div><b>{fmtDate(b.check_in)}</b> → <b>{fmtDate(b.check_out)}</b></div>
            <div>{b.adults} adult(s), {b.children} child(ren) · via {b.channel.name}</div>
            <div>Rate: {lkr(b.nightly_rate)}/night ({b.rate_basis})</div>
            <div>Deposit due at booking: {lkr(b.deposit_due)}</div>
            {b.notes && <div className="text-slate-500">📝 {b.notes}</div>}
          </div>
        </Card>
        <Card title="Unit">
          <div className="space-y-1 text-sm">
            <div className="flex items-center justify-between">
              <span><b>{b.unit.unit_no}</b> <span className="text-xs text-slate-400">{b.unit.unit_type.name}</span></span>
              {b.unit.status && <Badge color={statusColor(b.unit.status.code.toUpperCase())}>{b.unit.status.code.toUpperCase()}</Badge>}
            </div>
            {b.unit.property && <div className="text-xs text-slate-400">{b.unit.property.name}</div>}
          </div>
        </Card>
      </div>

      {l && (
        <Card
          title={`Ledger — all charges flow here automatically (${l.status.code.toUpperCase()}${l.invoice_no ? ` · ${l.invoice_no}` : ""})`}
          actions={
            l.status.code === "open" && (
              <>
                {can("apartment_ledgers.add_line") && <button className="btn-secondary !py-1" onClick={() => setAddLineOpen(true)}><Plus size={14} /> Add charge</button>}
                {l.balance > 0 && can("apartment_ledgers.payment") && <button className="btn-secondary !py-1" onClick={() => setPayOpen(true)}>Take payment</button>}
                {l.paid - l.refunded > 0 && can("apartment_ledgers.refund") && <button className="btn-ghost !py-1 text-red-600" onClick={() => setRefundOpen(true)}>Refund…</button>}
              </>
            )
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px]">
              <thead><tr><th className="th">When</th><th className="th">Item</th><th className="th">By</th><th className="th text-right">Amount</th><th className="th" /></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {l.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="td whitespace-nowrap text-xs text-slate-400">{fmtDateTime(line.created_at)}</td>
                    <td className="td"><Badge>{line.source.code.toUpperCase()}</Badge> {line.description}</td>
                    <td className="td text-xs text-slate-400">{line.staff.name}</td>
                    <td className="td text-right font-semibold">{lkr(line.amount)}</td>
                    <td className="td text-right">
                      {l.status.code === "open" && can("apartment_ledgers.void_line") && (
                        <button className="btn-ghost !p-1.5 text-red-400 hover:!bg-red-50 hover:text-red-600" title="Remove charge" onClick={() => setVoidingLine(line)}>
                          <Trash2 size={13} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {l.lines.length === 0 && <Empty text="Charges post automatically at check-in" />}
          </div>
          <div className="mt-3 grid gap-1 border-t border-slate-100 pt-3 text-sm sm:ml-auto sm:w-72">
            <div className="flex justify-between font-extrabold"><span>Total</span><span>{lkr(l.total)}</span></div>
            <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(l.paid)}</span></div>
            {l.refunded > 0 && <div className="flex justify-between text-red-600"><span>Refunded</span><span>{lkr(l.refunded)}</span></div>}
            <div className="flex justify-between font-bold"><span>Balance</span><span>{lkr(l.balance)}</span></div>
          </div>
          {l.payments.length > 0 && (
            <div className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
              {l.payments.map((p) => (
                <div key={p.id}>
                  {fmtDateTime(p.created_at)} — {p.kind.code.toUpperCase()} {p.method.code.toUpperCase()} {lkr(p.amount)} {p.reference && `(${p.reference})`} by {p.staff.name} {p.reason && `· ${p.reason}`}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {checkinOpen && <CheckInModal b={b} onClose={() => setCheckinOpen(false)} onDone={() => { setCheckinOpen(false); reload(); }} />}
      {checkoutOpen && <CheckOutModal b={b} onClose={() => setCheckoutOpen(false)} onDone={() => { setCheckoutOpen(false); reload(); }} />}
      {cancelOpen && (
        <ReasonModal
          title="Cancel booking — policy refund applied automatically"
          onSubmit={async (reason) => {
            try {
              const res = await post<{ ok: boolean; refund_pct: number; refunded: number }>(`/apartments/bookings/${b.id}/cancel`, { reason });
              toast.info(`Booking ${b.code} cancelled`, res.refunded > 0 ? `${res.refund_pct}% refund — LKR ${(res.refunded / 100).toFixed(2)}` : "No refund per policy");
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
      {addLineOpen && l && <AddLineModal ledgerId={l.id} onClose={() => setAddLineOpen(false)} onDone={() => { setAddLineOpen(false); reload(); }} />}
      {payOpen && l && (
        <SplitPay
          due={Math.max(l.balance, 0)}
          onDone={async (payments) => {
            try {
              for (const p of payments) {
                await post(`/apartments/ledgers/${l.id}/payment`, { method: p.method.toLowerCase(), amount: p.amount, reference: p.reference, idempotency_key: crypto.randomUUID() });
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
      {refundOpen && l && (
        <ReasonModal
          title="Refund from ledger — reason required"
          withAmount={l.paid - l.refunded}
          onSubmit={async (reason, amount, method) => {
            try {
              await post(`/apartments/ledgers/${l.id}/refund`, { reason, amount, method: method?.toLowerCase() });
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
              toast.info("Charge removed", voidingLine.description);
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

function CheckInModal({ b, onClose, onDone }: { b: Detail; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [idNumber, setIdNumber] = useState(b.customer.id_number ?? "");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const doCheckIn = async () => {
    setBusy(true);
    setError("");
    try {
      await post(`/apartments/bookings/${b.id}/check-in`, { id_number: idNumber.trim() || undefined });
      toast.success(`${b.customer.name} checked in`, `${b.code} — rent posted to the ledger`);
      onDone();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Check in ${b.customer.name}`}>
      <div className="space-y-3">
        <Field label="Customer ID / passport number (required)">
          <input className="input" value={idNumber} onChange={(e) => setIdNumber(e.target.value)} placeholder="NIC or passport no." />
        </Field>
        <ErrorText error={error} />
        <button className="btn-primary w-full !py-3" disabled={busy} onClick={doCheckIn}>
          {busy ? "Checking in…" : "Confirm check-in (posts rent to ledger)"}
        </button>
      </div>
    </Modal>
  );
}

function CheckOutModal({ b, onClose, onDone }: { b: Detail; onClose: () => void; onDone: () => void }) {
  const { str, num } = useSettings();
  const toast = useToast();
  const late = new Date().toTimeString().slice(0, 5) > str("frontdesk.check_out_time", "12:00");
  const lateAmt = num("apartment.late_checkout_surcharge", 0);
  const [applyLate, setApplyLate] = useState(late && lateAmt > 0);
  const { data: quote } = useFetch<CheckoutQuote>(`/apartments/bookings/${b.id}/checkout-quote?late=${applyLate ? "1" : "0"}`, [applyLate]);
  const [payments, setPayments] = useState<{ method: string; amount: string; reference: string }[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [invoiceNo, setInvoiceNo] = useState("");

  if (invoiceNo) {
    return (
      <Modal open onClose={onDone} title="Checked out ✓">
        <div className="space-y-3 text-center">
          <div className="text-3xl">🧾</div>
          <p className="text-sm">Invoice <b>{invoiceNo}</b> generated. Unit is available again.</p>
          <button className="btn-ghost w-full" onClick={onDone}>Done</button>
        </div>
      </Modal>
    );
  }

  if (!quote) return null;
  const newSum = payments.reduce((s, p) => s + toCents(p.amount), 0);
  const remaining = quote.balance_due - newSum;

  const doCheckout = async () => {
    setBusy(true);
    setError("");
    try {
      const res = await post<{ invoice_no: string }>(`/apartments/bookings/${b.id}/checkout`, {
        apply_late_surcharge: applyLate,
        payments: payments.filter((p) => toCents(p.amount) > 0).map((p) => ({ method: p.method, amount: toCents(p.amount), reference: p.reference || undefined })),
      });
      setInvoiceNo(res.invoice_no);
      toast.success(`${b.customer.name} checked out`, `Invoice ${res.invoice_no} generated`);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Check out ${b.customer.name} — bill`} wide>
      <div className="max-h-48 overflow-y-auto rounded-lg border border-slate-100">
        <table className="w-full text-sm">
          <tbody className="divide-y divide-slate-50">
            {quote.lines.map((line) => (
              <tr key={line.id}><td className="td">{line.description}</td><td className="td text-right">{lkr(line.amount)}</td></tr>
            ))}
            {applyLate && quote.late_surcharge > 0 && <tr><td className="td">Late check-out surcharge</td><td className="td text-right">{lkr(quote.late_surcharge)}</td></tr>}
            {quote.service_charge > 0 && <tr><td className="td">Service charge {quote.service_charge_pct}%</td><td className="td text-right">{lkr(quote.service_charge)}</td></tr>}
            {quote.vat > 0 && <tr><td className="td">VAT {quote.vat_pct}%</td><td className="td text-right">{lkr(quote.vat)}</td></tr>}
          </tbody>
        </table>
      </div>
      {late && (
        <label className="mt-2 flex items-center gap-2 text-sm font-semibold">
          <input type="checkbox" checked={applyLate} onChange={(e) => setApplyLate(e.target.checked)} disabled={lateAmt === 0} />
          Late check-out — surcharge {lkr(lateAmt)} {lateAmt === 0 && "(not configured)"}
        </label>
      )}
      <div className="mt-3 space-y-1 rounded-xl bg-slate-50 p-3 text-sm">
        <div className="flex justify-between font-extrabold"><span>Grand total</span><span>{lkr(quote.grand_total)}</span></div>
        <div className="flex justify-between text-emerald-700"><span>Already paid (deposit etc.)</span><span>{lkr(quote.ledger.paid - quote.ledger.refunded)}</span></div>
        <div className="flex justify-between text-base font-extrabold"><span>{quote.balance_due >= 0 ? "Balance due now" : "Refund due to customer"}</span><span>{lkr(Math.abs(quote.balance_due))}</span></div>
      </div>

      {quote.balance_due > 0 && (
        <div className="mt-3 space-y-2">
          <div className="label">Payments (mixed methods supported)</div>
          {payments.map((p, i) => (
            <div key={i} className="flex gap-2">
              <select className="input !w-40" value={p.method} onChange={(e) => setPayments(payments.map((x, j) => (j === i ? { ...x, method: e.target.value } : x)))}>
                {["cash", "card", "lankaqr", "bank_transfer"].map((m) => <option key={m} value={m}>{m.toUpperCase()}</option>)}
              </select>
              <input className="input" placeholder="Amount LKR" value={p.amount} onChange={(e) => setPayments(payments.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} />
              <input className="input !w-28" placeholder="Ref" value={p.reference} onChange={(e) => setPayments(payments.map((x, j) => (j === i ? { ...x, reference: e.target.value } : x)))} />
              <button className="btn-ghost !px-2" onClick={() => setPayments(payments.filter((_, j) => j !== i))}>✕</button>
            </div>
          ))}
          <button className="btn-secondary w-full" onClick={() => setPayments([...payments, { method: "cash", amount: remaining > 0 ? (remaining / 100).toFixed(2) : "", reference: "" }])}>
            + Add payment
          </button>
          <div className={`text-right text-sm font-bold ${remaining === 0 ? "text-emerald-600" : "text-red-600"}`}>
            {remaining === 0 ? "Fully covered ✓" : remaining > 0 ? `Short ${lkr(remaining)}` : `Over ${lkr(-remaining)}`}
          </div>
        </div>
      )}
      <ErrorText error={error} />
      <button className="btn-primary mt-3 w-full !py-3" disabled={busy || (quote.balance_due > 0 && remaining !== 0)} onClick={doCheckout}>
        {busy ? "Processing…" : quote.balance_due < 0 ? `Check out & refund ${lkr(-quote.balance_due)}` : "Complete checkout & generate invoice"}
      </button>
    </Modal>
  );
}

function AddLineModal({ ledgerId, onClose, onDone }: { ledgerId: number; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ source: "cleaning_fee", description: "", qty: "1", unitPrice: "" });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="Add charge to ledger">
      <div className="space-y-3">
        <Field label="Type">
          <select className="input" value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })}>
            {[
              { code: "cleaning_fee", label: "CLEANING FEE" },
              { code: "extra_guest_fee", label: "EXTRA GUEST FEE" },
              { code: "utility", label: "UTILITY" },
              { code: "damage", label: "DAMAGE" },
              { code: "adjustment", label: "ADJUSTMENT" },
              { code: "surcharge", label: "SURCHARGE" },
            ].map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
          </select>
        </Field>
        <Field label="Description"><input className="input" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="e.g. Extra guest fee / Electricity — July" /></Field>
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
