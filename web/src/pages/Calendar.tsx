import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { ChevronLeft, ChevronRight, CalendarDays, Plus, X } from "lucide-react";
import dayjs from "dayjs";
import { useFetch } from "../lib/util";
import { Badge, Empty, Tabs } from "../components/ui";
import { NewBooking } from "./Reservations";
import clsx from "clsx";

type Room = { id: number; number: string; room_type: { name: string } };
type CalRes = {
  id: number; code: string; status: string; check_in: string; check_out: string;
  guest: string; group: string | null; room_ids: number[];
};

type View = "7" | "14" | "21" | "30" | "YEAR";
const VIEW_LABEL: Record<View, string> = { "7": "Week", "14": "2 weeks", "21": "3 weeks", "30": "Month", YEAR: "Year" };

const STATUS_STYLE: Record<string, string> = {
  CONFIRMED: "bg-sky-500/90 hover:bg-sky-600 text-white",
  PENDING: "bg-amber-400/90 hover:bg-amber-500 text-amber-950",
  CHECKED_IN: "bg-emerald-600/90 hover:bg-emerald-700 text-white",
  CHECKED_OUT: "bg-slate-300 hover:bg-slate-400 text-slate-600",
};

/**
 * Front-desk room calendar.
 * - Views: week / 2 weeks / 3 weeks / month (tape chart) + YEAR (occupancy heatmap)
 * - Click empty cells to select a stay and open a pre-filled booking form
 * - Click a heatmap day in Year view to jump the tape chart to that date
 */
export default function Calendar() {
  const [view, setView] = useState<View>("14");
  const [start, setStart] = useState(() => dayjs().subtract(1, "day").startOf("day"));
  const [year, setYear] = useState(() => dayjs().year());
  const { data: roomsResp } = useFetch<{ rooms: Room[] }>("/rooms");
  const rooms = roomsResp?.rooms;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold">
          <CalendarDays /> Room Calendar
        </h1>
        <div className="flex flex-wrap items-center gap-2">
          <Tabs
            tabs={(Object.keys(VIEW_LABEL) as View[]).map((v) => ({ id: v, label: VIEW_LABEL[v] }))}
            active={view}
            onChange={setView}
          />
          {view === "YEAR" ? (
            <div className="flex items-center gap-1.5">
              <button className="btn-secondary !px-2.5" onClick={() => setYear(year - 1)}><ChevronLeft size={16} /></button>
              <span className="w-14 text-center text-sm font-extrabold">{year}</span>
              <button className="btn-secondary !px-2.5" onClick={() => setYear(year + 1)}><ChevronRight size={16} /></button>
            </div>
          ) : (
            <div className="flex items-center gap-1.5">
              <button className="btn-secondary !px-2.5" onClick={() => setStart(start.subtract(7, "day"))}><ChevronLeft size={16} /></button>
              <button className="btn-secondary" onClick={() => setStart(dayjs().subtract(1, "day").startOf("day"))}>Today</button>
              <button className="btn-secondary !px-2.5" onClick={() => setStart(start.add(7, "day"))}><ChevronRight size={16} /></button>
              <input
                type="date"
                className="input !w-36 !py-1.5"
                title="Jump to date"
                value={start.format("YYYY-MM-DD")}
                onChange={(e) => e.target.value && setStart(dayjs(e.target.value).startOf("day"))}
              />
            </div>
          )}
        </div>
      </div>

      {view === "YEAR" ? (
        <YearView
          year={year}
          rooms={rooms ?? []}
          onPickDay={(date) => {
            setStart(dayjs(date).subtract(1, "day").startOf("day"));
            setView("14");
          }}
        />
      ) : (
        <TapeChart daysShown={parseInt(view)} start={start} rooms={rooms ?? []} />
      )}
    </div>
  );
}

