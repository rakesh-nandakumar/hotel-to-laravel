import { useEffect, useMemo, useRef, useState } from "react";
import {
  ChefHat, Printer, Volume2, VolumeX, Maximize, Minimize, Clock3, Flame,
  BedDouble, ShoppingBag, Utensils, History, Sun, Moon,
} from "lucide-react";
import { api, openPdf } from "../lib/api";
import { useFetch, fmtDateTime } from "../lib/util";
import { Empty } from "../components/ui";
import { getSocket } from "../lib/socket";
import clsx from "clsx";

type KotOrder = {
  id: number; type: { code: string }; dining_mode?: { code: string }; kot_status: { code: string }; notes?: string;
  created_at: string;
  room?: { number: string } | null;
  reservation?: { guest: { name: string } } | null;
  customer_name?: string;
  items: { id: number; name: string; qty: number; notes?: string; voided: boolean }[];
};
type RecentOrder = {
  id: number; type: { code: string }; kot_status: { code: string }; created_at: string;
  room?: { number: string } | null;
  customer_name?: string;
};

const NEXT: Record<string, { to: string; label: string; accent: string }> = {
  new: { to: "preparing", label: "Start preparing", accent: "bg-amber-500 hover:bg-amber-400 text-amber-950" },
  preparing: { to: "ready", label: "Mark ready", accent: "bg-emerald-500 hover:bg-emerald-400 text-emerald-950" },
  ready: { to: "served", label: "Served", accent: "bg-sky-500 hover:bg-sky-400 text-sky-950" },
};

const COLS: { status: string; title: string; ring: string; dot: string }[] = [
  { status: "new", title: "NEW", ring: "border-red-500/40", dot: "bg-red-500" },
  { status: "preparing", title: "PREPARING", ring: "border-amber-500/40", dot: "bg-amber-400" },
  { status: "ready", title: "READY TO SERVE", ring: "border-emerald-500/40", dot: "bg-emerald-400" },
];

/** Kitchen Display theme — everything besides fixed accent colors (status rings, buttons) flips here. */
function theme(light: boolean) {
  return {
    page: light ? "bg-slate-50 text-slate-900" : "bg-slate-950 text-slate-100",
    toolbarBorder: light ? "border-slate-200" : "border-slate-800",
    subtitle: light ? "text-slate-500" : "text-slate-400",
    clockMain: light ? "text-slate-900" : "text-white",
    clockSub: light ? "text-slate-500" : "text-slate-500",
    divider: light ? "bg-slate-200" : "bg-slate-800",
    neutralBtn: light ? "bg-slate-100 text-slate-500 hover:bg-slate-200" : "bg-slate-800 text-slate-300 hover:bg-slate-700",
    mutedBtn: light ? "bg-slate-100 text-slate-400" : "bg-slate-800 text-slate-400",
    colTitle: light ? "text-slate-600" : "text-slate-300",
    colCount: light ? "bg-slate-200 text-slate-600" : "bg-slate-800 text-slate-300",
    card: light ? "bg-white shadow-md" : "bg-slate-900/80 shadow-lg backdrop-blur",
    roomLabel: light ? "text-slate-600" : "text-slate-300",
    itemDivider: light ? "border-slate-100" : "border-slate-800",
    itemText: light ? "text-slate-800" : "text-slate-100",
    noteBg: light ? "bg-amber-50 text-amber-700" : "bg-amber-500/10 text-amber-300",
    emptyBox: light ? "border-slate-200 text-slate-400" : "border-slate-800 text-slate-600",
    recentBar: light ? "border-slate-200" : "border-slate-800",
    recentPill: light ? "bg-slate-100 text-slate-600" : "bg-slate-900 text-slate-400",
    recentTime: light ? "text-slate-400" : "text-slate-600",
    timer: light
      ? { ok: "text-emerald-600", medium: "text-amber-600", high: "text-orange-600", critical: "text-red-600" }
      : { ok: "text-emerald-400", medium: "text-amber-400", high: "text-orange-400", critical: "text-red-400" },
  };
}

