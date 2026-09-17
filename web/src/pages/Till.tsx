import { ReactNode, useEffect, useState } from "react";
import { post } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, centsToRupees, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination, Stat } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

type TillOption = {
  id: number; name: string;
  last_closing_cash: number | null; last_closed_at: string | null; last_closed_by: string | null;
};
type CurrentSession = {
  id: number; till: { id: number; name: string }; opened_at: string;
  opening_cash: number; carried_opening_cash: number | null; opening_variance: number | null;
  opening_reason: string | null; expected_balance: number;
} | null;
type Movement = {
  id: number; type: { code: string; name: string; color: string }; amount: number;
  reason: string | null; reference: string | null; performed_by: { id: number; name: string } | null;
  approved_by: { id: number; name: string } | null; created_at: string;
};
type SessionHistory = {
  id: number; till: { id: number; name: string }; staff: { id: number; name: string }; status: { code: string };
  opened_at: string; closed_at: string | null; opening_cash: number; closing_cash: number | null;
  carried_opening_cash: number | null; opening_variance: number | null; opening_reason: string | null;
  expected_cash: number | null; variance: number | null;
};
/** One itemised line of the close-out statement — an activity that produced cash, or a movement type that took it out. */
type StatementLine = { code: string; label: string; count: number; amount: number };
type Tally = { count: number; amount: number };
type Summary = {
  opening_balance: number;
  collections: StatementLine[];
  collections_total: number;
  refunds: Tally;
  manual_cash_in: Tally;
  manual_cash_out: StatementLine[];
  manual_cash_out_total: number;
  adjustments: Tally;
  net_change: number;
  expected_balance: number;
  movement_count: number;
};

const plural = (n: number, noun: string) => `${n} ${noun}${n === 1 ? "" : "s"}`;
const signed = (cents: number) => `${cents > 0 ? "+" : ""}${lkr(cents)}`;

