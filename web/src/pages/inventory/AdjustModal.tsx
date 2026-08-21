import { useState } from "react";
import { ArrowDownToLine, ArrowUpFromLine, Trash2 } from "lucide-react";
import clsx from "clsx";
import { api, post, put } from "../../lib/api";
import { lkr, toCents, centsToRupees } from "../../lib/util";
import { Field, Modal, ErrorText } from "../../components/ui";
import { StockItem } from "./types";

/**
 * Receive/write-down stock with an audit trail, and edit costing. This is for
 * corrections — wastage, spillage, a stocktake count, a manual top-up — not
 * purchases; a real supplier delivery should go through a Goods Received Note
 * so its cost and expiry are captured per batch.
 */
export default function AdjustModal({
  item, basePath, canAdjust, canDelete, canEdit, onClose,
}: {
  item: StockItem; basePath: string; canAdjust: boolean; canDelete: boolean; canEdit: boolean; onClose: () => void;
}) {
  const [mode, setMode] = useState<"IN" | "OUT">("IN");
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [expiryDate, setExpiryDate] = useState("");
  const [error, setError] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [busy, setBusy] = useState(false);
  const n = parseFloat(amount) || 0;

  const [unitCost, setUnitCost] = useState(item.unit_cost !== null ? centsToRupees(item.unit_cost) : "");
  const [costSaved, setCostSaved] = useState(false);
  const saveUnitCost = () =>
    put(`${basePath}/${item.id}`, { unit_cost: unitCost.trim() === "" ? null : toCents(unitCost) })
      .then(() => {
        setCostSaved(true);
        setTimeout(() => setCostSaved(false), 1500);
      })
      .catch((e) => setError((e as Error).message));

  const apply = async () => {
    setBusy(true);
    setError("");
    try {
      await post(`${basePath}/${item.id}/adjust`, {
        delta: mode === "IN" ? n : -n,
        reason: reason.trim(),
        expiry_date: mode === "IN" && expiryDate ? expiryDate : undefined,
      });
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    setBusy(true);
    setError("");
    try {
      await api(`${basePath}/${item.id}`, { method: "DELETE" });
      onClose();
    } catch (e) {
      setError((e as Error).message);
      setConfirmDelete(false);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`${item.name} — ${item.stock_qty.toLocaleString()} ${item.unit} in stock`}>
      {canAdjust && (<>
      <p className="mb-3 text-xs text-slate-400">Corrections only — wastage, spillage, a stocktake count. A supplier delivery should go through a Goods Received Note instead, so its cost and expiry are captured per batch.</p>
      <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
        <button className={clsx("flex-1 rounded-lg px-2 py-2 text-sm font-semibold", mode === "IN" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setMode("IN")}>
          <ArrowDownToLine size={14} className="mr-1 inline" /> Receive stock
        </button>
        <button className={clsx("flex-1 rounded-lg px-2 py-2 text-sm font-semibold", mode === "OUT" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setMode("OUT")}>
          <ArrowUpFromLine size={14} className="mr-1 inline" /> Write down
        </button>
      </div>

      <div className="space-y-3">
        <Field label={`Quantity (${item.unit})`}>
          <input className="input" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={mode === "IN" ? "e.g. 5000" : "e.g. 200"} autoFocus />
        </Field>
        {mode === "IN" && (
          <Field label="Expiry date (recommended — enables alerts & first-expiring-first-out)">
            <input type="date" className="input" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} />
          </Field>
        )}
        <Field label="Reason (required, audited)">
          <input className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={mode === "IN" ? "e.g. stocktake correction" : "e.g. spoilage / spillage"} />
        </Field>
        <ErrorText error={error} />
        <button className={clsx("w-full !py-3", mode === "IN" ? "btn-primary" : "btn-danger")} disabled={busy || !reason.trim() || n <= 0} onClick={apply}>
          {mode === "IN" ? `Receive +${n || 0} ${item.unit}` : `Write down −${n || 0} ${item.unit}`}
        </button>
      </div>
      </>)}

      {canEdit && (
        <div className={clsx("rounded-xl border border-slate-100 p-3", canAdjust && "mt-5")}>
          <div className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Costing (for the Food Cost % report)</div>
          <div className="flex items-end gap-2">
            <Field label={`Cost per ${item.unit} (LKR)`} hint="Leave blank if unknown — priced items just show — on the report.">
              <input className="input" inputMode="decimal" value={unitCost} onChange={(e) => setUnitCost(e.target.value)} placeholder="e.g. 45.00" />
            </Field>
            <button className="btn-secondary !py-2.5" onClick={saveUnitCost}>{costSaved ? "Saved ✓" : "Save"}</button>
          </div>
          {item.unit_cost !== null && <p className="mt-1 text-[11px] text-slate-400">Currently {lkr(item.unit_cost)} / {item.unit}</p>}
        </div>
      )}

      {canDelete && (
        <div className="mt-5 rounded-xl border border-red-100 bg-red-50/50 p-3">
          <div className="text-xs font-bold uppercase tracking-wide text-red-400">Danger zone</div>
          {item.used_in.length > 0 ? (
            <p className="mt-1 text-xs text-slate-500">
              This can't be removed while it's used in <b>{item.used_in.length}</b> recipe{item.used_in.length === 1 ? "" : "s"} ({item.used_in.slice(0, 4).join(", ")}{item.used_in.length > 4 ? "…" : ""}). Edit those menu items first.
            </p>
          ) : !confirmDelete ? (
            <button className="btn-secondary mt-2 !py-1.5 text-xs !text-red-600" onClick={() => setConfirmDelete(true)}>
              <Trash2 size={13} /> Remove…
            </button>
          ) : (
            <div className="mt-2 space-y-2">
              <p className="text-xs font-semibold text-red-700">
                Permanently remove “{item.name}” and its {item.batches.length} tracked batch(es)? This cannot be undone.
              </p>
              <div className="flex gap-2">
                <button className="btn-danger !py-1.5 text-xs" disabled={busy} onClick={remove}>Yes, remove permanently</button>
                <button className="btn-secondary !py-1.5 text-xs" onClick={() => setConfirmDelete(false)}>Cancel</button>
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
