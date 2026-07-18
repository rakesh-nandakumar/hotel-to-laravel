import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Plus, Search, Users } from "lucide-react";
import { post } from "../lib/api";
import { useFetch, usePagedFetch, lkr, todayStr, fmtDate, toCents, useSettings } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination, statusColor, Tabs } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

/** Every status/channel/etc. lookup relation serializes as this shape (see App\Models\Lookup). */
type Lookup = { id: number; code: string; name: string };

const STATUS_OPTIONS = [
  { code: "pending", label: "PENDING" },
  { code: "confirmed", label: "CONFIRMED" },
  { code: "checked_in", label: "CHECKED_IN" },
  { code: "checked_out", label: "CHECKED_OUT" },
  { code: "cancelled", label: "CANCELLED" },
  { code: "no_show", label: "NO_SHOW" },
];

const CHANNEL_OPTIONS = [
  { code: "walkin", label: "Walk-in" },
  { code: "phone", label: "Phone" },
  { code: "booking_com", label: "Booking.com" },
  { code: "website", label: "Website" },
];

const PAY_METHOD_OPTIONS = [
  { code: "cash", label: "CASH" },
  { code: "card", label: "CARD" },
  { code: "lankaqr", label: "LANKAQR" },
  { code: "bank_transfer", label: "BANK_TRANSFER" },
];

type ResRow = {
  id: number; code: string; check_in: string; check_out: string;
  status: Lookup; channel: Lookup;
  guest: { id: number; name: string; loyalty_points: number };
  rooms: { id: number; room: { id: number; number: string } }[];
  package: { id: number; code: string; name: string } | null;
  group_booking: { id: number; reference: string; name: string } | null;
  corporate_account: { id: number; company_name: string } | null;
};
type AvailRoom = {
  id: number; number: string;
  room_type: { id: number; name: string; max_occupancy: number };
  stay_total: number;
  nights: { date: string; rate: number }[];
};
type GuestLite = { id: number; name: string; phone: string | null; loyalty_points: number };
type Corp = { id: number; company_name: string; discount_pct: number };
type Pkg = { id: number; code: string; name: string; price_per_person_per_night: number };
type GroupRes = {
  id: number; code: string;
  // Not eager-loaded by ReservationController::groups() on the backend — always
  // absent today; guarded below and kept typed for when that's added.
  status?: Lookup;
  guest: { name: string };
  rooms: { room: { number: string } }[];
  folio?: { id: number; status: Lookup } | null;
};
type Group = {
  id: number; reference: string; name: string;
  contact_name: string | null; contact_phone: string | null; created_at: string;
  reservations: GroupRes[];
};
type GroupInvoice = {
  group: { id: number; reference: string; name: string; contact_name: string | null };
  folios: { id: number; total: number; paid: number; balance: number; invoice_no: string | null }[];
  grand_total: number; total_paid: number; balance: number;
};

export default function Reservations() {
  const [tab, setTab] = useState<"list" | "groups">("list");
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Reservations</h1>
        <Tabs tabs={[{ id: "list" as const, label: "All reservations" }, { id: "groups" as const, label: "Group bookings" }]} active={tab} onChange={setTab} />
      </div>
      {tab === "list" ? <ReservationsList /> : <GroupsTab />}
    </div>
  );
}