/**
 * ~4-second "new order" alert — generated with WebAudio, no asset files.
 * A soft bell tone (pitch glide + exponential decay, like a modern app
 * notification) repeated 4× so it's noticeable across a noisy kitchen
 * without being a harsh alarm.
 */
function chime(ctx: AudioContext) {
  const bell = (freq: number, start: number) => {
    const t0 = ctx.currentTime + start;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = "sine";
    osc.frequency.setValueAtTime(freq * 1.9, t0);
    osc.frequency.exponentialRampToValueAtTime(freq, t0 + 0.12);
    gain.gain.setValueAtTime(0, t0);
    gain.gain.linearRampToValueAtTime(0.28, t0 + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.9);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(t0);
    osc.stop(t0 + 0.95);
  };
  // Four "ding-dong" pulses spanning ~4 seconds — a falling fourth (880→659Hz) reads as pleasant, not alarming.
  for (const t of [0, 1.05, 2.1, 3.15]) {
    bell(880, t);
    bell(659.25, t + 0.16);
  }
}

function elapsedMins(iso: string, now: number) {
  return Math.max(0, Math.floor((now - +new Date(iso)) / 60000));
}

/** Kitchen Order Ticket screen — a full-screen Kitchen Display System for the shared kitchen monitor. */
export default function KOT() {
  const { data: kotData, reload } = useFetch<{ orders: KotOrder[] }>("/orders/kot");
  const { data: todaysData, reload: reloadToday } = useFetch<{ orders: RecentOrder[] }>("/orders?scope=today");
  const orders = kotData?.orders;
  const todays = todaysData?.orders;
  const [muted, setMuted] = useState(() => localStorage.getItem("mv.kot.muted") === "1");
  const [light, setLight] = useState(() => localStorage.getItem("mv.kot.light") === "1");
  const [fullscreen, setFullscreen] = useState(false);
  const [now, setNow] = useState(Date.now());
  const [clock, setClock] = useState(new Date());
  const containerRef = useRef<HTMLDivElement>(null);
  const audioCtxRef = useRef<AudioContext | null>(null);
  const seenIds = useRef<Set<number>>(new Set());
  const initialized = useRef(false);
  const T = theme(light);

  useEffect(() => {
    const s = getSocket();
    const onKot = () => {
      reload();
      reloadToday();
    };
    s.on("kot", onKot);
    const poll = setInterval(onKot, 30000); // safety refresh
    const tick = setInterval(() => setNow(Date.now()), 15000); // re-render elapsed timers
    const clockTick = setInterval(() => setClock(new Date()), 1000); // toolbar clock
    return () => {
      s.off("kot", onKot);
      clearInterval(poll);
      clearInterval(tick);
      clearInterval(clockTick);
    };
  }, [reload, reloadToday]);

  useEffect(() => {
    const onFsChange = () => setFullscreen(!!document.fullscreenElement);
    document.addEventListener("fullscreenchange", onFsChange);
    return () => document.removeEventListener("fullscreenchange", onFsChange);
  }, []);

  // Sound alert exactly once per new ticket that appears after the initial load.
  useEffect(() => {
    if (!orders) return;
    const currentIds = new Set(orders.map((o) => o.id));
    if (!initialized.current) {
      seenIds.current = currentIds;
      initialized.current = true;
      return;
    }
    const isNew = orders.some((o) => o.kot_status.code === "new" && !seenIds.current.has(o.id));
    if (isNew && !muted) {
      try {
        audioCtxRef.current ??= new AudioContext();
        if (audioCtxRef.current.state === "suspended") audioCtxRef.current.resume();
        chime(audioCtxRef.current);
      } catch {
        /* audio blocked — silent fail, ticket still shows visually */
      }
    }
    seenIds.current = new Set([...seenIds.current, ...currentIds]);
  }, [orders, muted]);

  const toggleMute = () => {
    const next = !muted;
    setMuted(next);
    localStorage.setItem("mv.kot.muted", next ? "1" : "0");
    // First tap doubles as the user gesture that unlocks WebAudio autoplay.
    try {
      audioCtxRef.current ??= new AudioContext();
      if (audioCtxRef.current.state === "suspended") audioCtxRef.current.resume();
    } catch {}
  };

  const toggleLight = () => {
    const next = !light;
    setLight(next);
    localStorage.setItem("mv.kot.light", next ? "1" : "0");
  };

  const toggleFullscreen = () => {
    if (!document.fullscreenElement) containerRef.current?.requestFullscreen().catch(() => {});
    else document.exitFullscreen().catch(() => {});
  };

  const queue = orders ?? [];
  const avgWait = useMemo(() => {
    const waiting = queue.filter((o) => o.kot_status.code !== "ready");
    if (waiting.length === 0) return 0;
    return Math.round(waiting.reduce((s, o) => s + elapsedMins(o.created_at, now), 0) / waiting.length);
  }, [queue, now]);

  const recentlyServed = useMemo(
    () => (todays ?? []).filter((o) => o.kot_status.code === "served").sort((a, b) => +new Date(b.created_at) - +new Date(a.created_at)).slice(0, 8),
    [todays]
  );

  return (
    <div ref={containerRef} className={clsx("flex min-h-0 flex-1 flex-col transition-colors", T.page)}>
      {/* Toolbar */}
      <div className={clsx("flex flex-wrap items-center gap-3 border-b px-4 py-3 lg:px-6", T.toolbarBorder)}>
        <ChefHat size={22} className="text-brand-400" />
        <div>
          <h1 className="text-lg font-black leading-tight lg:text-xl">Kitchen Display</h1>
          <p className={clsx("text-xs", T.subtitle)}>
            {queue.length} ticket{queue.length === 1 ? "" : "s"} in queue{avgWait > 0 && ` · avg wait ${avgWait}m`}
          </p>
        </div>
        <div className="ml-auto flex items-center gap-4">
          <div className="hidden text-right sm:block">
            <div className={clsx("font-mono text-2xl font-black tabular-nums leading-none", T.clockMain)}>
              {clock.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
            </div>
            <div className={clsx("text-[11px] font-semibold", T.clockSub)}>
              {clock.toLocaleDateString("en-GB", { weekday: "long", day: "2-digit", month: "short" })}
            </div>
          </div>
          <div className={clsx("h-8 w-px", T.divider)} />
          <button
            onClick={toggleLight}
            className={clsx("flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold transition", T.neutralBtn)}
            title={light ? "Switch to dark mode" : "Switch to light mode"}
          >
            {light ? <Moon size={15} /> : <Sun size={15} />}
          </button>
          <button
            onClick={toggleMute}
            className={clsx("flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold transition", muted ? T.mutedBtn : "bg-brand-600 text-white")}
            title={muted ? "Sound off — tap to enable new-order chime" : "Sound on — new tickets chime"}
          >
            {muted ? <VolumeX size={15} /> : <Volume2 size={15} />}
            {muted ? "Muted" : "Sound on"}
          </button>
          <button onClick={toggleFullscreen} className={clsx("flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-bold transition", T.neutralBtn)}>
            {fullscreen ? <Minimize size={15} /> : <Maximize size={15} />}
            {fullscreen ? "Exit full screen" : "Full screen"}
          </button>
        </div>
      </div>

      {/* Ticket columns */}
      <div className="grid flex-1 grid-cols-1 gap-4 overflow-y-auto p-4 md:grid-cols-3 lg:p-6">
        {COLS.map((col) => {
          const list = queue.filter((o) => o.kot_status.code === col.status);
          return (
            <div key={col.status} className="flex min-h-0 flex-col">
              <div className="mb-3 flex items-center gap-2">
                <span className={clsx("h-2.5 w-2.5 rounded-full", col.dot)} />
                <span className={clsx("text-sm font-black uppercase tracking-widest", T.colTitle)}>{col.title}</span>
                <span className={clsx("rounded-full px-2 py-0.5 text-xs font-bold", T.colCount)}>{list.length}</span>
              </div>
              <div className="space-y-3">
                {list.map((o) => {
                  const mins = elapsedMins(o.created_at, now);
                  const urgency = mins >= 15 ? "critical" : mins >= 10 ? "high" : mins >= 5 ? "medium" : "ok";
                  const timerColor = T.timer[urgency];
                  const label =
                    o.type.code === "room_guest"
                      ? `Room ${o.room?.number}${o.reservation?.guest.name ? ` — ${o.reservation.guest.name}` : ""}`
                      : o.customer_name || "Walk-in";
                  return (
                    <div
                      key={o.id}
                      className={clsx("rounded-2xl border-2 p-4 transition-colors", T.card, col.ring, urgency === "critical" && "animate-pulse border-red-500")}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <span className="text-3xl font-black leading-none lg:text-4xl">#{o.id}</span>
                        <div className="flex flex-col items-end gap-1">
                          <span className={clsx("flex items-center gap-1 font-mono text-lg font-black tabular-nums", timerColor)}>
                            <Clock3 size={16} /> {mins}m
                          </span>
                          {urgency === "critical" && <Flame size={16} className={light ? "text-red-600" : "text-red-400"} />}
                        </div>
                      </div>
                      <div className={clsx("mt-1 flex items-center gap-1.5 text-sm font-bold", T.roomLabel)}>
                        {o.type.code === "room_guest" ? <BedDouble size={14} className="shrink-0 text-sky-400" /> : <Utensils size={14} className="shrink-0 opacity-60" />}
                        <span className="truncate">{label}</span>
                        {o.type.code === "walkin" && o.dining_mode?.code === "takeaway" && (
                          <span className="ml-auto flex shrink-0 items-center gap-1 rounded-full bg-purple-500/20 px-2 py-0.5 text-[10px] font-black uppercase text-purple-500">
                            <ShoppingBag size={10} /> Takeaway
                          </span>
                        )}
                      </div>
                      <ul className={clsx("mt-3 space-y-1.5 border-t pt-3", T.itemDivider)}>
                        {o.items.filter((i) => !i.voided).map((i) => (
                          <li key={i.id} className={clsx("text-lg font-bold leading-tight lg:text-xl", T.itemText)}>
                            {i.qty} × {i.name}
                            {i.notes && <div className="text-xs font-semibold text-amber-500">→ {i.notes}</div>}
                          </li>
                        ))}
                      </ul>
                      {o.notes && <div className={clsx("mt-2 rounded-lg px-2.5 py-1.5 text-xs font-semibold", T.noteBg)}>Note: {o.notes}</div>}
                      <div className="mt-4 flex gap-2">
                        <button
                          className={clsx("flex-1 rounded-xl py-3 text-sm font-black uppercase tracking-wide transition active:scale-[.97]", NEXT[o.kot_status.code].accent)}
                          onClick={() => api(`/orders/${o.id}/kot`, { method: "PUT", body: { status: NEXT[o.kot_status.code].to } }).then(() => { reload(); reloadToday(); })}
                        >
                          {NEXT[o.kot_status.code].label}
                        </button>
                        <button className={clsx("rounded-xl px-3 transition", T.neutralBtn)} title="Print KOT ticket" onClick={() => openPdf(`/orders/${o.id}/kot-ticket`)}>
                          <Printer size={18} />
                        </button>
                      </div>
                    </div>
                  );
                })}
                {list.length === 0 && <div className={clsx("rounded-xl border-2 border-dashed p-6 text-center text-sm", T.emptyBox)}>—</div>}
              </div>
            </div>
          );
        })}
      </div>

      {/* Recently served — quick recall strip */}
      {recentlyServed.length > 0 && (
        <div className={clsx("border-t px-4 py-2.5 lg:px-6", T.recentBar)}>
          <div className="flex items-center gap-2 overflow-x-auto">
            <span className={clsx("flex shrink-0 items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide", T.subtitle)}>
              <History size={12} /> Recently served
            </span>
            {recentlyServed.map((o) => (
              <span key={o.id} className={clsx("shrink-0 whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold", T.recentPill)}>
                #{o.id} · {o.type.code === "room_guest" ? `Room ${o.room?.number}` : o.customer_name || "Walk-in"}
                <span className={clsx("ml-1.5", T.recentTime)}>{fmtDateTime(o.created_at).split(",")[1]}</span>
              </span>
            ))}
          </div>
        </div>
      )}
      {queue.length === 0 && recentlyServed.length === 0 && (
        <div className="p-6">
          <Empty text="No active kitchen tickets" />
        </div>
      )}
    </div>
  );
}
