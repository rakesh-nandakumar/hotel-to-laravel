import { useEffect, useMemo, useState } from "react";
import { Plus, Trash2, Search } from "lucide-react";
import clsx from "clsx";
import { post, put } from "../../lib/api";
import { useFetch, lkr, toCents, todayStr } from "../../lib/util";
import { Field, Modal, ErrorText } from "../../components/ui";
import { StockItem } from "./types";

type Line = {
  key: string;
  ingredientId: number | null;
  query: string;
  unit: string;
  qty: string;
  unitCost: string;
  batchNo: string;
  manufacturedAt: string;
  expiryDate: string;
};

type GrnDetail = {
  id: number;
  grn_no: string;
  reference: string | null;
  received_at: string;
  notes: string | null;
  total_cost: number;
  status: { code: string };
  lines: { id: number; ingredient_id: number; qty: number; unit_cost: number; batch_no: string | null; manufactured_at: string | null; expiry_date: string | null; ingredient: { id: number; name: string; unit: string } }[];
};

const blankLine = (): Line => ({
  key: crypto.randomUUID(), ingredientId: null, query: "", unit: "", qty: "", unitCost: "", batchNo: "", manufacturedAt: "", expiryDate: "",
});

/** Create/edit a GRN — draft only once received (the editor opens read-only for a received/cancelled GRN). */
export default function GrnEditor({ grnId, onClose, onReceive }: { grnId: number | null; onClose: () => void; onReceive?: (id: number) => void }) {
  const { data } = useFetch<{ grn: GrnDetail }>(grnId ? `/grns/${grnId}` : null);
  const { data: ingredientsData } = useFetch<{ ingredients: StockItem[] }>("/ingredients");
  const catalog = ingredientsData?.ingredients ?? [];

  const [reference, setReference] = useState("");
  const [receivedAt, setReceivedAt] = useState(todayStr());
  const [notes, setNotes] = useState("");
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [loadedFor, setLoadedFor] = useState<number | null>(null);

  const grn = data?.grn;
  const readOnly = grn ? grn.status.code !== "draft" : false;

  useEffect(() => {
    if (!grn || loadedFor === grn.id) return;
    setReference(grn.reference ?? "");
    setReceivedAt(grn.received_at.slice(0, 10));
    setNotes(grn.notes ?? "");
    setLines(
      grn.lines.length
        ? grn.lines.map((l) => ({
            key: crypto.randomUUID(), ingredientId: l.ingredient_id, query: l.ingredient.name, unit: l.ingredient.unit,
            qty: String(l.qty), unitCost: (l.unit_cost / 100).toFixed(2), batchNo: l.batch_no ?? "",
            manufacturedAt: l.manufactured_at?.slice(0, 10) ?? "", expiryDate: l.expiry_date?.slice(0, 10) ?? "",
          }))
        : [blankLine()]
    );
    setLoadedFor(grn.id);
  }, [grn, loadedFor]);

  const updateLine = (key: string, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const removeLine = (key: string) => setLines((ls) => (ls.length > 1 ? ls.filter((l) => l.key !== key) : ls));

  const lineTotal = (l: Line) => Math.round((parseFloat(l.qty) || 0) * toCents(l.unitCost || "0"));
  const grandTotal = lines.reduce((s, l) => s + lineTotal(l), 0);

  const valid = lines.every((l) => l.ingredientId && parseFloat(l.qty) > 0 && l.unitCost.trim() !== "") && receivedAt;

  const payload = () => ({
    reference: reference.trim() || null,
    received_at: receivedAt,
    notes: notes.trim() || null,
    lines: lines.map((l) => ({
      ingredient_id: l.ingredientId, qty: parseFloat(l.qty), unit_cost: toCents(l.unitCost),
      batch_no: l.batchNo.trim() || null, manufactured_at: l.manufacturedAt || null, expiry_date: l.expiryDate || null,
    })),
  });

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      if (grnId) await put(`/grns/${grnId}`, payload());
      else await post("/grns", payload());
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const receive = async () => {
    if (!grnId) return;
    setBusy(true);
    setError("");
    try {
      await post(`/grns/${grnId}/receive`);
      onReceive?.(grnId);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={grn ? `GRN ${grn.grn_no}` : "New Goods Received Note"} wide>
      {grn && <div className="mb-3 text-xs font-bold uppercase tracking-wide text-slate-400">Status: {grn.status.code}</div>}
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Reference (supplier invoice / bill no.)">
          <input className="input" disabled={readOnly} value={reference} onChange={(e) => setReference(e.target.value)} placeholder="e.g. INV-1001" />
        </Field>
        <Field label="Received date">
          <input type="date" className="input" disabled={readOnly} value={receivedAt} onChange={(e) => setReceivedAt(e.target.value)} />
        </Field>
        <Field label="Notes">
          <input className="input" disabled={readOnly} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </div>

      <div className="mt-4 overflow-x-auto">
        <table className="w-full min-w-[820px] text-sm">
          <thead>
            <tr className="text-left text-[11px] font-bold uppercase tracking-wide text-slate-400">
              <th className="px-2 py-1.5 w-64">Item</th>
              <th className="px-2 py-1.5">Qty</th>
              <th className="px-2 py-1.5">Unit cost</th>
              <th className="px-2 py-1.5">Batch no.</th>
              <th className="px-2 py-1.5">MFD</th>
              <th className="px-2 py-1.5">Expiry</th>
              <th className="px-2 py-1.5 text-right">Line total</th>
              <th className="px-2 py-1.5" />
            </tr>
          </thead>
          <tbody>
            {lines.map((l) => (
              <ItemLineRow key={l.key} line={l} catalog={catalog} readOnly={readOnly} onChange={(p) => updateLine(l.key, p)} onRemove={() => removeLine(l.key)} total={lineTotal(l)} />
            ))}
          </tbody>
        </table>
      </div>

      {!readOnly && (
        <button className="btn-secondary mt-2 !py-1.5 text-xs" onClick={() => setLines((ls) => [...ls, blankLine()])}>
          <Plus size={13} /> Add line
        </button>
      )}

      <div className="mt-3 flex justify-end border-t border-slate-100 pt-3 text-sm font-bold">
        <span>Total: {lkr(grandTotal)}</span>
      </div>

      <ErrorText error={error} />

      <div className="mt-4 flex flex-wrap justify-end gap-2">
        {!readOnly && (
          <button className="btn-primary" disabled={busy || !valid} onClick={save}>
            {grnId ? "Save draft" : "Create draft"}
          </button>
        )}
        {grnId && !readOnly && (
          <button className="btn-secondary !text-emerald-700" disabled={busy} onClick={receive}>
            Receive — post to stock
          </button>
        )}
      </div>
    </Modal>
  );
}

/** Search-existing / create-new-inline ingredient picker for one GRN line. */
function ItemLineRow({ line, catalog, readOnly, onChange, onRemove, total }: {
  line: Line; catalog: StockItem[]; readOnly: boolean; onChange: (p: Partial<Line>) => void; onRemove: () => void; total: number;
}) {
  const [open, setOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [newUnit, setNewUnit] = useState("pcs");
  const [createError, setCreateError] = useState("");

  const matches = useMemo(() => {
    const needle = line.query.trim().toLowerCase();
    if (!needle) return catalog.slice(0, 8);
    return catalog.filter((c) => c.name.toLowerCase().includes(needle)).slice(0, 8);
  }, [catalog, line.query]);

  const select = (item: StockItem) => {
    onChange({ ingredientId: item.id, query: item.name, unit: item.unit });
    setOpen(false);
  };

  const createInline = () => {
    setCreateError("");
    post<{ ingredient: { id: number; name: string; unit: string } }>("/ingredients", {
      name: line.query.trim(), unit: newUnit, stock_qty: 0, low_stock_threshold: 0, kind: "ingredient",
    })
      .then((res) => {
        onChange({ ingredientId: res.ingredient.id, query: res.ingredient.name, unit: res.ingredient.unit });
        setCreating(false);
        setOpen(false);
      })
      .catch((e) => setCreateError((e as Error).message));
  };

  return (
    <tr className="border-t border-slate-50 align-top">
      <td className="px-2 py-1.5">
        <div className="relative">
          <div className="relative">
            <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-300" />
            <input
              className="input !py-1.5 !pl-6 text-xs"
              disabled={readOnly}
              placeholder="Search ingredient/product…"
              value={line.query}
              onFocus={() => setOpen(true)}
              onChange={(e) => { onChange({ ingredientId: null, query: e.target.value }); setOpen(true); }}
              onBlur={() => setTimeout(() => setOpen(false), 150)}
            />
          </div>
          {open && !readOnly && (
            <div className="absolute z-10 mt-1 w-72 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
              {matches.map((m) => (
                <button key={m.id} type="button" className="flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-xs hover:bg-slate-50" onMouseDown={() => select(m)}>
                  <span>{m.name}</span>
                  <span className="text-slate-400">{m.unit}{m.kind?.code === "product" ? " · product" : ""}</span>
                </button>
              ))}
              {matches.length === 0 && <div className="px-2 py-1.5 text-xs text-slate-400">No matches</div>}
              {line.query.trim() && !catalog.some((c) => c.name.toLowerCase() === line.query.trim().toLowerCase()) && (
                creating ? (
                  <div className="mt-1 space-y-1.5 border-t border-slate-100 p-1.5" onMouseDown={(e) => e.preventDefault()}>
                    <select className="input !py-1 !text-xs" value={newUnit} onChange={(e) => setNewUnit(e.target.value)}>
                      {["g", "kg", "ml", "l", "pcs"].map((u) => <option key={u}>{u}</option>)}
                    </select>
                    <button type="button" className="btn-primary w-full !py-1 !text-xs" onClick={createInline}>
                      Create "{line.query.trim()}"
                    </button>
                    {createError && <p className="text-[11px] text-red-500">{createError}</p>}
                  </div>
                ) : (
                  <button type="button" className="w-full rounded px-2 py-1.5 text-left text-xs font-semibold text-brand-600 hover:bg-brand-50" onMouseDown={(e) => { e.preventDefault(); setCreating(true); }}>
                    <Plus size={11} className="mr-1 inline" /> Create "{line.query.trim()}" as a new ingredient
                  </button>
                )
              )}
            </div>
          )}
        </div>
      </td>
      <td className="px-2 py-1.5"><input className="input !w-20 !py-1.5 text-xs" disabled={readOnly} inputMode="decimal" value={line.qty} onChange={(e) => onChange({ qty: e.target.value })} /></td>
      <td className="px-2 py-1.5"><input className="input !w-24 !py-1.5 text-xs" disabled={readOnly} inputMode="decimal" value={line.unitCost} onChange={(e) => onChange({ unitCost: e.target.value })} placeholder="0.00" /></td>
      <td className="px-2 py-1.5"><input className="input !w-24 !py-1.5 text-xs" disabled={readOnly} value={line.batchNo} onChange={(e) => onChange({ batchNo: e.target.value })} /></td>
      <td className="px-2 py-1.5"><input type="date" className="input !w-32 !py-1.5 text-xs" disabled={readOnly} value={line.manufacturedAt} onChange={(e) => onChange({ manufacturedAt: e.target.value })} /></td>
      <td className="px-2 py-1.5"><input type="date" className="input !w-32 !py-1.5 text-xs" disabled={readOnly} value={line.expiryDate} onChange={(e) => onChange({ expiryDate: e.target.value })} /></td>
      <td className="px-2 py-1.5 text-right text-xs font-semibold">{lkr(total)}</td>
      <td className="px-2 py-1.5">
        {!readOnly && (
          <button type="button" className={clsx("btn-ghost !p-1 text-red-400")} onClick={onRemove}><Trash2 size={13} /></button>
        )}
      </td>
    </tr>
  );
}
