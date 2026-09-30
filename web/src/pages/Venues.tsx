import { useState, useEffect } from "react";
import { Plus, Printer } from "lucide-react";
import { printDocument, post, put } from "../lib/api";
import { useFetch, usePagedFetch, lkr, toCents, centsToRupees, fmtDate, todayStr } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor, Tabs, Pagination } from "../components/ui";
import { SplitPay, ReasonModal } from "./POS";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

type Lookup = { id: number; code: string; name: string };
type Venue = {
  id: number; name: string; max_capacity: number; facilities: string[];
  hourly_rate: number; half_day_rate: number; full_day_rate: number;
  // Enhanced venue fields
  hall_type?: 'luxury' | 'basic' | null;
  luxury_hall_charge?: number;
  basic_hall_charge?: number;
  per_plate_starting_price?: number;
  hall_only_per_person?: number;
  dj_included?: boolean;
  bar_charges_included?: boolean;
  byod_allowed?: boolean;
  use_package_pricing?: boolean;
  default_charge_defaults?: { [key: string]: number } | null;
};
type Booking = {
  id: number; code: string; client_name: string; client_phone?: string | null; event_type?: string | null; date: string;
  start_time?: string | null; end_time?: string | null; guest_count: number; status: Lookup;
  seating?: string | null; av_needs?: string | null; decoration?: string | null; catering_by_hotel: boolean; deposit_due: number;
  venue: { id: number; name: string; max_capacity: number };
  folio?: { id: number; invoice_no?: string | null } | null;
  total: number; paid: number; balance: number;
  // Enhanced booking fields
  package_type?: 'hall_only' | 'hall_food' | null;
  per_plate_price?: number | null;
  hall_charge_used?: number | null;
  service_charge_pct?: number;
  byod_selected?: boolean;
  dj_required?: boolean;
  advance_payment?: number;
};

export default function Venues() {
  const { can } = useAuth();
  const canBookings = can("hotel_venue_bookings.access");
  const [tab, setTab] = useState<"bookings" | "calendar" | "venues">(canBookings ? "bookings" : "venues");
  const tabs = [
    ...(canBookings ? [{ id: "bookings" as const, label: "Bookings" }, { id: "calendar" as const, label: "Calendar" }] : []),
    { id: "venues" as const, label: "Venues & pricing" },
  ];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Wedding Halls & Rooftop</h1>
        <Tabs tabs={tabs} active={tab} onChange={setTab} />
      </div>
      {tab === "bookings" && canBookings && <Bookings />}
      {tab === "calendar" && canBookings && <VenueCalendar />}
      {tab === "venues" && <VenueList />}
    </div>
  );
}

