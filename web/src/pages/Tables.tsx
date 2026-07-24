import { useState } from "react";
import { Plus, Grid2x2, Users } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch } from "../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, statusColor, Tabs } from "../components/ui";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type Area = { id: number; name: string; sort_order: number; active: boolean; tables_count: number };
type DiningTable = {
  id: number; table_no: string; capacity: number; notes?: string | null;
  status: { code: string };
  area: { id: number; name: string } | null;
  open_order: { id: number; customer_name?: string | null; total: number } | null;
};

const STATUS_OPTIONS = ["free", "reserved", "cleaning"];

export default function Tables() {
  const { can } = useAuth();
  const [tab, setTab] = useState<"floor" | "areas">("floor");
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><Grid2x2 /> Dining Tables</h1>
        <Tabs tabs={[{ id: "floor" as const, label: "Floor plan" }, { id: "areas" as const, label: "Areas" }]} active={tab} onChange={setTab} />
      </div>
      {tab === "floor" && <FloorPlan canManage={can("hotel_dining_tables.create")} canEditStatus={can("hotel_dining_tables.edit_status")} />}
      {tab === "areas" && <Areas />}
    </div>
  );
}

function FloorPlan({ canManage, canEditStatus }: { canManage: boolean; canEditStatus: boolean }) {
  const { data, reload, error: loadError } = useFetch<{ dining_tables: DiningTable[] }>("/dining-tables");
  const { data: areasData } = useFetch<{ dining_areas: Area[] }>("/dining-areas");
  const tables = data?.dining_tables ?? [];
  const areas = areasData?.dining_areas ?? [];
  const [areaFilter, setAreaFilter] = useState("");
  const [openNew, setOpenNew] = useState(false);
  const [error, setError] = useState("");

  const setStatus = (id: number, status: string) =>
    put(`/dining-tables/${id}/status`, { status })
      .then(() => { setError(""); reload(); })
      .catch((e) => setError(e.message));

  const shown = areaFilter ? tables.filter((t) => String(t.area?.id) === areaFilter) : tables;
  const counts = {
    free: tables.filter((t) => t.status.code === "free").length,
    occupied: tables.filter((t) => t.status.code === "occupied").length,
    reserved: tables.filter((t) => t.status.code === "reserved").length,
    cleaning: tables.filter((t) => t.status.code === "cleaning").length,
  };

  return (
    <div className="space-y-3">
      <ErrorText error={loadError || error} />
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {(["free", "occupied", "reserved", "cleaning"] as const).map((s) => (
          <div key={s} className="card p-3">
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{s}</div>
            <div className="mt-1 text-2xl font-extrabold">{counts[s]}</div>
          </div>
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <select className="input !w-48" value={areaFilter} onChange={(e) => setAreaFilter(e.target.value)}>
          <option value="">All areas</option>
          {areas.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
        {canManage && <button className="btn-primary ml-auto" onClick={() => setOpenNew(true)}><Plus size={16} /> New table</button>}
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        {shown.map((t) => (
          <div key={t.id} className="card p-3">
            <div className="flex items-start justify-between">
              <div className="text-2xl font-black">{t.table_no}</div>
              <Badge color={statusColor(t.status.code)}>{t.status.code.toUpperCase()}</Badge>
            </div>
            <div className="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
              {t.area && <span>{t.area.name}</span>}
              <span className="flex items-center gap-0.5"><Users size={11} /> {t.capacity}</span>
            </div>
            {t.open_order ? (
              <div className="mt-2 rounded-lg bg-sky-50 px-2 py-1.5 text-xs">
                <div className="truncate font-bold text-sky-900">{t.open_order.customer_name || `Order #${t.open_order.id}`}</div>
              </div>
            ) : (
              canEditStatus && (
                <select
                  className="input mt-2 !h-7 !py-0 !text-[11px]"
                  value=""
                  onChange={(e) => e.target.value && setStatus(t.id, e.target.value)}
                >
                  <option value="">Set status…</option>
                  {STATUS_OPTIONS.filter((s) => s !== t.status.code).map((s) => <option key={s} value={s}>{s.toUpperCase()}</option>)}
                </select>
              )
            )}
          </div>
        ))}
      </div>
      {tables.length === 0 && <Empty text="No dining tables yet" />}
      {tables.length > 0 && shown.length === 0 && <Empty text="No tables in this area" />}
      {openNew && <TableEditor areas={areas} onClose={() => { setOpenNew(false); reload(); }} />}
    </div>
  );
}

function TableEditor({ areas, onClose }: { areas: Area[]; onClose: () => void }) {
  const [f, setF] = useState({ tableNo: "", areaId: "", capacity: "4", notes: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      await post("/dining-tables", {
        table_no: f.tableNo.trim(),
        dining_area_id: f.areaId ? Number(f.areaId) : undefined,
        capacity: parseInt(f.capacity) || 1,
        notes: f.notes.trim() || undefined,
      });
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title="New table">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Table number *"><input className="input" value={f.tableNo} onChange={(e) => setF({ ...f, tableNo: e.target.value })} autoFocus /></Field>
        <Field label="Capacity"><input className="input" inputMode="numeric" value={f.capacity} onChange={(e) => setF({ ...f, capacity: e.target.value })} /></Field>
        <Field label="Area">
          <select className="input" value={f.areaId} onChange={(e) => setF({ ...f, areaId: e.target.value })}>
            <option value="">No area</option>
            {areas.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </Field>
      </div>
      <div className="mt-3">
        <Field label="Notes"><input className="input" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={busy || !f.tableNo.trim()} onClick={save}>
        {busy ? "Saving…" : "Create table"}
      </button>
    </Modal>
  );
}

function Areas() {
  const { can } = useAuth();
  const { data, reload } = useFetch<{ dining_areas: Area[] }>("/dining-areas");
  const areas = data?.dining_areas ?? [];
  const [openNew, setOpenNew] = useState(false);
  const [name, setName] = useState("");
  const [error, setError] = useState("");

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        {can("hotel_dining_tables.create") && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New area</button>}
      </div>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {areas.map((a) => (
          <div key={a.id} className="card flex items-center justify-between p-3">
            <span className="font-bold">{a.name}</span>
            <Badge>{a.tables_count} table{a.tables_count === 1 ? "" : "s"}</Badge>
          </div>
        ))}
      </div>
      {areas.length === 0 && <Empty text="No dining areas yet — tables can also go unassigned" />}
      {openNew && (
        <Modal open onClose={() => setOpenNew(false)} title="New dining area">
          <Field label="Name *"><input className="input" value={name} onChange={(e) => setName(e.target.value)} autoFocus /></Field>
          <ErrorText error={error} />
          <button
            className="btn-primary mt-4 w-full"
            disabled={!name.trim()}
            onClick={() =>
              post("/dining-areas", { name: name.trim() })
                .then(() => { setOpenNew(false); setName(""); reload(); })
                .catch((e) => setError(e.message))
            }
          >
            Create area
          </button>
        </Modal>
      )}
    </div>
  );
}
