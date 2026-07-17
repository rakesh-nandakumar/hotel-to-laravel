import { useMemo, useState } from "react";
import { History, Search, ChevronDown, Globe, Info } from "lucide-react";
import { useFetch, fmtDateTime } from "../lib/util";
import { Badge, Card, Empty, Field, Pagination } from "../components/ui";
import clsx from "clsx";

type Row = {
  id: number;
  action: string;
  description: string;
  subject_type: string | null;
  subject_id: number | string | null;
  context?: unknown;
  ip: string | null;
  user_agent: string | null;
  route: string | null;
  created_at: string;
  actor: { id: number; name: string; email: string; roles: { id: number; name: string }[] } | null;
};
type Paginator<T> = { data: T[]; current_page: number; per_page: number; total: number; last_page: number };
type ActorOption = { id: number; name: string; email: string };
type EntityOption = { value: string; label: string };
/** Shape of AuditLogController@index — the paginated log page bundled together with its own filter facets. */
type IndexResponse = {
  logs: Paginator<Row>;
  actorOptions: ActorOption[];
  availableActions: string[];
  availableEntities: EntityOption[];
};

/** Short display name for an Eloquent FQCN subject_type, e.g. "App\Models\Hotel\Reservation" → "Reservation". */
const entityLabel = (subjectType: string | null | undefined): string => {
  if (!subjectType) return "—";
  const parts = subjectType.split("\\");
  return parts[parts.length - 1];
};

/** Roughly categorize actions for a colored badge — purely cosmetic. */
const ACTION_COLOR = (action: string): string => {
  const a = action.toLowerCase();
  if (a.includes("delete") || a.includes("void") || a.includes("cancel") || a.includes("refund") || a.includes("locked") || a.includes("blocked")) return "red";
  if (a.includes("create") || a.includes("login") || a.includes("checked_in") || a.includes("signed_in") || a.includes("confirmed")) return "green";
  if (a.includes("update") || a.includes("adjust") || a.includes("change") || a.includes("suspended")) return "amber";
  return "slate";
};

export default function AuditLog() {
  const [staffId, setStaffId] = useState("");
  const [action, setAction] = useState("");
  const [entity, setEntity] = useState("");
  const [q, setQ] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(50);
  const [expanded, setExpanded] = useState<number | null>(null);

  const query = useMemo(() => {
    const p = new URLSearchParams();
    if (staffId) p.set("actor_id", staffId);
    if (action) p.set("actions[]", action);
    if (entity) p.set("entity", entity);
    if (q.trim()) p.set("search", q.trim());
    if (from) p.set("from", from);
    if (to) p.set("to", to);
    p.set("page", String(page));
    p.set("page_size", String(pageSize));
    return p.toString();
  }, [staffId, action, entity, q, from, to, page, pageSize]);

  const { data, reload } = useFetch<IndexResponse>(`/audit-logs?${query}`, [query]);
  const rows = data?.logs.data;

  const resetPage = (fn: () => void) => {
    fn();
    setPage(1);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold">
          <History /> Audit Log <Badge color="purple">OWNER / MANAGER / SYS ADMIN</Badge>
        </h1>
        <button className="btn-ghost text-xs" onClick={reload}>Refresh</button>
      </div>
      <p className="text-xs text-slate-500">
        Every sensitive action across the system — logins, check-ins/checkouts, voids, refunds, discounts, settings and menu changes, stock
        adjustments, payroll, staff changes and more — with who did it and when. Not shown here: routine read-only page views.
      </p>

      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Search (action / entity / id)">
            <div className="relative">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input className="input !pl-8" value={q} onChange={(e) => resetPage(() => setQ(e.target.value))} placeholder="e.g. VOID, Order, RSV-0012…" />
            </div>
          </Field>
          <Field label="Staff member">
            <select className="input" value={staffId} onChange={(e) => resetPage(() => setStaffId(e.target.value))}>
              <option value="">All staff</option>
              {(data?.actorOptions ?? []).map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.email})</option>
              ))}
            </select>
          </Field>
          <Field label="Action">
            <select className="input" value={action} onChange={(e) => resetPage(() => setAction(e.target.value))}>
              <option value="">All actions</option>
              {(data?.availableActions ?? []).map((a) => (
                <option key={a} value={a}>{a}</option>
              ))}
            </select>
          </Field>
          <Field label="Entity">
            <select className="input" value={entity} onChange={(e) => resetPage(() => setEntity(e.target.value))}>
              <option value="">All entities</option>
              {(data?.availableEntities ?? []).map((e) => (
                <option key={e.value} value={e.value}>{e.label}</option>
              ))}
            </select>
          </Field>
          <Field label="From date">
            <input type="date" className="input" value={from} onChange={(e) => resetPage(() => setFrom(e.target.value))} />
          </Field>
          <Field label="To date">
            <input type="date" className="input" value={to} onChange={(e) => resetPage(() => setTo(e.target.value))} />
          </Field>
        </div>
      </Card>

      <div className="card divide-y divide-slate-50">
        {(rows ?? []).map((r) => {
          const isOpen = expanded === r.id;
          return (
            <div key={r.id}>
              <button
                className="flex w-full flex-wrap items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-slate-50"
                onClick={() => setExpanded(isOpen ? null : r.id)}
              >
                <ChevronDown size={14} className={clsx("shrink-0 text-slate-300 transition-transform", isOpen && "rotate-180")} />
                <span className="w-40 shrink-0 text-xs text-slate-400">{fmtDateTime(r.created_at)}</span>
                <Badge color={ACTION_COLOR(r.action)}>{r.action}</Badge>
                <span className="text-slate-500">{entityLabel(r.subject_type)}{r.subject_id != null ? ` · ${String(r.subject_id).slice(0, 10)}…` : ""}</span>
                {r.ip && <span className="hidden shrink-0 font-mono text-[11px] text-slate-400 sm:inline">{r.ip}</span>}
                <span className="ml-auto shrink-0 text-xs font-semibold text-slate-600">
                  {r.actor ? r.actor.name : "System"}
                  {r.actor?.roles?.[0] && <span className="ml-1 font-normal text-slate-400">({r.actor.roles.map((role) => role.name).join(", ")})</span>}
                </span>
              </button>
              {isOpen && (
                <div className="space-y-2 bg-slate-50/60 px-11 py-3">
                  <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                    <span className="flex items-center gap-1.5"><Globe size={12} /> IP: <b className="font-mono text-slate-700">{r.ip ?? "—"}</b></span>
                    <span className="flex items-center gap-1.5"><Info size={12} /> {r.description}</span>
                  </div>
                  <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-400">
                    <span>Route: <span className="font-mono text-slate-600">{r.route ?? "—"}</span></span>
                    <span className="truncate">Device: <span className="text-slate-600">{r.user_agent ?? "—"}</span></span>
                  </div>
                  <pre className="max-w-full overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-slate-900 p-3 text-xs text-slate-200">
                    {JSON.stringify({ subjectId: r.subject_id, context: r.context }, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          );
        })}
        {(rows ?? []).length === 0 && <Empty text="No audit entries match these filters" />}
      </div>

      {data && (
        <Pagination
          page={data.logs.current_page}
          pageSize={data.logs.per_page}
          total={data.logs.total}
          onPage={setPage}
          onPageSize={(n) => { setPageSize(n); setPage(1); }}
        />
      )}
    </div>
  );
}
