import { useEffect, useMemo, useState } from "react";
import {
  Minus, Plus, Printer, Send, PauseCircle, PlayCircle, BedDouble, User, RefreshCw,
  Search, StickyNote, Trash2, UtensilsCrossed, Timer, ShoppingBag,
} from "lucide-react";
import { api, openPdf, post } from "../lib/api";
import { posRequest } from "../lib/offline";
import { lkr, toCents, useFetch, useSettings, usd } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor, Tabs } from "../components/ui";
import { getSocket } from "../lib/socket";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type MenuItem = { id: number; item_no?: number | null; name: string; price: number; sold_out: boolean; description: string };
type MenuCat = { id: number; name: string; is_minibar: boolean; items: MenuItem[] };
type BoardRoom = { id: number; number: string; status: { code: string }; occupant: { code: string; guest: { name: string } } | null };
type Order = {
  id: number; type: { code: string }; dining_mode: { code: string }; status: { code: string }; kot_status: { code: string }; created_at: string;
  customer_name?: string; subtotal: number; discount: number; discount_reason?: string; service_charge: number; vat: number; total: number;
  room?: { id: number; number: string } | null;
  reservation?: { code: string; guest: { id: number; name: string } } | null;
  items: { id: number; name: string; qty: number; unit_price: number; amount: number; voided: boolean }[];
  payments: { id: number; method: { code: string }; amount: number; kind: { code: string } }[];
  staff: { name: string };
};

type CartLine = { menuItemId: number; name: string; price: number; qty: number; notes?: string };

const PAY_METHODS = ["cash", "card", "lankaqr", "bank_transfer"] as const;

const minsAgo = (iso: string) => Math.max(0, Math.round((Date.now() - +new Date(iso)) / 60000));

export default function POS() {
  const { can } = useAuth();
  const canCreate = can("hotel_orders.create");
  const [view, setView] = useState<"new" | "open">(canCreate ? "new" : "open");
  const { data: menuData, reload: reloadMenu } = useFetch<{ categories: MenuCat[] }>("/menu/full");
  const { data: roomsData } = useFetch<{ rooms: BoardRoom[] }>("/rooms");
  const { data: activeData, reload: reloadActive } = useFetch<{ orders: Order[] }>("/orders?scope=active");
  const { data: todaysData, reload: reloadToday } = useFetch<{ orders: Order[] }>("/orders?scope=today");
  const menu = menuData?.categories;
  const active = activeData?.orders;
  const todays = todaysData?.orders;
  const { num } = useSettings();
  const usdRate = num("currency.usd_rate", 0);

  // Realtime: menu sold-out changes + order/KOT updates
  useEffect(() => {
    const s = getSocket();
    const orders = () => {
      reloadActive();
      reloadToday();
    };
    s.on("menu", reloadMenu);
    s.on("kot", orders);
    s.on("orders", orders);
    return () => {
      s.off("menu", reloadMenu);
      s.off("kot", orders);
      s.off("orders", orders);
    };
  }, [reloadMenu, reloadActive, reloadToday]);

  // cache for offline reloads
  useEffect(() => {
    if (menu) localStorage.setItem("mv.cache.menu", JSON.stringify(menu));
    if (roomsData?.rooms) localStorage.setItem("mv.cache.board", JSON.stringify(roomsData.rooms));
  }, [menu, roomsData]);
  const menuData_: MenuCat[] = menu ?? JSON.parse(localStorage.getItem("mv.cache.menu") ?? "[]");
  const boardData: BoardRoom[] = roomsData?.rooms ?? JSON.parse(localStorage.getItem("mv.cache.board") ?? "[]");
  const occupiedRooms = boardData.filter((r) => r.status.code === "occupied" && r.occupant);

  const openCount = (active ?? []).filter((o) => o.status.code === "open").length;
  const parkedCount = (active ?? []).filter((o) => o.status.code === "parked").length;
  const todaysSales = (todays ?? []).filter((o) => o.status.code === "settled" || o.status.code === "charged_to_room").reduce((s, o) => s + o.total, 0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><UtensilsCrossed /> Restaurant POS</h1>
        <div className="flex flex-wrap items-center gap-2">
          <span className="hidden rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 shadow-sm sm:inline">
            Today: <span className="text-brand-700">{lkr(todaysSales)}</span>
          </span>
          <Tabs
            tabs={[
              ...(canCreate ? [{ id: "new" as const, label: "New order" }] : []),
              { id: "open" as const, label: `Open orders${openCount + parkedCount > 0 ? ` (${openCount + parkedCount})` : ""}` },
            ]}
            active={view}
            onChange={setView}
          />
        </div>
      </div>
      {view === "new" ? (
        <NewOrder
          menu={menuData_}
          rooms={occupiedRooms}
          usdRate={usdRate}
          scPct={num("billing.service_charge_pct", 0)}
          vatPct={num("billing.vat_pct", 0)}
          onDone={() => {
            reloadActive();
            reloadToday();
            setView("open");
          }}
        />
      ) : (
        <OpenOrders active={active ?? []} todays={todays ?? []} usdRate={usdRate} reload={() => { reloadActive(); reloadToday(); }} />
      )}
    </div>
  );
}

