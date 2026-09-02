import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Minus, Plus, Printer, Send, PauseCircle, PlayCircle, BedDouble, User, RefreshCw,
  Search, StickyNote, Trash2, UtensilsCrossed, Timer, ShoppingBag, Bike, Split, Combine,
  ChefHat, HandPlatter, ScanBarcode, X,
} from "lucide-react";
import { api, printDocument, post, put } from "../lib/api";
import { posRequest } from "../lib/offline";
import { lkr, toCents, useFetch, useSettings, usd } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, statusColor, Tabs } from "../components/ui";
import { getSocket } from "../lib/socket";
import { useToast } from "../lib/toast";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type Modifier = { id: number; name: string; price_delta: number };
type ModifierGroup = { id: number; name: string; is_required: boolean; max_select: number; modifiers: Modifier[] };
type AddOn = { id: number; name: string; price: number };

type MenuItem = {
  id: number;
  item_no?: number | null;
  name: string;
  price: number;
  /** @deprecated Prefer `available` + `availability_reason` */
  sold_out?: boolean;
  available?: boolean;
  availability_reason?: string | null;
  description: string;
  image?: string | null;
  modifier_groups?: ModifierGroup[];
  addons?: AddOn[];
};

/** Directly-sellable, non-recipe stock item (bottled drink, packaged snack) — never routes to the kitchen. */
type Product = {
  id: number;
  name: string;
  selling_price: number;
  stock_qty: number;
  image?: string | null;
  unit?: string | null;
  available?: boolean;
  availability_reason?: string | null;
};

type MenuCat = { id: number; name: string; is_minibar: boolean; items: MenuItem[]; products: Product[] };
type BoardRoom = { id: number; number: string; status: { code: string }; occupant: { code: string; guest: { name: string } } | null };
type DiningTable = { id: number; table_no: string; capacity: number; status: { code: string }; area: { name: string } | null };
type StaffLite = { id: number; name: string };
type Order = {
  id: number; type: { code: string }; dining_mode: { code: string }; status: { code: string }; kot_status: { code: string }; created_at: string;
  customer_name?: string; placed_via_qr?: boolean; subtotal: number; discount: number; discount_reason?: string; service_charge: number; vat: number; total: number;
  room?: { id: number; number: string } | null;
  dining_table?: { id: number; table_no: string } | null;
  delivery_address?: string | null; delivery_phone?: string | null;
  delivery_status?: { code: string } | null; delivery_rider?: { id: number; name: string } | null;
  reservation?: { code: string; guest: { id: number; name: string } } | null;
  items: { id: number; name: string; qty: number; unit_price: number; amount: number; voided: boolean; send_to_kot?: boolean; add_on_id?: number | null; product_id?: number | null; modifiers?: { id: number; name: string; price_delta: number }[] }[];
  payments: { id: number; method: { code: string }; amount: number; kind: { code: string } }[];
  staff: { name: string } | null;
};

type Guest = { id: number; name: string; phone?: string | null; email?: string | null; loyalty_points: number; lifetime_spend: number };

type CartLine = {
  key: string;
  kind: "item" | "addon" | "product";
  menuItemId?: number;
  addOnId?: number;
  productId?: number;
  name: string;
  price: number;
  qty: number;
  notes?: string;
  modifierIds: number[];
  sendToKot: boolean;
};

/** Unified POS-grid tile — a menu item or a directly-sellable product. */
type GridEntry =
  | {
      key: string;
      kind: "item";
      id: number;
      name: string;
      price: number;
      itemNo: number | null;
      image?: string | null;
      available: boolean;
      availabilityReason: string | null;
      item: MenuItem;
    }
  | {
      key: string;
      kind: "product";
      id: number;
      name: string;
      price: number;
      itemNo: null;
      image?: string | null;
      available: boolean;
      availabilityReason: string | null;
      product: Product;
    };

/** Small routing indicator — kitchen ticket vs front-of-house pickup. */
function RouteIcon({ sendToKot, className }: { sendToKot: boolean; className?: string }) {
  return sendToKot ? (
    <ChefHat size={12} className={className} />
  ) : (
    <HandPlatter size={12} className={className} />
  );
}

/** Human-readable label for an availability reason. */
function availabilityLabel(reason: string | null | undefined): string {
  if (!reason) return "UNAVAILABLE";
  switch (reason) {
    case "out_of_stock":
      return "OUT OF STOCK";
    case "expired":
      return "EXPIRED";
    case "ingredient_expired":
      return "INGREDIENT EXPIRED";
    case "sold_out":
      return "SOLD OUT";
    default:
      return reason.replace(/_/g, " ").toUpperCase();
  }
}

/** Badge color based on reason — amber for expiry-related, red for stock. */
function availabilityBadgeColor(reason: string | null | undefined): "red" | "amber" {
  if (reason === "ingredient_expired" || reason === "expired") return "amber";
  return "red";
}

const PAY_METHODS = ["cash", "card", "lankaqr", "bank_transfer"] as const;
const DELIVERY_STEPS = ["pending", "out_for_delivery", "delivered", "failed"] as const;

const minsAgo = (iso: string) => Math.max(0, Math.round((Date.now() - +new Date(iso)) / 60000));

/** Debounce hook for search inputs */
function useDebounce<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

