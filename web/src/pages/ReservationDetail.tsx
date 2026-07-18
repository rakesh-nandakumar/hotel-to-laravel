import { useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { Printer, LogIn, LogOut, Ban, Plus, Pencil, Trash2, Check, CircleDot, Circle } from "lucide-react";
import { openPdf, post, put } from "../lib/api";
import { useFetch, lkr, usd, fmtDate, fmtDateTime, toCents, useSettings } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor } from "../components/ui";
import { SplitPay, ReasonModal } from "./POS";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

/** Every status/channel/etc. lookup relation serializes as this shape (see App\Models\Lookup). */
type Lookup = { id: number; code: string; name: string };

type Detail = {
  id: number; code: string; status: Lookup; channel: Lookup;
  check_in: string; check_out: string;
  adults: number; children: number; deposit_due: number; notes: string | null; package_id: number | null;
  pre_check_in: Record<string, string> | null;
  guest: { id: number; name: string; phone: string | null; email: string | null; id_number: string | null; loyalty_points: number; preferences: string | null };
  package: { id: number; name: string; code: string } | null;
  group_booking: { id: number; reference: string; name: string } | null;
  corporate_account: { id: number; company_name: string } | null;
  rooms: {
    id: number; nightly_rate: number;
    // `rooms.room.status` isn't eager-loaded by ReservationController::show() on
    // the backend today, so this is normally absent — guarded below.
    room: { id: number; number: string; status?: Lookup; room_type: { name: string; item_checklist: string[] } };
    bill_to_guest: { id: number; name: string } | null;
  }[];
  // RoomItemCheck::kind() isn't eager-loaded by ReservationController::show()
  // either — only the raw check_kind_id FK comes through, so we can't label
  // check-in vs check-out checks from here (see final report).
  room_item_checks: { id: number; check_kind_id: number; created_at: string; items: { item: string; ok: boolean; note?: string }[] }[];
};
type FolioLineT = { id: number; source: Lookup; description: string; amount: number; voided: boolean; created_at: string; staff: { name: string } };
type PaymentT = { id: number; kind: Lookup; method: Lookup; amount: number; reference: string | null; reason: string | null; created_at: string; staff: { name: string } };
type FolioT = {
  id: number; status: Lookup; invoice_no: string | null;
  lines: FolioLineT[]; payments: PaymentT[];
  total: number; paid: number; refunded: number; balance: number;
};
/** ReservationService::checkoutQuote() nests the folio+totals under `folio`, distinct from FolioController::show()'s flat shape. */
type CheckoutQuote = {
  folio: { id: number; status: Lookup; invoice_no: string | null; total: number; paid: number; refunded: number; balance: number };
  lines: FolioLineT[];
  late_surcharge: number; service_charge: number; service_charge_pct: number;
  vat: number; vat_pct: number; grand_total: number; balance_due: number;
};

