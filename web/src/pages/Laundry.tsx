import { useState } from "react";
import { Shirt, Plus, Minus, Send } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch, lkr, toCents, centsToRupees } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field } from "../components/ui";
import { useAuth } from "../lib/auth";
import { useToast } from "../lib/toast";

type LaundryItem = { id: number; name: string; price: number; active: boolean };
type BoardRoom = { id: number; number: string; status: { code: string }; occupant: { code: string; guest: { name: string } } | null };

/**
 * Laundry service — housekeeper/manager records collected laundry against a
 * checked-in room; charges post to the guest folio as LAUNDRY lines and appear
 * on the consolidated A4 bill at checkout. Prices editable by Manager.
 */
export default function Laundry() {
  const toast = useToast();
  const { can } = useAuth();
  const canEditPrices = can("hotel_laundry.edit");
  const { data: itemsData, reload } = useFetch<{ items: LaundryItem[] }>("/laundry/items");
  const { data: roomsData } = useFetch<{ rooms: BoardRoom[] }>("/rooms");
  const items = itemsData?.items;
  const occupied = (roomsData?.rooms ?? []).filter((r) => r.status.code === "OCCUPIED" && r.occupant);

  const [roomId, setRoomId] = useState("");
  const [qty, setQty] = useState<Record<number, number>>({});
  const [note, setNote] = useState("");
  const [error, setError] = useState("");
  const [okMsg, setOkMsg] = useState("");
  const [busy, setBusy] = useState(false);

  const active = (items ?? []).filter((i) => i.active);
  const total = active.reduce((s, i) => s + (qty[i.id] ?? 0) * i.price, 0);
  const pieces = Object.values(qty).reduce((s, n) => s + n, 0);

  const setItemQty = (id: number, n: number) => setQty({ ...qty, [id]: Math.max(0, n) });

  const charge = async () => {
    setBusy(true);
    setError("");
    setOkMsg("");
    try {
      const res = await post<{ guest: string; reservation: string; total: number }>("/laundry/charge", {
        room_id: Number(roomId),
        items: active.filter((i) => (qty[i.id] ?? 0) > 0).map((i) => ({ laundry_item_id: i.id, qty: qty[i.id] })),
        note: note || undefined,
      });
      setOkMsg(`Charged ${lkr(res.total)} to ${res.guest} (${res.reservation}) — it will appear on the checkout bill.`);
      toast.success(`Laundry charged to ${res.guest}`, lkr(res.total));
      setQty({});
      setNote("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <h1 className="flex items-center gap-2 text-xl font-extrabold">
        <Shirt /> Laundry Service
      </h1>
      <p className="text-xs text-slate-500">Charges post straight to the guest's folio and appear on the final A4 bill — no re-entry at checkout.</p>

      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <Card title="Charge laundry to a room">
          <div className="mb-3 grid gap-3 sm:grid-cols-2">
            <Field label="Room (checked-in guests)">
              <select className="input" value={roomId} onChange={(e) => setRoomId(e.target.value)}>
                <option value="">Select room…</option>
                {occupied.map((r) => (
                  <option key={r.id} value={r.id}>Room {r.number} — {r.occupant?.guest.name}</option>
                ))}
              </select>
            </Field>
            <Field label="Note (optional)">
              <input className="input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. express — return by 6pm" />
            </Field>
          </div>
          <div className="divide-y divide-slate-50">
            {active.map((i) => (
              <div key={i.id} className="flex items-center gap-2 py-2">
                <div className="min-w-0 flex-1">
                  <div className="truncate text-sm font-semibold">{i.name}</div>
                  <div className="text-xs text-slate-400">{lkr(i.price)} / piece</div>
                </div>
                <button className="btn-secondary !p-1.5" onClick={() => setItemQty(i.id, (qty[i.id] ?? 0) - 1)}><Minus size={13} /></button>
                <span className="w-8 text-center font-bold">{qty[i.id] ?? 0}</span>
                <button className="btn-secondary !p-1.5" onClick={() => setItemQty(i.id, (qty[i.id] ?? 0) + 1)}><Plus size={13} /></button>
                <span className="w-24 text-right text-sm font-semibold">{(qty[i.id] ?? 0) > 0 ? lkr((qty[i.id] ?? 0) * i.price) : ""}</span>
              </div>
            ))}
            {active.length === 0 && <Empty text="No laundry items — add prices on the right" />}
          </div>
          <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
            <span className="text-sm font-semibold">{pieces} piece{pieces === 1 ? "" : "s"}</span>
            <span className="text-lg font-extrabold text-brand-700">{lkr(total)}</span>
          </div>
          <ErrorText error={error} />
          {okMsg && <div className="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">{okMsg}</div>}
          <button className="btn-primary mt-3 w-full !py-3" disabled={busy || !roomId || pieces === 0} onClick={charge}>
            <Send size={15} /> Charge {lkr(total)} to room folio
          </button>
        </Card>

        <Card title={`Price list ${canEditPrices ? "(edit & click away to save)" : ""}`}>
          <div className="space-y-2">
            {(items ?? []).map((i) => (
              <div key={i.id} className="flex items-center gap-2">
                <span className={`min-w-0 flex-1 truncate text-sm ${!i.active ? "text-slate-300 line-through" : ""}`}>{i.name}</span>
                {canEditPrices ? (
                  <>
                    <input
                      className="input !w-24 !py-1 text-right"
                      defaultValue={centsToRupees(i.price)}
                      onBlur={(e) => {
                        const cents = toCents(e.target.value);
                        if (cents !== i.price) put(`/laundry/items/${i.id}`, { price: cents }).then(reload).catch((err) => setError(err.message));
                      }}
                    />
                    <button
                      className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${i.active ? "bg-emerald-100 text-emerald-700" : "bg-slate-100 text-slate-400"}`}
                      onClick={() => put(`/laundry/items/${i.id}`, { active: !i.active }).then(reload)}
                    >
                      {i.active ? "ON" : "OFF"}
                    </button>
                  </>
                ) : (
                  <Badge>{lkr(i.price)}</Badge>
                )}
              </div>
            ))}
          </div>
          {canEditPrices && <NewLaundryItem onDone={reload} />}
          <p className="mt-2 text-[11px] text-slate-400">⚠ Seeded prices are placeholders — confirm with the owner.</p>
        </Card>
      </div>
    </div>
  );
}

function NewLaundryItem({ onDone }: { onDone: () => void }) {
  const [name, setName] = useState("");
  const [price, setPrice] = useState("");
  const [error, setError] = useState("");
  return (
    <div className="mt-3 border-t border-slate-100 pt-3">
      <div className="flex gap-2">
        <input className="input" placeholder="New item name" value={name} onChange={(e) => setName(e.target.value)} />
        <input className="input !w-24" placeholder="LKR" value={price} onChange={(e) => setPrice(e.target.value)} />
        <button
          className="btn-secondary"
          disabled={!name.trim() || toCents(price) <= 0}
          onClick={() =>
            post("/laundry/items", { name: name.trim(), price: toCents(price) })
              .then(() => { setName(""); setPrice(""); onDone(); })
              .catch((e) => setError(e.message))
          }
        >
          <Plus size={14} /> Add
        </button>
      </div>
      <ErrorText error={error} />
    </div>
  );
}