/** Month calendar of all venue events — spot free dates at a glance. */
function VenueCalendar() {
  const { can } = useAuth();
  const canView = can("hotel_venue_bookings.view");
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
                      onClick={canView ? () => setSelected(b) : undefined}
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
  const { can } = useAuth();
  const canEdit = can("hotel_venues.edit");
  const canCreate = can("hotel_venues.create");
  const toast = useToast();
  const { data, reload, error, loading } = useFetch<{ venues: Venue[] }>("/venues");
  const venues = data?.venues;
  const [localError, setLocalError] = useState("");
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [venueChanges, setVenueChanges] = useState<Record<number, any>>({});

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        {canCreate && <button className="btn-primary" onClick={() => setShowCreateModal(true)}><Plus size={16} /> New venue</button>}
      </div>
      <div className="grid gap-3 md:grid-cols-3">
        <ErrorText error={error || localError} />
        {loading && <div className="text-slate-500">Loading venues...</div>}
        {!loading && (!venues || venues.length === 0) && <div className="text-slate-500">No venues found</div>}
        {(venues ?? []).map((v) => (
          <Card key={v.id} title={v.name}>
            <div className="space-y-2 text-sm">
              {/* Legacy pricing - only show when package pricing is NOT enabled */}
              {!v.use_package_pricing && (["hourly_rate", "half_day_rate", "full_day_rate"] as const).map((k) => (
                <div key={k} className="flex items-center gap-2">
                  <span className="w-20 text-xs text-slate-500">{k === "hourly_rate" ? "Hourly" : k === "half_day_rate" ? "Half-day" : "Full-day"}</span>
                  <input
                    className="input"
                    disabled={!canEdit}
                    defaultValue={centsToRupees(v[k])}
                    onBlur={(e) => put(`/venues/${v.id}`, { [k]: toCents(e.target.value) }).then(reload).catch((err) => setLocalError(err.message))}
                  />
                </div>
              ))}

              {/* Package pricing - only show when package pricing is enabled */}
              {v.use_package_pricing && (
                <>
                  <div className="flex items-center gap-2">
                    <span className="w-20 text-xs text-slate-500">Hall Charge</span>
                    <input
                      className="input"
                      disabled={!canEdit}
                      defaultValue={centsToRupees(v.hall_type === "luxury" ? (v.luxury_hall_charge ?? 4500000) : (v.basic_hall_charge ?? 4000000))}
                      onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], hall_charge: e.target.value } })}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="w-20 text-xs text-slate-500">Plate</span>
                    <input
                      className="input"
                      disabled={!canEdit}
                      defaultValue={centsToRupees(v.per_plate_starting_price || 1950)}
                      onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], per_plate: e.target.value } })}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="w-20 text-xs text-slate-500">Hall Only</span>
                    <input
                      className="input"
                      disabled={!canEdit}
                      defaultValue={centsToRupees(v.hall_only_per_person || 500)}
                      onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], hall_only: e.target.value } })}
                    />
                  </div>
                  {/* Service inclusions */}
                  {canEdit && (
                    <>
                      <label className="flex items-center gap-2 text-xs">
                        <input
                          type="checkbox"
                          defaultChecked={v.dj_included}
                          onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], dj_included: e.currentTarget.checked } })}
                        />
                        DJ Included
                      </label>
                      <label className="flex items-center gap-2 text-xs">
                        <input
                          type="checkbox"
                          defaultChecked={v.bar_charges_included}
                          onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], bar_charges_included: e.currentTarget.checked } })}
                        />
                        Bar Charges Included
                      </label>
                      <label className="flex items-center gap-2 text-xs">
                        <input
                          type="checkbox"
                          defaultChecked={v.byod_allowed}
                          onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], byod_allowed: e.currentTarget.checked } })}
                        />
                        BYOD Allowed
                      </label>
                    </>
                  )}
                </>
              )}

              {/* Package pricing toggle - always show when canEdit */}
              {canEdit && (
                <label className="flex items-center gap-2 text-xs font-medium">
                  <input
                    type="checkbox"
                    defaultChecked={v.use_package_pricing}
                    onChange={(e) => setVenueChanges({ ...venueChanges, [v.id]: { ...venueChanges[v.id], use_package_pricing: e.currentTarget.checked } })}
                  />
                  Use Package Pricing
                </label>
              )}

              {canEdit && (
                <button
                  className="btn-primary w-full mt-2"
                  onClick={async () => {
                    const changes = venueChanges[v.id] || {};
                    const payload: any = {};
                    if (changes.hall_charge !== undefined) {
                      payload[v.hall_type === "luxury" ? "luxury_hall_charge" : "basic_hall_charge"] = toCents(changes.hall_charge);
                    }
                    if (changes.per_plate !== undefined) {
                      payload.per_plate_starting_price = toCents(changes.per_plate);
                    }
                    if (changes.hall_only !== undefined) {
                      payload.hall_only_per_person = toCents(changes.hall_only);
                    }
                    if (changes.dj_included !== undefined) {
                      payload.dj_included = changes.dj_included;
                    }
                    if (changes.bar_charges_included !== undefined) {
                      payload.bar_charges_included = changes.bar_charges_included;
                    }
                    if (changes.byod_allowed !== undefined) {
                      payload.byod_allowed = changes.byod_allowed;
                    }
                    if (changes.use_package_pricing !== undefined) {
                      payload.use_package_pricing = changes.use_package_pricing;
                    }
                    if (Object.keys(payload).length === 0) {
                      toast.info("No changes to save");
                      return;
                    }
                    try {
                      await put(`/venues/${v.id}`, payload);
                      toast.success("Venue pricing saved successfully");
                      setVenueChanges({ ...venueChanges, [v.id]: undefined });
                      reload();
                    } catch (e) {
                      toast.error((e as Error).message);
                    }
                  }}
                >
                  Save Changes
                </button>
              )}

              <p className="text-[11px] text-slate-400">Pricing is editable settings (owner instruction §9). Edit and click Save Changes.</p>
            </div>
          </Card>
        ))}
      </div>
      {showCreateModal && <CreateVenueModal onClose={() => setShowCreateModal(false)} onDone={() => { setShowCreateModal(false); reload(); }} />}
    </div>
  );
}

