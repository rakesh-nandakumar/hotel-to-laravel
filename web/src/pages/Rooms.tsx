import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Wrench, Search, Plus, Users, BedDouble, Sparkles, UtensilsCrossed, Pencil, Eye, StickyNote, ChevronDown } from "lucide-react";
import { api, post, put } from "../lib/api";
import { useFetch, lkr, toCents, centsToRupees, fmtDate } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor, Tabs } from "../components/ui";
import { getSocket } from "../lib/socket";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type BoardRoom = {
  id: number; number: string; room_type_id: number; floor: string; view: string; amenities: string[]; notes?: string | null;
  status: { code: string };
  room_type: { id: number; name: string };
  branch?: { id: number; name: string } | null;
  occupant: { id: number; code: string; check_out: string; guest: { name: string } } | null;
  pending_housekeeping: boolean;
  open_issues: { id: number; description: string; status: string }[];
};
type RoomType = {
  id: number; name: string; max_occupancy: number; bed_config: string; weekday_rate: number; weekend_rate: number;
  amenities: string[]; item_checklist: string[]; cleaning_checklist: string[];
  seasonal_rates: { id: number; name: string; start_date: string; end_date: string; rate: number }[];
  rooms: { id: number; number: string }[];
};
type Pkg = { id: number; code: string; name: string; description: string; price_per_person_per_night: number; meal_inclusions: string[]; active: boolean };

export default function Rooms() {
  const { can } = useAuth();
  const [tab, setTab] = useState<"board" | "types" | "packages">("board");
  const tabs = [
    { id: "board" as const, label: "Live board" },
    ...(can("hotel_room_types.access") ? [{ id: "types" as const, label: "Room types & rates" }] : []),
    ...(can("hotel_packages.access") ? [{ id: "packages" as const, label: "Packages" }] : []),
  ];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Rooms</h1>
        <Tabs tabs={tabs} active={tab} onChange={setTab} />
      </div>
      {tab === "board" && <Board />}
      {tab === "types" && can("hotel_room_types.access") && <Types />}
      {tab === "packages" && can("hotel_packages.access") && <Packages />}
    </div>
  );
}

// Lookup codes are lowercase snake_case on the wire (App\Support\Lookups\RoomStatus) — display upper-cased.
const STATUS_FILTERS = ["available", "occupied", "dirty", "maintenance"] as const;

/** Manual status transitions allowed from the board (dirty→available and occupied→* are server-blocked / not manual). */
function nextStatuses(current: string): string[] {
  if (current === "available") return ["maintenance", "dirty"];
  if (current === "dirty") return ["maintenance"];
  if (current === "maintenance") return ["dirty", "available"];
  return [];
}

