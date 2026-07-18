import { useState } from "react";
import { Plus, Search, Download, Crown } from "lucide-react";
import { api, post, put } from "../lib/api";
import { useFetch, lkr, fmtDate } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination, statusColor } from "../components/ui";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";

/** Every status/etc. lookup relation serializes as this shape (see App\Models\Lookup). */
type Lookup = { id: number; code: string; name: string };

type Guest = {
  id: number; name: string; email: string | null; phone: string | null; id_number: string | null;
  nationality: string | null; preferences: string | null; loyalty_points: number; lifetime_spend: number;
};
type LoyaltyTxn = { id: number; points: number; reason: string; created_at: string };
/**
 * GuestController::show() only eager-loads loyaltyTransactions — it does not
 * return the guest's reservation history (no `reservations` relation loaded),
 * and Guest has no venue-bookings relation at all. Stay history below is kept
 * as always-empty pending backend support — see final report.
 */
type GuestDetail = Guest & { loyalty_transactions: LoyaltyTxn[] };
type StayHistoryRow = {
  id: number; code: string; status: Lookup; check_in: string; check_out: string;
  rooms: { room: { number: string } }[];
  folio?: { invoice_no?: string | null } | null;
};

const SORTS = [
  { id: "recent", label: "Recently added" },
  { id: "spend", label: "Highest lifetime spend" },
  { id: "points", label: "Most loyalty points" },
  { id: "name", label: "Name A–Z" },
] as const;

const VIP_SPEND_THRESHOLD = 5000000; // LKR 50,000 lifetime spend

