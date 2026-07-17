import { useState } from "react";
import { Plus, Printer } from "lucide-react";
import { openPdf, post, put } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, centsToRupees, fmtDate, todayStr } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor, Tabs, Pagination } from "../components/ui";
import { SplitPay, ReasonModal } from "./POS";
import { useToast } from "../lib/toast";

type Lookup = { id: number; code: string; name: string };
type Venue = { id: number; name: string; max_capacity: number; facilities: string[]; hourly_rate: number; half_day_rate: number; full_day_rate: number };
type Booking = {
  id: number; code: string; client_name: string; client_phone?: string | null; event_type?: string | null; date: string;
  start_time?: string | null; end_time?: string | null; guest_count: number; status: Lookup;
  seating?: string | null; av_needs?: string | null; decoration?: string | null; catering_by_hotel: boolean; deposit_due: number;
  venue: { id: number; name: string; max_capacity: number };
  folio?: { id: number; invoice_no?: string | null } | null;
  total: number; paid: number; balance: number;
};

export default function Venues() {
  const [tab, setTab] = useState<"bookings" | "calendar" | "venues">("bookings");
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Wedding Halls & Rooftop</h1>
        <Tabs
          tabs={[
            { id: "bookings" as const, label: "Bookings" },
            { id: "calendar" as const, label: "Calendar" },
            { id: "venues" as const, label: "Venues & pricing" },
          ]}
          active={tab}
          onChange={setTab}
        />
      </div>
      {tab === "bookings" && <Bookings />}
      {tab === "calendar" && <VenueCalendar />}
      {tab === "venues" && <VenueList />}
    </div>
  );
}