export default function ReservationDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, reload, error } = useFetch<{ reservation: Detail; folio: FolioT | null }>(`/reservations/${id}`);
  const { can } = useAuth();
  const { num } = useSettings();
  const usdRate = num("currency.usd_rate", 0);
  const toast = useToast();
  const [actErr, setActErr] = useState("");
  const [checkinOpen, setCheckinOpen] = useState(false);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [addLineOpen, setAddLineOpen] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  const [refundOpen, setRefundOpen] = useState(false);
  const [editStayOpen, setEditStayOpen] = useState(false);
  const [editGuestOpen, setEditGuestOpen] = useState(false);
  const [voidingLine, setVoidingLine] = useState<FolioLineT | null>(null);
  const nav = useNavigate();

  if (error) return <ErrorText error={error} />;
  if (!data) return <Empty text="Loading…" />;
  const r = data.reservation;
  const f = data.folio;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <button className="text-xs font-bold text-brand-600" onClick={() => nav("/reservations")}>← Reservations</button>
          <h1 className="text-xl font-extrabold">
            {r.code} <Badge color={statusColor(r.status.code.toUpperCase())}>{r.status.code.toUpperCase()}</Badge>
          </h1>
        </div>
        <div className="flex flex-wrap gap-2">
          {(r.status.code === "confirmed" || r.status.code === "pending") && (
            <>
              {can("hotel_reservations.check_in") && <button className="btn-primary" onClick={() => setCheckinOpen(true)}><LogIn size={16} /> Check in</button>}
              {can("hotel_reservations.cancel") && <button className="btn-danger" onClick={() => setCancelOpen(true)}><Ban size={16} /> Cancel</button>}
            </>
          )}
          {r.status.code === "checked_in" && can("hotel_reservations.checkout") && (
            <button className="btn-primary" onClick={() => setCheckoutOpen(true)}><LogOut size={16} /> Check out</button>
          )}
          {f && can("hotel_folios.invoice") && (
            <div className="flex">
              <button className="btn-secondary !rounded-r-none" onClick={() => openPdf(`/folios/${f.id}/invoice?format=a4`)}>
                <Printer size={16} /> Invoice {f.invoice_no ?? "(proforma)"}
              </button>
              <button
                className="btn-secondary !rounded-l-none !border-l !border-slate-200 !px-2.5"
                title="Print thermal (80mm)"
                onClick={() => openPdf(`/folios/${f.id}/invoice?format=thermal`)}
              >
                80mm
              </button>
            </div>
          )}
        </div>
      </div>
      {r.status.code !== "cancelled" && <StatusStepper status={r.status.code.toUpperCase()} />}
      <ErrorText error={actErr} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Card title="Guest" actions={can("hotel_guests.edit") ? <button className="btn-ghost !py-1 text-xs" onClick={() => setEditGuestOpen(true)}><Pencil size={12} /> Edit</button> : undefined}>
          <div className="space-y-1 text-sm">
            <div className="text-base font-bold">{r.guest.name} {r.guest.loyalty_points > 0 && <Badge color="brand">★ {r.guest.loyalty_points} pts</Badge>}</div>
            <div>📞 {r.guest.phone ? <a className="text-brand-600 hover:underline" href={`tel:${r.guest.phone}`}>{r.guest.phone}</a> : "—"}</div>
            <div>✉️ {r.guest.email ? <a className="text-brand-600 hover:underline" href={`mailto:${r.guest.email}`}>{r.guest.email}</a> : "—"}</div>
            <div>🪪 {r.guest.id_number ?? <span className="font-semibold text-amber-600">ID required at check-in</span>}</div>
            {r.guest.preferences && <div className="text-slate-500">♥ {r.guest.preferences}</div>}
            {r.pre_check_in && <Badge color="green">Pre-check-in submitted ✓</Badge>}
            <Link className="block pt-1 text-xs font-bold text-brand-600" to="/guests">Guest profile →</Link>
          </div>
        </Card>
        <Card title="Stay" actions={can("hotel_reservations.edit") ? <button className="btn-ghost !py-1 text-xs" onClick={() => setEditStayOpen(true)}><Pencil size={12} /> Edit</button> : undefined}>
          <div className="space-y-1 text-sm">
            <div><b>{fmtDate(r.check_in)}</b> → <b>{fmtDate(r.check_out)}</b></div>
            <div>{r.adults} adult(s), {r.children} child(ren) · via {r.channel.code.toUpperCase()}</div>
            <div>Package: {r.package?.name ?? "Room only"}</div>
            {r.group_booking && <div>Group: <Badge color="purple">{r.group_booking.reference}</Badge> {r.group_booking.name}</div>}
            {r.corporate_account && <div>Corporate: <Badge color="blue">{r.corporate_account.company_name}</Badge></div>}
            <div>Deposit due at booking: {lkr(r.deposit_due)}</div>
            {r.notes && <div className="text-slate-500">📝 {r.notes}</div>}
          </div>
        </Card>
        <Card title="Rooms">
          <div className="space-y-2 text-sm">
            {r.rooms.map((rr) => (
              <div key={rr.id} className="flex items-center justify-between">
                <span><b>Room {rr.room.number}</b> <span className="text-xs text-slate-400">{rr.room.room_type.name}</span></span>
                <span className="flex items-center gap-2">
                  <span className="text-xs">{lkr(rr.nightly_rate)}/n</span>
                  {rr.room.status && <Badge color={statusColor(rr.room.status.code.toUpperCase())}>{rr.room.status.code.toUpperCase()}</Badge>}
                </span>
              </div>
            ))}
            {r.rooms.some((rr) => rr.bill_to_guest) && (
              <div className="text-xs text-slate-500">Bill-to overrides: {r.rooms.filter((x) => x.bill_to_guest).map((x) => `Room ${x.room.number} → ${x.bill_to_guest!.name}`).join(", ")}</div>
            )}
          </div>
        </Card>
      </div>

      {f && (
        <Card
          title={`Folio — all charges flow here automatically (${f.status.code.toUpperCase()}${f.invoice_no ? ` · ${f.invoice_no}` : ""})`}
          actions={
            f.status.code === "open" && (
              <>
                {can("hotel_folios.add_line") && <button className="btn-secondary !py-1" onClick={() => setAddLineOpen(true)}><Plus size={14} /> Add charge</button>}
                {f.balance > 0 && can("hotel_folios.payment") && <button className="btn-secondary !py-1" onClick={() => setPayOpen(true)}>Take payment</button>}
                {f.paid - f.refunded > 0 && can("hotel_folios.refund") && <button className="btn-ghost !py-1 text-red-600" onClick={() => setRefundOpen(true)}>Refund…</button>}
              </>
            )
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px]">
              <thead><tr><th className="th">When</th><th className="th">Item</th><th className="th">By</th><th className="th text-right">Amount</th><th className="th" /></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {f.lines.map((l) => (
                  <tr key={l.id}>
                    <td className="td whitespace-nowrap text-xs text-slate-400">{fmtDateTime(l.created_at)}</td>
                    <td className="td"><Badge>{l.source.code.toUpperCase()}</Badge> {l.description}</td>
                    <td className="td text-xs text-slate-400">{l.staff.name}</td>
                    <td className="td text-right font-semibold">{lkr(l.amount)}</td>
                    <td className="td text-right">
                      {f.status.code === "open" && can("hotel_folios.void_line") && (
                        <button className="btn-ghost !p-1.5 text-red-400 hover:!bg-red-50 hover:text-red-600" title="Remove charge" onClick={() => setVoidingLine(l)}>
                          <Trash2 size={13} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {f.lines.length === 0 && <Empty text="Charges post automatically at check-in / from the POS" />}
          </div>
          <div className="mt-3 grid gap-1 border-t border-slate-100 pt-3 text-sm sm:ml-auto sm:w-72">
            <div className="flex justify-between font-extrabold"><span>Total</span><span>{lkr(f.total)} {usdRate > 0 && <span className="text-xs font-normal text-slate-400">{usd(f.total, usdRate)}</span>}</span></div>
            <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(f.paid)}</span></div>
            {f.refunded > 0 && <div className="flex justify-between text-red-600"><span>Refunded</span><span>{lkr(f.refunded)}</span></div>}
            <div className="flex justify-between font-bold"><span>Balance</span><span>{lkr(f.balance)}</span></div>
          </div>
          {f.payments.length > 0 && (
            <div className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
              {f.payments.map((p) => (
                <div key={p.id}>
                  {fmtDateTime(p.created_at)} — {p.kind.code.toUpperCase()} {p.method.code.toUpperCase()} {lkr(p.amount)} {p.reference && `(${p.reference})`} by {p.staff.name} {p.reason && `· ${p.reason}`}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {r.room_item_checks.length > 0 && (
        <Card title="Room item checks">
          {r.room_item_checks.map((c) => (
            <div key={c.id} className="mb-2 text-sm">
              <Badge color="slate">ITEM CHECK</Badge>{" "}
              <span className="text-xs text-slate-400">{fmtDateTime(c.created_at)}</span> —{" "}
              {c.items.filter((i) => !i.ok).length === 0 ? (
                <span className="text-emerald-700">all items OK ✓</span>
              ) : (
                <span className="text-red-600">issues: {c.items.filter((i) => !i.ok).map((i) => `${i.item}${i.note ? ` (${i.note})` : ""}`).join("; ")}</span>
              )}
            </div>
          ))}
        </Card>
      )}

      {checkinOpen && <CheckInModal r={r} onClose={() => setCheckinOpen(false)} onDone={() => { setCheckinOpen(false); reload(); }} />}
      {checkoutOpen && <CheckOutModal r={r} usdRate={usdRate} onClose={() => setCheckoutOpen(false)} onDone={() => { setCheckoutOpen(false); reload(); }} />}
      {cancelOpen && (
        <ReasonModal
          title="Cancel booking — policy refund applied automatically"
          onSubmit={async (reason) => {
            try {
              const res = await post<{ ok: boolean; refund_pct: number; refunded: number }>(`/reservations/${r.id}/cancel`, { reason });
              toast.info(`Booking ${r.code} cancelled`, res.refunded > 0 ? `${res.refund_pct}% refund — LKR ${(res.refunded / 100).toFixed(2)}` : "No refund per policy");
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
      {addLineOpen && f && <AddLineModal folioId={f.id} onClose={() => setAddLineOpen(false)} onDone={() => { setAddLineOpen(false); reload(); }} />}
      {payOpen && f && (
        <SplitPay
          due={Math.max(f.balance, 0)}
          onDone={async (payments) => {
            try {
              for (const p of payments) {
                // POS.tsx's shared SplitPay hardcodes uppercase method codes (CASH,
                // CARD, …) — the backend's payment_method lookups are lowercase.
                await post(`/folios/${f.id}/payments`, {
                  method: p.method.toLowerCase(),
                  amount: p.amount,
                  reference: p.reference,
                  idempotency_key: crypto.randomUUID(),
                });
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
      {refundOpen && f && (
        <ReasonModal
          title="Refund from folio — reason required"
          withAmount={f.paid - f.refunded}
          onSubmit={async (reason, amount, method) => {
            try {
              // Same lowercase-method caveat as the payment flow above — ReasonModal
              // (POS.tsx) also hardcodes uppercase method codes.
              await post(`/folios/${f.id}/refund`, { reason, amount, method: method?.toLowerCase() });
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
              await post(`/folios/lines/${voidingLine.id}/void`, { reason });
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
      {editStayOpen && <EditStayModal r={r} onClose={() => setEditStayOpen(false)} onDone={() => { setEditStayOpen(false); reload(); }} />}
      {editGuestOpen && <EditGuestModal guest={r.guest} onClose={() => setEditGuestOpen(false)} onDone={() => { setEditGuestOpen(false); reload(); }} />}
    </div>
  );
}

// ── Booking lifecycle stepper ────────────────────────────────────────────────
const STEPS = [
  { key: "PENDING", label: "Booked" },
  { key: "CONFIRMED", label: "Confirmed" },
  { key: "CHECKED_IN", label: "Checked in" },
  { key: "CHECKED_OUT", label: "Checked out" },
] as const;

function StatusStepper({ status }: { status: string }) {
  const idx = STEPS.findIndex((s) => s.key === status);
  const current = idx === -1 ? (status === "NO_SHOW" ? STEPS.length - 1 : 1) : idx;
  return (
    <div className="flex items-center">
      {STEPS.map((s, i) => (
        <div key={s.key} className="flex flex-1 items-center last:flex-none">
          <div className="flex items-center gap-1.5">
            {i < current ? (
              <Check size={15} className="rounded-full bg-emerald-500 p-0.5 text-white" />
            ) : i === current ? (
              <CircleDot size={15} className="text-brand-600" />
            ) : (
              <Circle size={15} className="text-slate-300" />
            )}
            <span className={clsx("text-xs font-semibold", i <= current ? "text-slate-700" : "text-slate-400")}>{s.label}</span>
          </div>
          {i < STEPS.length - 1 && <div className={clsx("mx-2 h-0.5 flex-1 rounded", i < current ? "bg-emerald-500" : "bg-slate-200")} />}
        </div>
      ))}
    </div>
  );
}

// ── Edit stay details (notes / occupancy / package) ──────────────────────────
function EditStayModal({ r, onClose, onDone }: { r: Detail; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({
    adults: String(r.adults), children: String(r.children),
    packageId: r.package?.code === "RO" ? "" : r.package_id ? String(r.package_id) : "",
    notes: r.notes ?? "",
  });
  const { data: pkgResp } = useFetch<{ packages: { id: number; code: string; name: string }[] }>("/rooms/packages");
  const packages = pkgResp?.packages;
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      await put(`/reservations/${r.id}`, {
        adults: parseInt(f.adults) || 1,
        children: parseInt(f.children) || 0,
        package_id: f.packageId ? Number(f.packageId) : null,
        notes: f.notes,
      });
      onDone();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Edit stay — ${r.code}`}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Adults"><input className="input" inputMode="numeric" value={f.adults} onChange={(e) => setF({ ...f, adults: e.target.value })} /></Field>
        <Field label="Children"><input className="input" inputMode="numeric" value={f.children} onChange={(e) => setF({ ...f, children: e.target.value })} /></Field>
        <Field label="Package" hint="Changes apply to future charges only">
          <select className="input" value={f.packageId} onChange={(e) => setF({ ...f, packageId: e.target.value })}>
            <option value="">Room only</option>
            {(packages ?? []).filter((p) => p.code !== "RO").map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </Field>
      </div>
      <div className="mt-3">
        <Field label="Notes"><textarea className="input" rows={3} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={busy} onClick={save}>{busy ? "Saving…" : "Save changes"}</button>
    </Modal>
  );
}

// ── Edit guest contact details ────────────────────────────────────────────────
function EditGuestModal({ guest, onClose, onDone }: { guest: Detail["guest"]; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({
    phone: guest.phone ?? "", email: guest.email ?? "", idNumber: guest.id_number ?? "", preferences: guest.preferences ?? "",
  });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      await put(`/guests/${guest.id}`, { phone: f.phone, email: f.email, id_number: f.idNumber, preferences: f.preferences });
      onDone();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Edit guest — ${guest.name}`}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Phone"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        <Field label="Email"><input className="input" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="ID / passport"><input className="input" value={f.idNumber} onChange={(e) => setF({ ...f, idNumber: e.target.value })} /></Field>
        <Field label="Preferences"><input className="input" value={f.preferences} onChange={(e) => setF({ ...f, preferences: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={busy} onClick={save}>{busy ? "Saving…" : "Save changes"}</button>
    </Modal>
  );
}

// ── Check-in: ID required + early surcharge + room item checklist ──
function CheckInModal({ r, onClose, onDone }: { r: Detail; onClose: () => void; onDone: () => void }) {
  const { str, num } = useSettings();
  const toast = useToast();
  const [idNumber, setIdNumber] = useState(r.guest.id_number ?? (r.pre_check_in?.id_number as string) ?? "");
  const now = new Date().toTimeString().slice(0, 5);
  const early = now < str("frontdesk.check_in_time", "14:00");
  const surcharge = num("billing.early_checkin_surcharge", 0);
  const [applyEarly, setApplyEarly] = useState(early && surcharge > 0);
  const [checks, setChecks] = useState<Record<number, { item: string; ok: boolean; note?: string }[]>>(
    Object.fromEntries(r.rooms.map((rr) => [rr.room.id, (rr.room.room_type.item_checklist ?? []).map((item) => ({ item, ok: true }))]))
  );
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const doCheckIn = async () => {
    setBusy(true);
    setError("");
    try {
      await post(`/reservations/${r.id}/check-in`, {
        id_number: idNumber.trim() || undefined,
        apply_early_surcharge: applyEarly,
        item_checks: Object.entries(checks).map(([roomId, items]) => ({ room_id: Number(roomId), items })),
      });
      toast.success(`${r.guest.name} checked in`, `${r.code} — room charges posted to the folio`);
      onDone();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Check in ${r.guest.name}`} wide>
      <div className="space-y-3">
        <Field label="Guest ID / passport number (required — government reporting)">
          <input className="input" value={idNumber} onChange={(e) => setIdNumber(e.target.value)} placeholder="NIC or passport no." />
        </Field>
        {r.pre_check_in && (
          <div className="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            Pre-check-in data received: {Object.entries(r.pre_check_in).filter(([k]) => !["submitted_at", "code"].includes(k)).map(([k, v]) => `${k}: ${v}`).join(" · ")}
          </div>
        )}
        {early && (
          <label className="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" checked={applyEarly} onChange={(e) => setApplyEarly(e.target.checked)} disabled={surcharge === 0} />
            Early check-in (before {str("frontdesk.check_in_time", "14:00")}) — surcharge {lkr(surcharge)} {surcharge === 0 && "(not configured)"}
          </label>
        )}
        {r.rooms.map((rr) => (
          <div key={rr.room.id}>
            <div className="label">Room {rr.room.number} — item checklist (confirm present & undamaged)</div>
            <div className="grid gap-1 sm:grid-cols-2">
              {checks[rr.room.id].map((c, i) => (
                <label key={i} className="flex items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-slate-50">
                  <input
                    type="checkbox"
                    checked={c.ok}
                    onChange={(e) =>
                      setChecks({ ...checks, [rr.room.id]: checks[rr.room.id].map((x, j) => (j === i ? { ...x, ok: e.target.checked } : x)) })
                    }
                  />
                  <span className={c.ok ? "" : "font-semibold text-red-600"}>{c.item}</span>
                </label>
              ))}
            </div>
          </div>
        ))}
        <ErrorText error={error} />
        <button className="btn-primary w-full !py-3" disabled={busy} onClick={doCheckIn}>
          {busy ? "Checking in…" : "Confirm check-in (posts room charges to folio)"}
        </button>
      </div>
    </Modal>
  );
}

// ── Checkout: quote → mixed payments → consolidated invoice ──
function CheckOutModal({ r, usdRate, onClose, onDone }: { r: Detail; usdRate: number; onClose: () => void; onDone: () => void }) {
  const { str, num } = useSettings();
  const toast = useToast();
  const late = new Date().toTimeString().slice(0, 5) > str("frontdesk.check_out_time", "12:00");
  const lateAmt = num("billing.late_checkout_surcharge", 0);
  const [applyLate, setApplyLate] = useState(late && lateAmt > 0);
  const { data: quote } = useFetch<CheckoutQuote>(`/reservations/${r.id}/checkout-quote?late=${applyLate ? "1" : "0"}`, [applyLate]);
  const [payments, setPayments] = useState<{ method: string; amount: string; reference: string }[]>([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [invoiceNo, setInvoiceNo] = useState("");

  if (invoiceNo) {
    return (
      <Modal open onClose={onDone} title="Checked out ✓">
        <div className="space-y-3 text-center">
          <div className="text-3xl">🧾</div>
          <p className="text-sm">Consolidated invoice <b>{invoiceNo}</b> generated. Rooms sent to housekeeping.</p>
          <div className="flex justify-center gap-2">
            <button className="btn-primary" onClick={() => quote && openPdf(`/folios/${quote.folio.id}/invoice?format=a4`)}><Printer size={15} /> Print A4</button>
            <button className="btn-secondary" onClick={() => quote && openPdf(`/folios/${quote.folio.id}/invoice?format=thermal`)}>Thermal</button>
          </div>
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
      const res = await post<{ invoice_no: string }>(`/reservations/${r.id}/checkout`, {
        apply_late_surcharge: applyLate,
        payments: payments.filter((p) => toCents(p.amount) > 0).map((p) => ({ method: p.method, amount: toCents(p.amount), reference: p.reference || undefined })),
      });
      setInvoiceNo(res.invoice_no);
      toast.success(`${r.guest.name} checked out`, `Invoice ${res.invoice_no} generated`);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Check out ${r.guest.name} — consolidated bill`} wide>
      <div className="max-h-48 overflow-y-auto rounded-lg border border-slate-100">
        <table className="w-full text-sm">
          <tbody className="divide-y divide-slate-50">
            {quote.lines.map((l) => (
              <tr key={l.id}><td className="td">{l.description}</td><td className="td text-right">{lkr(l.amount)}</td></tr>
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
          Late check-out (after {str("frontdesk.check_out_time", "12:00")}) — surcharge {lkr(lateAmt)} {lateAmt === 0 && "(not configured)"}
        </label>
      )}
      <div className="mt-3 space-y-1 rounded-xl bg-slate-50 p-3 text-sm">
        <div className="flex justify-between font-extrabold"><span>Grand total</span><span>{lkr(quote.grand_total)} {usdRate > 0 && <span className="text-xs font-normal text-slate-400">{usd(quote.grand_total, usdRate)}</span>}</span></div>
        <div className="flex justify-between text-emerald-700"><span>Already paid (deposits etc.)</span><span>{lkr(quote.folio.paid - quote.folio.refunded)}</span></div>
        <div className="flex justify-between text-base font-extrabold"><span>{quote.balance_due >= 0 ? "Balance due now" : "Refund due to guest"}</span><span>{lkr(Math.abs(quote.balance_due))}</span></div>
      </div>

      {quote.balance_due > 0 && (
        <div className="mt-3 space-y-2">
          <div className="label">Payments (mixed methods supported)</div>
          {payments.map((p, i) => (
            <div key={i} className="flex gap-2">
              <select className="input !w-40" value={p.method} onChange={(e) => setPayments(payments.map((x, j) => (j === i ? { ...x, method: e.target.value } : x)))}>
                {["cash", "card", "lankaqr", "bank_transfer", ...(r.corporate_account ? ["corporate_credit"] : []), ...(r.guest.loyalty_points > 0 ? ["loyalty_points"] : [])].map((m) => (
                  <option key={m} value={m}>{m.toUpperCase()}</option>
                ))}
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

function AddLineModal({ folioId, onClose, onDone }: { folioId: number; onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ source: "minibar", description: "", qty: "1", unitPrice: "" });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="Add charge to folio">
      <div className="space-y-3">
        <Field label="Type">
          <select className="input" value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })}>
            {[
              { code: "minibar", label: "MINIBAR" },
              { code: "damage", label: "DAMAGE" },
              { code: "adjustment", label: "ADJUSTMENT" },
              { code: "surcharge", label: "SURCHARGE" },
            ].map((s) => <option key={s.code} value={s.code}>{s.label}</option>)}
          </select>
        </Field>
        <Field label="Description"><input className="input" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="e.g. Minibar — 2× Mineral Water / Damaged towel replacement" /></Field>
        <div className="grid grid-cols-2 gap-2">
          <Field label="Qty"><input className="input" value={f.qty} onChange={(e) => setF({ ...f, qty: e.target.value })} /></Field>
          <Field label="Unit price (LKR)"><input className="input" value={f.unitPrice} onChange={(e) => setF({ ...f, unitPrice: e.target.value })} /></Field>
        </div>
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={!f.description.trim() || toCents(f.unitPrice) <= 0}
          onClick={() =>
            post(`/folios/${folioId}/lines`, { source: f.source, description: f.description.trim(), qty: parseFloat(f.qty) || 1, unit_price: toCents(f.unitPrice) })
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