export default function POS() {
  const { can } = useAuth();
  const canCreate = can("hotel_orders.create");
  const [view, setView] = useState<"new" | "open">(canCreate ? "new" : "open");
  // Load only categories (lightweight) - items/products loaded on demand via search
  const { data: categoriesData, reload: reloadCategories } = useFetch<{ categories: { id: number; name: string; is_minibar: boolean }[] }>("/menu/categories");
  const { data: roomsData } = useFetch<{ rooms: BoardRoom[] }>("/rooms");
  const { data: tablesData, reload: reloadTables } = useFetch<{ dining_tables: DiningTable[] }>("/dining-tables");
  const { data: activeData, reload: reloadActive } = useFetch<{ orders: Order[] }>("/orders?scope=active");
  const { data: todaysData, reload: reloadToday } = useFetch<{ orders: Order[] }>("/orders?scope=today");
  const categories = categoriesData?.categories ?? [];
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
    s.on("menu", reloadCategories);
    s.on("kot", orders);
    s.on("orders", orders);
    return () => {
      s.off("menu", reloadCategories);
      s.off("kot", orders);
      s.off("orders", orders);
    };
  }, [reloadCategories, reloadActive, reloadToday]);

  const freeTables = (tablesData?.dining_tables ?? []).filter((t) => t.status.code === "free");

  // cache for offline reloads
  useEffect(() => {
    if (roomsData?.rooms) localStorage.setItem("mv.cache.board", JSON.stringify(roomsData.rooms));
  }, [roomsData]);
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
          categories={categories}
          rooms={occupiedRooms}
          tables={freeTables}
          usdRate={usdRate}
          scPct={num("billing.service_charge_pct", 0)}
          vatPct={num("billing.vat_pct", 0)}
          onDone={() => {
            reloadActive();
            reloadToday();
            reloadTables();
            setView("open");
          }}
        />
      ) : (
        <OpenOrders
          active={active ?? []}
          todays={todays ?? []}
          usdRate={usdRate}
          reload={() => { reloadActive(); reloadToday(); reloadTables(); }}
        />
      )}
    </div>
  );
}