// ── New order ─────────────────────────────────────────────────────────────────
function NewOrder({ menu, rooms, usdRate, scPct, vatPct, onDone }: {
  menu: MenuCat[]; rooms: BoardRoom[]; usdRate: number; scPct: number; vatPct: number; onDone: () => void;
}) {
  const toast = useToast();
  const [catId, setCatId] = useState<string>("ALL");
  const [q, setQ] = useState("");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [type, setType] = useState<"walkin" | "room_guest">("walkin");
  const [diningMode, setDiningMode] = useState<"dine_in" | "takeaway">("dine_in");
  const [roomId, setRoomId] = useState("");
  const [customerName, setCustomerName] = useState("");
  const [notes, setNotes] = useState("");
  const [noteFor, setNoteFor] = useState<number | null>(null); // cart line note editor
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [queuedMsg, setQueuedMsg] = useState("");
  const [quickNo, setQuickNo] = useState("");

  const allItems = useMemo(() => menu.flatMap((c) => c.items), [menu]);
  const gridItems = useMemo(() => {
    let list = catId === "ALL" ? allItems : (menu.find((c) => String(c.id) === catId)?.items ?? []);
    if (q.trim()) {
      const needle = q.toLowerCase();
      list = list.filter((i) => i.name.toLowerCase().includes(needle) || String(i.item_no ?? "").includes(needle));
    }
    return list;
  }, [menu, allItems, catId, q]);

  const subtotal = cart.reduce((s, l) => s + l.price * l.qty, 0);
  // Takeaway is exempt from service charge — no table service (VAT still applies)
  const takeaway = type === "walkin" && diningMode === "takeaway";
  const sc = takeaway ? 0 : Math.round((subtotal * scPct) / 100);
  const vat = Math.round(((subtotal + sc) * vatPct) / 100);
  const itemCount = cart.reduce((s, l) => s + l.qty, 0);

  const add = (item: MenuItem) => {
    setCart((c) => {
      const existing = c.find((l) => l.menuItemId === item.id);
      if (existing) return c.map((l) => (l.menuItemId === item.id ? { ...l, qty: l.qty + 1 } : l));
      return [...c, { menuItemId: item.id, name: item.name, price: item.price, qty: 1 }];
    });
  };
  const setQty = (id: number, qty: number) =>
    setCart((c) => (qty <= 0 ? c.filter((l) => l.menuItemId !== id) : c.map((l) => (l.menuItemId === id ? { ...l, qty } : l))));

  const quickAdd = () => {
    const no = parseInt(quickNo);
    if (!no) return;
    const item = allItems.find((i) => i.item_no === no);
    if (!item) return setError(`No menu item #${no}`);
    if (item.sold_out) return setError(`#${no} ${item.name} is sold out`);
    setError("");
    add(item);
    setQuickNo("");
  };

  const send = async () => {
    setError("");
    if (cart.length === 0) return setError("Add items first");
    if (type === "room_guest" && !roomId) return setError("Select the guest's room");
    setBusy(true);
    try {
      const res = await posRequest("/orders", {
        client_key: crypto.randomUUID(),
        type,
        dining_mode: type === "walkin" ? diningMode : undefined,
        room_id: type === "room_guest" ? Number(roomId) : undefined,
        customer_name: type === "walkin" ? customerName || undefined : undefined,
        notes: notes || undefined,
        items: cart.map((l) => ({ menu_item_id: l.menuItemId, qty: l.qty, notes: l.notes })),
      });
      setCart([]);
      setCustomerName("");
      setNotes("");
      setDiningMode("dine_in");
      if ((res as { queued?: boolean }).queued) {
        setQueuedMsg("No connection — order saved and will sync to the kitchen automatically when back online. Print the slip from Open Orders after sync.");
        toast.warning("Order queued offline", "Will sync to the kitchen automatically when back online");
      } else {
        const created = (res as { order: { id: number } }).order;
        toast.success(`Order #${created.id} sent to kitchen`);
        if (type === "walkin") {
          openPdf(`/orders/${created.id}/slip`).catch(() => {});
        }
        onDone();
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_370px]">
      <div className="space-y-3">
        {/* Search + categories */}
        <div className="flex flex-wrap items-center gap-1.5">
          <div className="relative">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className="input !w-52 !py-1.5 !pl-8" placeholder="Search / #no…" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          <button
            onClick={() => setCatId("ALL")}
            className={clsx("rounded-full px-3.5 py-1.5 text-sm font-semibold transition", catId === "ALL" ? "bg-brand-600 text-white" : "bg-white text-slate-600 shadow-sm hover:bg-slate-50")}
          >
            All
          </button>
          {menu.map((c) => (
            <button
              key={c.id}
              onClick={() => setCatId(String(c.id))}
              className={clsx("rounded-full px-3.5 py-1.5 text-sm font-semibold transition", catId === String(c.id) ? "bg-brand-600 text-white" : "bg-white text-slate-600 shadow-sm hover:bg-slate-50")}
            >
              {c.name}
            </button>
          ))}
        </div>

        {/* Item grid */}
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
          {gridItems.map((item) => {
            const inCart = cart.find((l) => l.menuItemId === item.id);
            return (
              <button
                key={item.id}
                disabled={item.sold_out}
                onClick={() => add(item)}
                className={clsx(
                  "card relative p-3 text-left transition",
                  item.sold_out ? "opacity-40" : "hover:-translate-y-0.5 hover:shadow-md active:scale-[.98]",
                  inCart && "ring-2 ring-brand-500"
                )}
              >
                <div className="flex items-start justify-between gap-1">
                  {item.item_no != null && <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-black text-slate-500">#{item.item_no}</span>}
                  {inCart && <span className="rounded-full bg-brand-600 px-1.5 py-0.5 text-[10px] font-black text-white">{inCart.qty}</span>}
                </div>
                <div className="mt-1 text-sm font-bold leading-tight">{item.name}</div>
                <div className="mt-1 text-sm font-semibold text-brand-600">{lkr(item.price)}</div>
                {item.sold_out && <Badge color="red">SOLD OUT</Badge>}
              </button>
            );
          })}
          {gridItems.length === 0 && <Empty text="No items match" />}
        </div>
      </div>

      {/* Cart */}
      <div className="card h-fit p-4 lg:sticky lg:top-16">
        <div className="mb-3 flex items-center gap-2">
          <span className="font-mono text-xs font-black text-slate-400">#</span>
          <input
            className="input !py-1.5"
            inputMode="numeric"
            placeholder="Quick add by menu number + Enter"
            value={quickNo}
            onChange={(e) => setQuickNo(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && quickAdd()}
          />
        </div>
        <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
          <button className={clsx("flex-1 rounded-lg px-2 py-1.5 text-sm font-semibold", type === "walkin" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setType("walkin")}>
            <User size={14} className="mr-1 inline" /> Walk-in
          </button>
          <button className={clsx("flex-1 rounded-lg px-2 py-1.5 text-sm font-semibold", type === "room_guest" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setType("room_guest")}>
            <BedDouble size={14} className="mr-1 inline" /> Room guest
          </button>
        </div>
        {type === "room_guest" ? (
          <select className="input mb-3" value={roomId} onChange={(e) => setRoomId(e.target.value)}>
            <option value="">Select occupied room…</option>
            {rooms.map((r) => (
              <option key={r.id} value={r.id}>Room {r.number} — {r.occupant?.guest.name}</option>
            ))}
          </select>
        ) : (
          <>
            <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
              <button
                className={clsx("flex-1 rounded-lg px-2 py-1.5 text-xs font-semibold", diningMode === "dine_in" ? "bg-white shadow-sm" : "text-slate-500")}
                onClick={() => setDiningMode("dine_in")}
              >
                <UtensilsCrossed size={13} className="mr-1 inline" /> Dine-in
              </button>
              <button
                className={clsx("flex-1 rounded-lg px-2 py-1.5 text-xs font-semibold", diningMode === "takeaway" ? "bg-white shadow-sm" : "text-slate-500")}
                onClick={() => setDiningMode("takeaway")}
              >
                <ShoppingBag size={13} className="mr-1 inline" /> Takeaway
              </button>
            </div>
            <input className="input mb-3" placeholder="Customer / table label (optional)" value={customerName} onChange={(e) => setCustomerName(e.target.value)} />
          </>
        )}

        {cart.length === 0 ? (
          <Empty text="Tap menu items to add" />
        ) : (
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <span className="text-xs font-bold uppercase tracking-wide text-slate-400">{itemCount} item{itemCount === 1 ? "" : "s"}</span>
              <button className="text-xs font-bold text-red-400 hover:text-red-600" onClick={() => setCart([])}>
                <Trash2 size={11} className="mr-0.5 inline" /> Clear
              </button>
            </div>
            {cart.map((l) => (
              <div key={l.menuItemId}>
                <div className="flex items-center gap-2 text-sm">
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-semibold">{l.name}</div>
                    <div className="text-xs text-slate-400">{lkr(l.price)}</div>
                  </div>
                  <button
                    className={clsx("btn-ghost !p-1", l.notes ? "text-amber-500" : "text-slate-300")}
                    title={l.notes ? `Note: ${l.notes}` : "Add kitchen note for this item"}
                    onClick={() => setNoteFor(noteFor === l.menuItemId ? null : l.menuItemId)}
                  >
                    <StickyNote size={13} />
                  </button>
                  <button className="btn-secondary !p-1" onClick={() => setQty(l.menuItemId, l.qty - 1)}><Minus size={13} /></button>
                  <span className="w-6 text-center font-bold">{l.qty}</span>
                  <button className="btn-secondary !p-1" onClick={() => setQty(l.menuItemId, l.qty + 1)}><Plus size={13} /></button>
                  <span className="w-20 text-right font-semibold">{lkr(l.price * l.qty)}</span>
                </div>
                {noteFor === l.menuItemId && (
                  <input
                    className="input mt-1 !py-1 text-xs"
                    placeholder="e.g. extra spicy, no onions…"
                    value={l.notes ?? ""}
                    autoFocus
                    onChange={(e) => setCart(cart.map((x) => (x.menuItemId === l.menuItemId ? { ...x, notes: e.target.value || undefined } : x)))}
                    onKeyDown={(e) => e.key === "Enter" && setNoteFor(null)}
                    onBlur={() => setNoteFor(null)}
                  />
                )}
              </div>
            ))}
          </div>
        )}

        <textarea className="input mt-3" rows={2} placeholder="Kitchen note for the whole order…" value={notes} onChange={(e) => setNotes(e.target.value)} />

        <div className="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
          <div className="flex justify-between"><span>Subtotal</span><span>{lkr(subtotal)}</span></div>
          {scPct > 0 && !takeaway && <div className="flex justify-between text-slate-500"><span>Service charge {scPct}%</span><span>{lkr(sc)}</span></div>}
          {scPct > 0 && takeaway && <div className="flex justify-between text-emerald-600"><span>Service charge</span><span>waived (takeaway)</span></div>}
          {vatPct > 0 && <div className="flex justify-between text-slate-500"><span>VAT {vatPct}%</span><span>{lkr(vat)}</span></div>}
          <div className="flex justify-between text-base font-extrabold"><span>Total</span><span>{lkr(subtotal + sc + vat)}</span></div>
          {usdRate > 0 && subtotal > 0 && <div className="text-right text-xs text-slate-400">{usd(subtotal + sc + vat, usdRate)}</div>}
        </div>

        <ErrorText error={error} />
        {queuedMsg && <div className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">{queuedMsg}</div>}
        <button className="btn-primary mt-3 w-full !py-3" disabled={busy || cart.length === 0} onClick={send}>
          <Send size={16} /> Send to kitchen{type === "walkin" ? " + print slip" : ""}
        </button>
      </div>
    </div>
  );
}

// ── Open orders ───────────────────────────────────────────────────────────────
type OrderFilter = "ALL" | "OPEN" | "PARKED" | "ROOM" | "WALKIN";

function OpenOrders({ active, todays, usdRate, reload }: { active: Order[]; todays: Order[]; usdRate: number; reload: () => void }) {
  const [selected, setSelected] = useState<number | null>(null);
  const [filter, setFilter] = useState<OrderFilter>("ALL");
  const [q, setQ] = useState("");

  const shown = useMemo(() => {
    let list = active;
    if (filter === "OPEN") list = list.filter((o) => o.status.code === "open");
    if (filter === "PARKED") list = list.filter((o) => o.status.code === "parked");
    if (filter === "ROOM") list = list.filter((o) => o.type.code === "room_guest");
    if (filter === "WALKIN") list = list.filter((o) => o.type.code === "walkin");
    if (q.trim()) {
      const needle = q.toLowerCase();
      list = list.filter(
        (o) =>
          String(o.id).includes(needle) ||
          (o.customer_name ?? "").toLowerCase().includes(needle) ||
          (o.room?.number ?? "").includes(needle) ||
          (o.reservation?.guest.name ?? "").toLowerCase().includes(needle)
      );
    }
    return list;
  }, [active, filter, q]);

  const finished = todays.filter((o) => o.status.code === "settled" || o.status.code === "charged_to_room" || o.status.code === "void");

  const FILTERS: { id: OrderFilter; label: string }[] = [
    { id: "ALL", label: `All (${active.length})` },
    { id: "OPEN", label: "Open" },
    { id: "PARKED", label: "Parked" },
    { id: "ROOM", label: "Rooms" },
    { id: "WALKIN", label: "Walk-ins" },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !w-56 !py-1.5 !pl-8" placeholder="Search #, name, room…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <div className="flex gap-1 rounded-xl bg-slate-200/70 p-1">
          {FILTERS.map((f) => (
            <button
              key={f.id}
              onClick={() => setFilter(f.id)}
              className={clsx("rounded-lg px-3 py-1.5 text-xs font-semibold transition", filter === f.id ? "bg-white shadow-sm" : "text-slate-500 hover:text-slate-800")}
            >
              {f.label}
            </button>
          ))}
        </div>
        <button className="btn-ghost !py-1.5" onClick={reload}><RefreshCw size={14} /></button>
      </div>

      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        {shown.map((o) => {
          const mins = minsAgo(o.created_at);
          const paid = o.payments.filter((p) => p.kind.code !== "refund").reduce((s, p) => s + p.amount, 0);
          const kotColor = o.kot_status.code === "new" ? "bg-red-400" : o.kot_status.code === "preparing" ? "bg-amber-400" : o.kot_status.code === "ready" ? "bg-emerald-500" : "bg-slate-300";
          return (
            <button key={o.id} className="card relative overflow-hidden p-3 pl-4 text-left transition hover:-translate-y-0.5 hover:shadow-md" onClick={() => setSelected(o.id)}>
              <span className={clsx("absolute inset-y-0 left-0 w-1.5", kotColor)} />
              <div className="flex items-center justify-between">
                <span className="font-extrabold">#{o.id}</span>
                <div className="flex gap-1">
                  <Badge color={statusColor(o.kot_status.code)}>{o.kot_status.code.toUpperCase()}</Badge>
                  {o.status.code === "parked" && <Badge color="amber">PARKED</Badge>}
                  {o.type.code === "walkin" && o.dining_mode.code === "takeaway" && <Badge color="purple">TAKEAWAY</Badge>}
                </div>
              </div>
              <div className="mt-1 truncate text-sm font-semibold text-slate-700">
                {o.type.code === "room_guest" ? `Room ${o.room?.number} — ${o.reservation?.guest.name ?? ""}` : o.customer_name || "Walk-in"}
              </div>
              <div className="mt-0.5 truncate text-xs text-slate-400">
                {o.items.filter((i) => !i.voided).slice(0, 3).map((i) => `${i.qty}× ${i.name}`).join(", ")}
                {o.items.filter((i) => !i.voided).length > 3 && "…"}
              </div>
              <div className="mt-1.5 flex items-center justify-between">
                <span className={clsx("flex items-center gap-1 text-xs font-bold", mins >= 20 ? "text-red-500" : mins >= 10 ? "text-amber-600" : "text-slate-400")}>
                  <Timer size={12} /> {mins}m
                </span>
                <span className="font-bold text-brand-700">
                  {lkr(o.total)} {paid > 0 && <span className="text-[10px] font-semibold text-emerald-600">paid {lkr(paid)}</span>}
                </span>
              </div>
            </button>
          );
        })}
        {shown.length === 0 && <Empty text="No open orders — new orders appear here" />}
      </div>

      {finished.length > 0 && (
        <Card title={`Finished today (${finished.length})`}>
          <div className="divide-y divide-slate-100">
            {finished.map((o) => (
              <button key={o.id} className="flex w-full items-center justify-between py-2 text-sm hover:bg-slate-50" onClick={() => setSelected(o.id)}>
                <span className="font-semibold">#{o.id} · {o.type.code === "room_guest" ? `Room ${o.room?.number}` : o.customer_name || "Walk-in"}</span>
                <span className="flex items-center gap-2">
                  <Badge color={statusColor(o.status.code)}>{o.status.code.toUpperCase()}</Badge>
                  <span className="font-semibold">{lkr(o.total)}</span>
                </span>
              </button>
            ))}
          </div>
        </Card>
      )}

      {selected && (
        <OrderModal
          orderId={selected}
          usdRate={usdRate}
          onClose={() => {
            setSelected(null);
            reload();
          }}
        />
      )}
    </div>
  );
}

// ── Order detail modal ────────────────────────────────────────────────────────
function OrderModal({ orderId, usdRate, onClose }: { orderId: number; usdRate: number; onClose: () => void }) {
  const { data, reload } = useFetch<{ order: Order }>(`/orders/${orderId}`);
  const order = data?.order;
  const { can } = useAuth();
  const toast = useToast();
  const [error, setError] = useState("");
  const [payOpen, setPayOpen] = useState(false);
  const [discountOpen, setDiscountOpen] = useState(false);
  const [reasonAction, setReasonAction] = useState<"void" | "refund" | null>(null);
  const [voidingItem, setVoidingItem] = useState<Order["items"][number] | null>(null);

  if (!order) return null;
  const paid = order.payments.filter((p) => p.kind.code !== "refund").reduce((s, p) => s + p.amount, 0) - order.payments.filter((p) => p.kind.code === "refund").reduce((s, p) => s + p.amount, 0);
  const due = order.total - paid;
  const isDone = order.status.code === "settled" || order.status.code === "charged_to_room" || order.status.code === "void";
  const kitchenBusy = order.kot_status.code === "preparing" || order.kot_status.code === "ready";
  const canVoid = !isDone && !kitchenBusy;

  /** Returns true on success, false on failure — callers only toast/chain on true. */
  const act = async (fn: () => Promise<unknown>): Promise<boolean> => {
    setError("");
    try {
      await fn();
      reload();
      return true;
    } catch (e) {
      setError((e as Error).message);
      return false;
    }
  };

  return (
    <Modal open onClose={onClose} title={`Order #${order.id} — ${order.type.code === "room_guest" ? `Room ${order.room?.number}` : order.customer_name || "Walk-in"}`} wide>
      <div className="mb-2 flex flex-wrap gap-1.5">
        <Badge color={statusColor(order.status.code)}>{order.status.code.toUpperCase()}</Badge>
        <Badge color={statusColor(order.kot_status.code)}>KOT: {order.kot_status.code.toUpperCase()}</Badge>
        {order.type.code === "walkin" && <Badge color={order.dining_mode.code === "takeaway" ? "purple" : "slate"}>{order.dining_mode.code === "takeaway" ? "TAKEAWAY" : "DINE-IN"}</Badge>}
        <span className="text-xs text-slate-400">taken by {order.staff.name} · {minsAgo(order.created_at)}m ago</span>
      </div>
      <div className="divide-y divide-slate-100 text-sm">
        {order.items.map((i) => (
          <div key={i.id} className={clsx("flex items-center justify-between gap-2 py-1.5", i.voided && "text-slate-300 line-through")}>
            <span className="min-w-0 flex-1">{i.qty} × {i.name}</span>
            <span>{lkr(i.amount)}</span>
            {canVoid && !i.voided && can("hotel_orders.void_item") && (
              <button className="rounded px-1.5 py-0.5 text-xs font-bold text-red-400 hover:bg-red-50 hover:text-red-600" title="Void this item (reason required)" onClick={() => setVoidingItem(i)}>
                ✕
              </button>
            )}
          </div>
        ))}
      </div>
      {kitchenBusy && !isDone && (
        <p className="mt-1 rounded bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700">
          Kitchen is {order.kot_status.code === "preparing" ? "preparing" : "ready to serve"} — voiding is locked until served (or before it starts).
        </p>
      )}
      <div className="mt-2 space-y-1 border-t border-slate-200 pt-2 text-sm">
        <div className="flex justify-between"><span>Subtotal</span><span>{lkr(order.subtotal)}</span></div>
        {order.discount > 0 && <div className="flex justify-between text-red-600"><span>Discount ({order.discount_reason})</span><span>-{lkr(order.discount)}</span></div>}
        {order.service_charge > 0 && <div className="flex justify-between"><span>Service charge</span><span>{lkr(order.service_charge)}</span></div>}
        {order.service_charge === 0 && order.dining_mode.code === "takeaway" && <div className="flex justify-between text-emerald-600"><span>Service charge</span><span>waived (takeaway)</span></div>}
        {order.vat > 0 && <div className="flex justify-between"><span>VAT</span><span>{lkr(order.vat)}</span></div>}
        <div className="flex justify-between text-base font-extrabold">
          <span>Total</span>
          <span>{lkr(order.total)} {usdRate > 0 && <span className="text-xs font-normal text-slate-400">{usd(order.total, usdRate)}</span>}</span>
        </div>
        {paid > 0 && <div className="flex justify-between text-emerald-700"><span>Paid</span><span>{lkr(paid)}</span></div>}
        {!isDone && due > 0 && <div className="flex justify-between font-bold"><span>Due</span><span>{lkr(due)}</span></div>}
      </div>

      <ErrorText error={error} />

      <div className="mt-4 flex flex-wrap gap-2">
        {!isDone && (
          <>
            {can("hotel_orders.settle") && <button className="btn-primary" onClick={() => setPayOpen(true)}>Take payment / split bill</button>}
            {order.type.code === "room_guest" && can("hotel_orders.charge_to_room") && (
              <button
                className="btn-secondary"
                onClick={() =>
                  act(() => post(`/orders/${order.id}/charge-to-room`)).then(
                    (ok) => ok && toast.success(`Order #${order.id} charged to Room ${order.room?.number}`, "Now on the guest's folio")
                  )
                }
              >
                <BedDouble size={15} /> Charge to room folio
              </button>
            )}
            {can("hotel_orders.discount") && <button className="btn-secondary" onClick={() => setDiscountOpen(true)}>Discount (manager)</button>}
            {can("hotel_orders.hold") && (order.status.code === "parked" ? (
              <button className="btn-secondary" onClick={() => act(() => api(`/orders/${order.id}/resume`, { method: "PUT", body: {} }))}>
                <PlayCircle size={15} /> Resume
              </button>
            ) : (
              <button className="btn-secondary" onClick={() => act(() => api(`/orders/${order.id}/park`, { method: "PUT", body: {} }))}>
                <PauseCircle size={15} /> Park / hold
              </button>
            ))}
            {can("hotel_orders.void") && (
              <button
                className="btn-danger"
                disabled={kitchenBusy}
                title={kitchenBusy ? "Cannot void while preparing / ready to serve" : undefined}
                onClick={() => setReasonAction("void")}
              >
                Void order
              </button>
            )}
          </>
        )}
        {isDone && order.status.code !== "void" && paid > 0 && can("hotel_orders.refund") && (
          <button className="btn-danger" onClick={() => setReasonAction("refund")}>Refund…</button>
        )}
        {order.type.code === "walkin" && can("hotel_orders.slip") && (
          <button className="btn-secondary" onClick={() => openPdf(`/orders/${order.id}/slip`)}>
            <Printer size={15} /> Bill + token
          </button>
        )}
        {can("hotel_orders.receipt") && (
          <button className="btn-secondary" onClick={() => openPdf(`/orders/${order.id}/receipt?format=thermal`)}>
            <Printer size={15} /> Receipt
          </button>
        )}
        {can("hotel_orders.kot_ticket") && (
          <button className="btn-secondary" onClick={() => openPdf(`/orders/${order.id}/kot-ticket`)}>
            <Printer size={15} /> KOT ticket
          </button>
        )}
      </div>

      {payOpen && (
        <SplitPay
          due={due}
          onDone={async (payments) => {
            const ok = await act(() =>
              post(`/orders/${order.id}/settle`, {
                payments: payments.map((p) => ({ ...p, idempotency_key: crypto.randomUUID() })),
              })
            );
            if (ok) toast.success(`Order #${order.id} settled`, lkr(order.total));
            setPayOpen(false);
          }}
          onClose={() => setPayOpen(false)}
        />
      )}
      {discountOpen && (
        <DiscountModal
          onApply={async (mode, value, reason) => {
            const ok = await act(() => api(`/orders/${order.id}/discount`, { method: "PUT", body: { mode, value, reason } }));
            if (ok) toast.success(`Discount applied to order #${order.id}`, reason);
            setDiscountOpen(false);
          }}
          onClose={() => setDiscountOpen(false)}
        />
      )}
      {voidingItem && (
        <ReasonModal
          title={`Void ${voidingItem.qty} × ${voidingItem.name} — ${order.kot_status.code === "new" ? "ingredients will be restocked" : "served: no restock"}`}
          onSubmit={async (reason) => {
            await act(() => post(`/orders/${order.id}/items/${voidingItem.id}/void`, { reason }));
            setVoidingItem(null);
          }}
          onClose={() => setVoidingItem(null)}
        />
      )}
      {reasonAction && (
        <ReasonModal
          title={reasonAction === "void" ? "Void order — reason required" : "Refund — reason required"}
          withAmount={reasonAction === "refund" ? paid : undefined}
          onSubmit={async (reason, amount, method) => {
            if (reasonAction === "void") {
              const ok = await act(() => post(`/orders/${order.id}/void`, { reason }));
              if (ok) toast.info(`Order #${order.id} voided`, reason);
            } else {
              const ok = await act(() => post(`/orders/${order.id}/refund`, { reason, amount, method }));
              if (ok) toast.warning(`Refund issued — order #${order.id}`, `${lkr(amount ?? 0)} · ${reason}`);
            }
            setReasonAction(null);
          }}
          onClose={() => setReasonAction(null)}
        />
      )}
    </Modal>
  );
}

/** Split bill across multiple people / payment methods. */
export function SplitPay({ due, onDone, onClose }: { due: number; onDone: (p: { method: string; amount: number; reference?: string }[]) => void; onClose: () => void }) {
  const [rows, setRows] = useState<{ method: string; amount: string; reference: string }[]>([{ method: "cash", amount: (due / 100).toFixed(2), reference: "" }]);
  const sum = rows.reduce((s, r) => s + toCents(r.amount), 0);
  const remaining = due - sum;

  return (
    <Modal open onClose={onClose} title="Take payment — split across methods">
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="flex gap-2">
            <select className="input !w-36" value={r.method} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, method: e.target.value } : x)))}>
              {PAY_METHODS.map((m) => (
                <option key={m} value={m}>{m === "card" ? "CARD (manual)" : m.toUpperCase()}</option>
              ))}
            </select>
            <input className="input" inputMode="decimal" placeholder="Amount (LKR)" value={r.amount} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} />
            <input className="input !w-32" placeholder="Ref/slip #" value={r.reference} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, reference: e.target.value } : x)))} />
            {rows.length > 1 && (
              <button className="btn-ghost !px-2" onClick={() => setRows(rows.filter((_, j) => j !== i))}>✕</button>
            )}
          </div>
        ))}
        <button className="btn-secondary w-full" onClick={() => setRows([...rows, { method: "card", amount: remaining > 0 ? (remaining / 100).toFixed(2) : "", reference: "" }])}>
          + Add split
        </button>
        <div className="flex justify-between text-sm font-semibold">
          <span>Bill due: {lkr(due)}</span>
          <span className={remaining === 0 ? "text-emerald-600" : "text-red-600"}>{remaining === 0 ? "Balanced ✓" : remaining > 0 ? `Short ${lkr(remaining)}` : `Over ${lkr(-remaining)}`}</span>
        </div>
        <button
          className="btn-primary w-full !py-3"
          disabled={remaining !== 0 || due <= 0}
          onClick={() => onDone(rows.map((r) => ({ method: r.method, amount: toCents(r.amount), reference: r.reference || undefined })))}
        >
          Confirm {lkr(due)}
        </button>
      </div>
    </Modal>
  );
}