function Bookings() {
  const { can } = useAuth();
  const canView = can("hotel_venue_bookings.view");
  const toast = useToast();
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = usePagedFetch<Booking>(`/venues/bookings/list?page=${page}&page_size=${pageSize}`, "bookings", [page, pageSize]);
  const bookings = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const [selected, setSelected] = useState<Booking | null>(null);
  const [printingInvoice, setPrintingInvoice] = useState<number | null>(null);
  const [payOpen, setPayOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);

  const act = (fn: () => Promise<unknown>, successMsg?: string) =>
    fn()
      .then(() => {
        if (successMsg) toast.success(successMsg);
        reload();
      })
      .catch((e) => toast.error((e as Error).message));

  const handlePrintInvoice = async (b: Booking) => {
    setPrintingInvoice(b.id);
    try {
      await printDocument(`/folios/${b.folio!.id}/invoice?format=a4`);
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setPrintingInvoice(null);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        {can("hotel_venue_bookings.create") && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New venue booking</button>}
      </div>
      <ErrorText error={error} />
      <div className="card overflow-x-auto">
        <table className="w-full min-w-[800px]">
          <thead className="bg-gradient-to-r from-slate-50 to-slate-100 border-b-2 border-slate-200">
            <tr>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Code</th>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Venue</th>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Client</th>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Event</th>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Date</th>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Guests</th>
              <th className="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase tracking-wider">Status</th>
              <th className="px-4 py-3 text-right text-xs font-bold text-slate-600 uppercase tracking-wider">Balance</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {(bookings ?? []).map((b) => (
              <>
                <tr key={`${b.id}-main`} className="hover:bg-slate-50 transition-colors duration-150">
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-brand-100 text-brand-700">
                      {b.code}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-medium text-slate-700">{b.venue.name}</td>
                  <td className="px-4 py-3 text-slate-600">{b.client_name}</td>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">
                      {b.event_type ?? "—"}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600 font-medium">{fmtDate(b.date)}</td>
                  <td className="px-4 py-3">
                    <span className="inline-flex items-center justify-center w-12 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold">
                      {b.guest_count}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <Badge color={statusColor(b.status.code.toUpperCase())}>{b.status.code.toUpperCase()}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <span className="inline-block px-3 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold text-sm">
                      {lkr(b.balance)}
                    </span>
                  </td>
                </tr>
                <tr className="bg-slate-50/50">
                  <td colSpan={8} className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      {canView && (
                        <button key={`view-${b.id}`} className="inline-flex items-center px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition-all duration-150 shadow-sm" onClick={() => setSelected(b)}>
                          👁 View
                        </button>
                      )}
                      {b.status.code === "inquiry" && can("hotel_venue_bookings.confirm") && (
                        <button key={`confirm-${b.id}`} className="inline-flex items-center px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-xs font-medium hover:bg-emerald-600 transition-all duration-150 shadow-sm" onClick={() => act(() => post(`/venues/bookings/${b.id}/confirm`), "Venue booking confirmed")}>
                          ✓ Confirm booking
                        </button>
                      )}
                      {(b.status.code === "inquiry" || b.status.code === "confirmed") && b.folio && (
                        <>
                          {can("hotel_folios.payment") && (
                            <button key={`payment-${b.id}`} className="inline-flex items-center px-3 py-1.5 rounded-lg bg-blue-500 text-white text-xs font-medium hover:bg-blue-600 transition-all duration-150 shadow-sm" onClick={() => { setSelected(b); setPayOpen(true); }}>
                              💰 Take payment / deposit
                            </button>
                          )}
                          {can("hotel_venue_bookings.complete") && (
                            <button key={`complete-${b.id}`} className="inline-flex items-center px-3 py-1.5 rounded-lg bg-purple-500 text-white text-xs font-medium hover:bg-purple-600 transition-all duration-150 shadow-sm" onClick={() => act(() => post(`/venues/bookings/${b.id}/complete`), "Event completed — invoice generated")}>
                              📋 Complete event → invoice
                            </button>
                          )}
                          {can("hotel_venue_bookings.cancel") && (
                            <button key={`cancel-${b.id}`} className="inline-flex items-center px-3 py-1.5 rounded-lg bg-red-500 text-white text-xs font-medium hover:bg-red-600 transition-all duration-150 shadow-sm" onClick={() => { setSelected(b); setCancelOpen(true); }}>
                              ✕ Cancel
                            </button>
                          )}
                        </>
                      )}
                      {b.folio && can("hotel_folios.invoice") && (
                        <button
                          key={`invoice-${b.id}`}
                          className="inline-flex items-center px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition-all duration-150 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                          onClick={() => handlePrintInvoice(b)}
                          disabled={printingInvoice === b.id}
                        >
                          {printingInvoice === b.id ? (
                            <>
                              <span className="animate-spin mr-1">⏳</span> Loading...
                            </>
                          ) : (
                            <>
                              <Printer size={12} className="mr-1" /> Invoice {b.folio!.invoice_no ?? "(proforma)"}
                            </>
                          )}
                        </button>
                      )}
                      <button
                        key={`booking-details-${b.id}`}
                        className="inline-flex items-center px-3 py-1.5 rounded-lg bg-indigo-500 text-white text-xs font-medium hover:bg-indigo-600 transition-all duration-150 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                        onClick={() => printDocument(`/venues/bookings/${b.id}/print?format=a4`)}
                      >
                        <Printer size={12} className="mr-1" /> Booking Details
                      </button>
                    </div>
                  </td>
                </tr>
              </>
            ))}
          </tbody>
        </table>
        {(bookings ?? []).length === 0 && <Empty text="No venue bookings yet" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openNew && <NewBooking onClose={() => setOpenNew(false)} onDone={() => { setOpenNew(false); reload(); }} />}
      {selected && <BookingModal b={selected} onClose={() => { setSelected(null); setPayOpen(false); setCancelOpen(false); reload(); }} />}
      {selected && payOpen && selected.folio && (
        <SplitPay
          due={Math.max(selected.balance, 0)}
          onDone={async (payments) => {
            try {
              for (const p of payments) {
                await post(`/folios/${selected.folio!.id}/payments`, {
                  method: p.method.toLowerCase(),
                  amount: toCents(p.amount),
                  kind: "payment",
                  reason: "Venue booking payment",
                });
              }
              toast.success("Payment recorded successfully");
              setPayOpen(false);
              reload();
            } catch (e) {
              toast.error((e as Error).message);
            }
          }}
          onClose={() => setPayOpen(false)}
        />
      )}
      {selected && cancelOpen && (
        <ReasonModal
          title="Cancel Venue Booking"
          onSubmit={async (reason) => {
            try {
              await post(`/venues/bookings/${selected.id}/cancel`, { reason, refund_method: "cash" });
              toast.success("Booking cancelled successfully");
              setCancelOpen(false);
              setSelected(null);
              reload();
            } catch (e) {
              toast.error((e as Error).message);
            }
          }}
          onClose={() => setCancelOpen(false)}
        />
      )}
    </div>
  );
}

function NewBooking({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const { data } = useFetch<{ venues: Venue[] }>("/venues");
  const venues = data?.venues;
  const [f, setF] = useState({
    venueId: "", clientName: "", clientPhone: "", clientEmail: "", eventType: "Wedding",
    date: todayStr(14), startTime: "09:00", endTime: "17:00",
    guestCount: "100", items: "", cateringByHotel: false, notes: "", confirm: true,
    // Package pricing fields (only model - legacy removed)
    packageType: "hall_food",
    perPlatePrice: "1950",
    hallOnlyRate: "500",
    serviceChargePct: "10",
    byodSelected: false,
    djRequired: true,
    splitPayment: false,
    advancePayment: "",
    advancePaymentMethod: "cash",
    profitMargin: "20000",
  });
  const [venueExtras, setVenueExtras] = useState<{ description: string; amount: string; charge_type: string; is_percentage: boolean; enabled: boolean; isCustom: boolean }[]>([
    { description: "AC", amount: "12000", charge_type: "ac", is_percentage: false, enabled: false, isCustom: false },
    { description: "Table", amount: "10000", charge_type: "table", is_percentage: false, enabled: false, isCustom: false },
    { description: "Cleaning Staff", amount: "2500", charge_type: "cleaning_staff", is_percentage: false, enabled: false, isCustom: false },
    { description: "Service Supply", amount: "6000", charge_type: "service_supply", is_percentage: false, enabled: false, isCustom: false },
    { description: "Water", amount: "2000", charge_type: "water", is_percentage: false, enabled: false, isCustom: false },
    { description: "Light", amount: "7500", charge_type: "light", is_percentage: false, enabled: false, isCustom: false },
    { description: "DJ", amount: "11000", charge_type: "dj", is_percentage: false, enabled: false, isCustom: false },
    { description: "Water (Cleaning)", amount: "6000", charge_type: "water_cleaning", is_percentage: false, enabled: false, isCustom: false },
    { description: "Extra Kitchen", amount: "0", charge_type: "extra_kitchen", is_percentage: false, enabled: false, isCustom: false },
    { description: "Other", amount: "1000", charge_type: "other", is_percentage: false, enabled: false, isCustom: false },
  ]);
  const [customCharge, setCustomCharge] = useState({ description: "", amount: "" });
  const [splitPaymentMethods, setSplitPaymentMethods] = useState<{ method: string; amount: string }[]>([{ method: "cash", amount: "" }, { method: "card", amount: "" }]);

  const isFormValid = !!f.venueId && !!f.clientName && !(f.splitPayment && f.advancePayment && splitPaymentMethods.reduce((sum, pm) => sum + (parseInt(pm.amount) || 0), 0) !== (parseInt(f.advancePayment) || 0));
  const [error, setError] = useState("");
  const venue = (venues ?? []).find((v) => String(v.id) === f.venueId);

  // Update venue extras when venue changes to use hall-specific defaults
  useEffect(() => {
    if (venue && venue.default_charge_defaults) {
      const defaults = venue.default_charge_defaults;
      setVenueExtras([
        { description: "AC", amount: centsToRupees(defaults.ac || 1200000), charge_type: "ac", is_percentage: false, enabled: false, isCustom: false },
        { description: "Table", amount: centsToRupees(defaults.table || 1000000), charge_type: "table", is_percentage: false, enabled: false, isCustom: false },
        { description: "Cleaning Staff", amount: centsToRupees(defaults.cleaning_staff || 250000), charge_type: "cleaning_staff", is_percentage: false, enabled: false, isCustom: false },
        { description: "Service Supply", amount: centsToRupees(defaults.service_supply || 600000), charge_type: "service_supply", is_percentage: false, enabled: false, isCustom: false },
        { description: "Water", amount: centsToRupees(defaults.water || 200000), charge_type: "water", is_percentage: false, enabled: false, isCustom: false },
        { description: "Light", amount: centsToRupees(defaults.light || 750000), charge_type: "light", is_percentage: false, enabled: false, isCustom: false },
        { description: "DJ", amount: centsToRupees(defaults.dj || 1100000), charge_type: "dj", is_percentage: false, enabled: false, isCustom: false },
        { description: "Water (Cleaning)", amount: centsToRupees(defaults.water_cleaning || 600000), charge_type: "water_cleaning", is_percentage: false, enabled: false, isCustom: false },
        { description: "Extra Kitchen", amount: centsToRupees(defaults.extra_kitchen || 0), charge_type: "extra_kitchen", is_percentage: false, enabled: false, isCustom: false },
        { description: "Other", amount: centsToRupees(defaults.other || 100000), charge_type: "other", is_percentage: false, enabled: false, isCustom: false },
      ]);
      // Pre-fill hall only rate from venue
      const venueHallOnlyRate = venue.hall_only_per_person || 50000;
      setF(prev => ({ ...prev, hallOnlyRate: centsToRupees(venueHallOnlyRate) }));
    }
  }, [venue?.id]);
  
  // Calculate pricing based on package model only (legacy removed)
  const calculatePricing = () => {
    if (!venue) return { rental: 0, foodCost: 0, serviceCharge: 0, total: 0 };

    const guestCount = parseInt(f.guestCount) || 0;

    if (f.packageType === "hall_only") {
      // Use editable hall only rate
      const hallOnlyRate = f.hallOnlyRate ? toCents(f.hallOnlyRate) : (venue.hall_only_per_person || 50000);
      const hallCharge = hallOnlyRate * guestCount;
      const serviceCharge = Math.round(hallCharge * (parseFloat(f.serviceChargePct) || 10) / 100);
      return { rental: hallCharge, foodCost: 0, serviceCharge, total: hallCharge + serviceCharge };
    } else {
      // Hall + Food: use fixed venue hall charge
      const hallCharge = venue.hall_type === "luxury" ? (venue.luxury_hall_charge ?? 4500000) : (venue.basic_hall_charge ?? 4000000);
      const perPlatePrice = f.perPlatePrice ? toCents(f.perPlatePrice) : (venue.per_plate_starting_price || 195000);
      const foodCost = perPlatePrice * guestCount;
      const baseTotal = hallCharge + foodCost;
      const serviceCharge = Math.round(baseTotal * (parseFloat(f.serviceChargePct) || 10) / 100);
      return { rental: hallCharge, foodCost, serviceCharge, total: baseTotal + serviceCharge };
    }
  };
  
  const pricing = calculatePricing();
  const venueExtrasTotal = venueExtras.filter((x) => x.enabled || x.isCustom).reduce((sum, x) => sum + toCents(x.amount), 0);

  return (
    <Modal open onClose={onClose} title="New venue booking (rental separate from catering)" wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Venue">
          <select className="input" value={f.venueId} onChange={(e) => setF({ ...f, venueId: e.target.value })}>
            <option value="">Select…</option>
            {(venues ?? []).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </select>
        </Field>
        <Field label="Event type"><input className="input" value={f.eventType} onChange={(e) => setF({ ...f, eventType: e.target.value })} /></Field>
        <Field label="Client name *"><input className="input" value={f.clientName} onChange={(e) => setF({ ...f, clientName: e.target.value })} /></Field>
        <Field label="Client phone"><input className="input" value={f.clientPhone} onChange={(e) => setF({ ...f, clientPhone: e.target.value })} /></Field>
        <Field label="Client email"><input className="input" value={f.clientEmail} onChange={(e) => setF({ ...f, clientEmail: e.target.value })} /></Field>
        <Field label="Date"><input type="date" className="input" value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} /></Field>

        {/* Package Type Selection - Radio Buttons */}
        <div className="col-span-2">
          <label className="label">Package Type</label>
          <div className="flex gap-4">
            <label className="flex items-center gap-2">
              <input type="radio" name="packageType" value="hall_food" checked={f.packageType === "hall_food"} onChange={(e) => setF({ ...f, packageType: e.target.value })} />
              Hall + Food
            </label>
            <label className="flex items-center gap-2">
              <input type="radio" name="packageType" value="hall_only" checked={f.packageType === "hall_only"} onChange={(e) => setF({ ...f, packageType: e.target.value })} />
              Hall Only
            </label>
          </div>
        </div>

        {f.packageType === "hall_food" ? (
          <>
            <Field label="Per Plate Price (LKR)">
              <input
                className="input"
                type="number"
                value={f.perPlatePrice}
                onChange={(e) => setF({ ...f, perPlatePrice: e.target.value })}
                placeholder={venue ? centsToRupees(venue.per_plate_starting_price || 195000) : "1950"}
              />
            </Field>
            {venue && (
              <div className="text-xs text-slate-500">
                Hall charge: {lkr(venue.hall_type === "luxury" ? (venue.luxury_hall_charge ?? 4500000) : (venue.basic_hall_charge ?? 4000000))}
              </div>
            )}
          </>
        ) : (
          <>
            <Field label="Hall Only Rate (LKR per person)">
              <input
                className="input"
                type="number"
                value={f.hallOnlyRate}
                onChange={(e) => setF({ ...f, hallOnlyRate: e.target.value })}
                placeholder={venue ? centsToRupees(venue.hall_only_per_person || 50000) : "500"}
              />
            </Field>
          </>
        )}
        <Field label="Service Charge %">
          <input className="input" type="number" value={f.serviceChargePct} onChange={(e) => setF({ ...f, serviceChargePct: e.target.value })} />
        </Field>

        <Field label="Expected guest count"><input className="input" value={f.guestCount} onChange={(e) => setF({ ...f, guestCount: e.target.value })} /></Field>
        <Field label="NOTES"><textarea className="input" value={f.items} onChange={(e) => setF({ ...f, items: e.target.value })} placeholder="Seating arrangement, AV equipment, decoration requests" rows={3} /></Field>
        {/* BYOD option */}
        {venue?.byod_allowed && (
          <label className="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" checked={f.byodSelected} onChange={(e) => setF({ ...f, byodSelected: e.target.checked })} />
            BYOD (Bring Your Own Drink)
          </label>
        )}
      </div>
      
      <label className="mt-3 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={f.cateringByHotel} onChange={(e) => setF({ ...f, cateringByHotel: e.target.checked })} />
        Hotel provides catering (otherwise client brings own chefs — rental only)
      </label>

      {/* Package pricing extras */}
      <div className="mt-3">
        <div className="label">Additional Charges (check to enable)</div>
        <div className="grid grid-cols-1 gap-2">
          {venueExtras.map((x, i) => (
            <div key={`${x.description}-${i}`} className="flex items-center gap-2 p-2 border rounded-lg">
              {!x.isCustom && (
                <input
                  type="checkbox"
                  checked={x.enabled}
                  onChange={(e) => setVenueExtras(venueExtras.map((y, j) => (j === i ? { ...y, enabled: e.target.checked } : y)))}
                />
              )}
              <span className="text-sm flex-1">{x.description}</span>
              <input
                className="input !w-28 !py-1.5 text-sm"
                type="number"
                value={x.amount}
                onChange={(e) => setVenueExtras(venueExtras.map((y, j) => (j === i ? { ...y, amount: e.target.value } : y)))}
                disabled={!x.enabled && !x.isCustom}
              />
              {x.isCustom && (
                <button className="btn-ghost !px-2 text-red-500" onClick={() => setVenueExtras(venueExtras.filter((_, j) => j !== i))}>✕</button>
              )}
            </div>
          ))}
        </div>
        <div className="mt-2 pt-2 border-t">
          <div className="label">Add Custom Charge</div>
          <div className="flex gap-2">
            <input
              className="input flex-1"
              placeholder="Description"
              value={customCharge.description}
              onChange={(e) => setCustomCharge({ ...customCharge, description: e.target.value })}
            />
            <input
              className="input !w-36 !py-1.5 text-sm"
              type="number"
              placeholder="LKR"
              value={customCharge.amount}
              onChange={(e) => setCustomCharge({ ...customCharge, amount: e.target.value })}
            />
            <button
              className="btn-secondary"
              onClick={() => {
                if (customCharge.description && customCharge.amount) {
                  setVenueExtras([...venueExtras, { ...customCharge, enabled: true, charge_type: "other", is_percentage: false, isCustom: true }]);
                  setCustomCharge({ description: "", amount: "" });
                }
              }}
            >
              Add
            </button>
          </div>
        </div>
      </div>

      {/* DJ, Payment, and Profit Margin fields */}
      <div className="mt-3 space-y-4">
        <label className="flex items-center gap-2 text-sm font-semibold">
          <input type="checkbox" checked={f.djRequired} onChange={(e) => setF({ ...f, djRequired: e.target.checked })} />
          DJ Required (included in charge)
        </label>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <span className="label">ADVANCE PAYMENT (LKR)</span>
            <input className="input" type="number" value={f.advancePayment} onChange={(e) => setF({ ...f, advancePayment: e.target.value })} placeholder="Optional" />
          </div>
          <div>
            <span className="label">PROFIT MARGIN (LKR)</span>
            <input className="input" type="number" value={f.profitMargin} onChange={(e) => setF({ ...f, profitMargin: e.target.value })} />
          </div>
        </div>
        {f.advancePayment && (
          <>
            <label className="flex items-center gap-2 text-sm font-semibold">
              <input type="checkbox" checked={f.splitPayment} onChange={(e) => {
                setF({ ...f, splitPayment: e.target.checked });
                if (e.target.checked) {
                  setSplitPaymentMethods([{ method: "cash", amount: "" }, { method: "card", amount: "" }]);
                } else {
                  setSplitPaymentMethods([{ method: "cash", amount: "" }]);
                }
              }} />
              Split Payment
            </label>
            {f.splitPayment ? (
              <div className="flex gap-4 flex-wrap">
                {splitPaymentMethods.map((pm, i) => (
                  <div key={i} className="flex-1 min-w-[200px] space-y-2">
                    <div className="flex justify-between items-center">
                      <span className="label">Payment Method {i + 1}</span>
                      {splitPaymentMethods.length > 1 && (
                        <button className="btn-ghost !px-2 text-xs" onClick={() => setSplitPaymentMethods(splitPaymentMethods.filter((_, j) => j !== i))}>✕</button>
                      )}
                    </div>
                    <select className="input" value={pm.method} onChange={(e) => setSplitPaymentMethods(splitPaymentMethods.map((x, j) => j === i ? { ...x, method: e.target.value } : x))}>
                      <option value="cash">Cash</option>
                      <option value="card">Card</option>
                      <option value="credit_card">Credit Card</option>
                      <option value="debit_card">Debit Card</option>
                      <option value="bank_transfer">Bank Transfer</option>
                      <option value="cheque">Cheque</option>
                      <option value="online">Online Payment</option>
                    </select>
                    <input className="input" type="number" placeholder="Amount (LKR)" value={pm.amount} onChange={(e) => setSplitPaymentMethods(splitPaymentMethods.map((x, j) => j === i ? { ...x, amount: e.target.value } : x))} />
                  </div>
                ))}
                <button className="btn-secondary w-full mt-2" onClick={() => setSplitPaymentMethods([...splitPaymentMethods, { method: "cash", amount: "" }])} disabled={splitPaymentMethods.length >= 3}>
                  + Add Payment Method
                </button>
                {splitPaymentMethods.length >= 3 && <div className="flex justify-center w-full mt-1"><p className="text-xs bg-yellow-100 text-yellow-800 py-1 px-2 rounded font-semibold">Maximum 3 payment methods allowed</p></div>}
                <div className="w-full mt-2 p-2 bg-slate-50 rounded text-sm">
                  <div className="flex justify-between">
                    <span>Total split payment:</span>
                    <span className={splitPaymentMethods.reduce((sum, pm) => sum + (parseInt(pm.amount) || 0), 0) === (parseInt(f.advancePayment) || 0) ? "text-green-600 font-semibold" : "text-red-600 font-semibold"}>
                      {splitPaymentMethods.reduce((sum, pm) => sum + (parseInt(pm.amount) || 0), 0).toLocaleString()} LKR
                    </span>
                  </div>
                  <div className="flex justify-between text-gray-500">
                    <span>Advance payment:</span>
                    <span>{(parseInt(f.advancePayment) || 0).toLocaleString()} LKR</span>
                  </div>
                </div>
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="label">PAYMENT METHOD</span>
                  <select className="input" value={f.advancePaymentMethod} onChange={(e) => setF({ ...f, advancePaymentMethod: e.target.value })}>
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="credit_card">Credit Card</option>
                    <option value="debit_card">Debit Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="online">Online Payment</option>
                  </select>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      <div className="mt-3 rounded-xl bg-slate-50 p-3 text-sm">
        {f.packageType === "hall_food" && (
          <>
            <div className="flex justify-between"><span>Hall Charge</span><span>{lkr(pricing.rental)}</span></div>
            <div className="flex justify-between"><span>Food & Beverage ({parseInt(f.guestCount) || 0} plates)</span><span>{lkr(pricing.foodCost)}</span></div>
          </>
        )}
        {f.packageType === "hall_only" && (
          <div className="flex justify-between font-bold"><span>Hall Only ({parseInt(f.guestCount) || 0} persons)</span><span>{lkr(pricing.rental)}</span></div>
        )}
        {pricing.serviceCharge > 0 && (
          <div className="flex justify-between"><span>Service Charge ({f.serviceChargePct}%)</span><span>{lkr(pricing.serviceCharge)}</span></div>
        )}
        {parseInt(f.profitMargin) > 0 && (
          <div className="flex justify-between"><span>Profit Margin</span><span>{lkr(toCents(f.profitMargin))}</span></div>
        )}
        <div className="flex justify-between"><span>Base Total</span><span>{lkr(pricing.total)}</span></div>
        <div className="flex justify-between"><span>Additional Charges</span><span>{lkr(venueExtrasTotal)}</span></div>
        <div className="flex justify-between font-bold border-t pt-2"><span>Total</span><span>{lkr(pricing.total + venueExtrasTotal + toCents(f.profitMargin))}</span></div>
        {parseInt(f.advancePayment) > 0 && (
          <div className="flex justify-between text-emerald-700"><span>Advance Payment</span><span>{lkr(toCents(f.advancePayment))}</span></div>
        )}
      </div>
      
      <label className="mt-2 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={f.confirm} onChange={(e) => setF({ ...f, confirm: e.target.checked })} />
        Confirm now (unchecked = record as inquiry)
      </label>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-3 w-full !py-3"
        disabled={!isFormValid}
        onClick={() => {
          const payload: any = {
            venue_id: Number(f.venueId),
            client_name: f.clientName,
            client_phone: f.clientPhone,
            client_email: f.clientEmail,
            event_type: f.eventType,
            date: f.date,
            start_time: f.startTime,
            end_time: f.endTime,
            guest_count: parseInt(f.guestCount) || 0,
            items: f.items,
            catering_by_hotel: f.cateringByHotel,
            notes: f.notes,
            confirm: f.confirm,
            // Package pricing fields (always used - legacy removed)
            use_package_pricing: true,
            package_type: f.packageType,
            per_plate_price: f.perPlatePrice ? toCents(f.perPlatePrice) : null,
            hall_charge_used: f.packageType === "hall_food" && venue ? (venue.hall_type === "luxury" ? venue.luxury_hall_charge : venue.basic_hall_charge) : null,
            hall_only_per_person: f.packageType === "hall_only" ? (f.hallOnlyRate ? toCents(f.hallOnlyRate) : (venue?.hall_only_per_person ?? null)) : null,
            service_charge_pct: parseInt(f.serviceChargePct) || 10,
            byod_selected: f.byodSelected,
            dj_required: f.djRequired,
            advance_payment: f.advancePayment ? toCents(f.advancePayment) : 0,
            advance_payment_method: f.splitPayment ? JSON.stringify(splitPaymentMethods) : f.advancePaymentMethod,
            profit_margin: f.profitMargin ? toCents(f.profitMargin) : 0,
            venue_extras: venueExtras.filter((x) => (x.enabled || x.isCustom) && toCents(x.amount) > 0).map((x) => ({
              description: x.description,
              amount: toCents(x.amount),
              charge_type: x.charge_type,
              is_percentage: x.is_percentage,
            }))
          };

          post<{ message: string; booking: Booking }>("/venues/bookings", payload)
            .then((r) => {
              toast.success(`Venue booking ${r.booking.code} ${f.confirm ? "confirmed" : "recorded as inquiry"}`);
              onDone();
            })
            .catch((e) => setError(e.message));
        }}
      >
        Create booking (sends confirmation)
      </button>
    </Modal>
  );
}

function BookingModal({ b, onClose }: { b: Booking; onClose: () => void }) {
  const toast = useToast();
  const { can } = useAuth();
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
        {state.status.code === "inquiry" && can("hotel_venue_bookings.confirm") && (
          <button className="btn-primary" onClick={() => act(() => post(`/venues/bookings/${state.id}/confirm`), "Venue booking confirmed")}>Confirm booking</button>
        )}
        {(state.status.code === "inquiry" || state.status.code === "confirmed") && state.folio && (
          <>
            {can("hotel_folios.payment") && <button className="btn-primary" onClick={() => setPayOpen(true)}>Take payment / deposit</button>}
            {can("hotel_venue_bookings.complete") && <button className="btn-secondary" onClick={() => act(() => post(`/venues/bookings/${state.id}/complete`), "Event completed — invoice generated")}>Complete event → invoice</button>}
            {can("hotel_venue_bookings.cancel") && <button className="btn-danger" onClick={() => setCancelOpen(true)}>Cancel</button>}
          </>
        )}
        {state.folio && can("hotel_folios.invoice") && (
          <button className="btn-secondary" onClick={() => printDocument(`/folios/${state.folio!.id}/invoice?format=a4`)}>
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

function CreateVenueModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [f, setF] = useState({
    name: "", maxCapacity: "", hourlyRate: "", halfDayRate: "", fullDayRate: ""
  });
  const [error, setError] = useState("");

  return (
    <Modal open onClose={onClose} title="Create new venue">
      <div className="space-y-3">
        <Field label="Venue name *">
          <input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="e.g. Grand Ballroom" />
        </Field>
        <Field label="Max capacity *">
          <input className="input" type="number" value={f.maxCapacity} onChange={(e) => setF({ ...f, maxCapacity: e.target.value })} placeholder="e.g. 300" />
        </Field>
        <Field label="Hourly rate (LKR) *">
          <input className="input" type="number" value={f.hourlyRate} onChange={(e) => setF({ ...f, hourlyRate: e.target.value })} placeholder="e.g. 15000" />
        </Field>
        <Field label="Half-day rate (LKR) *">
          <input className="input" type="number" value={f.halfDayRate} onChange={(e) => setF({ ...f, halfDayRate: e.target.value })} placeholder="e.g. 60000" />
        </Field>
        <Field label="Full-day rate (LKR) *">
          <input className="input" type="number" value={f.fullDayRate} onChange={(e) => setF({ ...f, fullDayRate: e.target.value })} placeholder="e.g. 100000" />
        </Field>
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={!f.name.trim() || !f.maxCapacity || !f.hourlyRate || !f.halfDayRate || !f.fullDayRate}
          onClick={() =>
            post<{ message: string; venue: Venue }>("/venues", {
              name: f.name,
              max_capacity: parseInt(f.maxCapacity) || 0,
              hourly_rate: toCents(f.hourlyRate),
              half_day_rate: toCents(f.halfDayRate),
              full_day_rate: toCents(f.fullDayRate),
              active: true,
            })
              .then((r) => {
                toast.success(`Venue "${r.venue.name}" created`);
                onDone();
              })
              .catch((e) => setError(e.message))
          }
        >
          Create venue
        </button>
      </div>
    </Modal>
  );
}
