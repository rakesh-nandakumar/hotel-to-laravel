import { useState } from "react";
import { Plus, Package } from "lucide-react";
import { post } from "../../lib/api";
import { useAuth } from "../../lib/auth";
import { Field, Modal, ErrorText } from "../../components/ui";
import { InventoryNav } from "./InventoryNav";
import StockItemList from "./StockItemList";

export default function IngredientsTab() {
  const { can } = useAuth();
  const canCreate = can("hotel_ingredients.create");
  const [openNew, setOpenNew] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><Package /> Kitchen Inventory</h1>
        <div className="flex items-center gap-2">
          <InventoryNav active="ingredients" />
          {canCreate && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New ingredient</button>}
        </div>
      </div>

      <StockItemList
        kind="ingredient"
        basePath="/ingredients"
        canAdjust={can("hotel_ingredients.adjust_stock")}
        canDelete={can("hotel_ingredients.delete")}
        canWriteOff={can("hotel_ingredients.write_off")}
        canEdit={can("hotel_ingredients.edit")}
        refreshKey={refreshKey}
      />

      {openNew && <NewIngredient onClose={() => { setOpenNew(false); setRefreshKey((k) => k + 1); }} />}
    </div>
  );
}

function NewIngredient({ onClose }: { onClose: () => void }) {
  const [f, setF] = useState({ name: "", unit: "g", stockQty: "0", lowStockThreshold: "0" });
  const [error, setError] = useState("");
  return (
    <Modal open onClose={onClose} title="New ingredient">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus /></Field>
        <Field label="Unit">
          <select className="input" value={f.unit} onChange={(e) => setF({ ...f, unit: e.target.value })}>
            {["g", "kg", "ml", "l", "pcs"].map((u) => <option key={u}>{u}</option>)}
          </select>
        </Field>
        <Field label="Opening stock"><input className="input" value={f.stockQty} onChange={(e) => setF({ ...f, stockQty: e.target.value })} /></Field>
        <Field label="Low-stock threshold"><input className="input" value={f.lowStockThreshold} onChange={(e) => setF({ ...f, lowStockThreshold: e.target.value })} /></Field>
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.name.trim()}
        onClick={() =>
          post("/ingredients", {
            name: f.name.trim(), unit: f.unit, stock_qty: parseFloat(f.stockQty) || 0, low_stock_threshold: parseFloat(f.lowStockThreshold) || 0,
            kind: "ingredient",
          })
            .then(onClose)
            .catch((e) => setError(e.message))
        }
      >
        Create
      </button>
    </Modal>
  );
}