// ─────────────────────────────── Tape chart ─────────────────────────────────
function TapeChart({ daysShown, start, rooms }: { daysShown: number; start: dayjs.Dayjs; rooms: Room[] }) {
  const from = start.format("YYYY-MM-DD");
  const to = start.add(daysShown, "day").format("YYYY-MM-DD");
  const { data: calResp, reload } = useFetch<{ reservations: CalRes[] }>(`/reservations/calendar?from=${from}&to=${to}`, [from, to]);
  const reservations = calResp?.reservations;
  const nav = useNavigate();
  const [sel, setSel] = useState<{ roomId: number; startDay: string; endDay: string } | null>(null);
  const [bookingOpen, setBookingOpen] = useState(false);

  const days = useMemo(() => Array.from({ length: daysShown }, (_, i) => start.add(i, "day")), [start, daysShown]);
  const today = dayjs().startOf("day");
  const cellW = daysShown <= 7 ? 88 : daysShown <= 14 ? 52 : daysShown <= 21 ? 40 : 32;

  const grid = useMemo(() => {
    const map = new Map<number, (CalRes | null)[]>();
    for (const room of rooms) {
      map.set(
        room.id,
        days.map(
          (d) =>
            (reservations ?? []).find(
              (r) => r.room_ids.includes(room.id) && !d.isBefore(dayjs(r.check_in), "day") && d.isBefore(dayjs(r.check_out), "day")
            ) ?? null
        )
      );
    }
    return map;
  }, [rooms, reservations, days]);

  // Per-day occupied-room counts for the totals row
  const occupancyPerDay = useMemo(
    () =>
      days.map((_, i) => {
        let n = 0;
        for (const room of rooms) if (grid.get(room.id)?.[i]) n++;
        return n;
      }),
    [days, rooms, grid]
  );

  const rangeFree = (roomId: number, a: dayjs.Dayjs, b: dayjs.Dayjs) => {
    const cells = grid.get(roomId) ?? [];
    for (let i = 0; i < days.length; i++) {
      if (!days[i].isBefore(a, "day") && !days[i].isAfter(b, "day") && cells[i]) return false;
    }
    return true;
  };

  const clickEmpty = (roomId: number, day: dayjs.Dayjs) => {
    const dstr = day.format("YYYY-MM-DD");
    if (sel && sel.roomId === roomId) {
      const s = dayjs(sel.startDay);
      if (day.isBefore(s, "day") || !rangeFree(roomId, s, day)) return setSel({ roomId, startDay: dstr, endDay: dstr });
      return setSel({ ...sel, endDay: dstr });
    }
    setSel({ roomId, startDay: dstr, endDay: dstr });
  };

  const selNights = sel ? dayjs(sel.endDay).diff(dayjs(sel.startDay), "day") + 1 : 0;
  const selRoom = rooms.find((r) => r.id === sel?.roomId);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3 text-xs">
        {Object.entries({ CONFIRMED: "Confirmed", CHECKED_IN: "Checked in", PENDING: "Pending", CHECKED_OUT: "Checked out" }).map(([k, label]) => (
          <span key={k} className="flex items-center gap-1.5">
            <span className={clsx("h-3 w-3 rounded", STATUS_STYLE[k].split(" ")[0])} /> {label}
          </span>
        ))}
        <span className="text-slate-400">Click an empty cell to start a booking · click a later free cell in the same row to extend</span>
      </div>

      {sel && (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm shadow-sm">
          <span className="font-bold text-brand-900">
            Room {selRoom?.number} · {dayjs(sel.startDay).format("DD MMM")} → {dayjs(sel.endDay).add(1, "day").format("DD MMM")}
          </span>
          <Badge color="brand">{selNights} night{selNights === 1 ? "" : "s"}</Badge>
          <button className="btn-primary !py-1.5" onClick={() => setBookingOpen(true)}>
            <Plus size={14} /> Book this
          </button>
          <button className="btn-ghost !py-1.5" onClick={() => setSel(null)}>
            <X size={14} /> Clear
          </button>
        </div>
      )}

      <div className="card overflow-x-auto">
        <table className="w-full border-collapse" style={{ minWidth: daysShown * cellW + 90 }}>
          <thead>
            <tr>
              <th className="sticky left-0 z-10 border-b border-r border-slate-200 bg-white px-2 py-1.5 text-left text-xs font-bold">Room</th>
              {days.map((d) => {
                const isToday = d.isSame(today, "day");
                const weekend = d.day() === 0 || d.day() === 6;
                return (
                  <th
                    key={d.format()}
                    style={{ width: cellW }}
                    className={clsx(
                      "border-b border-slate-200 px-0.5 py-1 text-center text-[10px] font-semibold leading-tight",
                      isToday ? "bg-brand-100 text-brand-800" : weekend ? "bg-slate-50 text-slate-500" : "text-slate-500"
                    )}
                  >
                    {d.format("dd")}
                    <br />
                    <span className={clsx("text-xs", isToday && "font-black")}>{d.format("D")}</span>
                    {(d.date() === 1 || d.isSame(start, "day")) && <div className="text-[9px] text-slate-400">{d.format("MMM")}</div>}
                  </th>
                );
              })}
            </tr>
            {/* Occupancy totals row */}
            <tr>
              <th className="sticky left-0 z-10 border-b border-r border-slate-200 bg-white px-2 py-0.5 text-left text-[9px] font-bold uppercase tracking-wide text-slate-400">
                Occupancy
              </th>
              {occupancyPerDay.map((n, i) => {
                const pct = rooms.length ? n / rooms.length : 0;
                return (
                  <th
                    key={i}
                    className={clsx(
                      "border-b border-slate-200 py-0.5 text-center text-[9px] font-bold tabular-nums",
                      pct >= 0.9 ? "bg-red-50 text-red-600" : pct >= 0.6 ? "bg-amber-50 text-amber-700" : "text-slate-400"
                    )}
                    title={`${n}/${rooms.length} rooms occupied`}
                  >
                    {n > 0 ? n : "·"}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {rooms.map((room) => {
              const cells = grid.get(room.id) ?? [];
              return (
                <tr key={room.id} className="border-b border-slate-100">
                  <td className="sticky left-0 z-10 border-r border-slate-200 bg-white px-2 py-1">
                    <div className="text-sm font-extrabold">{room.number}</div>
                    <div className="max-w-[70px] truncate text-[9px] text-slate-400">{room.room_type.name}</div>
                  </td>
                  {cells.map((res, i) => {
                    const d = days[i];
                    const isToday = d.isSame(today, "day");
                    const weekend = d.day() === 0 || d.day() === 6;
                    if (!res) {
                      const inSel =
                        sel?.roomId === room.id && !d.isBefore(dayjs(sel.startDay), "day") && !d.isAfter(dayjs(sel.endDay), "day");
                      return (
                        <td key={i} className={clsx("h-10 border-r border-slate-50 p-0", isToday && "bg-brand-50", weekend && !isToday && "bg-slate-50/60")}>
                          <button
                            className={clsx("group flex h-full w-full items-center justify-center transition", inSel ? "bg-brand-500/80" : "hover:bg-brand-100")}
                            title={`Book room ${room.number} — night of ${d.format("DD MMM")}`}
                            onClick={() => clickEmpty(room.id, d)}
                          >
                            {inSel ? (
                              <span className="text-[10px] font-black text-white">✓</span>
                            ) : (
                              <Plus size={12} className="text-brand-500 opacity-0 transition group-hover:opacity-100" />
                            )}
                          </button>
                        </td>
                      );
                    }
                    const isStart = i === 0 || cells[i - 1]?.id !== res.id;
                    return (
                      <td key={i} className={clsx("h-10 border-r border-slate-50 p-0", isToday && "bg-brand-50")}>
                        <button
                          className={clsx(
                            "block h-8 w-full cursor-pointer overflow-hidden whitespace-nowrap px-1 text-left text-[10px] font-bold leading-8 transition",
                            STATUS_STYLE[res.status.toUpperCase()] ?? "bg-slate-400 text-white",
                            isStart && "ml-0.5 rounded-l-md",
                            (i === cells.length - 1 || cells[i + 1]?.id !== res.id) && "rounded-r-md"
                          )}
                          title={`${res.code} · ${res.guest} (${res.status.toUpperCase()})${res.group ? ` · ${res.group}` : ""}`}
                          onClick={() => nav(`/reservations/${res.id}`)}
                        >
                          {isStart ? res.guest : ""}
                        </button>
                      </td>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
        {rooms.length === 0 && <Empty text="Loading rooms…" />}
      </div>

      {bookingOpen && sel && (
        <NewBooking
          initial={{
            checkIn: sel.startDay,
            checkOut: dayjs(sel.endDay).add(1, "day").format("YYYY-MM-DD"),
            roomIds: [sel.roomId],
          }}
          onClose={() => setBookingOpen(false)}
          onCreated={(id) => {
            setBookingOpen(false);
            setSel(null);
            reload();
            nav(`/reservations/${id}`);
          }}
        />
      )}
    </div>
  );
}

// ─────────────────────────── Year occupancy heatmap ─────────────────────────
function YearView({ year, rooms, onPickDay }: { year: number; rooms: Room[]; onPickDay: (date: string) => void }) {
  const from = `${year}-01-01`;
  const to = `${year + 1}-01-01`;
  const { data: calResp } = useFetch<{ reservations: CalRes[] }>(`/reservations/calendar?from=${from}&to=${to}`, [from, to]);
  const reservations = calResp?.reservations;
  const totalRooms = rooms.length || 1;

  // date → number of occupied rooms that night
  const occ = useMemo(() => {
    const m = new Map<string, number>();
    for (const r of reservations ?? []) {
      let d = dayjs(r.check_in).isBefore(from) ? dayjs(from) : dayjs(r.check_in);
      const end = dayjs(r.check_out).isAfter(to) ? dayjs(to) : dayjs(r.check_out);
      while (d.isBefore(end, "day")) {
        const key = d.format("YYYY-MM-DD");
        m.set(key, (m.get(key) ?? 0) + r.room_ids.length);
        d = d.add(1, "day");
      }
    }
    return m;
  }, [reservations, from, to]);

  const heat = (pct: number) => {
    if (pct === 0) return "bg-slate-100 hover:bg-slate-200";
    if (pct < 0.25) return "bg-brand-100 hover:bg-brand-500/40";
    if (pct < 0.5) return "bg-brand-500/40 hover:bg-brand-500/60";
    if (pct < 0.75) return "bg-brand-500/70 hover:bg-brand-500";
    if (pct < 1) return "bg-brand-600 hover:bg-brand-700";
    return "bg-brand-900 hover:bg-black";
  };
  const heatText = (pct: number) => (pct < 0.5 ? "text-slate-600" : "text-white");

  const today = dayjs().format("YYYY-MM-DD");
  const yearTotal = [...occ.values()].reduce((s, n) => s + n, 0);
  const daysElapsed = Math.max(1, Math.min(dayjs().diff(dayjs(from), "day") + 1, 365));
  const avgPct = year === dayjs().year() ? Math.round((yearTotal / (daysElapsed * totalRooms)) * 100) : Math.round((yearTotal / (365 * totalRooms)) * 100);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3 text-xs">
        <span className="font-semibold text-slate-600">Occupancy heatmap — darker = fuller</span>
        <span className="flex items-center gap-1">
          <span className="h-3 w-3 rounded bg-slate-100" /> 0%
          <span className="ml-1 h-3 w-3 rounded bg-brand-100" />
          <span className="h-3 w-3 rounded bg-brand-500/40" />
          <span className="h-3 w-3 rounded bg-brand-500/70" />
          <span className="h-3 w-3 rounded bg-brand-600" />
          <span className="h-3 w-3 rounded bg-brand-900" /> 100%
        </span>
        <Badge color="brand">{year} average: {avgPct}%</Badge>
        <span className="text-slate-400">Click a day to open it in the 2-week chart</span>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        {Array.from({ length: 12 }).map((_, m) => {
          const first = dayjs(new Date(year, m, 1));
          const blanks = first.day();
          const dim = first.daysInMonth();
          return (
            <div key={m} className="card p-3">
              <div className="mb-2 text-center text-xs font-extrabold uppercase tracking-wide text-slate-600">{first.format("MMMM")}</div>
              <div className="grid grid-cols-7 gap-1">
                {["S", "M", "T", "W", "T", "F", "S"].map((d, i) => (
                  <div key={i} className="text-center text-[8px] font-bold text-slate-300">{d}</div>
                ))}
                {Array.from({ length: blanks }).map((_, i) => (
                  <div key={`b${i}`} />
                ))}
                {Array.from({ length: dim }).map((_, i) => {
                  const dateStr = first.add(i, "day").format("YYYY-MM-DD");
                  const n = Math.min(occ.get(dateStr) ?? 0, totalRooms);
                  const pct = n / totalRooms;
                  return (
                    <button
                      key={dateStr}
                      onClick={() => onPickDay(dateStr)}
                      title={`${dayjs(dateStr).format("DD MMM YYYY")} — ${n}/${totalRooms} rooms (${Math.round(pct * 100)}%)`}
                      className={clsx(
                        "flex aspect-square w-full items-center justify-center rounded-[4px] text-[9px] font-bold leading-none transition",
                        heat(pct),
                        heatText(pct),
                        dateStr === today && "ring-2 ring-red-400 ring-offset-1"
                      )}
                    >
                      {i + 1}
                    </button>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
