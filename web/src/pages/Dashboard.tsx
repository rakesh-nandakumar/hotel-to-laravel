import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  BedDouble, TrendingUp, UtensilsCrossed, Users, ChefHat, Sparkles, Wrench, Package,
  CalendarClock, Plus, ClipboardCheck, ArrowRight, Bell, Search, Crown, IdCard,
  PartyPopper, UserCheck, TrendingDown,
} from "lucide-react";
import { useFetch, lkr, usd, useSettings, fmtDate, fmtDateTime } from "../lib/util";
import { Card, Badge, statusColor, Empty } from "../components/ui";
import { useAuth } from "../lib/auth";
import { getSocket } from "../lib/socket";
import clsx from "clsx";

type Dash = {
  rooms: { total: number; occupied: number; available: number; dirty: number; maintenance: number; occupancy_pct: number };
  arrivals: GuestRow[];
  departures: GuestRow[];
  in_house: number;
  venues_today: number;
  staff_on_duty: number;
  revenue_today: { collected: number; charges_posted: number; pos_sales: number; pos_orders: number };
  yesterday: { occupancy_pct: number; collected: number; pos_sales: number };
  ops: { open_kots: number; pending_housekeeping: number; open_maintenance: number; low_stock_ingredients: number; expiring_batches: number };
};
type Monthly = { days: { date: string; revenue: number; occupancy_pct: number }[] };
type Notif = { id: number; type: string; channel: { code: string }; to: string; subject: string; status: { code: string }; created_at: string };
type OnDuty = { id: number; name: string; role: string; clock_in: string };
type SearchRow = { id: number; code: string; status: { code: string }; guest: { name: string }; rooms: { room: { number: string } }[] };

const initials = (name: string) =>
  name.split(" ").filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase();

const AVATAR_HUES = ["bg-brand-100 text-brand-700", "bg-sky-100 text-sky-700", "bg-purple-100 text-purple-700", "bg-amber-100 text-amber-700"];
const avatarHue = (name: string) => AVATAR_HUES[[...name].reduce((s, c) => s + c.charCodeAt(0), 0) % AVATAR_HUES.length];

const VIP_POINTS_THRESHOLD = 500;

function pctDelta(curr: number, prev: number): number | null {
  if (prev === 0) return curr === 0 ? 0 : null;
  return Math.round(((curr - prev) / prev) * 100);
}

function DeltaBadge({ delta }: { delta: number | null | undefined }) {
  if (delta === null || delta === undefined || !Number.isFinite(delta)) return null;
  return (
    <span className={clsx("flex items-center gap-0.5 text-xs font-bold", delta >= 0 ? "text-emerald-600" : "text-red-600")}>
      {delta >= 0 ? <TrendingUp size={12} /> : <TrendingDown size={12} />}
      {Math.abs(delta)}%
    </span>
  );
}

