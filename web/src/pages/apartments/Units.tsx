import { useState } from "react";
import { Plus } from "lucide-react";
import { post, put } from "../../lib/api";
import { useFetch, lkr, toCents } from "../../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, statusColor } from "../../components/ui";
import { useAuth } from "../../lib/auth";

type Lookup = { id: number; code: string; name: string };
type Property = { id: number; name: string };
type UnitType = { id: number; name: string; nightly_rate: number | null };
type Unit = {
  id: number; unit_no: string; floor: string | null; view: string | null;
  property: Property | null; unit_type: UnitType; listing_type: Lookup; status: Lookup; sale_price: number | null;
};

const STATUS_OPTIONS = ["available", "maintenance", "blocked", "off_market"];

export default function Units() {
  const { can } = useAuth();
  const [status, setStatus] = useState("");
  const [listingType, setListingType] = useState("");
  const { data, reload } = useFetch<{ units: Unit[] }>(
    `/apartments/units?status=${status}&listing_type=${listingType}`,
    [status, listingType],
  );
  const units = data?.units ?? [];
  const [editing, setEditing] = useState<Unit | null>(null);
  const [openNew, setOpenNew] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Units</h1>
        {can("apartment_units.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New unit</button>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        <select className="input !w-48" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          {STATUS_OPTIONS.concat(["occupied", "reserved", "sold"]).map((s) => <option key={s} value={s}>{s.replace("_", " ")}</option>)}
        </select>
        <select className="input !w-48" value={listingType} onChange={(e) => setListingType(e.target.value)}>
          <option value="">Rental & sale</option>
          <option value="rental">Rental only</option>
          <option value="sale">For sale only</option>
        </select>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px]">
          <thead className="border-b border-slate-100">
            <tr>
              <th className="th">Unit</th>
              <th className="th">Type</th>
              <th className="th">Property</th>
              <th className="th">Listing</th>
              <th className="th">Status</th>
              <th className="th text-right">Rate / Price</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {units.map((u) => (
              <tr
                key={u.id}
                className={can("apartment_units.edit") ? "cursor-pointer hover:bg-slate-50" : ""}
                onClick={can("apartment_units.edit") ? () => setEditing(u) : undefined}
              >
                <td className="td font-semibold">{u.unit_no}{u.floor ? <span className="ml-1 text-xs text-slate-400">Fl. {u.floor}</span> : null}</td>
                <td className="td text-xs">{u.unit_type.name}</td>
                <td className="td text-xs">{u.property?.name ?? "—"}</td>
                <td className="td"><Badge color={u.listing_type.code === "sale" ? "purple" : "blue"}>{u.listing_type.name}</Badge></td>
                <td className="td"><Badge color={statusColor(u.status.code.toUpperCase())}>{u.status.name}</Badge></td>
                <td className="td text-right">
                  {u.listing_type.code === "sale" ? lkr(u.sale_price) : (u.unit_type.nightly_rate != null ? `${lkr(u.unit_type.nightly_rate)}/night` : "—")}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {units.length === 0 && <Empty text="No units found" />}
      </div>

      {editing && <UnitEditor unit={editing} onClose={() => { setEditing(null); reload(); }} />}
      {openNew && <UnitEditor onClose={() => { setOpenNew(false); reload(); }} />}
    </div>
  );
}

function UnitEditor({ unit, onClose }: { unit?: Unit; onClose: () => void }) {
  const { can } = useAuth();
  const { data: propData } = useFetch<{ properties: Property[] }>("/apartments/properties");
  const { data: typeData } = useFetch<{ unit_types: UnitType[] }>("/apartments/unit-types");
  const [f, setF] = useState({
    unitNo: unit?.unit_no ?? "",
    propertyId: unit?.property?.id ? String(unit.property.id) : "",
    unitTypeId: unit?.unit_type.id ? String(unit.unit_type.id) : "",
    floor: unit?.floor ?? "",
    view: unit?.view ?? "",
    listingType: unit?.listing_type.code ?? "rental",
    salePrice: unit?.sale_price != null ? String(unit.sale_price / 100) : "",
  });
  const [error, setError] = useState("");
  const [statusError, setStatusError] = useState("");

  const changeStatus = (newStatus: string) => {
    if (!unit) return;
    put(`/apartments/units/${unit.id}/status`, { status: newStatus })
      .then(onClose)
      .catch((e) => setStatusError(e.message));
  };

  return (
    <Modal open onClose={onClose} title={unit ? `Edit ${unit.unit_no}` : "New unit"}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Unit number *"><input className="input" value={f.unitNo} onChange={(e) => setF({ ...f, unitNo: e.target.value })} /></Field>
        <Field label="Floor"><input className="input" value={f.floor} onChange={(e) => setF({ ...f, floor: e.target.value })} /></Field>
        <Field label="Property">
          <select className="input" value={f.propertyId} onChange={(e) => setF({ ...f, propertyId: e.target.value })}>
            <option value="">— none —</option>
            {(propData?.properties ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </Field>
        <Field label="Unit type *">
          <select className="input" value={f.unitTypeId} onChange={(e) => setF({ ...f, unitTypeId: e.target.value })}>
            <option value="">— select —</option>
            {(typeData?.unit_types ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </Field>
        <Field label="View"><input className="input" value={f.view} onChange={(e) => setF({ ...f, view: e.target.value })} /></Field>
        <Field label="Listing type">
          <select className="input" value={f.listingType} onChange={(e) => setF({ ...f, listingType: e.target.value })}>
            <option value="rental">Rental</option>
            <option value="sale">For sale</option>
          </select>
        </Field>
        {f.listingType === "sale" && (
          <Field label="Sale price (LKR) *"><input className="input" value={f.salePrice} onChange={(e) => setF({ ...f, salePrice: e.target.value })} /></Field>
        )}
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.unitNo.trim() || !f.unitTypeId || (f.listingType === "sale" && !f.salePrice)}
        onClick={() => {
          const body = {
            unit_no: f.unitNo,
            property_id: f.propertyId ? parseInt(f.propertyId) : null,
            unit_type_id: parseInt(f.unitTypeId),
            floor: f.floor || null,
            view: f.view || null,
            listing_type: f.listingType,
            sale_price: f.listingType === "sale" ? toCents(f.salePrice) : null,
          };
          (unit ? put(`/apartments/units/${unit.id}`, body) : post("/apartments/units", body))
            .then(onClose)
            .catch((e) => setError(e.message));
        }}
      >
        Save
      </button>

      {unit && can("apartment_units.edit_status") && (
        <div className="mt-4 border-t border-slate-100 pt-3">
          <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Change status</div>
          <div className="flex flex-wrap gap-2">
            {STATUS_OPTIONS.map((s) => (
              <button key={s} className="btn-secondary !py-1 text-xs" disabled={unit.status.code === s} onClick={() => changeStatus(s)}>
                {s.replace("_", " ")}
              </button>
            ))}
          </div>
          <p className="mt-1 text-xs text-slate-400">Occupied / reserved / sold are set automatically by bookings, leases, and sales.</p>
          <ErrorText error={statusError} />
        </div>
      )}
    </Modal>
  );
}