function Board() {
  const { can } = useAuth();
  const { data: roomsData, reload } = useFetch<{ rooms: BoardRoom[] }>("/rooms");
  const { data: typesData } = useFetch<{ room_types: RoomType[] }>("/rooms/types");
  const rooms = roomsData?.rooms;
  const roomTypes = typesData?.room_types;
  const [error, setError] = useState("");
  const [q, setQ] = useState("");
  const [floor, setFloor] = useState("");
  const [type, setType] = useState("");
  const [status, setStatus_] = useState<string>("");
  const [edit, setEdit] = useState<BoardRoom | "new" | null>(null);
  const nav = useNavigate();

  useEffect(() => {
    const s = getSocket();
    s.on("rooms", reload);
    return () => {
      s.off("rooms", reload);
    };
  }, [reload]);

  const all = rooms ?? [];
  const floors = useMemo(() => [...new Set(all.map((r) => r.floor).filter(Boolean))].sort(), [all]);
  const types = useMemo(() => [...new Set(all.map((r) => r.room_type.name))].sort(), [all]);
  const counts = {
    total: all.length,
    available: all.filter((r) => r.status.code === "available").length,
    occupied: all.filter((r) => r.status.code === "occupied").length,
    dirty: all.filter((r) => r.status.code === "dirty").length,
    maintenance: all.filter((r) => r.status.code === "maintenance").length,
  };
  const shown = all.filter((r) => {
    if (floor && r.floor !== floor) return false;
    if (type && r.room_type.name !== type) return false;
    if (status && r.status.code !== status) return false;
    if (q.trim() && !r.number.toLowerCase().includes(q.trim().toLowerCase())) return false;
    return true;
  });

  const setStatus = (id: number, s: string) =>
    put(`/rooms/${id}/status`, { status: s })
      .then(() => {
        setError("");
        reload();
      })
      .catch((e) => setError(e.message));

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        {can("hotel_rooms.create") && <button className="btn-primary" onClick={() => setEdit("new")}><Plus size={16} /> New room</button>}
      </div>
      <ErrorText error={error} />
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <button
          className={clsx("card p-3 text-left transition hover:shadow-md", !status && "ring-2 ring-brand-500")}
          onClick={() => setStatus_("")}
        >
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">All rooms</div>
          <div className="mt-1 text-2xl font-extrabold">{counts.total}</div>
        </button>
        {STATUS_FILTERS.map((s) => (
          <button
            key={s}
            className={clsx("card p-3 text-left transition hover:shadow-md", status === s && "ring-2 ring-brand-500")}
            onClick={() => setStatus_(status === s ? "" : s)}
          >
            <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{s}</div>
            <div className="mt-1 text-2xl font-extrabold">{counts[s]}</div>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-40">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !w-40 !pl-8" placeholder="Room #…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <select className="input !w-36" value={floor} onChange={(e) => setFloor(e.target.value)}>
          <option value="">All floors</option>
          {floors.map((f) => <option key={f} value={f}>{f}</option>)}
        </select>
        <select className="input !w-44" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All room types</option>
          {types.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        {(q || floor || type || status) && (
          <button className="btn-ghost text-xs" onClick={() => { setQ(""); setFloor(""); setType(""); setStatus_(""); }}>Clear filters</button>
        )}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        {shown.map((r) => (
          <div key={r.id} className="card p-3">
            <div className="flex items-start justify-between">
              <div className="text-2xl font-black">{r.number}</div>
              <div className="flex items-center gap-1">
                <Badge color={statusColor(r.status.code)}>{r.status.code.toUpperCase()}</Badge>
                {can("hotel_rooms.edit") && (
                  <button className="btn-ghost !p-1 text-slate-400 hover:text-brand-600" title="Edit room" onClick={() => setEdit(r)}>
                    <Pencil size={13} />
                  </button>
                )}
              </div>
            </div>
            <div className="mt-0.5 truncate text-xs text-slate-500">{r.room_type.name}{r.floor && ` · floor ${r.floor}`}</div>
            {(r.view || r.amenities.length > 0 || r.notes) && (
              <div className="mt-1 flex flex-wrap items-center gap-1 text-[10px] text-slate-400">
                {r.view && <span className="flex items-center gap-0.5"><Eye size={10} /> {r.view}</span>}
                {r.amenities.length > 0 && <span>· {r.amenities.length} amenit{r.amenities.length === 1 ? "y" : "ies"}</span>}
                {r.notes && (
                  <span className="flex items-center gap-0.5 text-amber-600" title={r.notes}>
                    <StickyNote size={10} /> note
                  </span>
                )}
              </div>
            )}
            {r.open_issues.length > 0 && (
              <div className="mt-1.5 flex items-start gap-1 rounded-lg bg-red-50 px-2 py-1 text-[11px] text-red-700" title={r.open_issues.map((i) => i.description).join("; ")}>
                <Wrench size={12} className="mt-0.5 shrink-0" />
                <span className="truncate">{r.open_issues.length === 1 ? r.open_issues[0].description : `${r.open_issues.length} open issues`}</span>
              </div>
            )}
            {r.occupant ? (
              <button className="mt-2 w-full rounded-lg bg-sky-50 px-2 py-1.5 text-left text-xs hover:bg-sky-100" onClick={() => nav(`/reservations/${r.occupant!.id}`)}>
                <div className="truncate font-bold text-sky-900">{r.occupant.guest.name}</div>
                <div className="text-sky-700">out {fmtDate(r.occupant.check_out)}</div>
              </button>
            ) : (
              <div className="mt-2 flex items-center justify-between gap-1">
                {r.status.code === "dirty" && (
                  <span className="truncate text-[11px] font-semibold text-amber-700">Awaiting cleaning{r.pending_housekeeping ? "" : " (no task!)"}</span>
                )}
                {nextStatuses(r.status.code).length > 0 && can("hotel_rooms.edit_status") && (
                  <div className="relative ml-auto shrink-0">
                    <select
                      className="input !h-7 !w-32 !py-0 !pl-2 !pr-6 !text-[11px]"
                      value=""
                      onChange={(e) => e.target.value && setStatus(r.id, e.target.value)}
                    >
                      <option value="">Set status…</option>
                      {nextStatuses(r.status.code).map((s) => <option key={s} value={s}>{s.toUpperCase()}</option>)}
                    </select>
                    <ChevronDown size={11} className="pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400" />
                  </div>
                )}
              </div>
            )}
          </div>
        ))}
      </div>
      {all.length === 0 && <Empty text="Loading rooms…" />}
      {all.length > 0 && shown.length === 0 && <Empty text="No rooms match these filters" />}
      {edit && (
        <RoomEditor
          room={edit === "new" ? null : edit}
          types={roomTypes ?? []}
          onClose={() => { setEdit(null); reload(); }}
        />
      )}
    </div>
  );
}

function RoomEditor({ room, types, onClose }: { room: BoardRoom | null; types: RoomType[]; onClose: () => void }) {
  const [f, setF] = useState({
    number: room?.number ?? "",
    roomTypeId: room ? String(room.room_type_id) : types[0] ? String(types[0].id) : "",
    floor: room?.floor ?? "",
    view: room?.view ?? "",
    amenities: (room?.amenities ?? []).join(", "),
    notes: room?.notes ?? "",
  });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setError("");
    const body = {
      number: f.number.trim(),
      room_type_id: Number(f.roomTypeId),
      floor: f.floor.trim(),
      view: f.view.trim(),
      amenities: f.amenities.split(",").map((s) => s.trim()).filter(Boolean),
      notes: f.notes.trim(),
    };
    try {
      if (room) await put(`/rooms/${room.id}`, body);
      else await post("/rooms", body);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={room ? `Edit Room ${room.number}` : "New room"}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Room number *"><input className="input" value={f.number} onChange={(e) => setF({ ...f, number: e.target.value })} autoFocus /></Field>
        <Field label="Room type *">
          <select className="input" value={f.roomTypeId} onChange={(e) => setF({ ...f, roomTypeId: e.target.value })}>
            {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </Field>
        <Field label="Floor"><input className="input" value={f.floor} onChange={(e) => setF({ ...f, floor: e.target.value })} placeholder="e.g. 2nd" /></Field>
        <Field label="View"><input className="input" value={f.view} onChange={(e) => setF({ ...f, view: e.target.value })} placeholder="e.g. Garden, Mountain" /></Field>
      </div>
      <div className="mt-3">
        <Field label="Amenities (comma-separated)"><input className="input" value={f.amenities} onChange={(e) => setF({ ...f, amenities: e.target.value })} placeholder="e.g. Balcony, Bathtub" /></Field>
      </div>
      <div className="mt-3">
        <Field label="Notes" hint="Internal — visible to staff on the room card"><textarea className="input" rows={2} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={busy || !f.number.trim() || !f.roomTypeId} onClick={save}>
        {busy ? "Saving…" : room ? "Save room" : "Create room"}
      </button>
    </Modal>
  );
}

function Types() {
  const { can } = useAuth();
  const { data: typesData, reload } = useFetch<{ room_types: RoomType[] }>("/rooms/types");
  const [edit, setEdit] = useState<RoomType | "new" | null>(null);
  const all = typesData?.room_types ?? [];
  const totalRooms = all.reduce((s, t) => s + t.rooms.length, 0);
  const avgWeekday = all.length ? Math.round(all.reduce((s, t) => s + t.weekday_rate, 0) / all.length) : 0;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-slate-500">⚠ Rates, occupancy, bed config and amenities are placeholders pending owner confirmation — edit them here (no developer needed).</p>
        {can("hotel_room_types.create") && <button className="btn-primary shrink-0" onClick={() => setEdit("new")}><Plus size={16} /> New room type</button>}
      </div>
      <div className="grid grid-cols-3 gap-3">
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Room types</div>
          <div className="mt-1 text-2xl font-extrabold">{all.length}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Total rooms</div>
          <div className="mt-1 text-2xl font-extrabold">{totalRooms}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Avg weekday rate</div>
          <div className="mt-1 text-2xl font-extrabold text-brand-700">{lkr(avgWeekday)}</div>
        </div>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {all.map((t) => (
          <Card
            key={t.id}
            title={
              <span className="flex items-center gap-2">
                {t.name} <Badge>{t.rooms.length} room{t.rooms.length === 1 ? "" : "s"}</Badge>
              </span>
            }
            actions={can("hotel_room_types.edit") ? <button className="btn-secondary !py-1" onClick={() => setEdit(t)}>Edit</button> : undefined}
          >
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-lg bg-slate-50 px-3 py-2">
                <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Weekday</div>
                <div className="text-base font-extrabold">{lkr(t.weekday_rate)}</div>
              </div>
              <div className="rounded-lg bg-amber-50 px-3 py-2">
                <div className="text-[10px] font-semibold uppercase tracking-wide text-amber-600">Weekend/peak</div>
                <div className="text-base font-extrabold text-amber-700">{lkr(t.weekend_rate)}</div>
              </div>
            </div>
            <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
              <span className="flex items-center gap-1"><Users size={13} /> sleeps {t.max_occupancy}</span>
              <span className="flex items-center gap-1"><BedDouble size={13} /> {t.bed_config}</span>
            </div>
            {t.rooms.length > 0 && <div className="mt-1 text-xs text-slate-400">Rooms: {t.rooms.map((r) => r.number).join(", ")}</div>}
            {t.amenities.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {t.amenities.map((a) => <Badge key={a} color="blue">{a}</Badge>)}
              </div>
            )}
            <div className="mt-2 flex flex-wrap gap-1.5 text-[11px] text-slate-400">
              <span>{t.item_checklist.length} item check{t.item_checklist.length === 1 ? "" : "s"}</span>
              <span>·</span>
              <span>{t.cleaning_checklist.length} cleaning step{t.cleaning_checklist.length === 1 ? "" : "s"}</span>
            </div>
            {t.seasonal_rates.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {t.seasonal_rates.map((s) => (
                  <Badge key={s.id} color="purple">{s.name} {fmtDate(s.start_date)}–{fmtDate(s.end_date)} @ {lkr(s.rate)}</Badge>
                ))}
              </div>
            )}
          </Card>
        ))}
      </div>
      {all.length === 0 && <Empty text="No room types yet" />}
      {edit && <TypeEditor type={edit === "new" ? null : edit} onClose={() => { setEdit(null); reload(); }} />}
    </div>
  );
}

function TypeEditor({ type, onClose }: { type: RoomType | null; onClose: () => void }) {
  const [f, setF] = useState({
    name: type?.name ?? "",
    maxOccupancy: type ? String(type.max_occupancy) : "2",
    bedConfig: type?.bed_config ?? "",
    weekdayRate: type ? centsToRupees(type.weekday_rate) : "",
    weekendRate: type ? centsToRupees(type.weekend_rate) : "",
    amenities: (type?.amenities ?? []).join(", "),
    itemChecklist: (type?.item_checklist ?? []).join("\n"),
    cleaningChecklist: (type?.cleaning_checklist ?? []).join("\n"),
  });
  const [seasonal, setSeasonal] = useState({ name: "", startDate: "", endDate: "", rate: "" });
  const [rates, setRates] = useState(type?.seasonal_rates ?? []); // local copy so add/remove reflect immediately
  const [error, setError] = useState("");

  const save = async () => {
    setError("");
    const body = {
      max_occupancy: parseInt(f.maxOccupancy) || 1,
      bed_config: f.bedConfig,
      weekday_rate: toCents(f.weekdayRate),
      weekend_rate: toCents(f.weekendRate),
      amenities: f.amenities.split(",").map((s) => s.trim()).filter(Boolean),
      item_checklist: f.itemChecklist.split("\n").map((s) => s.trim()).filter(Boolean),
      cleaning_checklist: f.cleaningChecklist.split("\n").map((s) => s.trim()).filter(Boolean),
    };
    try {
      if (type) await put(`/rooms/types/${type.id}`, body);
      else await post("/rooms/types", { ...body, name: f.name.trim() });
      onClose();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  return (
    <Modal open onClose={onClose} title={type ? `Edit ${type.name}` : "New room type"} wide>
      <div className="grid gap-3 sm:grid-cols-2">
        {!type && <Field label="Name *"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus /></Field>}
        <Field label="Weekday rate (LKR/night)"><input className="input" value={f.weekdayRate} onChange={(e) => setF({ ...f, weekdayRate: e.target.value })} /></Field>
        <Field label="Weekend/peak rate (LKR/night)"><input className="input" value={f.weekendRate} onChange={(e) => setF({ ...f, weekendRate: e.target.value })} /></Field>
        <Field label="Max occupancy"><input className="input" value={f.maxOccupancy} onChange={(e) => setF({ ...f, maxOccupancy: e.target.value })} /></Field>
        <Field label="Bed configuration"><input className="input" value={f.bedConfig} onChange={(e) => setF({ ...f, bedConfig: e.target.value })} /></Field>
      </div>
      <div className="mt-3">
        <Field label="Amenities (comma-separated)"><input className="input" value={f.amenities} onChange={(e) => setF({ ...f, amenities: e.target.value })} /></Field>
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <Field label="Room item checklist (one per line)" hint="Verified at check-in/check-out">
          <textarea className="input" rows={8} value={f.itemChecklist} onChange={(e) => setF({ ...f, itemChecklist: e.target.value })} />
        </Field>
        <Field label="Cleaning checklist (one per line)" hint="Must be completed before room can be re-sold">
          <textarea className="input" rows={8} value={f.cleaningChecklist} onChange={(e) => setF({ ...f, cleaningChecklist: e.target.value })} />
        </Field>
      </div>
      {type && (
        <div className="mt-3 rounded-lg bg-slate-50 p-3">
          <div className="label">Add seasonal/peak rate override</div>
          <div className="flex flex-wrap gap-2">
            <input className="input !w-36" placeholder="Name (e.g. Peak)" value={seasonal.name} onChange={(e) => setSeasonal({ ...seasonal, name: e.target.value })} />
            <input className="input !w-36" type="date" value={seasonal.startDate} onChange={(e) => setSeasonal({ ...seasonal, startDate: e.target.value })} />
            <input className="input !w-36" type="date" value={seasonal.endDate} onChange={(e) => setSeasonal({ ...seasonal, endDate: e.target.value })} />
            <input className="input !w-28" placeholder="LKR/night" value={seasonal.rate} onChange={(e) => setSeasonal({ ...seasonal, rate: e.target.value })} />
            <button
              className="btn-secondary"
              disabled={!seasonal.name || !seasonal.startDate || !seasonal.endDate || !seasonal.rate}
              onClick={() =>
                api<{ seasonal_rate: RoomType["seasonal_rates"][number] }>(`/rooms/types/${type.id}/seasonal`, {
                  body: { name: seasonal.name, start_date: seasonal.startDate, end_date: seasonal.endDate, rate: toCents(seasonal.rate) },
                })
                  .then((r) => {
                    setRates([...rates, r.seasonal_rate]);
                    setSeasonal({ name: "", startDate: "", endDate: "", rate: "" });
                  })
                  .catch((e) => setError(e.message))
              }
            >
              Add
            </button>
          </div>
          {rates.map((s) => (
            <div key={s.id} className="mt-1 flex items-center justify-between text-xs">
              <span>{s.name}: {fmtDate(s.start_date)} – {fmtDate(s.end_date)} @ {lkr(s.rate)}</span>
              <button
                className="font-bold text-red-500"
                onClick={() => api(`/rooms/seasonal/${s.id}`, { method: "DELETE" }).then(() => setRates(rates.filter((x) => x.id !== s.id)))}
              >
                remove
              </button>
            </div>
          ))}
        </div>
      )}
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={!type && !f.name.trim()} onClick={save}>
        {type ? "Save room type" : "Create room type"}
      </button>
    </Modal>
  );
}

function Packages() {
  const { can } = useAuth();
  const canEdit = can("hotel_packages.edit");
  const { data: pkgsData, reload } = useFetch<{ packages: Pkg[] }>("/rooms/packages");
  const pkgs = pkgsData?.packages;
  const [error, setError] = useState("");
  const save = (id: number, body: Record<string, unknown>) =>
    put(`/rooms/packages/${id}`, body).then(() => reload()).catch((err) => setError(err.message));

  return (
    <div className="grid gap-3 md:grid-cols-2">
      <ErrorText error={error} />
      {(pkgs ?? []).map((p) => (
        <Card
          key={p.id}
          title={
            <span className="flex items-center gap-2">
              <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><UtensilsCrossed size={14} /></span>
              {p.code} — {p.name}
            </span>
          }
          actions={
            canEdit ? (
              <button
                className={clsx("rounded-full px-2.5 py-1 text-xs font-bold", p.active ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-400")}
                onClick={() => save(p.id, { active: !p.active })}
              >
                {p.active ? "Active" : "Inactive"}
              </button>
            ) : (
              <Badge color={p.active ? "green" : "slate"}>{p.active ? "Active" : "Inactive"}</Badge>
            )
          }
        >
          <Field label="Price per person per night (LKR)">
            <input
              className="input"
              disabled={!canEdit}
              defaultValue={centsToRupees(p.price_per_person_per_night)}
              onBlur={(e) => toCents(e.target.value) !== p.price_per_person_per_night && save(p.id, { price_per_person_per_night: toCents(e.target.value) })}
            />
          </Field>
          <div className="mt-2">
            <span className="label">Meal inclusions (comma-separated)</span>
            <input
              className="input"
              disabled={!canEdit}
              defaultValue={(p.meal_inclusions ?? []).join(", ")}
              onBlur={(e) => save(p.id, { meal_inclusions: e.target.value.split(",").map((s) => s.trim()).filter(Boolean) })}
            />
            {p.meal_inclusions.length > 0 && (
              <div className="mt-1.5 flex flex-wrap gap-1">
                {p.meal_inclusions.map((m) => <Badge key={m} color="green"><Sparkles size={10} className="mr-0.5 inline" />{m}</Badge>)}
              </div>
            )}
          </div>
          <div className="mt-2">
            <span className="label">Description</span>
            <input className="input" disabled={!canEdit} defaultValue={p.description} placeholder="Shown to staff when picking a package" onBlur={(e) => e.target.value !== p.description && save(p.id, { description: e.target.value })} />
          </div>
          <p className="mt-2 text-[11px] text-slate-400">Children under the free-age policy are not charged. Edit and click away to save.</p>
        </Card>
      ))}
      {(pkgs ?? []).length === 0 && <Empty text="No packages configured" />}
    </div>
  );
}
