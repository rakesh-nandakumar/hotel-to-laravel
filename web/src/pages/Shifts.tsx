import { useState } from "react";
import { post } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination } from "../components/ui";
import { useToast } from "../lib/toast";

type CurrentShift = { id: number; opened_at: string; opening_cash: number; cash_in: number; cash_out: number; expected_now: number } | null;
type Shift = {
  id: number; opened_at: string; closed_at?: string | null; opening_cash: number; closing_cash?: number | null;
  expected_cash?: number | null; variance?: number | null; notes?: string | null; staff: { id: number; name: string };
};

export default function Shifts() {
  const { data: currentData, reload: reloadCurrent } = useFetch<{ shift: CurrentShift }>("/shifts/current");
  const current = currentData?.shift ?? null;
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<Shift>(`/shifts?page=${page}&page_size=${pageSize}`, "shifts", [page, pageSize]);
  const shifts = data?.rows;
  const [openOpen, setOpenOpen] = useState(false);
  const [closeOpen, setCloseOpen] = useState(false);

  const refresh = () => {
    reload();
    reloadCurrent();
  };

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Cash Drawer & Shifts</h1>

      <Card title="My shift">
        {current ? (
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <div>Opened <b>{fmtDateTime(current.opened_at)}</b></div>
            <div>Opening float: <b>{lkr(current.opening_cash)}</b></div>
            <div className="text-emerald-700">Cash in: <b>{lkr(current.cash_in)}</b></div>
            <div className="text-red-600">Cash refunds: <b>{lkr(current.cash_out)}</b></div>
            <div className="rounded-lg bg-slate-100 px-3 py-1.5">Expected in drawer: <b>{lkr(current.expected_now)}</b></div>
            <button className="btn-primary" onClick={() => setCloseOpen(true)}>Close shift & count cash</button>
          </div>
        ) : (
          <div className="flex items-center gap-3 text-sm">
            <span>No open shift — open one before taking cash payments.</span>
            <button className="btn-primary" onClick={() => setOpenOpen(true)}>Open shift</button>
          </div>
        )}
      </Card>

      <Card title="Shift history — daily cash-drawer reconciliation">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px]">
            <thead className="border-b border-slate-100">
              <tr>
                <th className="th">Staff</th><th className="th">Opened</th><th className="th">Closed</th>
                <th className="th text-right">Opening</th><th className="th text-right">Expected</th>
                <th className="th text-right">Counted</th><th className="th text-right">Variance</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {(shifts ?? []).map((s) => (
                <tr key={s.id}>
                  <td className="td font-semibold">{s.staff.name}</td>
                  <td className="td text-xs">{fmtDateTime(s.opened_at)}</td>
                  <td className="td text-xs">{s.closed_at ? fmtDateTime(s.closed_at) : <Badge color="green">OPEN</Badge>}</td>
                  <td className="td text-right">{lkr(s.opening_cash)}</td>
                  <td className="td text-right">{s.expected_cash !== null && s.expected_cash !== undefined ? lkr(s.expected_cash) : "—"}</td>
                  <td className="td text-right">{s.closing_cash !== null && s.closing_cash !== undefined ? lkr(s.closing_cash) : "—"}</td>
                  <td className="td text-right">
                    {s.variance !== null && s.variance !== undefined ? (
                      <b className={s.variance === 0 ? "text-emerald-600" : "text-red-600"}>{s.variance > 0 ? "+" : ""}{lkr(s.variance)}</b>
                    ) : "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {(shifts ?? []).length === 0 && <Empty text="No shifts yet" />}
          {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
        </div>
      </Card>

      {openOpen && <OpenShift onClose={() => { setOpenOpen(false); refresh(); }} />}
      {closeOpen && current && <CloseShift shiftId={current.id} expected={current.expected_now} onClose={() => { setCloseOpen(false); refresh(); }} />}
    </div>
  );
}

function OpenShift({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const [amount, setAmount] = useState("");
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="Open shift — count opening cash">
      <Field label="Opening cash in drawer (LKR)">
        <input className="input" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
      </Field>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-3 w-full"
        onClick={() =>
          post("/shifts/open", { opening_cash: toCents(amount) })
            .then(() => {
              toast.success("Shift opened", `Opening float ${lkr(toCents(amount))}`);
              onClose();
            })
            .catch((e) => setError(e.message))
        }
      >
        Open shift
      </button>
    </Modal>
  );
}

function CloseShift({ shiftId, expected, onClose }: { shiftId: number; expected: number; onClose: () => void }) {
  const toast = useToast();
  const [amount, setAmount] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState("");
  const variance = toCents(amount) - expected;
  return (
    <Modal open onClose={onClose} title="Close shift — reconciliation">
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
          post(`/shifts/${shiftId}/close`, { closing_cash: toCents(amount), notes: notes || undefined })
            .then(() => {
              if (variance === 0) toast.success("Shift closed — balanced", "No variance");
              else toast.warning("Shift closed — variance found", `${variance > 0 ? "+" : ""}${lkr(variance)}`);
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
