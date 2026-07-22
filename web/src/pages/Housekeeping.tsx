import { useState } from "react";
import { post, put } from "../lib/api";
import { useFetch, usePagedFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Modal, statusColor, Pagination } from "../components/ui";
import { useAuth } from "../lib/auth";
import { useToast } from "../lib/toast";

type Task = {
  id: number; notes?: string | null; created_at: string;
  status: { code: string };
  room: { number: string; status: { code: string }; room_type: { name: string } | null };
  assigned_to?: { id: number; name: string } | null;
  checklist: { item: string; done: boolean }[];
};
type StaffLite = { id: number; name: string; roles: { name: string }[] };

export default function Housekeeping() {
  const { can } = useAuth();
  const isManager = can("hotel_housekeeping.assign");
  const canChecklist = can("hotel_housekeeping.checklist");
  const canComplete = can("hotel_housekeeping.complete");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = usePagedFetch<Task>(`/housekeeping/tasks?page=${page}&page_size=${pageSize}`, "tasks", [page, pageSize]);
  const tasks = data?.rows;
  const { data: staffData } = useFetch<{ staff: StaffLite[] }>("/staff");
  const [open, setOpen] = useState<Task | null>(null);
  const housekeepers = (staffData?.staff ?? []).filter((s) => s.roles.some((r) => r.name === "Housekeeper"));

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Housekeeping</h1>
      <p className="text-xs text-slate-500">A room cannot be sold again until its cleaning checklist is submitted — completing a task marks the room Clean/Available.</p>
      <ErrorText error={error} />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {(tasks ?? []).map((t) => {
          const done = t.checklist.filter((c) => c.done).length;
          return (
            <button key={t.id} className="card p-4 text-left hover:shadow-md" onClick={() => setOpen(t)}>
              <div className="flex items-center justify-between">
                <span className="text-2xl font-black">Room {t.room.number}</span>
                <Badge color={statusColor(t.status.code)}>{t.status.code}</Badge>
              </div>
              <div className="text-xs text-slate-500">{t.room.room_type?.name ?? "—"} · created {fmtDateTime(t.created_at)}</div>
              <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div className="h-full bg-brand-500 transition-all" style={{ width: `${(done / t.checklist.length) * 100}%` }} />
              </div>
              <div className="mt-1 flex items-center justify-between text-xs">
                <span>{done}/{t.checklist.length} items</span>
                <span className="font-semibold">{t.assigned_to?.name ?? "Unassigned"}</span>
              </div>
              {t.notes && <div className="mt-1 text-xs text-amber-700">📝 {t.notes}</div>}
            </button>
          );
        })}
      </div>
      {(tasks ?? []).length === 0 && <Empty text="No rooms waiting to be cleaned 🎉" />}
      {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}

      {open && (
        <ChecklistModal
          task={open}
          canAssign={isManager}
          canChecklist={canChecklist}
          canComplete={canComplete}
          housekeepers={housekeepers}
          onClose={() => {
            setOpen(null);
            reload();
          }}
        />
      )}
    </div>
  );
}

function ChecklistModal({ task, canAssign, canChecklist, canComplete, housekeepers, onClose }: { task: Task; canAssign: boolean; canChecklist: boolean; canComplete: boolean; housekeepers: StaffLite[]; onClose: () => void }) {
  const toast = useToast();
  const [items, setItems] = useState(task.checklist);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const allDone = items.every((i) => i.done);

  const toggle = (i: number) => {
    if (!canChecklist) return;
    const next = items.map((x, j) => (j === i ? { ...x, done: !x.done } : x));
    setItems(next);
    put(`/housekeeping/tasks/${task.id}/checklist`, { checklist: next }).catch((e) => setError(e.message));
  };

  const complete = async () => {
    setBusy(true);
    setError("");
    try {
      await post(`/housekeeping/tasks/${task.id}/complete`, { checklist: items });
      toast.success(`Room ${task.room.number} is now Available`, "Cleaning checklist submitted");
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Room ${task.room.number} — cleaning checklist`}>
      {canAssign && (
        <div className="mb-3">
          <label className="label">Assigned to</label>
          <select
            className="input"
            defaultValue={task.assigned_to?.id ?? ""}
            onChange={(e) => put(`/housekeeping/tasks/${task.id}/assign`, { assigned_to_id: e.target.value || null }).catch((err) => setError(err.message))}
          >
            <option value="">Unassigned</option>
            {housekeepers.map((h) => (
              <option key={h.id} value={h.id}>{h.name}</option>
            ))}
          </select>
        </div>
      )}
      <div className="space-y-1">
        {items.map((c, i) => (
          <label key={i} className={`flex items-start gap-2.5 rounded-lg px-2 py-2 text-sm ${c.done ? "bg-emerald-50 text-emerald-900" : "hover:bg-slate-50"}`}>
            <input type="checkbox" className="mt-0.5 h-4 w-4" checked={c.done} disabled={!canChecklist} onChange={() => toggle(i)} />
            <span className={c.done ? "line-through opacity-70" : "font-medium"}>{c.item}</span>
          </label>
        ))}
      </div>
      <ErrorText error={error} />
      {canComplete && (
        <button className="btn-primary mt-4 w-full !py-3" disabled={!allDone || busy} onClick={complete}>
          {allDone ? "Submit checklist — room becomes Clean/Available" : `Complete all ${items.length} items first`}
        </button>
      )}
    </Modal>
  );
}
