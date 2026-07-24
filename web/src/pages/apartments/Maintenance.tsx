import { useState } from "react";
import { Plus } from "lucide-react";
import { post, put } from "../../lib/api";
import { useFetch, usePagedFetch, fmtDateTime } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, statusColor, Pagination } from "../../components/ui";
import { useToast } from "../../lib/toast";
import { useAuth } from "../../lib/auth";

type Lookup = { id: number; code: string; name: string };
type Issue = {
  id: number; description: string; status: Lookup; created_at: string; resolved_at?: string | null; resolution_notes?: string | null;
  unit: { id: number; unit_no: string };
  logged_by: { id: number; name: string };
};
type UnitLite = { id: number; unit_no: string };

export default function ApartmentMaintenance() {
  const { can } = useAuth();
  const [showAll, setShowAll] = useState(false);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = usePagedFetch<Issue>(`/apartments/maintenance?all=${showAll ? "1" : "0"}&page=${page}&page_size=${pageSize}`, "issues", [showAll, page, pageSize]);
  const issues = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const [resolving, setResolving] = useState<Issue | null>(null);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Maintenance</h1>
        <div className="flex gap-2">
          <button className="btn-secondary" onClick={() => { setShowAll(!showAll); setPage(1); }}>{showAll ? "Open only" : "Show resolved"}</button>
          {can("apartment_maintenance.create") && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> Log issue</button>}
        </div>
      </div>
      <ErrorText error={error} />
      <div className="space-y-2">
        {(issues ?? []).map((i) => (
          <div key={i.id} className="card flex flex-wrap items-center gap-3 p-3">
            <Badge color={statusColor(i.status.code.toUpperCase())}>{i.status.code.toUpperCase()}</Badge>
            <span className="font-bold">{i.unit.unit_no}</span>
            <span className="min-w-0 flex-1 text-sm">{i.description}</span>
            <span className="text-xs text-slate-400">by {i.logged_by.name} · {fmtDateTime(i.created_at)}</span>
            {i.status.code !== "resolved" && can("apartment_maintenance.edit") && (
              <div className="flex gap-1.5">
                {i.status.code === "open" && (
                  <button className="btn-secondary !py-1 text-xs" onClick={() => put(`/apartments/maintenance/${i.id}`, { status: "in_progress" }).then(reload)}>Start</button>
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
  const { data: unitData } = useFetch<{ units: UnitLite[] }>("/apartments/units");
  const units = unitData?.units;
  const [f, setF] = useState({ unitId: "", description: "", takeOut: false });
  const [error, setError] = useState("");
  const unitLabel = (units ?? []).find((u) => String(u.id) === f.unitId)?.unit_no ?? "";

  return (
    <Modal open onClose={onClose} title="Log maintenance issue">
      <div className="space-y-3">
        <Field label="Unit">
          <select className="input" value={f.unitId} onChange={(e) => setF({ ...f, unitId: e.target.value })}>
            <option value="">Select…</option>
            {(units ?? []).map((u) => <option key={u.id} value={u.id}>{u.unit_no}</option>)}
          </select>
        </Field>
        <Field label="Describe the problem">
          <textarea className="input" rows={3} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="e.g. AC not cooling, plumbing leak under sink…" />
        </Field>
        <label className="flex items-center gap-2 text-sm font-semibold">
          <input type="checkbox" checked={f.takeOut} onChange={(e) => setF({ ...f, takeOut: e.target.checked })} />
          Take unit out of service (status → MAINTENANCE)
        </label>
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={!f.unitId || f.description.trim().length < 3}
          onClick={() =>
            post("/apartments/maintenance", {
              unit_id: Number(f.unitId),
              description: f.description.trim(),
              take_unit_out_of_service: f.takeOut,
            })
              .then(() => {
                toast.success(`Issue logged — ${unitLabel}`, f.description.trim());
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
  const [returnUnit, setReturnUnit] = useState(true);
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={`Resolve — ${issue.unit.unit_no}`}>
      <div className="space-y-3">
        <Field label="Resolution notes">
          <textarea className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
        <label className="flex items-center gap-2 text-sm font-semibold">
          <input type="checkbox" checked={returnUnit} onChange={(e) => setReturnUnit(e.target.checked)} />
          Return unit to service (→ needs cleaning, checklist gate applies)
        </label>
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          onClick={() =>
            put(`/apartments/maintenance/${issue.id}`, { status: "resolved", resolution_notes: notes || undefined, return_unit_to_service: returnUnit })
              .then(() => {
                toast.success("Issue resolved", issue.unit.unit_no);
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
