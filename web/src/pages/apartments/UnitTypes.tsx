import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { api, post, put } from "../../lib/api";
import { useFetch, lkr, toCents, centsToRupees, fmtDate } from "../../lib/util";
import { Card, Empty, ErrorText, Field, Modal } from "../../components/ui";
import { useAuth } from "../../lib/auth";

type SeasonalRate = { id: number; name: string; start_date: string; end_date: string; rate: number };
type UnitType = {
  id: number; name: string; max_occupancy: number; bedrooms: number; bathrooms: number; size_sqft: number | null;
  amenities: string[] | null; nightly_rate: number | null; weekly_rate: number | null; monthly_rate: number | null;
  min_nights: number; cleaning_fee: number; extra_guest_fee: number;
  item_checklist: string[] | null; cleaning_checklist: string[] | null;
  units: { id: number; unit_no: string }[]; seasonal_rates: SeasonalRate[];
};

const linesToArray = (s: string) => s.split("\n").map((x) => x.trim()).filter(Boolean);

export default function UnitTypes() {
  const { can } = useAuth();
  const { data, reload } = useFetch<{ unit_types: UnitType[] }>("/apartments/unit-types");
  const unitTypes = data?.unit_types ?? [];
  const [editing, setEditing] = useState<UnitType | null>(null);
  const [openNew, setOpenNew] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Apartment Unit Types</h1>
        {can("apartment_unit_types.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New unit type</button>
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {unitTypes.map((t) => (
          <Card
            key={t.id}
            title={t.name}
            actions={can("apartment_unit_types.edit") ? <button className="btn-secondary !py-1 text-xs" onClick={() => setEditing(t)}>Edit</button> : undefined}
          >
            <div className="space-y-1 text-sm">
              <div>{t.bedrooms} bed · {t.bathrooms} bath · sleeps {t.max_occupancy}{t.size_sqft ? ` · ${t.size_sqft} sqft` : ""}</div>
              <div className="text-slate-500">
                {t.nightly_rate != null && <div>Nightly: {lkr(t.nightly_rate)}</div>}
                {t.weekly_rate != null && <div>Weekly: {lkr(t.weekly_rate)}</div>}
                {t.monthly_rate != null && <div>Monthly: {lkr(t.monthly_rate)}</div>}
              </div>
              <div className="text-xs text-slate-400">{t.units.length} unit(s) · min stay {t.min_nights} night(s)</div>
            </div>
          </Card>
        ))}
        {unitTypes.length === 0 && <Empty text="No unit types yet" />}
      </div>

      {editing && <UnitTypeEditor unitType={editing} onClose={() => { setEditing(null); reload(); }} />}
      {openNew && <UnitTypeEditor onClose={() => { setOpenNew(false); reload(); }} />}
    </div>
  );
}

function UnitTypeEditor({ unitType, onClose }: { unitType?: UnitType; onClose: () => void }) {
  const { can } = useAuth();
  const [f, setF] = useState({
    name: unitType?.name ?? "",
    maxOccupancy: String(unitType?.max_occupancy ?? 2),
    bedrooms: String(unitType?.bedrooms ?? 1),
    bathrooms: String(unitType?.bathrooms ?? 1),
    sizeSqft: unitType?.size_sqft != null ? String(unitType.size_sqft) : "",
    amenities: (unitType?.amenities ?? []).join("\n"),
    nightlyRate: unitType?.nightly_rate != null ? centsToRupees(unitType.nightly_rate) : "",
    weeklyRate: unitType?.weekly_rate != null ? centsToRupees(unitType.weekly_rate) : "",
    monthlyRate: unitType?.monthly_rate != null ? centsToRupees(unitType.monthly_rate) : "",
    minNights: String(unitType?.min_nights ?? 1),
    cleaningFee: unitType ? centsToRupees(unitType.cleaning_fee) : "0",
    extraGuestFee: unitType ? centsToRupees(unitType.extra_guest_fee) : "0",
    itemChecklist: (unitType?.item_checklist ?? []).join("\n"),
    cleaningChecklist: (unitType?.cleaning_checklist ?? []).join("\n"),
  });
  const [error, setError] = useState("");
  const [seasonalName, setSeasonalName] = useState("");
  const [seasonalStart, setSeasonalStart] = useState("");
  const [seasonalEnd, setSeasonalEnd] = useState("");
  const [seasonalRate, setSeasonalRate] = useState("");
  const [seasonalError, setSeasonalError] = useState("");
  const [seasonalRates, setSeasonalRates] = useState(unitType?.seasonal_rates ?? []);

  const addSeasonalRate = () => {
    if (!unitType) return;
    post<{ seasonal_rate: SeasonalRate }>(`/apartments/unit-types/${unitType.id}/seasonal`, {
      name: seasonalName, start_date: seasonalStart, end_date: seasonalEnd, rate: toCents(seasonalRate),
    })
      .then((res) => {
        setSeasonalRates((prev) => [...prev, res.seasonal_rate]);
        setSeasonalName(""); setSeasonalStart(""); setSeasonalEnd(""); setSeasonalRate(""); setSeasonalError("");
      })
      .catch((e) => setSeasonalError(e.message));
  };

  const removeSeasonalRate = (id: number) => {
    api(`/apartments/unit-types/seasonal/${id}`, { method: "DELETE" }).then(() => {
      setSeasonalRates((prev) => prev.filter((r) => r.id !== id));
    });
  };

  return (
    <Modal open onClose={onClose} title={unitType ? "Edit unit type" : "New unit type"} wide>
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Name *"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Bedrooms"><input className="input" type="number" min={0} value={f.bedrooms} onChange={(e) => setF({ ...f, bedrooms: e.target.value })} /></Field>
        <Field label="Bathrooms"><input className="input" type="number" min={0} value={f.bathrooms} onChange={(e) => setF({ ...f, bathrooms: e.target.value })} /></Field>
        <Field label="Max occupancy"><input className="input" type="number" min={1} value={f.maxOccupancy} onChange={(e) => setF({ ...f, maxOccupancy: e.target.value })} /></Field>
        <Field label="Size (sqft)"><input className="input" type="number" min={0} value={f.sizeSqft} onChange={(e) => setF({ ...f, sizeSqft: e.target.value })} /></Field>
        <Field label="Minimum stay (nights)"><input className="input" type="number" min={1} value={f.minNights} onChange={(e) => setF({ ...f, minNights: e.target.value })} /></Field>
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-3">
        <Field label="Nightly rate (LKR)" hint="Leave blank for sale-only types"><input className="input" value={f.nightlyRate} onChange={(e) => setF({ ...f, nightlyRate: e.target.value })} /></Field>
        <Field label="Weekly rate (LKR)"><input className="input" value={f.weeklyRate} onChange={(e) => setF({ ...f, weeklyRate: e.target.value })} /></Field>
        <Field label="Monthly rate (LKR)"><input className="input" value={f.monthlyRate} onChange={(e) => setF({ ...f, monthlyRate: e.target.value })} /></Field>
        <Field label="Cleaning fee (LKR)"><input className="input" value={f.cleaningFee} onChange={(e) => setF({ ...f, cleaningFee: e.target.value })} /></Field>
        <Field label="Extra guest fee (LKR/night)"><input className="input" value={f.extraGuestFee} onChange={(e) => setF({ ...f, extraGuestFee: e.target.value })} /></Field>
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-3">
        <Field label="Amenities" hint="One per line"><textarea className="input" rows={3} value={f.amenities} onChange={(e) => setF({ ...f, amenities: e.target.value })} /></Field>
        <Field label="Move-in/out checklist" hint="One item per line"><textarea className="input" rows={3} value={f.itemChecklist} onChange={(e) => setF({ ...f, itemChecklist: e.target.value })} /></Field>
        <Field label="Cleaning checklist" hint="One item per line"><textarea className="input" rows={3} value={f.cleaningChecklist} onChange={(e) => setF({ ...f, cleaningChecklist: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.name.trim()}
        onClick={() => {
          const body = {
            name: f.name,
            max_occupancy: parseInt(f.maxOccupancy) || 1,
            bedrooms: parseInt(f.bedrooms) || 0,
            bathrooms: parseInt(f.bathrooms) || 0,
            size_sqft: f.sizeSqft ? parseInt(f.sizeSqft) : null,
            amenities: linesToArray(f.amenities),
            nightly_rate: f.nightlyRate ? toCents(f.nightlyRate) : null,
            weekly_rate: f.weeklyRate ? toCents(f.weeklyRate) : null,
            monthly_rate: f.monthlyRate ? toCents(f.monthlyRate) : null,
            min_nights: parseInt(f.minNights) || 1,
            cleaning_fee: toCents(f.cleaningFee || "0"),
            extra_guest_fee: toCents(f.extraGuestFee || "0"),
            item_checklist: linesToArray(f.itemChecklist),
            cleaning_checklist: linesToArray(f.cleaningChecklist),
          };
          (unitType ? put(`/apartments/unit-types/${unitType.id}`, body) : post("/apartments/unit-types", body))
            .then(onClose)
            .catch((e) => setError(e.message));
        }}
      >
        Save
      </button>

      {unitType && (
        <Card title="Seasonal rates" className="mt-4">
          <div className="space-y-1 text-sm">
            {seasonalRates.map((r) => (
              <div key={r.id} className="flex items-center justify-between gap-2 border-b border-slate-50 py-1">
                <span>{r.name} — {fmtDate(r.start_date)} → {fmtDate(r.end_date)} — {lkr(r.rate)}/night</span>
                {can("apartment_unit_types.edit") && (
                  <button className="text-red-500 hover:text-red-700" onClick={() => removeSeasonalRate(r.id)}><Trash2 size={14} /></button>
                )}
              </div>
            ))}
            {seasonalRates.length === 0 && <div className="text-xs text-slate-400">No seasonal overrides.</div>}
          </div>
          {can("apartment_unit_types.edit") && (
            <div className="mt-3 grid gap-2 sm:grid-cols-5">
              <input className="input" placeholder="Name" value={seasonalName} onChange={(e) => setSeasonalName(e.target.value)} />
              <input className="input" type="date" value={seasonalStart} onChange={(e) => setSeasonalStart(e.target.value)} />
              <input className="input" type="date" value={seasonalEnd} onChange={(e) => setSeasonalEnd(e.target.value)} />
              <input className="input" placeholder="Rate/night (LKR)" value={seasonalRate} onChange={(e) => setSeasonalRate(e.target.value)} />
              <button className="btn-secondary" onClick={addSeasonalRate} disabled={!seasonalName || !seasonalStart || !seasonalEnd || !seasonalRate}>Add</button>
            </div>
          )}
          <ErrorText error={seasonalError} />
        </Card>
      )}
    </Modal>
  );
}
