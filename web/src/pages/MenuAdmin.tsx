import { useState } from "react";
import { Plus, Search, ClipboardList, Trash2, ArchiveRestore } from "lucide-react";
import { api, post, put } from "../lib/api";
import { useFetch, lkr, toCents, centsToRupees } from "../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination } from "../components/ui";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type Cat = { id: number; name: string; sort_order: number; is_minibar: boolean; active: boolean; items_count: number };
type Ingredient = { id: number; name: string; unit: string };
type Item = {
  id: number; item_no?: number | null; name: string; price: number; sold_out: boolean; active: boolean; description: string;
  category: { id: number; name: string };
  recipe: { ingredient_id: number; qty: number; ingredient: { name: string; unit: string } }[];
};
type ItemsPage = { menu_items: { data: Item[]; current_page: number; per_page: number; total: number }; stats: { on_menu: number; sold_out: number; archived: number } };

export default function MenuAdmin() {
  const { can } = useAuth();
  const canCreate = can("hotel_menu_items.create");
  const canEdit = can("hotel_menu_items.edit");
  const canDelete = can("hotel_menu_items.delete");
  const canSoldOut = can("hotel_menu_items.sold_out"); // chef: may hold this alone
  const [q, setQ] = useState("");
  const [catFilter, setCatFilter] = useState("");
  const [showArchived, setShowArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const params = `active=${!showArchived}&category_id=${catFilter}&q=${encodeURIComponent(q)}&page=${page}&page_size=${pageSize}`;
  const { data, reload, error } = useFetch<ItemsPage>(`/menu/items?${params}`, [q, catFilter, showArchived, page, pageSize]);
  const { data: catsData, reload: reloadCats } = useFetch<{ menu_categories: Cat[] }>("/menu/categories");
  const { data: ingredientsData } = useFetch<{ ingredients: Ingredient[] }>("/ingredients");
  const cats = catsData?.menu_categories;
  const ingredients = ingredientsData?.ingredients;
  const [edit, setEdit] = useState<Item | "new" | null>(null);
  const [removing, setRemoving] = useState<Item | null>(null);
  const [flash, setFlash] = useState("");
  const [err, setErr] = useState("");

  const shown = data?.menu_items.data ?? [];
  const soldOutCount = data?.stats.sold_out ?? 0;
  const archivedCount = data?.stats.archived ?? 0;

  const toggleSoldOut = (i: Item) =>
    put(`/menu/items/${i.id}/sold-out`, { sold_out: !i.sold_out })
      .then(() => {
        setErr("");
        reload();
      })
      .catch((e) => setErr(e.message));

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><ClipboardList /> Menu</h1>
        {canCreate && <button className="btn-primary" onClick={() => setEdit("new")}><Plus size={16} /> New item</button>}
      </div>

      {/* Stats */}
      <div className="grid grid-cols-3 gap-3">
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Items on menu</div>
          <div className="mt-1 text-2xl font-extrabold">{data?.stats.on_menu ?? 0}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Sold out now</div>
          <div className={clsx("mt-1 text-2xl font-extrabold", soldOutCount > 0 ? "text-red-600" : "text-emerald-600")}>{soldOutCount}</div>
        </div>
        <button className="card p-4 text-left transition hover:shadow-md" onClick={() => { setShowArchived(!showArchived); setPage(1); }}>
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Archived</div>
          <div className="mt-1 text-2xl font-extrabold text-slate-500">{archivedCount}</div>
        </button>
      </div>

      {/* Search + category chips */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-56">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !w-64 !pl-8" placeholder="Search name or #number…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <button
          onClick={() => { setCatFilter(""); setPage(1); }}
          className={clsx("rounded-full px-3.5 py-1.5 text-xs font-semibold", !catFilter ? "bg-brand-600 text-white" : "bg-white text-slate-600 shadow-sm")}
        >
          All
        </button>
        {(cats ?? []).map((c) => (
          <button
            key={c.id}
            onClick={() => { setCatFilter(catFilter === String(c.id) ? "" : String(c.id)); setPage(1); }}
            className={clsx("rounded-full px-3.5 py-1.5 text-xs font-semibold", catFilter === String(c.id) ? "bg-brand-600 text-white" : "bg-white text-slate-600 shadow-sm")}
          >
            {c.name} <span className="opacity-50">{c.items_count}</span>
          </button>
        ))}
        {can("hotel_menu_categories.access") && (
          <CategoryManager
            cats={cats ?? []}
            canCreate={can("hotel_menu_categories.create")}
            canEdit={can("hotel_menu_categories.edit")}
            canDelete={can("hotel_menu_categories.delete")}
            onChanged={() => { reloadCats(); reload(); }}
          />
        )}
        <button
          onClick={() => { setShowArchived(!showArchived); setPage(1); }}
          className={clsx("ml-auto rounded-full px-3.5 py-1.5 text-xs font-semibold", showArchived ? "bg-slate-700 text-white" : "bg-white text-slate-500 shadow-sm")}
        >
          {showArchived ? "Viewing archived" : "Show archived"}
        </button>
      </div>

      {flash && <div className="rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800">{flash}</div>}
      <ErrorText error={err || error} />

      {/* Item list */}
      <div className="card divide-y divide-slate-50">
        {shown.map((i) => (
          <div key={i.id} className="flex flex-wrap items-center gap-3 px-4 py-2.5 transition hover:bg-slate-50/60">
            <span className="w-12 shrink-0 rounded-lg bg-slate-100 px-2 py-1 text-center font-mono text-xs font-black text-slate-600">
              #{i.item_no ?? "—"}
            </span>
            <div className="min-w-0 flex-1">
              <div className={clsx("truncate text-sm font-bold", !i.active && "text-slate-400 line-through")}>{i.name}</div>
              <div className="text-[11px] text-slate-400">
                {i.category.name}
                {i.recipe.length > 0 && <> · BOM: {i.recipe.length} ingredient{i.recipe.length === 1 ? "" : "s"}</>}
              </div>
            </div>
            <span className="text-sm font-extrabold text-brand-700">{lkr(i.price)}</span>
            {i.active ? (
              canSoldOut ? (
                <button
                  className={clsx("rounded-full px-2.5 py-1 text-xs font-bold transition", i.sold_out ? "bg-red-100 text-red-700 hover:bg-red-200" : "bg-emerald-100 text-emerald-700 hover:bg-emerald-200")}
                  onClick={() => toggleSoldOut(i)}
                >
                  {i.sold_out ? "SOLD OUT" : "Available"}
                </button>
              ) : (
                <Badge color={i.sold_out ? "red" : "green"}>{i.sold_out ? "SOLD OUT" : "Available"}</Badge>
              )
            ) : (
              canEdit && (
                <button
                  className="btn-secondary !py-1 text-xs"
                  onClick={() => put(`/menu/items/${i.id}`, { active: true }).then(() => { setFlash(`"${i.name}" restored to the menu.`); reload(); })}
                >
                  <ArchiveRestore size={13} /> Restore
                </button>
              )
            )}
            {i.active && (
              <>
                {canEdit && <button className="btn-ghost !py-1 text-xs" onClick={() => setEdit(i)}>Edit</button>}
                {canDelete && (
                  <button className="btn-ghost !p-1.5 text-red-400 hover:!bg-red-50 hover:text-red-600" title="Remove item" onClick={() => setRemoving(i)}>
                    <Trash2 size={15} />
                  </button>
                )}
              </>
            )}
          </div>
        ))}
        {shown.length === 0 && <Empty text={showArchived ? "Nothing archived" : "No items match"} />}
        {data && <Pagination page={data.menu_items.current_page} pageSize={data.menu_items.per_page} total={data.menu_items.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {edit && (
        <ItemEditor
          item={edit === "new" ? null : edit}
          cats={(cats ?? []).filter((c) => c.active)}
          ingredients={ingredients ?? []}
          onClose={() => { setEdit(null); reload(); reloadCats(); }}
        />
      )}
      {removing && (
        <Modal open onClose={() => setRemoving(null)} title={`Remove "${removing.name}"?`}>
          <p className="text-sm text-slate-600">If this item has never been ordered, it's permanently deleted along with its recipe. If it appears in past orders, it's archived instead (deactivated, order history preserved) — you can restore it from the Archived filter.</p>
          <div className="mt-4 flex gap-2">
            <button
              className="btn-danger flex-1"
              onClick={() =>
                api<{ message: string }>(`/menu/items/${removing.id}`, { method: "DELETE" })
                  .then((r) => { setFlash(r.message); setErr(""); setRemoving(null); reload(); reloadCats(); })
                  .catch((e) => { setErr(e.message); setRemoving(null); })
              }
            >
              <Trash2 size={15} /> Delete permanently
            </button>
            <button className="btn-secondary flex-1" onClick={() => setRemoving(null)}>Cancel</button>
          </div>
        </Modal>
      )}
    </div>
  );
}

// ── Category chips manager (add / rename / delete-empty) ─────────────────────
function CategoryManager({ cats, canCreate, canEdit, canDelete, onChanged }: { cats: Cat[]; canCreate: boolean; canEdit: boolean; canDelete: boolean; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [newCat, setNewCat] = useState("");
  const [error, setError] = useState("");
  return (
    <>
      <button className="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-brand-600 shadow-sm hover:bg-brand-50" onClick={() => setOpen(true)}>
        <Plus size={12} className="mr-0.5 inline" /> Categories
      </button>
      {open && (
        <Modal open onClose={() => setOpen(false)} title="Manage categories">
          <div className="space-y-2">
            {cats.map((c) => (
              <div key={c.id} className="flex items-center gap-2">
                <input
                  className="input !py-1.5"
                  defaultValue={c.name}
                  disabled={!canEdit}
                  onBlur={(e) => e.target.value.trim() && e.target.value !== c.name && put(`/menu/categories/${c.id}`, { name: e.target.value.trim() }).then(onChanged).catch((err) => setError(err.message))}
                />
                <Badge>{c.items_count} items</Badge>
                {c.is_minibar && <Badge color="purple">minibar</Badge>}
                {canDelete && (
                  <button
                    className={clsx("btn-ghost !p-1.5", c.items_count > 0 ? "cursor-not-allowed text-slate-200" : "text-red-400 hover:text-red-600")}
                    title={c.items_count > 0 ? "Move or remove its items first" : "Delete category"}
                    disabled={c.items_count > 0}
                    onClick={() => api(`/menu/categories/${c.id}`, { method: "DELETE" }).then(onChanged).catch((e) => setError(e.message))}
                  >
                    <Trash2 size={14} />
                  </button>
                )}
              </div>
            ))}
            {canCreate && (
              <div className="flex gap-2 border-t border-slate-100 pt-3">
                <input className="input" placeholder="New category name" value={newCat} onChange={(e) => setNewCat(e.target.value)} />
                <button
                  className="btn-secondary"
                  disabled={!newCat.trim()}
                  onClick={() => post("/menu/categories", { name: newCat.trim(), sort_order: cats.length + 1 }).then(() => { setNewCat(""); onChanged(); }).catch((e) => setError(e.message))}
                >
                  Add
                </button>
              </div>
            )}
            <ErrorText error={error} />
          </div>
        </Modal>
      )}
    </>
  );
}

// ── Item editor ───────────────────────────────────────────────────────────────
function ItemEditor({ item, cats, ingredients, onClose }: { item: Item | null; cats: Cat[]; ingredients: Ingredient[]; onClose: () => void }) {
  const [f, setF] = useState({
    name: item?.name ?? "",
    categoryId: item ? String(item.category.id) : cats[0] ? String(cats[0].id) : "",
    price: item ? centsToRupees(item.price) : "",
    itemNo: item?.item_no != null ? String(item.item_no) : "",
    description: item?.description ?? "",
  });
  const [recipe, setRecipe] = useState<{ ingredientId: string; qty: string }[]>(
    (item?.recipe ?? []).map((r) => ({ ingredientId: String(r.ingredient_id), qty: String(r.qty) }))
  );
  const [error, setError] = useState("");

  const save = async () => {
    setError("");
    const body = {
      name: f.name.trim(),
      menu_category_id: Number(f.categoryId),
      price: toCents(f.price),
      item_no: f.itemNo.trim() ? parseInt(f.itemNo) : null,
      description: f.description,
      recipe: recipe.filter((r) => r.ingredientId && parseFloat(r.qty) > 0).map((r) => ({ ingredient_id: Number(r.ingredientId), qty: parseFloat(r.qty) })),
    };
    try {
      if (item) await put(`/menu/items/${item.id}`, body);
      else await post("/menu/items", body);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  return (
    <Modal open onClose={onClose} title={item ? `Edit #${item.item_no ?? "—"} ${item.name}` : "New menu item"} wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus /></Field>
        <Field label="Category">
          <select className="input" value={f.categoryId} onChange={(e) => setF({ ...f, categoryId: e.target.value })}>
            {cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        <Field label="Price (LKR)"><input className="input" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} /></Field>
        <Field label="Menu number" hint="Printed menu no. — cashier can type it for quick POS entry. Blank = auto-assign.">
          <input className="input" inputMode="numeric" value={f.itemNo} onChange={(e) => setF({ ...f, itemNo: e.target.value })} placeholder="auto" />
        </Field>
      </div>
      <div className="mt-3">
        <Field label="Description"><input className="input" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
      </div>
      <div className="mt-3">
        <div className="label">Recipe / BOM (auto-deducts ingredient stock per portion)</div>
        <div className="space-y-1.5">
          {recipe.map((r, i) => (
            <div key={i} className="flex gap-2">
              <select className="input" value={r.ingredientId} onChange={(e) => setRecipe(recipe.map((x, j) => (j === i ? { ...x, ingredientId: e.target.value } : x)))}>
                <option value="">Ingredient…</option>
                {ingredients.map((ing) => <option key={ing.id} value={ing.id}>{ing.name} ({ing.unit})</option>)}
              </select>
              <input className="input !w-28" placeholder="Qty" value={r.qty} onChange={(e) => setRecipe(recipe.map((x, j) => (j === i ? { ...x, qty: e.target.value } : x)))} />
              <button className="btn-ghost !px-2" onClick={() => setRecipe(recipe.filter((_, j) => j !== i))}>✕</button>
            </div>
          ))}
          <button className="btn-secondary w-full" onClick={() => setRecipe([...recipe, { ingredientId: "", qty: "" }])}>+ Add ingredient</button>
        </div>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={!f.name.trim() || toCents(f.price) <= 0} onClick={save}>Save item</button>
    </Modal>
  );
}