/** Month calendar of all venue events — spot free dates at a glance. */
function VenueCalendar() {
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
  const { data } = useFetch<{ bookings: Booking[] }>("/venues/bookings/list");
  const bookings = data?.bookings;
  const [selected, setSelected] = useState<Booking | null>(null);

  const first = new Date(`${month}-01T00:00:00`);
  const daysInMonth = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
  const leadBlanks = first.getDay(); // Sunday-first grid
  const active = (bookings ?? []).filter((b) => b.status.code === "confirmed" || b.status.code === "inquiry");
  const byDay = new Map<string, Booking[]>();
  for (const b of active) {
    const key = String(b.date).slice(0, 10);
    byDay.set(key, [...(byDay.get(key) ?? []), b]);
  }
  const shift = (n: number) => {
    const d = new Date(first);
    d.setMonth(d.getMonth() + n);
    setMonth(d.toISOString().slice(0, 7));
  };
  const venueColor = (name: string) =>
    name.includes("Hall 1") ? "bg-purple-500 text-white" : name.includes("Hall 2") ? "bg-sky-500 text-white" : "bg-emerald-600 text-white";

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <button className="btn-secondary !px-2.5" onClick={() => shift(-1)}>←</button>
        <input type="month" className="input !w-44" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} />
        <button className="btn-secondary !px-2.5" onClick={() => shift(1)}>→</button>
        <span className="ml-2 flex flex-wrap gap-2 text-xs">
          <span className="flex items-center gap-1"><span className="h-3 w-3 rounded bg-purple-500" /> Hall 1</span>
          <span className="flex items-center gap-1"><span className="h-3 w-3 rounded bg-sky-500" /> Hall 2</span>
          <span className="flex items-center gap-1"><span className="h-3 w-3 rounded bg-emerald-600" /> Rooftop</span>
          <span className="text-slate-400">faded = inquiry (not confirmed)</span>
        </span>
      </div>
      <div className="card overflow-x-auto p-2">
        <div className="grid grid-cols-7 gap-1" style={{ minWidth: 640 }}>
          {["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map((d) => (
            <div key={d} className="px-1 py-1 text-center text-[10px] font-bold uppercase text-slate-400">{d}</div>
          ))}
          {Array.from({ length: leadBlanks }).map((_, i) => (
            <div key={`b${i}`} className="min-h-20 rounded-lg bg-slate-50/50" />
          ))}
          {Array.from({ length: daysInMonth }).map((_, i) => {
            const dateStr = `${month}-${String(i + 1).padStart(2, "0")}`;
            const todays = byDay.get(dateStr) ?? [];
            const isToday = dateStr === new Date().toISOString().slice(0, 10);
            return (
              <div key={dateStr} className={`min-h-20 rounded-lg border p-1 ${isToday ? "border-brand-500 bg-brand-50" : "border-slate-100"}`}>
                <div className={`text-right text-[10px] font-bold ${isToday ? "text-brand-700" : "text-slate-400"}`}>{i + 1}</div>
                <div className="space-y-0.5">
                  {todays.map((b) => (
                    <button
                      key={b.id}
                      onClick={() => setSelected(b)}
                      className={`block w-full truncate rounded px-1 py-0.5 text-left text-[9px] font-bold leading-tight ${venueColor(b.venue.name)} ${b.status.code === "inquiry" ? "opacity-50" : ""}`}
                      title={`${b.code} · ${b.venue.name} · ${b.client_name} (${b.status.code.toUpperCase()})`}
                    >
                      {b.client_name}
                    </button>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </div>
      {selected && <BookingModal b={selected} onClose={() => setSelected(null)} />}
    </div>
  );
}

function VenueList() {
  const { data, reload } = useFetch<{ venues: Venue[] }>("/venues");
  const venues = data?.venues;
  const [error, setError] = useState("");
  return (
    <div className="grid gap-3 md:grid-cols-3">
      <ErrorText error={error} />
      {(venues ?? []).map((v) => (
        <Card key={v.id} title={`${v.name} · seats ${v.max_capacity}`}>
          <div className="space-y-2 text-sm">
            {(["hourly_rate", "half_day_rate", "full_day_rate"] as const).map((k) => (
              <div key={k} className="flex items-center gap-2">
                <span className="w-20 text-xs text-slate-500">{k === "hourly_rate" ? "Hourly" : k === "half_day_rate" ? "Half-day" : "Full-day"}</span>
                <input
                  className="input"
                  defaultValue={centsToRupees(v[k])}
                  onBlur={(e) => put(`/venues/${v.id}`, { [k]: toCents(e.target.value) }).then(reload).catch((err) => setError(err.message))}
                />
              </div>
            ))}
            <div>
              <span className="label">Facilities (comma-separated)</span>
              <input
                className="input"
                defaultValue={(v.facilities ?? []).join(", ")}
                onBlur={(e) => put(`/venues/${v.id}`, { facilities: e.target.value.split(",").map((s) => s.trim()).filter(Boolean) }).catch((err) => setError(err.message))}
              />
            </div>
            <p className="text-[11px] text-slate-400">Pricing & facilities are editable settings (owner instruction §9). Edit and click away to save.</p>
          </div>
        </Card>
      ))}
    </div>
  );
}

function Bookings() {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = usePagedFetch<Booking>(`/venues/bookings/list?page=${page}&page_size=${pageSize}`, "bookings", [page, pageSize]);
  const bookings = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const [selected, setSelected] = useState<Booking | null>(null);

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New venue booking</button>
      </div>
      <ErrorText error={error} />
      <div className="card overflow-x-auto">
        <table className="w-full min-w-[760px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Code</th><th className="th">Venue</th><th className="th">Client</th><th className="th">Event</th><th className="th">Date</th><th className="th">Guests</th><th className="th">Status</th><th className="th text-right">Balance</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {(bookings ?? []).map((b) => (
              <tr key={b.id} className="cursor-pointer hover:bg-slate-50" onClick={() => setSelected(b)}>
                <td className="td font-bold">{b.code}</td>
                <td className="td">{b.venue.name}</td>
                <td className="td">{b.client_name}</td>
                <td className="td text-xs">{b.event_type ?? "—"}</td>
                <td className="td whitespace-nowrap">{fmtDate(b.date)}</td>
                <td className="td">{b.guest_count}</td>
                <td className="td"><Badge color={statusColor(b.status.code.toUpperCase())}>{b.status.code.toUpperCase()}</Badge></td>
                <td className="td text-right font-semibold">{lkr(b.balance)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {(bookings ?? []).length === 0 && <Empty text="No venue bookings yet" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openNew && <NewBooking onClose={() => setOpenNew(false)} onDone={() => { setOpenNew(false); reload(); }} />}
      {selected && <BookingModal b={selected} onClose={() => { setSelected(null); reload(); }} />}
    </div>
  );
}

function NewBooking({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const { data } = useFetch<{ venues: Venue[] }>("/venues");
  const venues = data?.venues;
  const [f, setF] = useState({
    venueId: "", clientName: "", clientPhone: "", clientEmail: "", eventType: "Wedding",
    date: todayStr(14), startTime: "09:00", endTime: "17:00", durationType: "full_day", hours: "4",
    guestCount: "100", seating: "", avNeeds: "", decoration: "", cateringByHotel: false, notes: "", confirm: true,
  });
  const [extras, setExtras] = useState<{ description: string; amount: string }[]>([]);
  const [error, setError] = useState("");
  const venue = (venues ?? []).find((v) => String(v.id) === f.venueId);
  const rental = venue
    ? f.durationType === "full_day" ? venue.full_day_rate : f.durationType === "half_day" ? venue.half_day_rate : Math.round(venue.hourly_rate * (parseFloat(f.hours) || 1))
    : 0;

  return (
    <Modal open onClose={onClose} title="New venue booking (rental separate from catering)" wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Venue">
          <select className="input" value={f.venueId} onChange={(e) => setF({ ...f, venueId: e.target.value })}>
            <option value="">Select…</option>
            {(venues ?? []).map((v) => <option key={v.id} value={v.id}>{v.name} (max {v.max_capacity})</option>)}
          </select>
        </Field>
        <Field label="Event type"><input className="input" value={f.eventType} onChange={(e) => setF({ ...f, eventType: e.target.value })} /></Field>
        <Field label="Client name *"><input className="input" value={f.clientName} onChange={(e) => setF({ ...f, clientName: e.target.value })} /></Field>
        <Field label="Client phone"><input className="input" value={f.clientPhone} onChange={(e) => setF({ ...f, clientPhone: e.target.value })} /></Field>
        <Field label="Client email"><input className="input" value={f.clientEmail} onChange={(e) => setF({ ...f, clientEmail: e.target.value })} /></Field>
        <Field label="Date"><input type="date" className="input" value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} /></Field>
        <Field label="Pricing (editable per venue)">
          <select className="input" value={f.durationType} onChange={(e) => setF({ ...f, durationType: e.target.value })}>
            <option value="full_day">Full day{venue ? ` — ${lkr(venue.full_day_rate)}` : ""}</option>
            <option value="half_day">Half day{venue ? ` — ${lkr(venue.half_day_rate)}` : ""}</option>
            <option value="hourly">Hourly{venue ? ` — ${lkr(venue.hourly_rate)}/h` : ""}</option>
          </select>
        </Field>
        {f.durationType === "hourly" ? (
          <Field label="Hours"><input className="input" value={f.hours} onChange={(e) => setF({ ...f, hours: e.target.value })} /></Field>
        ) : (
          <div className="grid grid-cols-2 gap-2">
            <Field label="Start"><input type="time" className="input" value={f.startTime} onChange={(e) => setF({ ...f, startTime: e.target.value })} /></Field>
            <Field label="End"><input type="time" className="input" value={f.endTime} onChange={(e) => setF({ ...f, endTime: e.target.value })} /></Field>
          </div>
        )}
        <Field label="Expected guest count"><input className="input" value={f.guestCount} onChange={(e) => setF({ ...f, guestCount: e.target.value })} /></Field>
        <Field label="Seating arrangement"><input className="input" value={f.seating} onChange={(e) => setF({ ...f, seating: e.target.value })} placeholder="e.g. round tables of 10" /></Field>
        <Field label="AV equipment needed"><input className="input" value={f.avNeeds} onChange={(e) => setF({ ...f, avNeeds: e.target.value })} /></Field>
        <Field label="Decoration requests"><input className="input" value={f.decoration} onChange={(e) => setF({ ...f, decoration: e.target.value })} /></Field>
      </div>
      <label className="mt-3 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={f.cateringByHotel} onChange={(e) => setF({ ...f, cateringByHotel: e.target.checked })} />
        Hotel provides catering (otherwise client brings own chefs — rental only)
      </label>

      <div className="mt-3">
        <div className="label">Optional extras (catering / decoration / add-ons on the invoice)</div>
        {extras.map((x, i) => (
          <div key={i} className="mb-1.5 flex gap-2">
            <input className="input" placeholder="Description" value={x.description} onChange={(e) => setExtras(extras.map((y, j) => (j === i ? { ...y, description: e.target.value } : y)))} />
            <input className="input !w-32" placeholder="LKR" value={x.amount} onChange={(e) => setExtras(extras.map((y, j) => (j === i ? { ...y, amount: e.target.value } : y)))} />
            <button className="btn-ghost !px-2" onClick={() => setExtras(extras.filter((_, j) => j !== i))}>✕</button>
          </div>
        ))}
        <button className="btn-secondary w-full" onClick={() => setExtras([...extras, { description: "", amount: "" }])}>+ Add extra</button>
      </div>

      <div className="mt-3 rounded-xl bg-slate-50 p-3 text-sm">
        <div className="flex justify-between font-bold"><span>Rental</span><span>{lkr(rental)}</span></div>
        <div className="flex justify-between"><span>Extras</span><span>{lkr(extras.reduce((s, x) => s + toCents(x.amount), 0))}</span></div>
      </div>
      <label className="mt-2 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={f.confirm} onChange={(e) => setF({ ...f, confirm: e.target.checked })} />
        Confirm now (unchecked = record as inquiry)
      </label>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-3 w-full !py-3"
        disabled={!f.venueId || !f.clientName.trim()}
        onClick={() =>
          post<{ message: string; booking: Booking }>("/venues/bookings", {
            venue_id: Number(f.venueId),
            client_name: f.clientName,
            client_phone: f.clientPhone,
            client_email: f.clientEmail,
            event_type: f.eventType,
            date: f.date,
            start_time: f.startTime,
            end_time: f.endTime,
            duration_type: f.durationType,
            hours: parseFloat(f.hours) || undefined,
            guest_count: parseInt(f.guestCount) || 0,
            seating: f.seating,
            av_needs: f.avNeeds,
            decoration: f.decoration,
            catering_by_hotel: f.cateringByHotel,
            notes: f.notes,
            confirm: f.confirm,
            extras: extras.filter((x) => x.description && toCents(x.amount) > 0).map((x) => ({ description: x.description, amount: toCents(x.amount) })),
          })
            .then((r) => {
              toast.success(`Venue booking ${r.booking.code} ${f.confirm ? "confirmed" : "recorded as inquiry"}`);
              onDone();
            })
            .catch((e) => setError(e.message))
        }
      >
        Create booking (sends confirmation)
      </button>
    </Modal>
  );
}

function BookingModal({ b, onClose }: { b: Booking; onClose: () => void }) {
  const toast = useToast();
  const [error, setError] = useState("");
  const [payOpen, setPayOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [state] = useState(b);

  const act = (fn: () => Promise<unknown>, successMsg?: string) =>
    fn()
      .then(() => {
        if (successMsg) toast.success(successMsg, `${state.code} — ${state.venue.name}`);
        onClose();
      })
      .catch((e) => setError((e as Error).message));

  return (
    <Modal open onClose={onClose} title={`${state.code} — ${state.venue.name}`} wide>
      <div className="grid gap-2 text-sm sm:grid-cols-2">
        <div><b>Client:</b> {state.client_name} {state.client_phone && `· ${state.client_phone}`}</div>
        <div><b>Event:</b> {state.event_type ?? "—"} on {fmtDate(state.date)} {state.start_time && `(${state.start_time}–${state.end_time})`}</div>
        <div><b>Guests:</b> {state.guest_count} / {state.venue.max_capacity}</div>
        <div><b>Catering:</b> {state.catering_by_hotel ? "By hotel" : "Client's own chefs (rental only)"}</div>
        {state.seating && <div><b>Seating:</b> {state.seating}</div>}
        {state.av_needs && <div><b>AV:</b> {state.av_needs}</div>}
        {state.decoration && <div><b>Decoration:</b> {state.decoration}</div>}
        <div><b>Status:</b> <Badge color={statusColor(state.status.code.toUpperCase())}>{state.status.code.toUpperCase()}</Badge></div>
      </div>
      <div className="mt-3 space-y-1 rounded-xl bg-slate-50 p-3 text-sm">
        <div className="flex justify-between font-bold"><span>Invoice total (separate VNU invoice type)</span><span>{lkr(state.total)}</span></div>
        <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(state.paid)}</span></div>
        <div className="flex justify-between font-extrabold"><span>Balance</span><span>{lkr(state.balance)}</span></div>
        <div className="text-xs text-slate-500">Required deposit: {lkr(state.deposit_due)}</div>
      </div>
      <ErrorText error={error} />
      <div className="mt-4 flex flex-wrap gap-2">
        {state.status.code === "inquiry" && (
          <button className="btn-primary" onClick={() => act(() => post(`/venues/bookings/${state.id}/confirm`), "Venue booking confirmed")}>Confirm booking</button>
        )}
        {(state.status.code === "inquiry" || state.status.code === "confirmed") && state.folio && (
          <>
            <button className="btn-primary" onClick={() => setPayOpen(true)}>Take payment / deposit</button>
            <button className="btn-secondary" onClick={() => act(() => post(`/venues/bookings/${state.id}/complete`), "Event completed — invoice generated")}>Complete event → invoice</button>
            <button className="btn-danger" onClick={() => setCancelOpen(true)}>Cancel</button>
          </>
        )}
        {state.folio && (
          <button className="btn-secondary" onClick={() => openPdf(`/folios/${state.folio!.id}/invoice?format=a4`)}>
            <Printer size={15} /> Invoice {state.folio.invoice_no ?? "(proforma)"}
          </button>
        )}
      </div>

      {payOpen && state.folio && (
        <SplitPay
          due={Math.max(state.balance, 0)}
          onDone={async (payments) => {
            try {
              for (const p of payments) {
                await post(`/folios/${state.folio!.id}/payments`, {
                  method: p.method.toLowerCase(),
                  amount: p.amount,
                  reference: p.reference,
                  kind: state.paid === 0 ? "deposit" : "payment",
                  idempotency_key: crypto.randomUUID(),
                });
              }
              setPayOpen(false);
              onClose();
            } catch (e) {
              setError((e as Error).message);
            }
          }}
          onClose={() => setPayOpen(false)}
        />
      )}
      {cancelOpen && (
        <ReasonModal
          title="Cancel venue booking"
          onSubmit={(reason) => act(() => post(`/venues/bookings/${state.id}/cancel`, { reason }), "Venue booking cancelled")}
          onClose={() => setCancelOpen(false)}
        />
      )}
    </Modal>
  );
}