/** Quick jump to any reservation by guest name, code, or room number. */
function QuickSearch() {
  const nav = useNavigate();
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const { data } = useFetch<{ reservations: SearchRow[] }>(q.trim().length >= 2 ? `/reservations?q=${encodeURIComponent(q.trim())}` : null, [q]);
  const results = (data?.reservations ?? []).slice(0, 8);

  return (
    <div className="relative min-w-52 flex-1 sm:flex-none">
      <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
      <input
        className="input !pl-9"
        placeholder="Jump to guest, booking code, or room…"
        value={q}
        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
      />
      {open && q.trim().length >= 2 && (
        <div className="absolute z-20 mt-1 w-full max-w-sm overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
          {results.length === 0 ? (
            <div className="px-3 py-2.5 text-sm text-slate-400">No matching bookings</div>
          ) : (
            results.map((r) => (
              <button
                key={r.id}
                className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
                onMouseDown={() => nav(`/reservations/${r.id}`)}
              >
                <span className="min-w-0">
                  <span className="block truncate font-semibold">{r.guest.name}</span>
                  <span className="text-xs text-slate-400">{r.code} · Room {r.rooms.map((x) => x.room.number).join(", ") || "—"}</span>
                </span>
                <Badge color={statusColor(r.status.code)}>{r.status.code}</Badge>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}

export default function Dashboard() {
  const { me } = useAuth();
  const { data, reload } = useFetch<Dash>("/reports/dashboard");
  const { data: monthly } = useFetch<Monthly>("/reports/monthly");
  const { data: notifsData } = useFetch<{ notifications: Notif[] }>("/notifications");
  const { data: onDutyData } = useFetch<{ on_duty: OnDuty[] }>("/attendance/on-duty");
  const notifs = notifsData?.notifications;
  const onDuty = onDutyData?.on_duty;
  const { num } = useSettings();
  const rate = num("currency.usd_rate", 0);

  useEffect(() => {
    const s = getSocket();
    const onAny = () => reload();
    s.on("rooms", onAny);
    s.on("orders", onAny);
    s.on("kot", onAny);
    s.on("menu", onAny);
    return () => {
      s.off("rooms", onAny);
      s.off("orders", onAny);
      s.off("kot", onAny);
      s.off("menu", onAny);
    };
  }, [reload]);

  const hour = new Date().getHours();
  const greeting = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";
  const firstName = me?.user.name?.split(" ")[0] ?? "";

  const last7 = useMemo(() => (monthly?.days ?? []).slice(-7), [monthly]);
  const maxRev = Math.max(1, ...last7.map((d) => d.revenue));
  const weekTotal = last7.reduce((s, d) => s + d.revenue, 0);

  if (!data) return <Empty text="Loading dashboard…" />;

  const ops: { label: string; value: number; icon: typeof ChefHat; color: string; to: string }[] = [
    { label: "Kitchen orders open", value: data.ops.open_kots, icon: ChefHat, color: "text-red-600 bg-red-50", to: "/kot" },
    { label: "Rooms to clean", value: data.ops.pending_housekeeping, icon: Sparkles, color: "text-amber-600 bg-amber-50", to: "/housekeeping" },
    { label: "Maintenance issues", value: data.ops.open_maintenance, icon: Wrench, color: "text-orange-600 bg-orange-50", to: "/maintenance" },
    { label: "Low-stock ingredients", value: data.ops.low_stock_ingredients, icon: Package, color: "text-purple-600 bg-purple-50", to: "/inventory" },
    { label: "Food expiring / expired", value: data.ops.expiring_batches ?? 0, icon: CalendarClock, color: "text-red-600 bg-red-50", to: "/inventory" },
    { label: "Venue events today", value: data.venues_today, icon: PartyPopper, color: "text-pink-600 bg-pink-50", to: "/venues" },
  ];

  const roomSegments: { key: string; label: string; n: number; color: string }[] = [
    { key: "available", label: "Available", n: data.rooms.available, color: "bg-emerald-500" },
    { key: "occupied", label: "Occupied", n: data.rooms.occupied, color: "bg-sky-500" },
    { key: "dirty", label: "Dirty", n: data.rooms.dirty, color: "bg-amber-400" },
    { key: "maintenance", label: "Maintenance", n: data.rooms.maintenance, color: "bg-red-500" },
  ];

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-extrabold">{greeting}{firstName ? `, ${firstName}` : ""} 👋</h1>
          <p className="text-sm text-slate-500">Here's what's happening at Mount View today, {fmtDate(new Date())}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <QuickSearch />
          <Link to="/reservations" className="btn-primary"><Plus size={15} /> New booking</Link>
          <Link to="/pos" className="btn-secondary"><UtensilsCrossed size={15} /> New order</Link>
          <Link to="/calendar" className="btn-secondary">Calendar</Link>
        </div>
      </div>

      {/* Hero stats */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <HeroStat
          icon={BedDouble} label="Occupancy" value={`${data.rooms.occupancy_pct}%`}
          sub={`${data.rooms.occupied}/${data.rooms.total} rooms`} accent="from-brand-500 to-brand-700"
          delta={pctDelta(data.rooms.occupancy_pct, data.yesterday.occupancy_pct)}
        />
        <HeroStat
          icon={TrendingUp}
          label="Revenue collected today"
          value={lkr(data.revenue_today.collected)}
          sub={rate ? usd(data.revenue_today.collected, rate) : "all payment methods"}
          accent="from-emerald-500 to-emerald-700"
          delta={pctDelta(data.revenue_today.collected, data.yesterday.collected)}
        />
        <HeroStat
          icon={UtensilsCrossed} label="POS sales today" value={lkr(data.revenue_today.pos_sales)}
          sub={`${data.revenue_today.pos_orders} orders`} accent="from-amber-500 to-amber-700"
          delta={pctDelta(data.revenue_today.pos_sales, data.yesterday.pos_sales)}
        />
        <HeroStat icon={Users} label="In-house guests" value={data.in_house} sub={`${data.arrivals.length} arriving · ${data.departures.length} departing`} accent="from-purple-500 to-purple-700" />
      </div>

      {/* Ops alert strip */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
        {ops.map((o) => (
          <Link key={o.label} to={o.to} className="card group flex items-center gap-3 p-3 transition hover:-translate-y-0.5 hover:shadow-md">
            <div className={clsx("flex h-9 w-9 shrink-0 items-center justify-center rounded-xl", o.color)}>
              <o.icon size={17} />
            </div>
            <div className="min-w-0">
              <div className={clsx("text-lg font-extrabold leading-none", o.value > 0 ? o.color.split(" ")[0] : "text-emerald-600")}>{o.value}</div>
              <div className="truncate text-[11px] font-semibold text-slate-500">{o.label}</div>
            </div>
          </Link>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Revenue trend */}
        <Card
          title="Revenue — last 7 days"
          actions={<Link to="/reports" className="text-xs font-bold text-brand-600">Full reports <ArrowRight size={11} className="inline" /></Link>}
        >
          {last7.length === 0 ? (
            <Empty text="No data yet" />
          ) : (
            <>
              <div className="mb-1 text-xl font-extrabold text-brand-700">{lkr(weekTotal)}</div>
              <div className="flex h-24 items-end gap-1.5">
                {last7.map((d) => (
                  <div key={d.date} className="group relative flex-1">
                    <div
                      className="w-full rounded-t-[3px] bg-brand-500 transition-all group-hover:bg-brand-700"
                      style={{ height: `${Math.max(4, (d.revenue / maxRev) * 88)}px` }}
                    />
                    <div className="absolute bottom-full left-1/2 z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2 py-1 text-[10px] font-semibold text-white group-hover:block">
                      {fmtDate(d.date)}: {lkr(d.revenue)}
                    </div>
                    <div className="mt-1 text-center text-[9px] font-semibold text-slate-400">{new Date(d.date).toLocaleDateString("en-GB", { weekday: "narrow" })}</div>
                  </div>
                ))}
              </div>
            </>
          )}
        </Card>

        {/* Room status bar */}
        <Card title="Room status" actions={<Link to="/rooms" className="text-xs font-bold text-brand-600">Room board <ArrowRight size={11} className="inline" /></Link>}>
          <div className="mb-3 flex h-3 overflow-hidden rounded-full bg-slate-100">
            {roomSegments.map((s) =>
              s.n > 0 ? <div key={s.key} className={clsx("h-full transition-all", s.color)} style={{ width: `${(s.n / data.rooms.total) * 100}%` }} title={`${s.label}: ${s.n}`} /> : null
            )}
          </div>
          <div className="space-y-1.5 text-sm">
            {roomSegments.map((s) => (
              <div key={s.key} className="flex items-center justify-between gap-3 py-0.5">
                <span className="flex min-w-0 items-center gap-2">
                  <span className={clsx("h-2.5 w-2.5 shrink-0 rounded-full", s.color)} />
                  <span className="truncate text-slate-600">{s.label}</span>
                </span>
                <span className="flex shrink-0 items-baseline gap-1.5 tabular-nums">
                  <span className="font-bold text-slate-900">{s.n}</span>
                  <span className="w-9 text-right text-xs text-slate-400">{data.rooms.total ? Math.round((s.n / data.rooms.total) * 100) : 0}%</span>
                </span>
              </div>
            ))}
          </div>
          {data.rooms.dirty > 0 && (
            <Link to="/housekeeping" className="mt-3 flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">
              <ClipboardCheck size={13} /> {data.rooms.dirty} room{data.rooms.dirty === 1 ? "" : "s"} awaiting cleaning checklist
            </Link>
          )}
        </Card>

        {/* Live activity feed */}
        <Card title="Recent activity" actions={<Link to="/notifications" className="text-xs font-bold text-brand-600">All <ArrowRight size={11} className="inline" /></Link>}>
          {(notifs ?? []).length === 0 ? (
            <Empty text="No activity yet" />
          ) : (
            <ul className="space-y-2.5">
              {(notifs ?? []).slice(0, 6).map((n) => (
                <li key={n.id} className="flex items-start gap-2 text-sm">
                  <span className={clsx("mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full", n.status.code === "failed" ? "bg-red-100 text-red-500" : "bg-brand-50 text-brand-600")}>
                    <Bell size={12} />
                  </span>
                  <div className="min-w-0">
                    <div className="truncate font-semibold text-slate-700">{n.subject}</div>
                    <div className="text-[11px] text-slate-400">{n.channel.code} · {fmtDateTime(n.created_at)}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {/* Arrivals, departures & staff on duty */}
      <div className="grid gap-4 lg:grid-cols-3">
        <GuestListCard title="Today's arrivals" empty="No arrivals today" items={data.arrivals} kind="arrival" />
        <GuestListCard title="Today's departures" empty="No departures today" items={data.departures} kind="departure" />
        <Card
          title="Staff on duty"
          actions={<Link to="/attendance" className="text-xs font-bold text-brand-600">Attendance <ArrowRight size={11} className="inline" /></Link>}
        >
          {(onDuty ?? []).length === 0 ? (
            <Empty text="Nobody clocked in right now" />
          ) : (
            <ul className="space-y-1">
              {(onDuty ?? []).map((o) => (
                <li key={o.id} className="flex items-center gap-3 rounded-lg px-2 py-2 text-sm">
                  <span className={clsx("flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-extrabold", avatarHue(o.name))}>
                    {initials(o.name)}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-semibold">{o.name}</div>
                    <div className="text-xs text-slate-400">{o.role}</div>
                  </div>
                  <Badge color="green"><UserCheck size={10} className="mr-0.5 inline" />since {new Date(o.clock_in).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}

function HeroStat({ icon: Icon, label, value, sub, accent, delta }: { icon: typeof BedDouble; label: string; value: React.ReactNode; sub?: string; accent: string; delta?: number | null }) {
  return (
    <div className="card relative overflow-hidden p-4">
      <div className={clsx("absolute -right-4 -top-4 h-16 w-16 rounded-full bg-gradient-to-br opacity-10", accent)} />
      <div className={clsx("mb-2 flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br text-white shadow-sm", accent)}>
        <Icon size={16} />
      </div>
      <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className="mt-0.5 flex items-baseline gap-2">
        <span className="text-2xl font-extrabold text-slate-900">{value}</span>
        <DeltaBadge delta={delta} />
      </div>
      {sub && <div className="mt-0.5 text-xs text-slate-500">{sub}</div>}
    </div>
  );
}

type GuestRow = {
  id: number; code: string; guest: { name: string; loyalty_points?: number; id_number?: string | null };
  rooms: { room: { number: string } }[];
  group_booking?: { reference: string } | null;
  corporate_account?: { company_name: string } | null;
};

function GuestListCard({ title, empty, items, kind }: { title: string; empty: string; kind: "arrival" | "departure"; items: GuestRow[] }) {
  return (
    <Card title={title}>
      {items.length === 0 ? (
        <Empty text={empty} />
      ) : (
        <ul className="space-y-1">
          {items.map((g) => (
            <li key={g.id}>
              <Link to={`/reservations/${g.id}`} className="flex items-center gap-3 rounded-lg px-2 py-2 text-sm hover:bg-slate-50">
                <span className={clsx("flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-extrabold", avatarHue(g.guest.name))}>
                  {initials(g.guest.name)}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-1 truncate font-semibold">
                    {g.guest.name}
                    {kind === "arrival" && !!g.guest.loyalty_points && g.guest.loyalty_points >= VIP_POINTS_THRESHOLD && <Crown size={12} className="shrink-0 text-amber-500" />}
                  </div>
                  <div className="flex flex-wrap items-center gap-1 text-xs text-slate-400">
                    <span>{g.code} · Room {g.rooms.map((r) => r.room.number).join(", ")}</span>
                    {g.group_booking && <Badge color="purple">{g.group_booking.reference}</Badge>}
                    {g.corporate_account && <Badge color="blue">{g.corporate_account.company_name}</Badge>}
                    {kind === "arrival" && !g.guest.id_number && (
                      <span className="flex items-center gap-0.5 text-amber-600" title="ID/passport not on file — collect at check-in">
                        <IdCard size={11} /> ID needed
                      </span>
                    )}
                  </div>
                </div>
                {kind === "arrival" && !!g.guest.loyalty_points && g.guest.loyalty_points > 0 && <Badge color="brand">★ {g.guest.loyalty_points}</Badge>}
                {kind === "departure" && <Badge color={statusColor("CHECKED_IN")}>due out</Badge>}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
