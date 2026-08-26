import { useEffect, useState } from "react";
import { Plus } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination, Stat } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

type TillOption = { id: number; name: string };
type TillManageOption = { id: number; name: string; is_active: boolean };
type CurrentSession = {
  id: number; till: { id: number; name: string }; opened_at: string;
  opening_cash: number; expected_balance: number;
} | null;
type Movement = {
  id: number; type: { code: string; name: string; color: string }; amount: number;
  reason: string | null; reference: string | null; performed_by: { id: number; name: string } | null;
  approved_by: { id: number; name: string } | null; created_at: string;
};
type SessionHistory = {
  id: number; till: { id: number; name: string }; staff: { id: number; name: string }; status: { code: string };
  opened_at: string; closed_at: string | null; opening_cash: number; closing_cash: number | null;
  expected_cash: number | null; variance: number | null;
};

export default function Till() {
  const { can } = useAuth();
  const canManage = can("till.manage");
  const { data: currentData, reload: reloadCurrent } = useFetch<{ session: CurrentSession }>("/till/current");
  const current = currentData?.session ?? null;
  const { data: tillsData, reload: reloadTillOptions } = useFetch<{ tills: TillOption[] }>("/till/tills");
  const tillOptions = tillsData?.tills ?? [];
  const { data: manageTillsData, reload: reloadManageTills } = useFetch<{ tills: TillManageOption[] }>(
    canManage ? "/till/tills?include_inactive=1" : null
  );
  const manageTills = manageTillsData?.tills ?? [];
  const [newTillOpen, setNewTillOpen] = useState(false);

  const reloadTills = () => {
    reloadTillOptions();
    reloadManageTills();
  };

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
              <button className="btn-primary" onClick={() => setCloseOpen(true)}>Close till & count cash</button>
            )}
          </div>
        ) : tillOptions.length === 0 ? (
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <span>No tills exist yet — {canManage ? "create one below to get started." : "ask a manager to create one before you can take cash payments."}</span>
            {canManage && <button className="btn-primary" onClick={() => setNewTillOpen(true)}><Plus size={16} /> New till</button>}
          </div>
        ) : (
          <div className="flex items-center gap-3 text-sm">
            <span>No till open — open one before taking cash payments.</span>
            {can("till.open") && <button className="btn-primary" onClick={() => setOpenOpen(true)}>Open till</button>}
          </div>
        )}
      </Card>

      {canManage && (
        <Card
          title="Manage tills"
          actions={<button className="btn-secondary !py-1 text-xs" onClick={() => setNewTillOpen(true)}><Plus size={13} /> New till</button>}
        >
          <div className="space-y-2">
            {manageTills.map((t) => <TillRow key={t.id} till={t} onDone={reloadTills} />)}
          </div>
          {manageTills.length === 0 && <Empty text="No tills yet — create one to get started" />}
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
                      {m.amount > 0 ? "+" : ""}{lkr(m.amount)}
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
          <table className="w-full min-w-[820px]">
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
                  <td className="td text-right">{lkr(s.opening_cash)}</td>
                  <td className="td text-right">{s.expected_cash !== null ? lkr(s.expected_cash) : "—"}</td>
                  <td className="td text-right">{s.closing_cash !== null ? lkr(s.closing_cash) : "—"}</td>
                  <td className="td text-right">
                    {s.variance !== null ? (
                      <b className={s.variance === 0 ? "text-emerald-600" : "text-red-600"}>{s.variance > 0 ? "+" : ""}{lkr(s.variance)}</b>
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
        <CloseTill sessionId={current.id} expected={current.expected_balance} onClose={() => { setCloseOpen(false); refreshAll(); }} />
      )}
      {movementModal && current && (
        <CashMovement
          sessionId={current.id} kind={movementModal}
          onClose={() => { setMovementModal(null); refreshAll(); }}
        />
      )}
      {newTillOpen && <NewTillModal onClose={() => { setNewTillOpen(false); reloadTills(); }} />}
    </div>
  );
}