function DiscountModal({ onApply, onClose }: { onApply: (mode: "PCT" | "FIXED", value: number, reason: string) => void; onClose: () => void }) {
  const [mode, setMode] = useState<"PCT" | "FIXED">("PCT");
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  return (
    <Modal open onClose={onClose} title="Manager discount">
      <div className="space-y-3">
        <div className="flex gap-1 rounded-xl bg-slate-100 p-1">
          {(["PCT", "FIXED"] as const).map((m) => (
            <button key={m} className={clsx("flex-1 rounded-lg py-1.5 text-sm font-semibold", mode === m ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setMode(m)}>
              {m === "PCT" ? "Percent %" : "Fixed LKR"}
            </button>
          ))}
        </div>
        <Field label={mode === "PCT" ? "Percent (0–100)" : "Amount (LKR)"}>
          <input className="input" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} />
        </Field>
        <Field label="Reason (required, logged)">
          <input className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. regular guest goodwill" />
        </Field>
        <button
          className="btn-primary w-full"
          disabled={!reason.trim() || !value}
          onClick={() => onApply(mode, mode === "PCT" ? parseFloat(value) : toCents(value), reason.trim())}
        >
          Apply discount
        </button>
      </div>
    </Modal>
  );
}

export function ReasonModal({ title, withAmount, onSubmit, onClose }: { title: string; withAmount?: number; onSubmit: (reason: string, amount?: number, method?: string) => void; onClose: () => void }) {
  const [reason, setReason] = useState("");
  const [amount, setAmount] = useState(withAmount !== undefined ? (withAmount / 100).toFixed(2) : "");
  const [method, setMethod] = useState("cash");
  return (
    <Modal open onClose={onClose} title={title}>
      <div className="space-y-3">
        {withAmount !== undefined && (
          <>
            <Field label="Refund amount (LKR)">
              <input className="input" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
            </Field>
            <Field label="Refund method">
              <select className="input" value={method} onChange={(e) => setMethod(e.target.value)}>
                {PAY_METHODS.map((m) => (
                  <option key={m} value={m}>{m.toUpperCase()}</option>
                ))}
              </select>
            </Field>
          </>
        )}
        <Field label="Reason (required — recorded in the audit log)">
          <textarea className="input" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <button className="btn-danger w-full" disabled={!reason.trim() || (withAmount !== undefined && toCents(amount) <= 0)} onClick={() => onSubmit(reason.trim(), withAmount !== undefined ? toCents(amount) : undefined, method)}>
          Confirm
        </button>
      </div>
    </Modal>
  );
}
