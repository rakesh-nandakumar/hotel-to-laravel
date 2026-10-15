import { useState } from "react";
import { Plus, Package } from "lucide-react";
import { post } from "../../lib/api";
import { useAuth } from "../../lib/auth";
import { useSettings } from "../../lib/util";
import { Field, Modal, ErrorText } from "../../components/ui";
import { InventoryNav } from "./InventoryNav";
import StockItemList from "./StockItemList";
import clsx from "clsx";

export default function IngredientsTab() {
  const { can } = useAuth();
  const canCreate = can("hotel_ingredients.create");
  const { bool } = useSettings();
  const botEnabled = bool("bot.enabled", false);
  const [openNew, setOpenNew] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const [activeTab, setActiveTab] = useState<"kitchen" | "bar">("kitchen");

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><Package /> Kitchen Inventory</h1>
        <div className="flex items-center gap-2">
          <InventoryNav active="ingredients" />
          {canCreate && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New ingredient</button>}
        </div>
      </div>

      {/* KOT/BOT tabs - only show if BOT is enabled */}
      {botEnabled && (
        <div className="flex gap-1 rounded-xl bg-slate-200/70 p-1">
          <button
            onClick={() => setActiveTab("kitchen")}
            className={clsx("rounded-lg px-4 py-2 text-sm font-semibold transition", activeTab === "kitchen" ? "bg-white shadow-sm" : "text-slate-500 hover:text-slate-800")}
          >
            Kitchen (KOT)
          </button>
          <button
            onClick={() => setActiveTab("bar")}
            className={clsx("rounded-lg px-4 py-2 text-sm font-semibold transition", activeTab === "bar" ? "bg-white shadow-sm" : "text-slate-500 hover:text-slate-800")}
          >
            Bar (BOT)
          </button>
        </div>
      )}

      <StockItemList
        kind="ingredient"
        basePath="/ingredients"
        canAdjust={can("hotel_ingredients.adjust_stock")}
        canDelete={can("hotel_ingredients.delete")}
        canWriteOff={can("hotel_ingredients.write_off")}
        canEdit={can("hotel_ingredients.edit")}
        refreshKey={refreshKey}
        kotTargetFilter={botEnabled ? activeTab : undefined}
      />

      {openNew && (
        <NewIngredient 
          onClose={() => { 
            setOpenNew(false); 
            setRefreshKey((k) => k + 1); 
          }} 
          defaultKotTarget={activeTab as "kitchen" | "bar"} 
          botEnabled={botEnabled}
        />
      )}
    </div>
  );
}

function NewIngredient({ onClose, defaultKotTarget, botEnabled }: { onClose: () => void; defaultKotTarget: "kitchen" | "bar"; botEnabled: boolean }) {
  const [f, setF] = useState<{ name: string; unit: string; stockQty: string; lowStockThreshold: string; kotTarget: "kitchen" | "bar" }>({
    name: "", 
    unit: "g", 
    stockQty: "0", 
    lowStockThreshold: "0", 
    kotTarget: defaultKotTarget
  });
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
        {botEnabled && (
          <Field label="KOT Target" hint="Where this ingredient appears: Kitchen (KOT) or Bar (BOT)">
            <select className="input" value={f.kotTarget} onChange={(e) => setF({ ...f, kotTarget: e.target.value as "kitchen" | "bar" })}>
              <option value="kitchen">Kitchen (KOT)</option>
              <option value="bar">Bar (BOT)</option>
            </select>
          </Field>
        )}
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
            kind: "ingredient", kot_target: botEnabled ? f.kotTarget : "kitchen",
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
