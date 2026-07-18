import { useState } from "react";
import { Plus } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch, usePagedFetch, fmtDateTime } from "../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, statusColor, Pagination } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

type Lookup = { id: number; code: string; name: string };
type Issue = {
  id: number; description: string; status: Lookup; created_at: string; resolved_at?: string | null; resolution_notes?: string | null;
  room?: { id: number; number: string } | null;
  venue?: { id: number; name: string } | null;
  logged_by: { id: number; name: string };
};
type RoomLite = { id: number; number: string };
type VenueLite = { id: number; name: string };

export default function Maintenance() {
  const { can } = useAuth();
  const [showAll, setShowAll] = useState(false);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = usePagedFetch<Issue>(`/maintenance?all=${showAll ? "1" : "0"}&page=${page}&page_size=${pageSize}`, "issues", [showAll, page, pageSize]);
  const issues = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const [resolving, setResolving] = useState<Issue | null>(null);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Maintenance</h1>
        <div className="flex gap-2">
          <button className="btn-secondary" onClick={() => { setShowAll(!showAll); setPage(1); }}>{showAll ? "Open only" : "Show resolved"}</button>
          {can("hotel_maintenance.create") && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> Log issue</button>}
        </div>
      </div>
      <ErrorText error={error} />
      <div className="space-y-2">
        {(issues ?? []).map((i) => (
          <div key={i.id} className="card flex flex-wrap items-center gap-3 p-3">
            <Badge color={statusColor(i.status.code.toUpperCase())}>{i.status.code.toUpperCase()}</Badge>
            <span className="font-bold">{i.room ? `Room ${i.room.number}` : i.venue ? i.venue.name : "—"}</span>
            <span className="min-w-0 flex-1 text-sm">{i.description}</span>
            <span className="text-xs text-slate-400">by {i.logged_by.name} · {fmtDateTime(i.created_at)}</span>
            {i.status.code !== "resolved" && can("hotel_maintenance.edit") && (
              <div className="flex gap-1.5">
                {i.status.code === "open" && (
                  <button className="btn-secondary !py-1 text-xs" onClick={() => put(`/maintenance/${i.id}`, { status: "in_progress" }).then(reload)}>Start</button>
                )}
                <button className="btn-primary !py-1 text-xs" onClick={() => setResolving(i)}>Resolve</button>
              </div>
            )}
            {i.resolution_notes && <span className="w-full text-xs text-emerald-700">✓ {i.resolution_notes}</span>}
          </div>
        ))}
        {(issues ?? []).length === 0 && <Empty text="No open maintenance issues" />}
      </div>
      {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}

      {openNew && <NewIssue onClose={() => setOpenNew(false)} onDone={() => { setOpenNew(false); reload(); }} />}
      {resolving && (
        <ResolveModal
          issue={resolving}
          onClose={() => setResolving(null)}
          onDone={() => { setResolving(null); reload(); }}
        />
      )}
    </div>
  );
}

function NewIssue({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const { data: roomData } = useFetch<{ rooms: RoomLite[] }>("/rooms");
  const { data: venueData } = useFetch<{ venues: VenueLite[] }>("/maintenance/venue-options");
  const rooms = roomData?.rooms;
  const venues = venueData?.venues;
  const [target, setTarget] = useState<"room" | "venue">("room");
  const [f, setF] = useState({ roomId: "", venueId: "", description: "", takeOut: false });
  const [error, setError] = useState("");
  const targetLabel = target === "room"
    ? (rooms ?? []).find((r) => String(r.id) === f.roomId)?.number ?? ""
    : (venues ?? []).find((v) => String(v.id) === f.venueId)?.name ?? "";

  return (
    <Modal open onClose={onClose} title="Log maintenance issue">
      <div className="space-y-3">
        <div className="flex gap-2">
          <button type="button" className={target === "room" ? "btn-primary !py-1 text-xs" : "btn-secondary !py-1 text-xs"} onClick={() => setTarget("room")}>Room</button>
          <button type="button" className={target === "venue" ? "btn-primary !py-1 text-xs" : "btn-secondary !py-1 text-xs"} onClick={() => setTarget("venue")}>Venue</button>
        </div>
        {target === "room" ? (
          <Field label="Room">
            <select className="input" value={f.roomId} onChange={(e) => setF({ ...f, roomId: e.target.value })}>
              <option value="">Select…</option>
              {(rooms ?? []).map((r) => <option key={r.id} value={r.id}>Room {r.number}</option>)}
            </select>
          </Field>
        ) : (
          <Field label="Venue">
            <select className="input" value={f.venueId} onChange={(e) => setF({ ...f, venueId: e.target.value })}>
              <option value="">Select…</option>
              {(venues ?? []).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
            </select>
          </Field>
        )}
        <Field label="Describe the problem">
          <textarea className="input" rows={3} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="e.g. AC not cooling, plumbing leak under sink…" />
        </Field>
        {target === "room" && (
          <label className="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" checked={f.takeOut} onChange={(e) => setF({ ...f, takeOut: e.target.checked })} />
            Take room out of service (status → MAINTENANCE)
          </label>
        )}
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={(target === "room" ? !f.roomId : !f.venueId) || f.description.trim().length < 3}
          onClick={() =>
            post("/maintenance", {
              room_id: target === "room" ? Number(f.roomId) : undefined,
              venue_id: target === "venue" ? Number(f.venueId) : undefined,
              description: f.description.trim(),
              take_room_out_of_service: target === "room" ? f.takeOut : false,
            })
              .then(() => {
                toast.success(`Issue logged — ${target === "room" ? "Room " : ""}${targetLabel}`, f.description.trim());
                onDone();
              })
              .catch((e) => setError(e.message))
          }
        >
          Log issue
        </button>
      </div>
    </Modal>
  );
}

function ResolveModal({ issue, onClose, onDone }: { issue: Issue; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [notes, setNotes] = useState("");
  const [returnRoom, setReturnRoom] = useState(true);
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={`Resolve — ${issue.room ? `Room ${issue.room.number}` : issue.venue ? issue.venue.name : "issue"}`}>
      <div className="space-y-3">
        <Field label="Resolution notes">
          <textarea className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
        {issue.room && (
          <label className="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" checked={returnRoom} onChange={(e) => setReturnRoom(e.target.checked)} />
            Return room to service (→ needs cleaning, checklist gate applies)
          </label>
        )}
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          onClick={() =>
            put(`/maintenance/${issue.id}`, { status: "resolved", resolution_notes: notes || undefined, return_room_to_service: returnRoom })
              .then(() => {
                toast.success("Issue resolved", issue.room ? `Room ${issue.room.number}` : undefined);
                onDone();
              })
              .catch((e) => setError(e.message))
          }
        >
          Mark resolved
        </button>
      </div>
    </Modal>
  );
}