function OpenTill({ tills, onClose }: { tills: TillOption[]; onClose: () => void }) {
  const toast = useToast();
  // Re-sync when `tills` resolves after this modal mounts (was captured once
  // from the initial empty/stale prop, so the select showed the first option
  // while tillId silently stayed "" and /till/open got sent an empty id).
  const [tillId, setTillId] = useState<number | "">(tills[0]?.id ?? "");
  useEffect(() => {
    if (tillId === "" && tills[0]) setTillId(tills[0].id);
  }, [tills]); // eslint-disable-line react-hooks/exhaustive-deps
  const [amount, setAmount] = useState("");
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="Open till — count opening cash">
      <Field label="Till">
        <select className="input" value={tillId} onChange={(e) => setTillId(Number(e.target.value))}>
          {tills.length === 0 && <option value="">No tills available</option>}
          {tills.map((t) => (
            <option key={t.id} value={t.id}>{t.name}</option>
          ))}
        </select>
      </Field>
      <Field label="Opening cash in drawer (LKR)">
        <input className="input" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
      </Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-3 w-full"
        disabled={tillId === ""}
        onClick={() =>
          post("/till/open", { till_id: tillId, opening_balance: toCents(amount) })
            .then(() => {
              toast.success("Till opened", `Opening balance ${lkr(toCents(amount))}`);
              onClose();
            })
            .catch((e) => setError(e.message))
        }
      >
        Open till
      </button>
    </Modal>
  );
}

function TillRow({ till, onDone }: { till: TillManageOption; onDone: () => void }) {
  const [name, setName] = useState(till.name);
  const [error, setError] = useState("");
  return (
    <div>
      <div className="flex items-center gap-2">
        <input
          className="input !w-48"
          value={name}
          onChange={(e) => setName(e.target.value)}
          onBlur={() => {
            const trimmed = name.trim();
            if (!trimmed || trimmed === till.name) return setName(till.name);
            put(`/till/tills/${till.id}`, { name: trimmed, is_active: till.is_active }).then(onDone).catch((e) => setError(e.message));
          }}
        />
        <button
          className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${till.is_active ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-400"}`}
          onClick={() => put(`/till/tills/${till.id}`, { name: till.name, is_active: !till.is_active }).then(onDone).catch((e) => setError(e.message))}
        >
          {till.is_active ? "ACTIVE" : "INACTIVE"}
        </button>
      </div>
      <ErrorText error={error} />
    </div>
  );
}

function NewTillModal({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [name, setName] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const save = () => {
    setBusy(true);
    setError("");
    post("/till/tills", { name: name.trim() })
      .then(() => {
        toast.success("Till created", name.trim());
        onClose();
      })
      .catch((e) => setError(e.message))
      .finally(() => setBusy(false));
  };
  return (
    <Modal open onClose={onClose} title="New till">
      <Field label="Till name *"><input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Front Desk Till" autoFocus /></Field>
      <ErrorText error={error} />
      <button className="btn-primary mt-3 w-full" disabled={busy || !name.trim()} onClick={save}>
        {busy ? "Creating…" : "Create till"}
      </button>
    </Modal>
  );
}

function CloseTill({ sessionId, expected, onClose }: { sessionId: number; expected: number; onClose: () => void }) {
  const toast = useToast();
  const [amount, setAmount] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState("");
  const variance = toCents(amount) - expected;
  return (
    <Modal open onClose={onClose} title="Close till — reconciliation">
      <div className="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-sm">Expected cash: <b>{lkr(expected)}</b></div>
      <Field label="Counted closing cash (LKR)">
        <input className="input" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
      </Field>
      {amount && (
        <div className={`mt-1 text-sm font-bold ${variance === 0 ? "text-emerald-600" : "text-red-600"}`}>
          Variance: {variance > 0 ? "+" : ""}{lkr(variance)}
        </div>
      )}
      <Field label="Notes (explain any variance)">
        <input className="input" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-3 w-full"
        onClick={() =>
          post(`/till/${sessionId}/close`, { closing_cash: toCents(amount), notes: notes || undefined })
            .then(() => {
              if (variance === 0) toast.success("Till closed — balanced", "No variance");
              else toast.warning("Till closed — variance found", `${variance > 0 ? "+" : ""}${lkr(variance)}`);
              onClose();
            })
            .catch((e) => setError(e.message))
        }
      >
        Close & reconcile
      </button>
    </Modal>
  );
}

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