function ReservationsList() {
  const { can } = useAuth();
  const canView = can("hotel_reservations.view");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload } = usePagedFetch<ResRow>(
    `/reservations?q=${encodeURIComponent(q)}&status=${status}&page=${page}&page_size=${pageSize}`,
    "reservations",
    [q, status, page, pageSize],
  );
  const rows = data?.rows;
  const [openNew, setOpenNew] = useState(false);
  const nav = useNavigate();

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {can("hotel_reservations.create") && (
          <button className="btn-primary" onClick={() => setOpenNew(true)}>
            <Plus size={16} /> New booking
          </button>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <div className="relative flex-1 min-w-52">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-9" placeholder="Search guest, code, room…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <select className="input !w-44" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => (
            <option key={s.code} value={s.code}>{s.label}</option>
          ))}
        </select>
      </div>

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[720px]">
          <thead className="border-b border-slate-100">
            <tr>
              <th className="th">Code</th><th className="th">Guest</th><th className="th">Rooms</th>
              <th className="th">Dates</th><th className="th">Channel</th><th className="th">Status</th><th className="th">Tags</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {(rows ?? []).map((r) => (
              <tr key={r.id} className={canView ? "cursor-pointer hover:bg-slate-50" : ""} onClick={canView ? () => nav(`/reservations/${r.id}`) : undefined}>
                <td className="td font-bold">{r.code}</td>
                <td className="td">
                  {r.guest.name}
                  {r.guest.loyalty_points > 0 && <span className="ml-1 text-xs text-brand-600">★{r.guest.loyalty_points}</span>}
                </td>
                <td className="td">{r.rooms.map((x) => x.room.number).join(", ")}</td>
                <td className="td whitespace-nowrap">{fmtDate(r.check_in)} → {fmtDate(r.check_out)}</td>
                <td className="td text-xs">{r.channel.code.toUpperCase()}</td>
                <td className="td"><Badge color={statusColor(r.status.code.toUpperCase())}>{r.status.code.toUpperCase()}</Badge></td>
                <td className="td space-x-1">
                  {r.group_booking && <Badge color="purple">{r.group_booking.reference}</Badge>}
                  {r.corporate_account && <Badge color="blue">{r.corporate_account.company_name}</Badge>}
                  {r.package && r.package.code !== "RO" && <Badge>{r.package.code}</Badge>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {(rows ?? []).length === 0 && <Empty text="No reservations found" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openNew && (
        <NewBooking
          onClose={() => setOpenNew(false)}
          onCreated={(id) => {
            setOpenNew(false);
            reload();
            nav(`/reservations/${id}`);
          }}
        />
      )}
    </div>
  );
}

function GroupsTab() {
  const { data: groupsResp } = useFetch<{ groups: Group[] }>("/reservations/groups");
  const groups = groupsResp?.groups;
  const [selected, setSelected] = useState<Group | null>(null);
  const nav = useNavigate();

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">One reference, one consolidated invoice across all rooms in the group — created from "New booking" → Group booking.</p>
      <div className="grid gap-3 md:grid-cols-2">
        {(groups ?? []).map((g) => {
          const rooms = g.reservations.flatMap((r) => r.rooms.map((x) => x.room.number));
          const active = g.reservations.filter((r) => r.status?.code.toUpperCase() !== "CANCELLED").length;
          return (
            <button key={g.id} className="card p-4 text-left transition hover:shadow-md" onClick={() => setSelected(g)}>
              <div className="flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-base font-extrabold"><Users size={15} className="text-brand-600" /> {g.reference}</span>
                <Badge color="purple">{active} room{active === 1 ? "" : "s"}</Badge>
              </div>
              <div className="mt-1 text-sm font-semibold">{g.name}</div>
              <div className="text-xs text-slate-500">
                {g.contact_name && `${g.contact_name} · `}{g.contact_phone ?? "no contact phone"}
              </div>
              {rooms.length > 0 && <div className="mt-1 text-xs text-slate-400">Rooms {rooms.join(", ")}</div>}
            </button>
          );
        })}
      </div>
      {(groups ?? []).length === 0 && <Empty text="No group bookings yet" />}
      {selected && <GroupModal group={selected} onClose={() => setSelected(null)} onOpenReservation={(id) => nav(`/reservations/${id}`)} />}
    </div>
  );
}

function GroupModal({ group, onClose, onOpenReservation }: { group: Group; onClose: () => void; onOpenReservation: (id: string) => void }) {
  const { data: inv } = useFetch<GroupInvoice>(`/reservations/groups/${group.id}/invoice`);
  return (
    <Modal open onClose={onClose} title={`${group.reference} — ${group.name}`} wide>
      <div className="grid gap-2 text-sm sm:grid-cols-2">
        <div><b>Contact:</b> {group.contact_name ?? "—"} {group.contact_phone && `· ${group.contact_phone}`}</div>
        <div><b>Created:</b> {fmtDate(group.created_at)}</div>
      </div>
      {inv && (
        <div className="mt-3 space-y-1 rounded-xl bg-slate-50 p-3 text-sm">
          <div className="flex justify-between font-bold"><span>Consolidated total ({inv.folios.length} folio{inv.folios.length === 1 ? "" : "s"})</span><span>{lkr(inv.grand_total)}</span></div>
          <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(inv.total_paid)}</span></div>
          <div className="flex justify-between font-extrabold"><span>Balance</span><span>{lkr(inv.balance)}</span></div>
        </div>
      )}
      <Card title="Reservations in this group" className="mt-3">
        <div className="divide-y divide-slate-50 text-sm">
          {group.reservations.map((r) => (
            <button key={r.id} className="flex w-full flex-wrap items-center gap-2 py-2 text-left hover:bg-slate-50" onClick={() => onOpenReservation(String(r.id))}>
              <span className="font-bold">{r.code}</span>
              <span>{r.guest.name}</span>
              <span className="text-xs text-slate-400">Rooms {r.rooms.map((x) => x.room.number).join(", ") || "—"}</span>
              {r.status && <Badge color={statusColor(r.status.code.toUpperCase())}>{r.status.code.toUpperCase()}</Badge>}
              {r.folio && <Badge color={statusColor(r.folio.status.code.toUpperCase())}>{r.folio.status.code.toUpperCase()}</Badge>}
            </button>
          ))}
          {group.reservations.length === 0 && <Empty text="No reservations in this group" />}
        </div>
      </Card>
    </Modal>
  );
}