function downloadCsv(filename: string, rows: (string | number)[][]) {
  const csv = rows.map((r) => r.map((v) => (typeof v === "string" && /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

export default function Guests() {
  const { can } = useAuth();
  const canView = can("hotel_guests.view");
  const [q, setQ] = useState("");
  const [sort, setSort] = useState<(typeof SORTS)[number]["id"]>("recent");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  // GuestController::index() wraps the paginator under `guests` and returns a
  // sibling `stats` object — usePagedFetch would discard `stats`, so this is
  // unwrapped by hand. Note: the backend currently hardcodes 15/page here and
  // ignores `page_size`, so the page-size selector has no visible effect.
  const { data, reload } = useFetch<{
    guests: { data: Guest[]; current_page: number; per_page: number; total: number };
    stats: { lifetime_spend: number; loyalty_points: number };
  }>(`/guests?q=${encodeURIComponent(q)}&sort=${sort}&page=${page}&page_size=${pageSize}`, [q, sort, page, pageSize]);
  const guests = data?.guests.data;
  const [openId, setOpenId] = useState<number | null>(null);
  const [openNew, setOpenNew] = useState(false);

  const exportCsv = async () => {
    const { guests: rows } = await api<{ guests: Guest[] }>(`/guests?q=${encodeURIComponent(q)}&sort=${sort}`);
    downloadCsv("guests.csv", [
      ["Name", "Phone", "Email", "ID/Passport", "Lifetime spend (LKR)", "Loyalty points"],
      ...rows.map((g) => [g.name, g.phone ?? "", g.email ?? "", g.id_number ?? "", (g.lifetime_spend / 100).toFixed(2), g.loyalty_points]),
    ]);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Guests & Loyalty</h1>
        <div className="flex gap-2">
          <button className="btn-secondary" onClick={exportCsv}><Download size={15} /> Export CSV</button>
          {can("hotel_guests.create") && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New guest</button>}
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Guests</div>
          <div className="mt-1 text-2xl font-extrabold">{data?.guests.total ?? 0}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Total lifetime value</div>
          <div className="mt-1 text-2xl font-extrabold text-brand-700">{lkr(data?.stats.lifetime_spend ?? 0)}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Loyalty points outstanding</div>
          <div className="mt-1 text-2xl font-extrabold">★ {data?.stats.loyalty_points ?? 0}</div>
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1">
          <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-9" placeholder="Search name / phone / email / ID…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <select className="input !w-52" value={sort} onChange={(e) => { setSort(e.target.value as typeof sort); setPage(1); }}>
          {SORTS.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
        </select>
      </div>
      <div className="card overflow-x-auto">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Guest</th><th className="th">Contact</th><th className="th">ID</th><th className="th text-right">Lifetime spend</th><th className="th text-right">Points</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {(guests ?? []).map((g) => (
              <tr key={g.id} className={canView ? "cursor-pointer hover:bg-slate-50" : ""} onClick={canView ? () => setOpenId(g.id) : undefined}>
                <td className="td font-semibold">
                  <span className="inline-flex items-center gap-1.5">
                    {g.name}
                    {g.lifetime_spend >= VIP_SPEND_THRESHOLD && <Badge color="amber"><Crown size={10} className="mr-0.5 inline" />VIP</Badge>}
                  </span>
                </td>
                <td className="td text-xs">{[g.phone, g.email].filter(Boolean).join(" · ") || "—"}</td>
                <td className="td text-xs">{g.id_number ?? "—"}</td>
                <td className="td text-right">{lkr(g.lifetime_spend)}</td>
                <td className="td text-right font-bold text-brand-600">★ {g.loyalty_points}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {(guests ?? []).length === 0 && <Empty text="No guests found" />}
        {data && (
          <Pagination
            page={data.guests.current_page}
            pageSize={data.guests.per_page}
            total={data.guests.total}
            onPage={setPage}
            onPageSize={(n) => { setPageSize(n); setPage(1); }}
          />
        )}
      </div>

      {openId !== null && <GuestModal id={openId} onClose={() => { setOpenId(null); reload(); }} />}
      {openNew && <GuestEditor onClose={() => { setOpenNew(false); reload(); }} />}
    </div>
  );
}

function GuestModal({ id, onClose }: { id: number; onClose: () => void }) {
  const { can } = useAuth();
  const { data, reload } = useFetch<{ guest: GuestDetail; total_stays: number }>(`/guests/${id}`);
  const [adjOpen, setAdjOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  if (!data) return null;
  const g = data.guest;
  // See the GuestDetail/StayHistoryRow comment above — always empty until the
  // backend eager-loads reservation history on guest show().
  const stayHistory: StayHistoryRow[] = [];
  return (
    <Modal open onClose={onClose} title={g.name} wide>
      <div className="grid gap-3 sm:grid-cols-3">
        <Card title="Profile">
          <div className="space-y-1 text-sm">
            <div>📞 {g.phone ?? "—"}</div>
            <div>✉️ {g.email ?? "—"}</div>
            <div>🪪 {g.id_number ?? "—"}</div>
            <div>🌐 {g.nationality ?? "—"}</div>
            {g.preferences && <div className="text-slate-500">♥ {g.preferences}</div>}
            {can("hotel_guests.edit") && <button className="btn-secondary mt-1 !py-1 text-xs" onClick={() => setEditOpen(true)}>Edit profile</button>}
          </div>
        </Card>
        <Card title="Lifetime value">
          <div className="text-2xl font-extrabold text-brand-700">{lkr(g.lifetime_spend)}</div>
          <div className="text-sm text-slate-500">{data.total_stays} completed stay(s)</div>
        </Card>
        <Card title="Loyalty points" actions={can("hotel_guests.loyalty_adjust") ? <button className="btn-secondary !py-1 text-xs" onClick={() => setAdjOpen(true)}>Adjust</button> : undefined}>
          <div className="text-2xl font-extrabold">★ {g.loyalty_points}</div>
          <div className="text-xs text-slate-500">Earned on rooms, restaurant & venue spend · redeemable at checkout / POS</div>
        </Card>
      </div>

      <Card title="Stay history" className="mt-3">
        <div className="divide-y divide-slate-50 text-sm">
          {stayHistory.map((r) => (
            <div key={r.id} className="flex flex-wrap items-center gap-2 py-1.5">
              <span className="font-bold">{r.code}</span>
              <span>{fmtDate(r.check_in)} → {fmtDate(r.check_out)}</span>
              <span className="text-xs text-slate-400">Rooms {r.rooms.map((x) => x.room.number).join(", ")}</span>
              <Badge color={statusColor(r.status.code.toUpperCase())}>{r.status.code.toUpperCase()}</Badge>
              {r.folio?.invoice_no && <span className="text-xs text-slate-400">{r.folio.invoice_no}</span>}
            </div>
          ))}
          {stayHistory.length === 0 && <Empty text="No stays yet" />}
        </div>
      </Card>

      {g.loyalty_transactions.length > 0 && (
        <Card title="Loyalty activity" className="mt-3">
          <div className="space-y-1 text-xs text-slate-600">
            {g.loyalty_transactions.map((t) => (
              <div key={t.id} className="flex justify-between">
                <span>{fmtDate(t.created_at)} — {t.reason}</span>
                <span className={t.points >= 0 ? "font-bold text-emerald-600" : "font-bold text-red-600"}>{t.points > 0 ? "+" : ""}{t.points}</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {adjOpen && (
        <AdjustPoints guestId={g.id} guestName={g.name} onClose={() => { setAdjOpen(false); reload(); }} />
      )}
      {editOpen && <GuestEditor guest={g} onClose={() => { setEditOpen(false); reload(); }} />}
    </Modal>
  );
}

function AdjustPoints({ guestId, guestName, onClose }: { guestId: number; guestName: string; onClose: () => void }) {
  const toast = useToast();
  const [points, setPoints] = useState("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="Adjust loyalty points">
      <div className="space-y-3">
        <Field label="Points (+ add / − deduct)"><input className="input" value={points} onChange={(e) => setPoints(e.target.value)} /></Field>
        <Field label="Reason (required)"><input className="input" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        <ErrorText error={error} />
        <button
          className="btn-primary w-full"
          disabled={!reason.trim() || !parseInt(points)}
          onClick={() =>
            post(`/guests/${guestId}/loyalty-adjust`, { points: parseInt(points), reason: reason.trim() })
              .then(() => {
                const n = parseInt(points);
                toast.success(`${guestName} — ${n > 0 ? "+" : ""}${n} points`, reason.trim());
                onClose();
              })
              .catch((e) => setError(e.message))
          }
        >
          Apply
        </button>
      </div>
    </Modal>
  );
}

function GuestEditor({ guest, onClose }: { guest?: Guest; onClose: () => void }) {
  const [f, setF] = useState({
    name: guest?.name ?? "", phone: guest?.phone ?? "", email: guest?.email ?? "",
    idNumber: guest?.id_number ?? "", nationality: guest?.nationality ?? "", preferences: guest?.preferences ?? "",
  });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title={guest ? "Edit guest" : "New guest"}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Full name *"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Phone"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        <Field label="Email"><input className="input" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="ID / passport"><input className="input" value={f.idNumber} onChange={(e) => setF({ ...f, idNumber: e.target.value })} /></Field>
        <Field label="Nationality"><input className="input" value={f.nationality} onChange={(e) => setF({ ...f, nationality: e.target.value })} /></Field>
        <Field label="Preferences"><input className="input" value={f.preferences} onChange={(e) => setF({ ...f, preferences: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.name.trim()}
        onClick={() => {
          const body = { name: f.name, phone: f.phone, email: f.email, id_number: f.idNumber, nationality: f.nationality, preferences: f.preferences };
          (guest ? put(`/guests/${guest.id}`, body) : post("/guests", body)).then(onClose).catch((e) => setError(e.message));
        }}
      >
        Save
      </button>
    </Modal>
  );
}
