import { Dispatch, SetStateAction, useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { Minus, Plus, ShoppingCart, StickyNote } from "lucide-react";
import { api, post } from "../lib/api";
import { lkr } from "../lib/util";
import { Empty, ErrorText, Field, Modal } from "../components/ui";
import clsx from "clsx";

type Modifier = { id: number; name: string; price_delta: number };
type ModifierGroup = { id: number; name: string; is_required: boolean; max_select: number; modifiers: Modifier[] };
type MenuItem = { id: number; name: string; price: number; description: string; image?: string | null; modifier_groups?: ModifierGroup[] };
type MenuCat = { id: number; name: string; items: MenuItem[] };
type OrderSummary = { id: number; status: string; kot_status: string; items: { name: string; qty: number; amount: number }[]; total: number };
type Theme = {
  welcome_message: string; accent_color: string; banner_image: string;
  show_item_images: boolean; show_descriptions: boolean;
  collect_customer_name: boolean; collect_customer_phone: boolean; footer_note: string;
};
type MenuResponse = {
  point: { type: "room" | "table"; label: string };
  branding: { name: string; logo: string };
  theme: Theme;
  current_order: OrderSummary | null;
  categories: MenuCat[];
};
type CartLine = { key: string; menuItemId: number; name: string; price: number; qty: number; notes?: string; modifierIds: number[] };

const KOT_LABEL: Record<string, string> = { new: "Received", preparing: "Preparing", ready: "Ready to serve", served: "Served" };

/** Public, unauthenticated — the page a guest lands on after scanning a room-desk or restaurant-table QR code. */
export default function QrOrder() {
  const { token = "" } = useParams();
  const [data, setData] = useState<MenuResponse | null>(null);
  const [blocked, setBlocked] = useState("");
  const [loading, setLoading] = useState(true);
  const [cart, setCart] = useState<CartLine[]>([]);
  const [pickerItem, setPickerItem] = useState<MenuItem | null>(null);
  const [cartOpen, setCartOpen] = useState(false);
  const [confirmed, setConfirmed] = useState<OrderSummary | null>(null);

  const load = () => {
    api<MenuResponse>(`/public/qr/${token}/menu`)
      .then((d) => { setData(d); setBlocked(""); })
      .catch((e) => setBlocked((e as Error).message || "Ordering isn't available here right now."))
      .finally(() => setLoading(false));
  };
  useEffect(load, [token]); // eslint-disable-line react-hooks/exhaustive-deps

  // Live status while the guest is looking at the confirmation screen.
  useEffect(() => {
    if (!confirmed) return;
    const id = setInterval(() => {
      api<{ order: OrderSummary }>(`/public/qr/${token}/orders/${confirmed.id}`)
        .then((r) => setConfirmed(r.order))
        .catch(() => {});
    }, 8000);
    return () => clearInterval(id);
  }, [confirmed, token]);

  const accent = data?.theme.accent_color || "#0462d3";

  if (loading) return <CenteredShell><p className="text-sm text-slate-400">Loading menu…</p></CenteredShell>;

  if (blocked || !data) {
    return (
      <CenteredShell>
        <div className="text-3xl">🍽️</div>
        <p className="mt-3 text-base font-bold text-slate-800">{blocked || "This ordering link is no longer valid."}</p>
      </CenteredShell>
    );
  }

  if (confirmed) {
    return (
      <CenteredShell wide>
        <div className="text-3xl">✅</div>
        <h1 className="mt-2 text-lg font-black text-slate-800">Order sent!</h1>
        <p className="mt-1 text-sm text-slate-500">Order #{confirmed.id} — {data.point.label}</p>
        <div className="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-left">
          <div className="text-xs font-bold uppercase tracking-wide text-slate-400">{KOT_LABEL[confirmed.kot_status] ?? confirmed.kot_status}</div>
          <div className="mt-2 space-y-1 text-sm">
            {confirmed.items.map((it, i) => (
              <div key={i} className="flex justify-between"><span>{it.qty}× {it.name}</span><span>{lkr(it.amount)}</span></div>
            ))}
          </div>
          <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 text-sm font-extrabold"><span>Total</span><span>{lkr(confirmed.total)}</span></div>
        </div>
        <button
          className="mt-4 w-full rounded-xl py-3 text-sm font-bold text-white"
          style={{ backgroundColor: accent }}
          onClick={() => { setConfirmed(null); setCart([]); load(); }}
        >
          Order more
        </button>
      </CenteredShell>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 pb-24">
      {data.theme.banner_image && <img src={data.theme.banner_image} alt="" className="h-40 w-full object-cover" />}
      <div className="mx-auto max-w-2xl px-4 pt-5">
        <div className="flex items-center gap-3">
          {data.branding.logo && <img src={data.branding.logo} alt="" className="h-10 w-10 shrink-0 rounded-lg object-contain" />}
          <div>
            <h1 className="text-lg font-black text-slate-900">{data.branding.name}</h1>
            <p className="text-xs font-semibold text-slate-400">{data.point.label}</p>
          </div>
        </div>
        {data.theme.welcome_message && <p className="mt-3 text-sm text-slate-600">{data.theme.welcome_message}</p>}

        {data.current_order && (
          <div className="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Your table already has an order — {data.current_order.items.length} item(s), {lkr(data.current_order.total)} so far. Anything you add now joins the same bill.
          </div>
        )}

        <div className="mt-5 space-y-6">
          {data.categories.map((cat) => (
            <section key={cat.id}>
              <h2 className="mb-2 text-sm font-black uppercase tracking-wide text-slate-500">{cat.name}</h2>
              <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                {cat.items.map((item) => {
                  const inCart = cart.filter((l) => l.menuItemId === item.id).reduce((s, l) => s + l.qty, 0);
                  return (
                    <button
                      key={item.id}
                      onClick={() => ((item.modifier_groups?.length ?? 0) > 0 ? setPickerItem(item) : addToCart(setCart, item))}
                      className={clsx(
                        "flex items-start gap-3 rounded-xl bg-white p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:scale-[.99]",
                        inCart > 0 && "ring-2"
                      )}
                      style={inCart > 0 ? { boxShadow: `0 0 0 2px ${accent}` } : undefined}
                    >
                      {data.theme.show_item_images && item.image && (
                        <img src={item.image} alt="" className="h-16 w-16 shrink-0 rounded-lg object-cover" />
                      )}
                      <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-1">
                          <span className="text-sm font-bold leading-tight text-slate-800">{item.name}</span>
                          {inCart > 0 && (
                            <span className="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-black text-white" style={{ backgroundColor: accent }}>{inCart}</span>
                          )}
                        </div>
                        {data.theme.show_descriptions && item.description && (
                          <p className="mt-0.5 line-clamp-2 text-xs text-slate-500">{item.description}</p>
                        )}
                        <div className="mt-1 text-sm font-semibold" style={{ color: accent }}>{lkr(item.price)}</div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </section>
          ))}
          {data.categories.length === 0 && <Empty text="Nothing on the menu right now — please check with staff." />}
        </div>

        {data.theme.footer_note && <p className="mt-8 pb-4 text-center text-[11px] text-slate-400">{data.theme.footer_note}</p>}
      </div>

      {cart.length > 0 && (
        <button
          className="fixed inset-x-4 bottom-4 z-40 flex items-center justify-between rounded-2xl px-5 py-4 text-white shadow-lg"
          style={{ backgroundColor: accent }}
          onClick={() => setCartOpen(true)}
        >
          <span className="flex items-center gap-2 text-sm font-bold">
            <ShoppingCart size={18} /> {cart.reduce((s, l) => s + l.qty, 0)} item(s)
          </span>
          <span className="text-sm font-black">{lkr(cart.reduce((s, l) => s + l.price * l.qty, 0))}</span>
        </button>
      )}

      {pickerItem && (
        <ModifierPickerModal item={pickerItem} accent={accent} onAdd={(ids, price) => addToCart(setCart, pickerItem, ids, price)} onClose={() => setPickerItem(null)} />
      )}

      {cartOpen && (
        <CartDrawer
          token={token}
          cart={cart}
          setCart={setCart}
          theme={data.theme}
          isTable={data.point.type === "table"}
          accent={accent}
          onClose={() => setCartOpen(false)}
          onPlaced={(order) => { setCartOpen(false); setCart([]); setConfirmed(order); }}
        />
      )}
    </div>
  );
}

function addToCart(setCart: Dispatch<SetStateAction<CartLine[]>>, item: MenuItem, modifierIds: number[] = [], unitPrice?: number) {
  const key = `${item.id}:${[...modifierIds].sort((a, b) => a - b).join(",")}`;
  const price = unitPrice ?? item.price;
  const modLabel = (item.modifier_groups ?? []).flatMap((g) => g.modifiers).filter((m) => modifierIds.includes(m.id)).map((m) => m.name).join(", ");
  setCart((c) => {
    const existing = c.find((l) => l.key === key);
    if (existing) return c.map((l) => (l.key === key ? { ...l, qty: l.qty + 1 } : l));
    return [...c, { key, menuItemId: item.id, name: modLabel ? `${item.name} (${modLabel})` : item.name, price, qty: 1, modifierIds }];
  });
}

function CenteredShell({ children, wide }: { children: React.ReactNode; wide?: boolean }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className={clsx("w-full rounded-2xl bg-white p-6 text-center shadow-sm", wide ? "max-w-md" : "max-w-sm")}>{children}</div>
    </div>
  );
}

/** Same required-group / max-select interaction as POS.tsx's ModifierPickerModal, re-implemented for this unauthenticated page. */
function ModifierPickerModal({ item, accent, onAdd, onClose }: { item: MenuItem; accent: string; onAdd: (modifierIds: number[], unitPrice: number) => void; onClose: () => void }) {
  const groups = item.modifier_groups ?? [];
  const [selected, setSelected] = useState<Record<number, number[]>>({});

  const toggle = (group: ModifierGroup, modifierId: number) => {
    setSelected((s) => {
      const cur = s[group.id] ?? [];
      if (cur.includes(modifierId)) return { ...s, [group.id]: cur.filter((id) => id !== modifierId) };
      if (group.max_select <= 1) return { ...s, [group.id]: [modifierId] };
      if (cur.length >= group.max_select) return s;
      return { ...s, [group.id]: [...cur, modifierId] };
    });
  };

  const chosenIds = Object.values(selected).flat();
  const chosenModifiers = groups.flatMap((g) => g.modifiers).filter((m) => chosenIds.includes(m.id));
  const unitPrice = item.price + chosenModifiers.reduce((s, m) => s + m.price_delta, 0);
  const allRequiredSatisfied = groups.every((g) => !g.is_required || (selected[g.id]?.length ?? 0) > 0);

  return (
    <Modal open onClose={onClose} title={item.name}>
      <div className="space-y-4">
        {groups.map((g) => (
          <div key={g.id}>
            <div className="label">{g.name}{g.is_required && " *"}{g.max_select > 1 && ` — choose up to ${g.max_select}`}</div>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {g.modifiers.map((m) => {
                const active = (selected[g.id] ?? []).includes(m.id);
                return (
                  <button
                    key={m.id}
                    className="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    style={active ? { backgroundColor: accent, color: "#fff" } : { backgroundColor: "#f1f5f9", color: "#475569" }}
                    onClick={() => toggle(g, m.id)}
                  >
                    {m.name}{m.price_delta !== 0 && ` (${m.price_delta > 0 ? "+" : "-"}${lkr(Math.abs(m.price_delta))})`}
                  </button>
                );
              })}
            </div>
          </div>
        ))}
        <div className="flex justify-between border-t border-slate-100 pt-3 text-sm font-bold">
          <span>Price</span><span>{lkr(unitPrice)}</span>
        </div>
        <button
          className="w-full rounded-xl py-3 text-sm font-bold text-white disabled:opacity-40"
          style={{ backgroundColor: accent }}
          disabled={!allRequiredSatisfied}
          onClick={() => onAdd(chosenIds, unitPrice)}
        >
          Add to order — {lkr(unitPrice)}
        </button>
      </div>
    </Modal>
  );
}

function CartDrawer({
  token, cart, setCart, theme, isTable, accent, onClose, onPlaced,
}: {
  token: string; cart: CartLine[]; setCart: Dispatch<SetStateAction<CartLine[]>>; theme: Theme; isTable: boolean; accent: string;
  onClose: () => void; onPlaced: (order: OrderSummary) => void;
}) {
  const [noteFor, setNoteFor] = useState<string | null>(null);
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const setQty = (key: string, qty: number) => setCart((c) => (qty <= 0 ? c.filter((l) => l.key !== key) : c.map((l) => (l.key === key ? { ...l, qty } : l))));
  const total = cart.reduce((s, l) => s + l.price * l.qty, 0);

  const submit = async () => {
    if (isTable && theme.collect_customer_name && !customerName.trim()) return setError("Please tell us your name.");
    if (isTable && theme.collect_customer_phone && !customerPhone.trim()) return setError("Please share a contact number.");
    setBusy(true);
    setError("");
    try {
      const res = await post<{ order: OrderSummary }>(`/public/qr/${token}/order`, {
        client_key: crypto.randomUUID(),
        items: cart.map((l) => ({ menu_item_id: l.menuItemId, qty: l.qty, notes: l.notes, modifier_ids: l.modifierIds.length ? l.modifierIds : undefined })),
        customer_name: isTable ? customerName.trim() || undefined : undefined,
        customer_phone: isTable ? customerPhone.trim() || undefined : undefined,
        notes: notes.trim() || undefined,
      });
      onPlaced(res.order);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title="Your order">
      <div className="space-y-1.5">
        {cart.map((l) => (
          <div key={l.key}>
            <div className="flex items-center gap-2 text-sm">
              <div className="min-w-0 flex-1">
                <div className="truncate font-semibold">{l.name}</div>
                <div className="text-xs text-slate-400">{lkr(l.price)}</div>
              </div>
              <button
                className={clsx("rounded p-1", l.notes ? "text-amber-500" : "text-slate-300")}
                title={l.notes ? `Note: ${l.notes}` : "Add a note for the kitchen"}
                onClick={() => setNoteFor(noteFor === l.key ? null : l.key)}
              >
                <StickyNote size={14} />
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
                onBlur={() => setNoteFor(null)}
              />
            )}
          </div>
        ))}
        {cart.length === 0 && <Empty text="Your cart is empty" />}
      </div>

      {isTable && (theme.collect_customer_name || theme.collect_customer_phone) && (
        <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
          {theme.collect_customer_name && (
            <Field label="Your name"><input className="input" value={customerName} onChange={(e) => setCustomerName(e.target.value)} autoFocus /></Field>
          )}
          {theme.collect_customer_phone && (
            <Field label="Contact number"><input className="input" value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} /></Field>
          )}
        </div>
      )}
      <div className="mt-3">
        <Field label="Anything else? (optional)"><textarea className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
      </div>

      <div className="mt-4 flex justify-between border-t border-slate-100 pt-3 text-base font-extrabold">
        <span>Total</span><span>{lkr(total)}</span>
      </div>
      <ErrorText error={error} />
      <button
        className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl py-3 text-sm font-bold text-white disabled:opacity-40"
        style={{ backgroundColor: accent }}
        disabled={busy || cart.length === 0}
        onClick={submit}
      >
        {busy ? "Placing order…" : `Place order — ${lkr(total)}`}
      </button>
    </Modal>
  );
}