export function NewBooking({
  onClose,
  onCreated,
  initial,
}: {
  onClose: () => void;
  onCreated: (id: string) => void;
  initial?: { checkIn?: string; checkOut?: string; roomIds?: number[] };
}) {
  const [checkIn, setCheckIn] = useState(initial?.checkIn ?? todayStr());
  const [checkOut, setCheckOut] = useState(initial?.checkOut ?? todayStr(1));
  const { data: availResp, loading } = useFetch<{ rooms: AvailRoom[] }>(`/reservations/availability?check_in=${checkIn}&check_out=${checkOut}`, [checkIn, checkOut]);
  const avail = availResp?.rooms;
  const { data: pkgResp } = useFetch<{ packages: Pkg[] }>("/rooms/packages");
  const packages = pkgResp?.packages;
  // CorporateAccountController::index always paginates (default 15/page) — ask
  // for a bigger page so this dropdown isn't silently missing accounts.
  const { data: corpResp } = useFetch<{ corporate_accounts: { data: Corp[] } }>("/corporate?page_size=100");
  const corps = corpResp?.corporate_accounts.data;
  const { num } = useSettings();
  const toast = useToast();

  const [selRooms, setSelRooms] = useState<number[]>(initial?.roomIds ?? []);
  const [guestQ, setGuestQ] = useState("");
  const { data: guestsResp } = useFetch<{ guests: GuestLite[] }>(guestQ.length >= 2 ? `/guests?q=${encodeURIComponent(guestQ)}` : null, [guestQ]);
  const guests = guestsResp?.guests;
  const [guestId, setGuestId] = useState("");
  const [guestPicked, setGuestPicked] = useState<GuestLite | null>(null);
  const [newGuest, setNewGuest] = useState({ name: "", phone: "", email: "", idNumber: "" });
  const [form, setForm] = useState({ channel: "walkin", adults: "2", children: "0", packageId: "", corporateAccountId: "", notes: "" });
  const [isGroup, setIsGroup] = useState(false);
  const [group, setGroup] = useState({ name: "", contactName: "", contactPhone: "" });
  const [deposit, setDeposit] = useState({ take: false, method: "cash", amount: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const chosen = useMemo(() => (avail ?? []).filter((r) => selRooms.includes(r.id)), [avail, selRooms]);
  const nightsCount = chosen[0]?.nights.length ?? 0;
  const pkg = (packages ?? []).find((p) => p.id === Number(form.packageId));
  const corp = (corps ?? []).find((c) => c.id === Number(form.corporateAccountId));
  let estTotal = chosen.reduce((s, r) => s + r.stay_total, 0);
  if (corp) estTotal = Math.round(estTotal * (1 - corp.discount_pct / 100));
  if (pkg) estTotal += pkg.price_per_person_per_night * parseInt(form.adults || "1") * nightsCount;
  const depositPct = num("billing.room_deposit_pct", 20);
  const suggestedDeposit = Math.round((estTotal * depositPct) / 100);

  const create = async () => {
    setError("");
    if (selRooms.length === 0) return setError("Select at least one room");
    if (!guestId && !newGuest.name.trim()) return setError("Pick an existing guest or enter a new guest name");
    setBusy(true);
    try {
      const res = await post<{ message: string; reservation: { id: number; code: string } }>("/reservations", {
        guest_id: guestId ? Number(guestId) : undefined,
        new_guest: guestId
          ? undefined
          : { name: newGuest.name.trim(), phone: newGuest.phone || undefined, email: newGuest.email || undefined, id_number: newGuest.idNumber || undefined },
        channel: form.channel,
        check_in: checkIn,
        check_out: checkOut,
        adults: parseInt(form.adults) || 1,
        children: parseInt(form.children) || 0,
        package_id: form.packageId ? Number(form.packageId) : undefined,
        corporate_account_id: form.corporateAccountId ? Number(form.corporateAccountId) : undefined,
        rooms: selRooms.map((roomId) => ({ room_id: roomId })),
        notes: form.notes || undefined,
        group: isGroup && group.name ? { name: group.name, contact_name: group.contactName || undefined, contact_phone: group.contactPhone || undefined } : undefined,
        deposit_payment: deposit.take && toCents(deposit.amount) > 0 ? { method: deposit.method, amount: toCents(deposit.amount) } : undefined,
      });
      toast.success(`Booking ${res.reservation.code} created`, "Confirmation sent to the guest");
      onCreated(String(res.reservation.id));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title="New booking" wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Check-in"><input type="date" className="input" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} /></Field>
        <Field label="Check-out"><input type="date" className="input" value={checkOut} onChange={(e) => setCheckOut(e.target.value)} /></Field>
      </div>

      <div className="mt-3">
        <div className="label">Available rooms ({nightsCount || "…"} night{nightsCount === 1 ? "" : "s"}) — dynamic weekday/weekend/seasonal pricing</div>
        {loading ? (
          <Empty text="Checking availability…" />
        ) : (
          <div className="grid max-h-56 grid-cols-2 gap-1.5 overflow-y-auto sm:grid-cols-3">
            {(avail ?? []).map((r) => (
              <button
                key={r.id}
                onClick={() => setSelRooms((s) => (s.includes(r.id) ? s.filter((x) => x !== r.id) : [...s, r.id]))}
                className={`rounded-lg border p-2 text-left text-xs transition ${selRooms.includes(r.id) ? "border-brand-500 bg-brand-50" : "border-slate-200 bg-white hover:border-slate-300"}`}
              >
                <div className="text-sm font-extrabold">Room {r.number}</div>
                <div className="truncate text-slate-500">{r.room_type.name} · sleeps {r.room_type.max_occupancy}</div>
                <div className="font-semibold text-brand-600">{lkr(r.stay_total)} / stay</div>
              </button>
            ))}
            {(avail ?? []).length === 0 && <Empty text="No rooms free for these dates" />}
          </div>
        )}
      </div>

      <div className="mt-4">
        <div className="label">Guest (returning guests are auto-recognised)</div>
        {guestPicked ? (
          <div className="flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm">
            <span>
              <b>{guestPicked.name}</b> {guestPicked.phone && `· ${guestPicked.phone}`}{" "}
              {guestPicked.loyalty_points > 0 && <Badge color="brand">★ {guestPicked.loyalty_points} pts</Badge>}
            </span>
            <button className="text-xs font-bold text-red-500" onClick={() => { setGuestId(""); setGuestPicked(null); }}>change</button>
          </div>
        ) : (
          <>
            <input className="input" placeholder="Search existing guest by name/phone/ID…" value={guestQ} onChange={(e) => setGuestQ(e.target.value)} />
            {(guests ?? []).length > 0 && (
              <div className="mt-1 max-h-32 overflow-y-auto rounded-lg border border-slate-200">
                {(guests ?? []).map((g) => (
                  <button key={g.id} className="flex w-full justify-between px-3 py-1.5 text-sm hover:bg-slate-50" onClick={() => { setGuestId(String(g.id)); setGuestPicked(g); }}>
                    <span>{g.name} {g.phone && <span className="text-slate-400">· {g.phone}</span>}</span>
                    {g.loyalty_points > 0 && <span className="text-xs text-brand-600">★{g.loyalty_points}</span>}
                  </button>
                ))}
              </div>
            )}
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              <input className="input" placeholder="…or NEW guest: full name *" value={newGuest.name} onChange={(e) => setNewGuest({ ...newGuest, name: e.target.value })} />
              <input className="input" placeholder="Phone (for WhatsApp)" value={newGuest.phone} onChange={(e) => setNewGuest({ ...newGuest, phone: e.target.value })} />
              <input className="input" placeholder="Email" value={newGuest.email} onChange={(e) => setNewGuest({ ...newGuest, email: e.target.value })} />
              <input className="input" placeholder="ID/passport (can capture at check-in)" value={newGuest.idNumber} onChange={(e) => setNewGuest({ ...newGuest, idNumber: e.target.value })} />
            </div>
          </>
        )}
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <Field label="Channel">
          <select className="input" value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
            {CHANNEL_OPTIONS.map((c) => <option key={c.code} value={c.code}>{c.label}</option>)}
          </select>
        </Field>
        <Field label="Adults"><input className="input" inputMode="numeric" value={form.adults} onChange={(e) => setForm({ ...form, adults: e.target.value })} /></Field>
        <Field label="Children (under-4 free)"><input className="input" inputMode="numeric" value={form.children} onChange={(e) => setForm({ ...form, children: e.target.value })} /></Field>
        <Field label="Package">
          <select className="input" value={form.packageId} onChange={(e) => setForm({ ...form, packageId: e.target.value })}>
            <option value="">Room only</option>
            {(packages ?? []).filter((p) => p.code !== "RO").map((p) => (
              <option key={p.id} value={p.id}>{p.name} ({lkr(p.price_per_person_per_night)}/pax/night)</option>
            ))}
          </select>
        </Field>
        <Field label="Corporate account (negotiated rate)">
          <select className="input" value={form.corporateAccountId} onChange={(e) => setForm({ ...form, corporateAccountId: e.target.value })}>
            <option value="">None</option>
            {(corps ?? []).map((c) => (
              <option key={c.id} value={c.id}>{c.company_name} (-{c.discount_pct}%)</option>
            ))}
          </select>
        </Field>
        <Field label="Notes"><input className="input" value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
      </div>

      <label className="mt-3 flex items-center gap-2 text-sm font-semibold">
        <input type="checkbox" checked={isGroup} onChange={(e) => setIsGroup(e.target.checked)} />
        Group booking (one reference, one consolidated invoice)
      </label>
      {isGroup && (
        <div className="mt-2 grid gap-2 sm:grid-cols-3">
          <input className="input" placeholder="Group/company name *" value={group.name} onChange={(e) => setGroup({ ...group, name: e.target.value })} />
          <input className="input" placeholder="Contact person" value={group.contactName} onChange={(e) => setGroup({ ...group, contactName: e.target.value })} />
          <input className="input" placeholder="Contact phone" value={group.contactPhone} onChange={(e) => setGroup({ ...group, contactPhone: e.target.value })} />
        </div>
      )}

      <div className="mt-4 rounded-xl bg-slate-50 p-3">
        <div className="flex items-center justify-between text-sm">
          <span className="font-semibold">Estimated stay total</span>
          <span className="text-lg font-extrabold text-brand-700">{lkr(estTotal)}</span>
        </div>
        <div className="mt-1 text-xs text-slate-500">Advance deposit ({depositPct}%): <b>{lkr(suggestedDeposit)}</b></div>
        <label className="mt-2 flex items-center gap-2 text-sm font-semibold">
          <input type="checkbox" checked={deposit.take} onChange={(e) => setDeposit({ ...deposit, take: e.target.checked, amount: deposit.amount || (suggestedDeposit / 100).toFixed(2) })} />
          Take deposit / prepayment now
        </label>
        {deposit.take && (
          <div className="mt-2 flex gap-2">
            <select className="input !w-40" value={deposit.method} onChange={(e) => setDeposit({ ...deposit, method: e.target.value })}>
              {PAY_METHOD_OPTIONS.map((m) => <option key={m.code} value={m.code}>{m.label}</option>)}
            </select>
            <input className="input" placeholder="Amount (LKR)" value={deposit.amount} onChange={(e) => setDeposit({ ...deposit, amount: e.target.value })} />
          </div>
        )}
      </div>

      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full !py-3" disabled={busy} onClick={create}>
        {busy ? "Creating…" : "Create booking (sends confirmation)"}
      </button>
    </Modal>
  );
}