export default function Till() {
  const { can } = useAuth();
  const { data: currentData, reload: reloadCurrent } = useFetch<{ session: CurrentSession }>("/till/current");
  const current = currentData?.session ?? null;
  const { data: tillsData } = useFetch<{ tills: TillOption[] }>("/till/tills");
  const tillOptions = tillsData?.tills ?? [];

  const { data: summaryData, reload: reloadSummary } = useFetch<{ summary: Summary }>(
    current ? `/till/${current.id}/summary` : null,
    [current?.id],
  );
  const summary = summaryData?.summary ?? null;

  const [mPage, setMPage] = useState(1);
  const [mPageSize, setMPageSize] = useState(25);
  const { data: movementsData, reload: reloadMovements } = usePagedFetch<Movement>(
    current ? `/till/${current.id}/movements?page=${mPage}&page_size=${mPageSize}` : null,
    "movements",
    [mPage, mPageSize, current?.id],
  );
  const movements = movementsData?.rows;

  const [sPage, setSPage] = useState(1);
  const [sPageSize, setSPageSize] = useState(10);
  const { data: sessionsData, reload: reloadSessions } = usePagedFetch<SessionHistory>(
    `/till/sessions?page=${sPage}&page_size=${sPageSize}`,
    "sessions",
    [sPage, sPageSize],
  );
  const sessions = sessionsData?.rows;

  const [openOpen, setOpenOpen] = useState(false);
  const [closeOpen, setCloseOpen] = useState(false);
  const [movementModal, setMovementModal] = useState<"cash_in" | "cash_out" | null>(null);

  const refreshAll = () => {
    reloadCurrent();
    reloadSummary();
    reloadMovements();
    reloadSessions();
  };

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Till</h1>

      <Card title="My till">
        {current ? (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-4 text-sm">
              <div>Till: <b>{current.till.name}</b></div>
              <div>Opened <b>{fmtDateTime(current.opened_at)}</b></div>
              <div>Opening balance: <b>{lkr(current.opening_cash)}</b></div>
            </div>
            {!!current.opening_variance && (
              <div className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Opened <b>{signed(current.opening_variance)}</b> against the {lkr(current.carried_opening_cash)} carried over
                from the last close{current.opening_reason ? <> — “{current.opening_reason}”</> : null}
              </div>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <Stat label="Expected cash in till" value={lkr(current.expected_balance)} />
              {can("till.cash_in") && (
                <button className="btn-secondary" onClick={() => setMovementModal("cash_in")}>Cash in</button>
              )}
              {can("till.cash_out") && (
                <button className="btn-secondary" onClick={() => setMovementModal("cash_out")}>Cash out / withdrawal</button>
              )}
            </div>
            {can("till.close") && (
              <button className="btn-primary" onClick={() => setCloseOpen(true)}>Close till & reconcile</button>
            )}
          </div>
        ) : tillOptions.length === 0 ? (
          <div className="flex items-center gap-3 text-sm">
            <span>No tills exist yet — ask a platform admin to set one up before you can take cash payments.</span>
          </div>
        ) : (
          <div className="flex items-center gap-3 text-sm">
            <span>No till open — open one before taking cash payments.</span>
            {can("till.open") && <button className="btn-primary" onClick={() => setOpenOpen(true)}>Open till</button>}
          </div>
        )}
      </Card>

      {current && summary && (
        <Card title="Cash statement — this session">
          <CashStatement summary={summary} />
        </Card>
      )}

      {current && (
        <Card title="Cash movement ledger — this session">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px]">
              <thead className="border-b border-slate-100">
                <tr>
                  <th className="th">When</th><th className="th">Type</th><th className="th text-right">Amount</th>
                  <th className="th">Reason</th><th className="th">Reference</th><th className="th">Performed by</th><th className="th">Approved by</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {(movements ?? []).map((m) => (
                  <tr key={m.id}>
                    <td className="td text-xs">{fmtDateTime(m.created_at)}</td>
                    <td className="td"><Badge color={m.type.color}>{m.type.name}</Badge></td>
                    <td className={`td text-right font-semibold ${m.amount < 0 ? "text-red-600" : "text-emerald-700"}`}>
                      {signed(m.amount)}
                    </td>
                    <td className="td">{m.reason ?? "—"}</td>
                    <td className="td text-xs">{m.reference ?? "—"}</td>
                    <td className="td">{m.performed_by?.name ?? "—"}</td>
                    <td className="td">{m.approved_by?.name ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {(movements ?? []).length === 0 && <Empty text="No cash movements yet" />}
            {movementsData && (
              <Pagination
                page={movementsData.page} pageSize={movementsData.pageSize} total={movementsData.total}
                onPage={setMPage} onPageSize={(n) => { setMPageSize(n); setMPage(1); }}
              />
            )}
          </div>
        </Card>
      )}

      <Card title="Session history — reconciliation & variance">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px]">
            <thead className="border-b border-slate-100">
              <tr>
                <th className="th">Till</th><th className="th">Staff</th><th className="th">Opened</th><th className="th">Closed</th>
                <th className="th text-right">Opening</th><th className="th text-right">Expected</th>
                <th className="th text-right">Counted</th><th className="th text-right">Variance</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {(sessions ?? []).map((s) => (
                <tr key={s.id}>
                  <td className="td font-semibold">{s.till.name}</td>
                  <td className="td">{s.staff.name}</td>
                  <td className="td text-xs">{fmtDateTime(s.opened_at)}</td>
                  <td className="td text-xs">{s.closed_at ? fmtDateTime(s.closed_at) : <Badge color="green">OPEN</Badge>}</td>
                  <td className="td text-right">
                    {lkr(s.opening_cash)}
                    {!!s.opening_variance && (
                      <div className="text-xs font-medium text-amber-600" title={s.opening_reason ?? undefined}>
                        {signed(s.opening_variance)} vs. carried {lkr(s.carried_opening_cash)}
                      </div>
                    )}
                  </td>
                  <td className="td text-right">{s.expected_cash !== null ? lkr(s.expected_cash) : "—"}</td>
                  <td className="td text-right">{s.closing_cash !== null ? lkr(s.closing_cash) : "—"}</td>
                  <td className="td text-right">
                    {s.variance !== null ? (
                      <b className={s.variance === 0 ? "text-emerald-600" : "text-red-600"}>{signed(s.variance)}</b>
                    ) : "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {(sessions ?? []).length === 0 && <Empty text="No till sessions yet" />}
          {sessionsData && (
            <Pagination
              page={sessionsData.page} pageSize={sessionsData.pageSize} total={sessionsData.total}
              onPage={setSPage} onPageSize={(n) => { setSPageSize(n); setSPage(1); }}
            />
          )}
        </div>
      </Card>

      {openOpen && <OpenTill tills={tillOptions} onClose={() => { setOpenOpen(false); refreshAll(); }} />}
      {closeOpen && current && (
        <CloseTill sessionId={current.id} onClose={() => { setCloseOpen(false); refreshAll(); }} />
      )}
      {movementModal && current && (
        <CashMovement
          sessionId={current.id} kind={movementModal}
          onClose={() => { setMovementModal(null); refreshAll(); }}
        />
      )}
    </div>
  );
}

/* ── Close-out statement ────────────────────────────────────────────────── */

function StatementRow({
  label, sub, amount, indent, emphasis,
}: {
  label: ReactNode; sub?: ReactNode; amount: number; indent?: boolean;
  emphasis?: "subtotal" | "total";
}) {
  return (
    <tr className={emphasis === "total" ? "bg-slate-50" : emphasis === "subtotal" ? "border-t border-slate-200" : ""}>
      <td className={`px-3 py-2 ${indent ? "pl-7" : ""} ${emphasis ? "font-bold" : ""}`}>
        {label}
        {sub && <span className="ml-2 text-xs font-normal text-slate-400">{sub}</span>}
      </td>
      <td
        className={`px-3 py-2 text-right tabular-nums ${emphasis ? "font-bold" : "font-semibold"} ${
          amount < 0 ? "text-red-600" : amount > 0 ? "text-emerald-700" : "text-slate-500"
        }`}
      >
        {emphasis === "total" ? lkr(amount) : signed(amount)}
      </td>
    </tr>
  );
}

function StatementSection({ label }: { label: string }) {
  return (
    <tr>
      <td colSpan={2} className="border-t border-slate-200 bg-slate-50/60 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-500">
        {label}
      </td>
    </tr>
  );
}

/**
 * The full itemised close-out: every activity that put cash in the drawer or
 * took it out, footing to the balance the close reconciles against. Rendered
 * both live on the Till page and inside the close dialog, so what the operator
 * confirms at close is the same statement they have been watching all shift.
 */
function CashStatement({ summary }: { summary: Summary }) {
  const { collections, manual_cash_out: manualOut, refunds, manual_cash_in: manualIn, adjustments } = summary;
  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 text-sm">
      <table className="w-full">
        <tbody>
          <StatementRow label="Opening balance" amount={summary.opening_balance} emphasis="subtotal" />

          <StatementSection label="Cash collected" />
          {collections.length === 0 ? (
            <tr>
              <td colSpan={2} className="px-3 py-3 text-center text-xs text-slate-400">
                No cash taken through the system this session
              </td>
            </tr>
          ) : (
            collections.map((line) => (
              <StatementRow key={line.code} indent label={line.label} sub={plural(line.count, "payment")} amount={line.amount} />
            ))
          )}
          <StatementRow label="Total cash collected" amount={summary.collections_total} emphasis="subtotal" />

          <StatementSection label="Cash paid out & manual movements" />
          <StatementRow
            indent label="Refunds paid out"
            sub={refunds.count > 0 ? plural(refunds.count, "refund") : undefined}
            amount={refunds.amount}
          />
          <StatementRow
            indent label="Manual cash in"
            sub={manualIn.count > 0 ? plural(manualIn.count, "top-up") : undefined}
            amount={manualIn.amount}
          />
          {manualOut.length > 0
            ? manualOut.map((line) => (
                <StatementRow key={line.code} indent label={line.label} sub={plural(line.count, "entry")} amount={line.amount} />
              ))
            : <StatementRow indent label="Manual cash out" amount={0} />}
          {adjustments.count > 0 && (
            <StatementRow indent label="Closing adjustment" sub={plural(adjustments.count, "entry")} amount={adjustments.amount} />
          )}

          <StatementRow label="Net change this session" amount={summary.net_change} emphasis="subtotal" />
          <StatementRow label="Expected closing balance" amount={summary.expected_balance} emphasis="total" />
        </tbody>
      </table>
    </div>
  );
}

/* ── Open ───────────────────────────────────────────────────────────────── */

/**
 * The last closing count is a suggestion, not a rule — the drawer is counted
 * fresh at every open. So the carried-over amount is presented for one-tap
 * acceptance, and counting a different amount is a deliberate second step that
 * asks why.
 */
function OpenTill({ tills, onClose }: { tills: TillOption[]; onClose: () => void }) {
  const toast = useToast();
  // Re-sync when `tills` resolves after this modal mounts (was captured once
  // from the initial empty/stale prop, so the select showed the first option
  // while tillId silently stayed "" and /till/open got sent an empty id).
  const [tillId, setTillId] = useState<number | "">(tills[0]?.id ?? "");
  useEffect(() => {
    if (tillId === "" && tills[0]) setTillId(tills[0].id);
  }, [tills]); // eslint-disable-line react-hooks/exhaustive-deps

  const selected = tills.find((t) => t.id === tillId) ?? null;
  const carried = selected?.last_closing_cash ?? null;

  // A till that has never been closed has nothing to carry over, so there is
  // nothing to accept — it goes straight to counting. Re-runs when the
  // selected till changes, and again when `tills` resolves after mount.
  const [counting, setCounting] = useState(carried === null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  useEffect(() => {
    setCounting(carried === null);
    setAmount(carried !== null ? centsToRupees(carried) : "");
    setReason("");
  }, [tillId, carried]);

  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const opening = counting ? toCents(amount) : (carried ?? 0);
  const variance = carried === null ? 0 : opening - carried;
  const needsReason = variance !== 0;

  const submit = () => {
    setBusy(true);
    post("/till/open", { till_id: tillId, opening_balance: opening, reason: reason || undefined })
      .then(() => {
        toast.success("Till opened", `Opening balance ${lkr(opening)}`);
        onClose();
      })
      .catch((e) => setError(e.message))
      .finally(() => setBusy(false));
  };

  return (
    <Modal open onClose={onClose} title="Open till">
      <Field label="Till">
        <select className="input" value={tillId} onChange={(e) => setTillId(Number(e.target.value))}>
          {tills.length === 0 && <option value="">No tills available</option>}
          {tills.map((t) => (
            <option key={t.id} value={t.id}>{t.name}</option>
          ))}
        </select>
      </Field>

      {carried !== null ? (
        <div className="mb-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Carried over from the last close</div>
          <div className="mt-0.5 text-2xl font-extrabold">{lkr(carried)}</div>
          <div className="mt-0.5 text-xs text-slate-500">
            Counted by {selected?.last_closed_by ?? "—"} on {fmtDateTime(selected?.last_closed_at)}
          </div>
          <p className="mt-2 text-xs text-slate-500">
            This is only a suggestion — count the drawer and open with whatever is actually in it.
          </p>
        </div>
      ) : (
        <div className="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
          This till has never been closed, so there is nothing to carry over — count the drawer and enter the opening cash.
        </div>
      )}

      {counting ? (
        <>
          <Field label="Opening cash counted in the drawer (LKR)">
            <input className="input" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
          </Field>
          {carried !== null && (
            <div className={`mt-1 text-sm font-bold ${variance === 0 ? "text-emerald-600" : "text-amber-600"}`}>
              {variance === 0 ? "Matches the carried-over balance" : `${signed(variance)} against the carried-over balance`}
            </div>
          )}
          {needsReason && (
            <Field label="Why is the opening amount different? (required)">
              <input
                className="input" value={reason} onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Overnight safe float added back"
              />
            </Field>
          )}
        </>
      ) : null}

      <ErrorText error={error} />

      {counting ? (
        <div className="mt-3 space-y-2">
          <button className="btn-primary w-full" disabled={busy || tillId === "" || (needsReason && !reason.trim())} onClick={submit}>
            Open with {lkr(opening)}
          </button>
          {carried !== null && (
            <button className="btn-ghost w-full" onClick={() => { setCounting(false); setReason(""); }}>
              Back to the carried-over amount
            </button>
          )}
        </div>
      ) : (
        <div className="mt-3 space-y-2">
          <button className="btn-primary w-full" disabled={busy || tillId === ""} onClick={submit}>
            Use {lkr(carried ?? 0)} & open till
          </button>
          <button className="btn-secondary w-full" onClick={() => setCounting(true)}>
            Count a different amount
          </button>
        </div>
      )}
    </Modal>
  );
}

/* ── Close ──────────────────────────────────────────────────────────────── */

/**
 * Close against the itemised statement. Counting the drawer is optional: the
 * counted field is pre-filled with what the ledger expects, and leaving it
 * alone closes at zero variance. Changing it is a claimed variance and asks
 * why — the same shape as the open.
 */
function CloseTill({ sessionId, onClose }: { sessionId: number; onClose: () => void }) {
  const toast = useToast();
  const { data, loading, error: loadError } = useFetch<{ summary: Summary }>(`/till/${sessionId}/summary`);
  const summary = data?.summary ?? null;
  const expected = summary?.expected_balance ?? 0;

  const [amount, setAmount] = useState("");
  const [touched, setTouched] = useState(false);
  const [reason, setReason] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  // Pre-fill once the statement lands, but never overwrite a recount already typed.
  useEffect(() => {
    if (summary && !touched) setAmount(centsToRupees(summary.expected_balance));
  }, [summary?.expected_balance]); // eslint-disable-line react-hooks/exhaustive-deps

  // A cleared field means "no recount" — accept the ledger — rather than zero cash.
  const counted = amount.trim() === "" ? null : toCents(amount);
  const variance = counted === null ? 0 : counted - expected;
  const needsReason = variance !== 0;

  const submit = () => {
    setBusy(true);
    post(`/till/${sessionId}/close`, {
      closing_cash: counted ?? undefined,
      reason: reason || undefined,
      notes: notes || undefined,
    })
      .then(() => {
        if (variance === 0) toast.success("Till closed — balanced", `Closing balance ${lkr(expected)}`);
        else toast.warning("Till closed — variance found", signed(variance));
        onClose();
      })
      .catch((e) => setError(e.message))
      .finally(() => setBusy(false));
  };

  return (
    <Modal open onClose={onClose} title="Close till — reconciliation" wide>
      {loading && !summary ? (
        <Empty text="Building the cash statement…" />
      ) : summary ? (
        <div className="space-y-3">
          <CashStatement summary={summary} />

          <Field
            label="Counted cash in the drawer (LKR)"
            hint="Pre-filled with the expected balance — leave it as it is to close on the system's figure, or replace it with your physical count."
          >
            <input
              className="input" value={amount} autoFocus
              onChange={(e) => { setAmount(e.target.value); setTouched(true); }}
            />
          </Field>

          <div className={`text-sm font-bold ${variance === 0 ? "text-emerald-600" : "text-red-600"}`}>
            {variance === 0 ? "Balanced — no variance" : `Variance: ${signed(variance)}`}
          </div>

          {needsReason && (
            <Field label="Why does the count differ? (required)">
              <input
                className="input" value={reason} onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. Cash short — under investigation"
              />
            </Field>
          )}

          <Field label="Notes (optional)">
            <input className="input" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>

          <ErrorText error={error} />

          <button
            className="btn-primary w-full"
            disabled={busy || (needsReason && !reason.trim())}
            onClick={submit}
          >
            {variance === 0 ? `Confirm ${lkr(expected)} & close till` : `Close with a ${signed(variance)} variance`}
          </button>
        </div>
      ) : (
        <ErrorText error={loadError || "Could not load the cash statement."} />
      )}
    </Modal>
  );
}

/* ── Manual movements ───────────────────────────────────────────────────── */

function CashMovement({ sessionId, kind, onClose }: { sessionId: number; kind: "cash_in" | "cash_out"; onClose: () => void }) {
  const toast = useToast();
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [reference, setReference] = useState("");
  const [error, setError] = useState("");
  const title = kind === "cash_in" ? "Cash in — add float to the till" : "Cash out — withdraw cash from the till";
  return (
    <Modal open onClose={onClose} title={title}>
      <Field label="Amount (LKR)">
        <input className="input" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
      </Field>
      <Field label={kind === "cash_out" ? "Reason (required)" : "Reason"}>
        <input className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={kind === "cash_out" ? "e.g. Bank deposit" : "e.g. Extra float"} />
      </Field>
      <Field label="Reference (optional)">
        <input className="input" value={reference} onChange={(e) => setReference(e.target.value)} />
      </Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-3 w-full"
        onClick={() =>
          post(`/till/${sessionId}/movements`, {
            type: kind, amount: toCents(amount), reason: reason || undefined, reference: reference || undefined,
          })
            .then(() => {
              toast.success(kind === "cash_in" ? "Cash added" : "Cash withdrawn", lkr(toCents(amount)));
              onClose();
            })
            .catch((e) => setError(e.message))
        }
      >
        {kind === "cash_in" ? "Add cash" : "Withdraw cash"}
      </button>
    </Modal>
  );
}
