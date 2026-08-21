import { useState } from "react";
import { Plus, ShoppingBasket } from "lucide-react";
import { post } from "../../lib/api";
import { useFetch, toCents } from "../../lib/util";
import { useAuth } from "../../lib/auth";
import { Field, Modal, ErrorText } from "../../components/ui";
import { ImageDropUpload } from "../../components/ImageUpload";
import { InventoryNav } from "./InventoryNav";
import StockItemList from "./StockItemList";
import { MenuCategoryLite } from "./types";

export default function ProductsTab() {
  const { can } = useAuth();
  const canCreate = can("hotel_products.create");
  const [openNew, setOpenNew] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><ShoppingBasket /> Products</h1>
        <div className="flex items-center gap-2">
          <InventoryNav active="products" />
          {canCreate && <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New product</button>}
        </div>
      </div>
      <p className="text-sm text-slate-500">
        Directly-sellable, non-recipe stock items — bottled drinks, packaged snacks. A product never routes to the kitchen; selling one unit deducts one unit of stock.
      </p>

      <StockItemList
        kind="product"
        basePath="/products"
        canAdjust={can("hotel_products.adjust_stock")}
        canDelete={can("hotel_products.delete")}
        canWriteOff={false}
        canEdit={can("hotel_products.edit")}
        refreshKey={refreshKey}
      />

      {openNew && <NewProduct onClose={() => { setOpenNew(false); setRefreshKey((k) => k + 1); }} />}
    </div>
  );
}

function NewProduct({ onClose }: { onClose: () => void }) {
  const { data } = useFetch<{ menu_categories: MenuCategoryLite[] }>("/menu/categories");
  const categories = data?.menu_categories ?? [];
  const [f, setF] = useState({ name: "", unit: "pcs", stockQty: "0", lowStockThreshold: "0", sellingPrice: "", menuCategoryId: "", image: "" });
  const [error, setError] = useState("");

  return (
    <Modal open onClose={onClose} title="New product">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus placeholder="e.g. Coca-Cola 500ml" /></Field>
        <Field label="Unit">
          <select className="input" value={f.unit} onChange={(e) => setF({ ...f, unit: e.target.value })}>
            {["pcs", "g", "kg", "ml", "l"].map((u) => <option key={u}>{u}</option>)}
          </select>
        </Field>
        <Field label="Selling price (LKR)"><input className="input" inputMode="decimal" value={f.sellingPrice} onChange={(e) => setF({ ...f, sellingPrice: e.target.value })} placeholder="e.g. 250.00" /></Field>
        <Field label="POS category">
          <select className="input" value={f.menuCategoryId} onChange={(e) => setF({ ...f, menuCategoryId: e.target.value })}>
            <option value="">Uncategorized</option>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        <Field label="Opening stock"><input className="input" value={f.stockQty} onChange={(e) => setF({ ...f, stockQty: e.target.value })} /></Field>
        <Field label="Low-stock threshold"><input className="input" value={f.lowStockThreshold} onChange={(e) => setF({ ...f, lowStockThreshold: e.target.value })} /></Field>
      </div>
      <div className="mt-3">
        <Field label="Image (shown on the POS grid)">
          <ImageDropUpload value={f.image} onChange={(dataUrl) => setF({ ...f, image: dataUrl })} />
        </Field>
      </div>
      <ErrorText error={error} />
      <button
        className="btn-primary mt-4 w-full"
        disabled={!f.name.trim() || !f.sellingPrice.trim()}
        onClick={() =>
          post("/products", {
            name: f.name.trim(), unit: f.unit, stock_qty: parseFloat(f.stockQty) || 0, low_stock_threshold: parseFloat(f.lowStockThreshold) || 0,
            kind: "product", selling_price: toCents(f.sellingPrice), menu_category_id: f.menuCategoryId ? Number(f.menuCategoryId) : null,
            image: f.image || null,
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