// ── New order ─────────────────────────────────────────────────────────────────
function NewOrder({ categories, rooms, tables, usdRate, scPct, vatPct, onDone }: {
  categories: { id: number; name: string; is_minibar: boolean }[];
  rooms: BoardRoom[]; tables: DiningTable[]; usdRate: number; scPct: number; vatPct: number; onDone: () => void;
}) {
  const toast = useToast();
  const [catId, setCatId] = useState<string>("ALL");
  const [searchQuery, setSearchQuery] = useState("");
  const [cart, setCart] = useState<CartLine[]>([]);
  const [type, setType] = useState<"walkin" | "room_guest" | "delivery">("walkin");
  const [diningMode, setDiningMode] = useState<"dine_in" | "takeaway">("dine_in");
  const [roomId, setRoomId] = useState("");
  const [tableId, setTableId] = useState("");
  const [deliveryAddress, setDeliveryAddress] = useState("");
  const [deliveryPhone, setDeliveryPhone] = useState("");
  const [customerName, setCustomerName] = useState("");
  const [notes, setNotes] = useState("");
  const [noteFor, setNoteFor] = useState<string | null>(null);
  const [pickerItem, setPickerItem] = useState<MenuItem | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [queuedMsg, setQueuedMsg] = useState("");
  const [quickNo, setQuickNo] = useState("");
  const [guestSearch, setGuestSearch] = useState("");
  const [guestResults, setGuestResults] = useState<Guest[]>([]);
  const [showGuestDropdown, setShowGuestDropdown] = useState(false);

  // Search state for items/products
  const [gridEntries, setGridEntries] = useState<GridEntry[]>([]);
  const [searchLoading, setSearchLoading] = useState(false);

  // Debounced search
  const debouncedSearchQuery = useDebounce(searchQuery, 300);
  const debouncedGuestSearch = useDebounce(guestSearch, 300);

  // Precomputed cart quantity map for O(1) lookups
  const cartQtyMap = useMemo(() => {
    const map = new Map<string, number>();
    cart.forEach((line) => {
      const existing = map.get(line.key) || 0;
      map.set(line.key, existing + line.qty);
    });
    return map;
  }, [cart]);

  // Helper to get cart qty for a grid entry (O(1) instead of O(N))
  const getCartQty = useCallback((entry: GridEntry) => {
    if (entry.kind === "product") {
      return cartQtyMap.get(`product:${entry.id}`) || 0;
    }
    return cartQtyMap.get(`item:${entry.id}`) || 0;
  }, [cartQtyMap]);

  // Normalise availability from backend (with safe fallbacks while backend is being rolled out)
  const resolveAvailability = (p: { available?: boolean; availability_reason?: string | null; sold_out?: boolean; stock_qty?: number }) => {
    // Prefer the new fields
    if (typeof p.available === "boolean") {
      return {
        available: p.available,
        reason: p.availability_reason ?? null,
      };
    }
    // Fallback for menu items that still only send sold_out
    if (typeof p.sold_out === "boolean") {
      return {
        available: !p.sold_out,
        reason: p.sold_out ? "sold_out" : null,
      };
    }
    // Fallback for products that still only send stock_qty
    if (typeof p.stock_qty === "number") {
      return {
        available: p.stock_qty > 0,
        reason: p.stock_qty <= 0 ? "out_of_stock" : null,
      };
    }
    return { available: true, reason: null };
  };

  // Fetch items/products for selected category or search
  const fetchGridEntries = useCallback(async () => {
    setSearchLoading(true);
    try {
      const params = new URLSearchParams();
      if (catId !== "ALL") params.set("category_id", catId);
      if (debouncedSearchQuery.trim()) params.set("q", debouncedSearchQuery.trim());
      params.set("limit", "100");

      const [itemsRes, productsRes] = await Promise.all([
        api<{ items: MenuItem[] }>(`/menu/search?${params}`),
        api<{ products: Product[] }>(`/products/search?${params}`),
      ]);

      const items = itemsRes.items ?? [];
      const products = productsRes.products ?? [];

      const entries: GridEntry[] = [
        ...items.map((i): GridEntry => {
          const { available, reason } = resolveAvailability(i);
          return {
            key: `item:${i.id}`,
            kind: "item",
            id: i.id,
            name: i.name,
            price: i.price,
            itemNo: i.item_no ?? null,
            image: i.image,
            available,
            availabilityReason: reason,
            item: i,
          };
        }),
        ...products.map((p): GridEntry => {
          const { available, reason } = resolveAvailability(p);
          return {
            key: `product:${p.id}`,
            kind: "product",
            id: p.id,
            name: p.name,
            price: p.selling_price,
            itemNo: null,
            image: p.image,
            available,
            availabilityReason: reason,
            product: p,
          };
        }),
      ];
      setGridEntries(entries);
    } catch (e) {
      console.error("Failed to fetch grid entries:", e);
      setGridEntries([]);
    } finally {
      setSearchLoading(false);
    }
  }, [catId, debouncedSearchQuery]);

  // Fetch grid entries when category or search changes
  useEffect(() => {
    fetchGridEntries();
  }, [fetchGridEntries]);

  // Guest search for delivery orders
  useEffect(() => {
    if (!debouncedGuestSearch.trim()) {
      setGuestResults([]);
      return;
    }
    api<{ guests: Guest[] }>(`/guests/search?q=${encodeURIComponent(debouncedGuestSearch.trim())}&limit=10`)
      .then((res) => setGuestResults(res.guests ?? []))
      .catch(() => setGuestResults([]));
  }, [debouncedGuestSearch]);

  // Select guest from search results
  const selectGuest = (guest: Guest) => {
    setCustomerName(guest.name);
    setDeliveryPhone(guest.phone ?? "");
    setGuestSearch("");
    setGuestResults([]);
    setShowGuestDropdown(false);
  };

  // Barcode scanning support
  const handleBarcodeScan = useCallback((barcode: string) => {
    // Try to find product by barcode (item_no or product id)
    const numericCode = parseInt(barcode, 10);
    if (!isNaN(numericCode)) {
      // Search for item by item_no
      api<{ items: MenuItem[] }>(`/menu/search?q=${numericCode}&limit=1`)
        .then((res) => {
          const item = res.items?.[0];
          if (item) {
            const { available } = resolveAvailability(item);
            if (available) {
              tryAdd(item);
              return;
            }
          }
          // Try product search
          return api<{ products: Product[] }>(`/products/search?q=${numericCode}&limit=1`);
        })
        .then((res) => {
          if (res?.products?.[0]) {
            const product = res.products[0];
            const { available } = resolveAvailability(product);
            if (available) addProduct(product);
          }
        })
        .catch(() => {});
    }
  }, []);

  // Listen for barcode scanner input (typically acts as keyboard wedge)
  useEffect(() => {
    let buffer = "";
    let lastKeyTime = 0;

    const handleKeyDown = (e: KeyboardEvent) => {
      // Ignore if user is typing in an input
      if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement || e.target instanceof HTMLSelectElement) {
        return;
      }

      const now = Date.now();
      if (now - lastKeyTime > 100) {
        buffer = "";
      }
      lastKeyTime = now;

      if (e.key === "Enter" && buffer.length >= 8) {
        handleBarcodeScan(buffer);
        buffer = "";
        e.preventDefault();
      } else if (e.key.length === 1) {
        buffer += e.key;
      }
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [handleBarcodeScan]);

  const subtotal = cart.reduce((s, l) => s + l.price * l.qty, 0);
  const takeaway = (type === "walkin" && diningMode === "takeaway") || type === "delivery";
  const sc = takeaway ? 0 : Math.round((subtotal * scPct) / 100);
  const vat = Math.round(((subtotal + sc) * vatPct) / 100);
  const itemCount = cart.reduce((s, l) => s + l.qty, 0);

  const add = (item: MenuItem, modifierIds: number[] = [], unitPrice?: number, addOnIds: number[] = []) => {
    const key = `${item.id}:${[...modifierIds].sort((a, b) => a - b).join(",")}`;
    const price = unitPrice ?? item.price;
    const modLabel = (item.modifier_groups ?? [])
      .flatMap((g) => g.modifiers)
      .filter((m) => modifierIds.includes(m.id))
      .map((m) => m.name)
      .join(", ");
    setCart((c) => {
      const existing = c.find((l) => l.key === key);
      if (existing) return c.map((l) => (l.key === key ? { ...l, qty: l.qty + 1 } : l));
      return [...c, { key, kind: "item", menuItemId: item.id, name: modLabel ? `${item.name} (${modLabel})` : item.name, price, qty: 1, modifierIds, sendToKot: true }];
    });
    for (const addOn of (item.addons ?? []).filter((a) => addOnIds.includes(a.id))) {
      setCart((c) => {
        const aKey = `addon:${addOn.id}`;
        const existing = c.find((l) => l.key === aKey);
        if (existing) return c.map((l) => (l.key === aKey ? { ...l, qty: l.qty + 1 } : l));
        return [...c, { key: aKey, kind: "addon", addOnId: addOn.id, name: addOn.name, price: addOn.price, qty: 1, modifierIds: [], sendToKot: true }];
      });
    }
  };
  const addProduct = (product: Product) => {
    const key = `product:${product.id}`;
    setCart((c) => {
      const existing = c.find((l) => l.key === key);
      if (existing) return c.map((l) => (l.key === key ? { ...l, qty: l.qty + 1 } : l));
      return [...c, { key, kind: "product", productId: product.id, name: product.name, price: product.selling_price, qty: 1, modifierIds: [], sendToKot: false }];
    });
  };
  const tryAdd = (item: MenuItem) => ((item.modifier_groups?.length ?? 0) > 0 || (item.addons?.length ?? 0) > 0 ? setPickerItem(item) : add(item));
  const setQty = (key: string, qty: number) =>
    setCart((c) => (qty <= 0 ? c.filter((l) => l.key !== key) : c.map((l) => (l.key === key ? { ...l, qty } : l))));

  const quickAdd = () => {
    const no = parseInt(quickNo);
    if (!no) return;
    // Try local grid entries first
    let entry = gridEntries
      .filter((e): e is Extract<GridEntry, { kind: "item" }> => e.kind === "item")
      .find((e) => e.itemNo === no);

    if (!entry) {
      // Fallback to API search
      api<{ items: MenuItem[] }>(`/menu/search?q=${no}&limit=1`)
        .then((res) => {
          const item = res.items?.[0];
          if (!item) {
            setError(`No menu item #${no}`);
            return;
          }
          const { available, reason } = resolveAvailability(item);
          if (!available) {
            setError(`#${no} ${item.name} is unavailable${reason ? ` (${availabilityLabel(reason)})` : ""}`);
            return;
          }
          tryAdd(item);
        })
        .catch(() => setError(`Failed to lookup #${no}`));
    } else if (!entry.available) {
      setError(`#${no} ${entry.name} is unavailable${entry.availabilityReason ? ` (${availabilityLabel(entry.availabilityReason)})` : ""}`);
    } else {
      setError("");
      tryAdd(entry.item);
    }
    setQuickNo("");
  };

  const send = async () => {
    setError("");
    if (cart.length === 0) return setError("Add items first");
    if (type === "room_guest" && !roomId) return setError("Select the guest's room");
    if (type === "delivery" && (!deliveryAddress.trim() || !deliveryPhone.trim())) return setError("Delivery address and phone are required");
    setBusy(true);
    try {
      const res = await posRequest("/orders", {
        client_key: crypto.randomUUID(),
        type,
        dining_mode: type === "walkin" ? diningMode : undefined,
        room_id: type === "room_guest" ? Number(roomId) : undefined,
        dining_table_id: type === "walkin" && diningMode === "dine_in" && tableId ? Number(tableId) : undefined,
        delivery_address: type === "delivery" ? deliveryAddress.trim() : undefined,
        delivery_phone: type === "delivery" ? deliveryPhone.trim() : undefined,
        customer_name: type !== "room_guest" ? customerName || undefined : undefined,
        notes: notes || undefined,
        items: cart.map((l) =>
          l.kind === "addon"
            ? { add_on_id: l.addOnId, qty: l.qty, notes: l.notes }
            : l.kind === "product"
              ? { product_id: l.productId, qty: l.qty, notes: l.notes }
              : { menu_item_id: l.menuItemId, qty: l.qty, notes: l.notes, modifier_ids: l.modifierIds.length ? l.modifierIds : undefined }
        ),
      });
      setCart([]);
      setCustomerName("");
      setNotes("");
      setDiningMode("dine_in");
      setTableId("");
      setDeliveryAddress("");
      setDeliveryPhone("");
      if ((res as { queued?: boolean }).queued) {
        setQueuedMsg("No connection — order saved and will sync to the kitchen automatically when back online. Print the slip from Open Orders after sync.");
        toast.warning("Order queued offline", "Will sync to the kitchen automatically when back online");
      } else {
        const created = (res as { order: { id: number } }).order;
        toast.success(`Order #${created.id} sent to kitchen`);
        if (type === "walkin") {
          printDocument(`/orders/${created.id}/slip`).catch(() => {});
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
            <input
              className="input !w-52 !py-1.5 !pl-8"
              placeholder="Search / #no…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
          <button
            onClick={() => setCatId("ALL")}
            className={clsx("rounded-full px-3.5 py-1.5 text-sm font-semibold transition", catId === "ALL" ? "bg-brand-600 text-white" : "bg-white text-slate-600 shadow-sm hover:bg-slate-50")}
          >
            All
          </button>
          {categories.map((c) => (
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
          {searchLoading ? (
            <div className="col-span-full flex justify-center py-8 text-slate-400">Loading…</div>
          ) : (
            <>
              {gridEntries.map((entry) => {
                const inCart = getCartQty(entry);
                const isUnavailable = !entry.available;
                return (
                  <button
                    key={entry.key}
                    disabled={isUnavailable}
                    onClick={() => (entry.kind === "product" ? addProduct(entry.product) : tryAdd(entry.item))}
                    className={clsx(
                      "card relative p-3 text-left transition",
                      isUnavailable ? "opacity-40" : "hover:-translate-y-0.5 hover:shadow-md active:scale-[.98]",
                      inCart && "ring-2 ring-brand-500"
                    )}
                  >
                    {entry.image && <img src={entry.image} alt="" className="mb-2 aspect-square w-full rounded-lg object-cover" />}
                    <div className="flex items-start justify-between gap-1">
                      {entry.itemNo != null && <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-black text-slate-500">#{entry.itemNo}</span>}
                      {inCart > 0 && <span className="rounded-full bg-brand-600 px-1.5 py-0.5 text-[10px] font-black text-white">{inCart}</span>}
                    </div>
                    <div className="mt-1 text-sm font-bold leading-tight">{entry.name}</div>
                    <div className="mt-1 text-sm font-semibold text-brand-600">{lkr(entry.price)}</div>
                    {isUnavailable && (
                      <div className="mt-1.5">
                        <Badge color={availabilityBadgeColor(entry.availabilityReason)}>
                          {availabilityLabel(entry.availabilityReason)}
                        </Badge>
                      </div>
                    )}
                  </button>
                );
              })}
              {gridEntries.length === 0 && !searchLoading && <Empty text="No items match" />}
            </>
          )}
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
          <button className={clsx("flex-1 rounded-lg px-1.5 py-1.5 text-xs font-semibold sm:text-sm", type === "walkin" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setType("walkin")}>
            <User size={14} className="mr-1 inline" /> Walk-in
          </button>
          <button className={clsx("flex-1 rounded-lg px-1.5 py-1.5 text-xs font-semibold sm:text-sm", type === "room_guest" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setType("room_guest")}>
            <BedDouble size={14} className="mr-1 inline" /> Room
          </button>
          <button className={clsx("flex-1 rounded-lg px-1.5 py-1.5 text-xs font-semibold sm:text-sm", type === "delivery" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setType("delivery")}>
            <Bike size={14} className="mr-1 inline" /> Delivery
          </button>
        </div>
        {type === "room_guest" && (
          <select className="input mb-3" value={roomId} onChange={(e) => setRoomId(e.target.value)}>
            <option value="">Select occupied room…</option>
            {rooms.map((r) => (
              <option key={r.id} value={r.id}>Room {r.number} — {r.occupant?.guest.name}</option>
            ))}
          </select>
        )}
        {type === "delivery" && (
          <div className="mb-3 space-y-2">
            <div className="relative">
              <User size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                className="input !pl-8"
                placeholder="Customer name (search guests…)"
                value={guestSearch}
                onChange={(e) => { setGuestSearch(e.target.value); setShowGuestDropdown(true); }}
                onFocus={() => setShowGuestDropdown(true)}
                onBlur={() => setTimeout(() => setShowGuestDropdown(false), 200)}
              />
              {showGuestDropdown && guestResults.length > 0 && (
                <div className="absolute z-10 w-full mt-1 rounded-lg bg-white shadow-lg border border-slate-200 max-h-60 overflow-auto">
                  {guestResults.map((g) => (
                    <button
                      key={g.id}
                      className="w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
                      onClick={() => selectGuest(g)}
                    >
                      <div className="font-medium">{g.name}</div>
                      <div className="text-xs text-slate-500">{g.phone ?? g.email ?? "No contact"}</div>
                    </button>
                  ))}
                </div>
              )}
            </div>
            <input className="input" placeholder="Delivery address *" value={deliveryAddress} onChange={(e) => setDeliveryAddress(e.target.value)} />
            <input className="input" placeholder="Delivery phone *" value={deliveryPhone} onChange={(e) => setDeliveryPhone(e.target.value)} />
          </div>
        )}
        {type === "walkin" && (
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
                onClick={() => { setDiningMode("takeaway"); setTableId(""); }}
              >
                <ShoppingBag size={13} className="mr-1 inline" /> Takeaway
              </button>
            </div>
            {diningMode === "dine_in" && tables.length > 0 && (
              <select className="input mb-3" value={tableId} onChange={(e) => setTableId(e.target.value)}>
                <option value="">No table (label only)</option>
                {tables.map((t) => (
                  <option key={t.id} value={t.id}>Table {t.table_no}{t.area ? ` — ${t.area.name}` : ""} (seats {t.capacity})</option>
                ))}
              </select>
            )}
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
              <div key={l.key}>
                <div className="flex items-center gap-2 text-sm">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 truncate font-semibold">
                      <span className="min-w-0 truncate">{l.name}</span>
                      <span
                        className={clsx("shrink-0 rounded px-1 py-0.5", l.sendToKot ? "bg-orange-100 text-orange-600" : "bg-sky-100 text-sky-600")}
                        title={l.sendToKot ? "Sent to kitchen" : "Picked from counter stock"}
                      >
                        <RouteIcon sendToKot={l.sendToKot} />
                      </span>
                    </div>
                    <div className="text-xs text-slate-400">{lkr(l.price)}</div>
                  </div>
                  <button
                    className={clsx("btn-ghost !p-1", l.notes ? "text-amber-500" : "text-slate-300")}
                    title={l.notes ? `Note: ${l.notes}` : "Add kitchen note for this item"}
                    onClick={() => setNoteFor(noteFor === l.key ? null : l.key)}
                  >
                    <StickyNote size={13} />
                  </button>
                  <button className="btn-secondary !p-1" onClick={() => setQty(l.key, l.qty - 1)}><Minus size={13} /></button>
                  <span className="w-6 text-center font-bold">{l.qty}</span>
                  <button className="btn-secondary !p-1" onClick={() => setQty(l.key, l.qty + 1)}><Plus size={13} /></button>
                  <span className="w-20 text-right font-semibold">{lkr(l.price * l.qty)}</span>
                </div>
                {noteFor === l.key && (
                  <input
                    className="input mt-1 !py-1 text-xs"
                    placeholder="e.g. extra spicy, no onions…"
                    value={l.notes ?? ""}
                    autoFocus
                    onChange={(e) => setCart(cart.map((x) => (x.key === l.key ? { ...x, notes: e.target.value || undefined } : x)))}
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
          {scPct > 0 && takeaway && <div className="flex justify-between text-emerald-600"><span>Service charge</span><span>waived ({type === "delivery" ? "delivery" : "takeaway"})</span></div>}
          {vatPct > 0 && <div className="flex justify-between text-slate-500"><span>VAT {vatPct}%</span><span>{lkr(vat)}</span></div>}
          <div className="flex justify-between text-base font-extrabold"><span>Total</span><span>{lkr(subtotal + sc + vat)}</span></div>
          {usdRate > 0 && subtotal > 0 && <div className="text-right text-xs text-slate-400">{usd(subtotal + sc + vat, usdRate)}</div>}
        </div>

        <ErrorText error={error} />
        {queuedMsg && <div className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">{queuedMsg}</div>}
        <button className="btn-primary mt-3 w-full !py-3" disabled={busy || cart.length === 0} onClick={send}>
          <Send size={16} /> Send order{type === "walkin" ? " + print slip" : ""}
        </button>
      </div>
      {pickerItem && (
        <ItemCustomizeModal
          item={pickerItem}
          onAdd={(modifierIds, unitPrice, addOnIds) => add(pickerItem, modifierIds, unitPrice, addOnIds)}
          onClose={() => setPickerItem(null)}
        />
      )}
    </div>
  );
}

/** Required-group + max-select picker for modifiers (Size, Spice level…), plus the
 * item's linked add-ons (extra cheese, extra curry…) — each selected add-on is
 * added to the cart as its own routed line. */
function ItemCustomizeModal({ item, onAdd, onClose }: { item: MenuItem; onAdd: (modifierIds: number[], unitPrice: number, addOnIds: number[]) => void; onClose: () => void }) {
  const groups = item.modifier_groups ?? [];
  const addOns = item.addons ?? [];
  const [selected, setSelected] = useState<Record<number, number[]>>({});
  const [selectedAddOns, setSelectedAddOns] = useState<number[]>([]);

  const toggle = (group: ModifierGroup, modifierId: number) => {
    setSelected((s) => {
      const cur = s[group.id] ?? [];
      if (cur.includes(modifierId)) return { ...s, [group.id]: cur.filter((id) => id !== modifierId) };
      if (group.max_select <= 1) return { ...s, [group.id]: [modifierId] };
      if (cur.length >= group.max_select) return s;
      return { ...s, [group.id]: [...cur, modifierId] };
    });
  };

  const toggleAddOn = (addOnId: number) =>
    setSelectedAddOns((s) => (s.includes(addOnId) ? s.filter((id) => id !== addOnId) : [...s, addOnId]));

  const chosenIds = Object.values(selected).flat();
  const chosenModifiers = groups.flatMap((g) => g.modifiers).filter((m) => chosenIds.includes(m.id));
  const chosenAddOns = addOns.filter((a) => selectedAddOns.includes(a.id));
  const unitPrice = item.price + chosenModifiers.reduce((s, m) => s + m.price_delta, 0) + chosenAddOns.reduce((s, a) => s + a.price, 0);
  const allRequiredSatisfied = groups.every((g) => !g.is_required || (selected[g.id]?.length ?? 0) > 0);

  return (
    <Modal open onClose={onClose} title={item.name}>
      <div className="space-y-4">
        {groups.map((g) => (
          <div key={g.id}>
            <div className="label">{g.name}{g.is_required && " *"}{g.max_select > 1 && ` — choose up to ${g.max_select}`}</div>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {g.modifiers.map((m) => (
                <button
                  key={m.id}
                  className={clsx(
                    "rounded-full px-3 py-1.5 text-xs font-semibold transition",
                    (selected[g.id] ?? []).includes(m.id) ? "bg-brand-600 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                  )}
                  onClick={() => toggle(g, m.id)}
                >
                  {m.name}{m.price_delta !== 0 && ` (${m.price_delta > 0 ? "+" : "-"}${lkr(Math.abs(m.price_delta))})`}
                </button>
              ))}
            </div>
          </div>
        ))}
        {addOns.length > 0 && (
          <div>
            <div className="label">Add-ons — each added as its own line</div>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {addOns.map((a) => (
                <button
                  key={a.id}
                  className={clsx(
                    "flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold transition",
                    selectedAddOns.includes(a.id) ? "bg-brand-600 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                  )}
                  onClick={() => toggleAddOn(a.id)}
                >
                  {a.name} (+{lkr(a.price)})
                </button>
              ))}
            </div>
          </div>
        )}
        <div className="flex justify-between border-t border-slate-100 pt-3 text-sm font-bold">
          <span>Price</span><span>{lkr(unitPrice)}</span>
        </div>
        <button className="btn-primary w-full !py-3" disabled={!allRequiredSatisfied} onClick={() => onAdd(chosenIds, unitPrice, selectedAddOns)}>
          Add to cart — {lkr(unitPrice)}
        </button>
      </div>
    </Modal>
  );
}

// ── Open orders ───────────────────────────────────────────────────────────────
type OrderFilter = "ALL" | "OPEN" | "PARKED" | "ROOM" | "WALKIN" | "DELIVERY";

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
    if (filter === "DELIVERY") list = list.filter((o) => o.type.code === "delivery");
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
    { id: "DELIVERY", label: "Delivery" },
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
          const paid = o.payments.filter((p) => p.kind?.code !== "refund").reduce((s, p) => s + p.amount, 0);
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
                  {o.type.code === "delivery" && <Badge color="purple">{o.delivery_status?.code.toUpperCase().replace(/_/g, " ") ?? "DELIVERY"}</Badge>}
                  {o.dining_table && <Badge color="blue">TABLE {o.dining_table.table_no}</Badge>}
                  {o.placed_via_qr && <Badge color="brand">SELF-ORDER</Badge>}
                </div>
              </div>
              <div className="mt-1 truncate text-sm font-semibold text-slate-700">
                {o.type.code === "room_guest"
                  ? `Room ${o.room?.number} — ${o.reservation?.guest.name ?? ""}`
                  : o.type.code === "delivery"
                    ? `${o.customer_name || "Delivery"} — ${o.delivery_address ?? ""}`
                    : o.customer_name || "Walk-in"}
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
          mergeCandidates={active.filter((o) => o.id !== selected && ["open", "parked"].includes(o.status.code))}
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
function OrderModal({ orderId, usdRate, mergeCandidates, onClose }: { orderId: number; usdRate: number; mergeCandidates: Order[]; onClose: () => void }) {
  const { data, reload } = useFetch<{ order: Order }>(`/orders/${orderId}`);
  const order = data?.order;
  const { can } = useAuth();
  const toast = useToast();
  const [error, setError] = useState("");
  const [payOpen, setPayOpen] = useState(false);
  const [discountOpen, setDiscountOpen] = useState(false);
  const [reasonAction, setReasonAction] = useState<"void" | "refund" | null>(null);
  const [voidingItem, setVoidingItem] = useState<Order["items"][number] | null>(null);
  const [splitOpen, setSplitOpen] = useState(false);
  const [mergeOpen, setMergeOpen] = useState(false);

  if (!order) return null;
  const paid = order.payments.filter((p) => p.kind?.code !== "refund").reduce((s, p) => s + p.amount, 0) - order.payments.filter((p) => p.kind?.code === "refund").reduce((s, p) => s + p.amount, 0);
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
        {order.type.code === "delivery" && order.delivery_status && <Badge color="purple">{order.delivery_status.code.toUpperCase().replace(/_/g, " ")}</Badge>}
        {order.dining_table && <Badge color="blue">TABLE {order.dining_table.table_no}</Badge>}
        {order.placed_via_qr && <Badge color="brand">SELF-ORDER</Badge>}
        <span className="text-xs text-slate-400">taken by {order.staff?.name ?? "guest (QR)"} · {minsAgo(order.created_at)}m ago</span>
      </div>
      {order.type.code === "delivery" && (
        <div className="mb-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
          <div>{order.delivery_address}{order.delivery_phone ? ` · ${order.delivery_phone}` : ""}</div>
          {order.delivery_rider && <div className="mt-0.5 font-semibold">Rider: {order.delivery_rider.name}</div>}
        </div>
      )}
      {order.type.code === "delivery" && !isDone && can("hotel_orders.delivery_dispatch") && (
        <DeliveryDispatch order={order} onChanged={() => { reload(); setError(""); }} onError={setError} />
      )}
      <div className="divide-y divide-slate-100 text-sm">
        {order.items.map((i) => (
          <div key={i.id} className={clsx("flex items-center justify-between gap-2 py-1.5", i.voided && "text-slate-300 line-through")}>
            <span className="flex min-w-0 flex-1 items-center gap-1.5">
              <span className="min-w-0 truncate">{i.qty} × {i.name}</span>
              {i.add_on_id != null && <Badge color="purple">add-on</Badge>}
              {i.product_id != null && <Badge color="blue">product</Badge>}
              <span
                className={clsx("shrink-0 rounded px-1 py-0.5", i.send_to_kot ? "bg-orange-100 text-orange-600" : "bg-sky-100 text-sky-600")}
                title={i.send_to_kot ? "Sent to kitchen" : "Picked from counter stock"}
              >
                <RouteIcon sendToKot={i.send_to_kot ?? true} />
              </span>
            </span>
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
        {order.service_charge === 0 && (order.dining_mode.code === "takeaway" || order.type.code === "delivery") && (
          <div className="flex justify-between text-emerald-600"><span>Service charge</span><span>waived ({order.type.code === "delivery" ? "delivery" : "takeaway"})</span></div>
        )}
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
            {can("hotel_orders.split") && order.items.filter((i) => !i.voided).length > 1 && (
              <button className="btn-secondary" onClick={() => setSplitOpen(true)}><Split size={15} /> Split bill</button>
            )}
            {can("hotel_orders.merge") && mergeCandidates.length > 0 && (
              <button className="btn-secondary" onClick={() => setMergeOpen(true)}><Combine size={15} /> Merge orders</button>
            )}
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
          <button className="btn-secondary" onClick={() => printDocument(`/orders/${order.id}/slip`)}>
            <Printer size={15} /> Bill + token
          </button>
        )}
        {can("hotel_orders.receipt") && (
          <button className="btn-secondary" onClick={() => printDocument(`/orders/${order.id}/receipt?format=thermal`)}>
            <Printer size={15} /> Receipt
          </button>
        )}
        {can("hotel_orders.kot_ticket") && (
          <button className="btn-secondary" onClick={() => printDocument(`/orders/${order.id}/kot-ticket`)}>
            <Printer size={15} /> KOT ticket
          </button>
        )}
      </div>

      {payOpen && (
        <SplitPay
          due={due}
          allowOverpay
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
      {splitOpen && (
        <SplitBillModal
          order={order}
          onDone={async (groups) => {
            const ok = await act(() => post(`/orders/${order.id}/split`, { groups }));
            if (ok) toast.info(`Order #${order.id} split into ${groups.length} bills`);
            setSplitOpen(false);
          }}
          onClose={() => setSplitOpen(false)}
        />
      )}
      {mergeOpen && (
        <MergeOrdersModal
          order={order}
          candidates={mergeCandidates}
          onDone={async (orderIds) => {
            const ok = await act(() => post(`/orders/${order.id}/merge`, { order_ids: orderIds }));
            if (ok) toast.success(`Merged ${orderIds.length} order(s) into #${order.id}`);
            setMergeOpen(false);
          }}
          onClose={() => setMergeOpen(false)}
        />
      )}
    </Modal>
  );
}

/** Rider assignment + delivery status stepper for a delivery order. */
function DeliveryDispatch({ order, onChanged, onError }: { order: Order; onChanged: () => void; onError: (m: string) => void }) {
  const { data } = useFetch<{ staff: StaffLite[] }>("/staff");
  const staff = data?.staff ?? [];
  const status = order.delivery_status?.code ?? "pending";
  const stepIdx = DELIVERY_STEPS.indexOf(status as (typeof DELIVERY_STEPS)[number]);

  const setStatus = (s: string) =>
    put(`/orders/${order.id}/delivery/status`, { status: s }).then(onChanged).catch((e) => onError(e.message));

  return (
    <div className="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-purple-50 px-3 py-2 text-xs">
      <select
        className="input !w-40 !py-1"
        value={order.delivery_rider?.id ?? ""}
        onChange={(e) => e.target.value && put(`/orders/${order.id}/delivery/rider`, { rider_id: Number(e.target.value) }).then(onChanged).catch((err) => onError(err.message))}
      >
        <option value="">Assign rider…</option>
        {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
      </select>
      <div className="flex gap-1">
        {DELIVERY_STEPS.filter((s) => s !== "failed").map((s, i) => (
          <button
            key={s}
            className={clsx("rounded-full px-2.5 py-1 font-semibold", i === stepIdx ? "bg-purple-600 text-white" : i < stepIdx ? "bg-purple-200 text-purple-700" : "bg-white text-slate-500")}
            onClick={() => setStatus(s)}
          >
            {s.replace(/_/g, " ")}
          </button>
        ))}
        <button className="rounded-full bg-white px-2.5 py-1 font-semibold text-red-500" onClick={() => setStatus("failed")}>failed</button>
      </div>
    </div>
  );
}

/** Assign each item to a check number; groups with 2+ checks become separate child orders. */
function SplitBillModal({ order, onDone, onClose }: { order: Order; onDone: (groups: number[][]) => void; onClose: () => void }) {
  const items = order.items.filter((i) => !i.voided);
  const [checks, setChecks] = useState<Record<number, number>>(() => Object.fromEntries(items.map((i) => [i.id, 1])));
  const maxCheck = Math.max(1, ...Object.values(checks));

  const submit = () => {
    const groups: number[][] = [];
    for (let c = 1; c <= maxCheck; c++) {
      const ids = items.filter((i) => checks[i.id] === c).map((i) => i.id);
      if (ids.length) groups.push(ids);
    }
    onDone(groups);
  };

  return (
    <Modal open onClose={onClose} title="Split bill into separate checks">
      <div className="space-y-2">
        {items.map((i) => (
          <div key={i.id} className="flex items-center justify-between gap-2 text-sm">
            <span className="min-w-0 flex-1 truncate">{i.qty}× {i.name}</span>
            <span className="w-20 text-right">{lkr(i.amount)}</span>
            <select className="input !w-28 !py-1" value={checks[i.id]} onChange={(e) => setChecks({ ...checks, [i.id]: Number(e.target.value) })}>
              {Array.from({ length: Math.max(2, maxCheck + 1) }, (_, idx) => idx + 1).map((n) => (
                <option key={n} value={n}>Check {n}</option>
              ))}
            </select>
          </div>
        ))}
        <button className="btn-primary w-full !py-3" disabled={maxCheck < 2} onClick={submit}>
          Split into {maxCheck} checks
        </button>
      </div>
    </Modal>
  );
}

/** Fold other open/parked orders' items into this one. */
function MergeOrdersModal({ order, candidates, onDone, onClose }: { order: Order; candidates: Order[]; onDone: (orderIds: number[]) => void; onClose: () => void }) {
  const [selected, setSelected] = useState<number[]>([]);

  return (
    <Modal open onClose={onClose} title={`Merge into order #${order.id}`}>
      <div className="space-y-2">
        {candidates.map((o) => (
          <label key={o.id} className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
            <input
              type="checkbox"
              checked={selected.includes(o.id)}
              onChange={(e) => setSelected(e.target.checked ? [...selected, o.id] : selected.filter((id) => id !== o.id))}
            />
            <span className="min-w-0 flex-1 truncate">#{o.id} — {o.customer_name || (o.type.code === "room_guest" ? `Room ${o.room?.number}` : "Walk-in")}</span>
            <span className="font-semibold">{lkr(o.total)}</span>
          </label>
        ))}
        {candidates.length === 0 && <Empty text="No other open orders to merge" />}
        <button className="btn-primary w-full !py-3" disabled={selected.length === 0} onClick={() => onDone(selected)}>
          Merge {selected.length || ""} order{selected.length === 1 ? "" : "s"}
        </button>
      </div>
    </Modal>
  );
}

/** Split bill across multiple people / payment methods. */
export function SplitPay({
  due, onDone, onClose, allowOverpay = false,
}: {
  due: number;
  onDone: (p: { method: string; amount: number; reference?: string }[]) => void;
  onClose: () => void;
  // POS settle: an over-tender is fine as long as it's cash (the till hands
  // back change). The folio "record a payment" and venue-deposit call sites
  // don't have a "change" concept, so they keep the strict exact-match.
  allowOverpay?: boolean;
}) {
  const [rows, setRows] = useState<{ method: string; amount: string; reference: string }[]>([{ method: "cash", amount: (due / 100).toFixed(2), reference: "" }]);
  const sum = rows.reduce((s, r) => s + toCents(r.amount), 0);
  const remaining = due - sum;
  const hasCash = rows.some((r) => r.method.toLowerCase() === "cash" && toCents(r.amount) > 0);
  const overTenderNeedsCash = allowOverpay && remaining < 0 && !hasCash;
  const blocked = allowOverpay ? remaining > 0 || overTenderNeedsCash : remaining !== 0;

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
          <span className={remaining === 0 || (allowOverpay && remaining < 0 && !overTenderNeedsCash) ? "text-emerald-600" : overTenderNeedsCash ? "text-amber-600" : "text-red-600"}>
            {remaining === 0
              ? "Balanced ✓"
              : remaining > 0
                ? `Short ${lkr(remaining)}`
                : allowOverpay
                  ? overTenderNeedsCash ? `Over ${lkr(-remaining)} — add a cash payment to give change` : `Change due ${lkr(-remaining)}`
                  : `Over ${lkr(-remaining)}`}
          </span>
        </div>
        <button
          className="btn-primary w-full !py-3"
          disabled={blocked || due <= 0}
          onClick={() => onDone(rows.map((r) => ({ method: r.method, amount: toCents(r.amount), reference: r.reference || undefined })))}
        >
          Confirm {lkr(due)}
        </button>
      </div>
    </Modal>
  );
}

export function DiscountModal({ onApply, onClose }: { onApply: (mode: "PCT" | "FIXED", value: number, reason: string) => void; onClose: () => void }) {
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