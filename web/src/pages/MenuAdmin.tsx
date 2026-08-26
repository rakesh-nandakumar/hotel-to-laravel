import { useState } from "react";
import { Plus, Search, ClipboardList, Trash2, ArchiveRestore, ImageOff, ListPlus } from "lucide-react";
import { api, post, put } from "../lib/api";
import { useFetch, lkr, toCents, centsToRupees } from "../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination } from "../components/ui";
import { ImageDropUpload } from "../components/ImageUpload";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type Cat = { id: number; name: string; sort_order: number; is_minibar: boolean; active: boolean; items_count: number; kitchen_station: { code: string; name: string } | null };
type Ingredient = { id: number; name: string; unit: string };
type Modifier = { id: number; name: string; price_delta: number };
type ModifierGroup = { id: number; name: string; is_required: boolean; max_select: number; modifiers: Modifier[] };
type AddOnLink = { id: number; menu_item_id?: number | null; menu_item?: { id: number; name: string } | null; menu_category_id?: number | null; menu_category?: { id: number; name: string } | null };
type AddOn = {
  id: number; name: string; price: number; active: boolean; sort_order: number;
  stock_ingredient_id?: number | null; stock_ingredient?: { id: number; name: string; unit: string } | null;
  links: AddOnLink[];
};
type Item = {
  id: number; item_no?: number | null; name: string; price: number; sold_out: boolean; active: boolean; description: string;
  image?: string | null; stock_ingredient_id?: number | null; stock_ingredient?: { id: number; name: string; unit: string } | null;
  category: { id: number; name: string };
  recipe: { ingredient_id: number; qty: number; ingredient: { name: string; unit: string } }[];
  modifier_groups: ModifierGroup[];
};

const KITCHEN_STATIONS = ["kitchen", "bar", "dessert"] as const;
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
  const [addOnsOpen, setAddOnsOpen] = useState(false);
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
        <div className="flex gap-2">
          {canEdit && (
            <button className="btn-secondary" onClick={() => setAddOnsOpen(true)}>
              <ListPlus size={15} /> Add-ons
            </button>
          )}
          {canCreate && <button className="btn-primary" onClick={() => setEdit("new")}><Plus size={16} /> New item</button>}
        </div>
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
            {i.image ? (
              <img src={i.image} alt="" className="h-10 w-10 shrink-0 rounded-lg object-cover" />
            ) : (
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-300">
                <ImageOff size={16} />
              </div>
            )}
            <span className="w-12 shrink-0 rounded-lg bg-slate-100 px-2 py-1 text-center font-mono text-xs font-black text-slate-600">
              #{i.item_no ?? "—"}
            </span>
            <div className="min-w-0 flex-1">
              <div className={clsx("truncate text-sm font-bold", !i.active && "text-slate-400 line-through")}>{i.name}</div>
              <div className="flex items-center gap-1 text-[11px] text-slate-400">
                <span>{i.category.name}</span>
                {i.recipe.length > 0 && <span>· BOM: {i.recipe.length} ingredient{i.recipe.length === 1 ? "" : "s"}</span>}
                {i.stock_ingredient && <span className="rounded bg-slate-100 px-1 py-px font-semibold text-slate-500">unit stock</span>}
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
      {addOnsOpen && (
        <AddOnsManager
          cats={(cats ?? []).filter((c) => c.active)}
          ingredients={ingredients ?? []}
          onClose={() => { setAddOnsOpen(false); reload(); }}
        />
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
                <select
                  className="input !w-28 !py-1.5 !text-xs"
                  disabled={!canEdit}
                  defaultValue={c.kitchen_station?.code ?? ""}
                  onChange={(e) => put(`/menu/categories/${c.id}`, { kitchen_station: e.target.value || null }).then(onChanged).catch((err) => setError(err.message))}
                >
                  <option value="">No station</option>
                  {KITCHEN_STATIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
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
    image: item?.image ?? "",
    stockIngredientId: item?.stock_ingredient_id != null ? String(item.stock_ingredient_id) : "",
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
      description: f.description.trim(),
      image: f.image || null,
      stock_ingredient_id: f.stockIngredientId ? Number(f.stockIngredientId) : null,
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
      <div className="grid gap-3 sm:grid-cols-[160px_1fr]">
        <Field label="Photo" hint="Shown on the POS item grid.">
          <ImageDropUpload
            value={f.image}
            onChange={(v) => setF({ ...f, image: v })}
            maxBox={240}
            removeLabel="Remove photo"
            previewClassName="h-20 w-20 rounded-lg object-cover"
          />
        </Field>
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
      </div>
      <div className="mt-3">
        <Field label="Description"><input className="input" value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <Field label="Unit stock ingredient" hint="A shortcut for a one-line recipe (1 unit = 1 portion, deducted FEFO from expiry batches) that still routes to the kitchen. Ignored when a recipe is set. Directly-sellable items with no kitchen ticket are Products, not menu items.">
          <select className="input" value={f.stockIngredientId} onChange={(e) => setF({ ...f, stockIngredientId: e.target.value })}>
            <option value="">No unit stock (recipe only)</option>
            {ingredients.map((ing) => <option key={ing.id} value={ing.id}>{ing.name} ({ing.unit})</option>)}
          </select>
        </Field>
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
      {item && <ModifierGroupsEditor item={item} />}
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={!f.name.trim() || toCents(f.price) <= 0} onClick={save}>Save item</button>
    </Modal>
  );
}

// ── Modifier groups (Size, Spice level, Extras…) — only editable on an existing item ──
function ModifierGroupsEditor({ item }: { item: Item }) {
  const [groups, setGroups] = useState(item.modifier_groups);
  const [newGroup, setNewGroup] = useState({ name: "", required: false, maxSelect: "1" });
  const [newModifier, setNewModifier] = useState<Record<number, { name: string; priceDelta: string }>>({});
  const [error, setError] = useState("");

  const addGroup = () =>
    post<{ modifier_group: ModifierGroup }>(`/menu/items/${item.id}/modifier-groups`, {
      name: newGroup.name.trim(), is_required: newGroup.required, max_select: parseInt(newGroup.maxSelect) || 1,
    })
      .then((r) => { setGroups([...groups, { ...r.modifier_group, modifiers: [] }]); setNewGroup({ name: "", required: false, maxSelect: "1" }); })
      .catch((e) => setError(e.message));

  const removeGroup = (id: number) =>
    api(`/menu/modifier-groups/${id}`, { method: "DELETE" }).then(() => setGroups(groups.filter((g) => g.id !== id))).catch((e) => setError(e.message));

  const addModifier = (group: ModifierGroup) => {
    const draft = newModifier[group.id] ?? { name: "", priceDelta: "0" };
    if (!draft.name.trim()) return;
    post<{ modifier: Modifier }>(`/menu/modifier-groups/${group.id}/modifiers`, { name: draft.name.trim(), price_delta: toCents(draft.priceDelta || "0") })
      .then((r) => {
        setGroups(groups.map((g) => (g.id === group.id ? { ...g, modifiers: [...g.modifiers, r.modifier] } : g)));
        setNewModifier({ ...newModifier, [group.id]: { name: "", priceDelta: "0" } });
      })
      .catch((e) => setError(e.message));
  };

  const removeModifier = (group: ModifierGroup, modifierId: number) =>
    api(`/menu/modifiers/${modifierId}`, { method: "DELETE" })
      .then(() => setGroups(groups.map((g) => (g.id === group.id ? { ...g, modifiers: g.modifiers.filter((m) => m.id !== modifierId) } : g))))
      .catch((e) => setError(e.message));

  return (
    <div className="mt-3">
      <div className="label">Modifiers (Size, Spice level, Extras…) — shown as a picker in POS before adding to cart</div>
      <div className="space-y-2">
        {groups.map((g) => (
          <div key={g.id} className="rounded-lg bg-slate-50 p-2.5">
            <div className="flex items-center gap-2">
              <span className="text-sm font-bold">{g.name}</span>
              {g.is_required && <Badge color="amber">required</Badge>}
              <Badge>up to {g.max_select}</Badge>
              <button className="btn-ghost ml-auto !p-1 text-red-400 hover:text-red-600" onClick={() => removeGroup(g.id)}><Trash2 size={13} /></button>
            </div>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              {g.modifiers.map((m) => (
                <span key={m.id} className="flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs shadow-sm">
                  {m.name}{m.price_delta !== 0 && ` (${m.price_delta > 0 ? "+" : ""}${lkr(m.price_delta)})`}
                  <button className="text-slate-300 hover:text-red-500" onClick={() => removeModifier(g, m.id)}>✕</button>
                </span>
              ))}
            </div>
            <div className="mt-1.5 flex gap-1.5">
              <input
                className="input !py-1 text-xs"
                placeholder="Option name (e.g. Large)"
                value={newModifier[g.id]?.name ?? ""}
                onChange={(e) => setNewModifier({ ...newModifier, [g.id]: { name: e.target.value, priceDelta: newModifier[g.id]?.priceDelta ?? "0" } })}
              />
              <input
                className="input !w-24 !py-1 text-xs"
                placeholder="+LKR"
                value={newModifier[g.id]?.priceDelta ?? ""}
                onChange={(e) => setNewModifier({ ...newModifier, [g.id]: { name: newModifier[g.id]?.name ?? "", priceDelta: e.target.value } })}
              />
              <button className="btn-secondary !py-1 text-xs" onClick={() => addModifier(g)}>Add option</button>
            </div>
          </div>
        ))}
        <div className="flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2">
          <input className="input !py-1.5 text-xs" placeholder="New group (e.g. Size)" value={newGroup.name} onChange={(e) => setNewGroup({ ...newGroup, name: e.target.value })} />
          <input className="input !w-20 !py-1.5 text-xs" placeholder="Max" inputMode="numeric" value={newGroup.maxSelect} onChange={(e) => setNewGroup({ ...newGroup, maxSelect: e.target.value })} />
          <label className="flex items-center gap-1 text-xs font-semibold">
            <input type="checkbox" checked={newGroup.required} onChange={(e) => setNewGroup({ ...newGroup, required: e.target.checked })} /> Required
          </label>
          <button className="btn-secondary !py-1.5 text-xs" disabled={!newGroup.name.trim()} onClick={addGroup}>+ Add group</button>
        </div>
      </div>
      <ErrorText error={error} />
    </div>
  );
}

// ── Add-ons manager (extra cheese, extra curry…) — standalone sellable lines ──
function AddOnsManager({ cats, ingredients, onClose }: { cats: Cat[]; ingredients: Ingredient[]; onClose: () => void }) {
  const { data, reload } = useFetch<{ add_ons: AddOn[] }>("/menu/add-ons");
  const { data: allItemsData } = useFetch<{ menu_items: Item[] }>("/menu/items?active=true");
  const allAddOns = data?.add_ons ?? [];
  const allItems = allItemsData?.menu_items ?? [];
  const [editing, setEditing] = useState<AddOn | "new" | null>(null);
  const [error, setError] = useState("");

  return (
    <Modal open onClose={onClose} title="Add-ons — extra cheese, extra curry, sides…" wide>
      <p className="mb-3 text-xs text-slate-500">
        Add-ons are standalone order lines with their own price and stock — every add-on prints a kitchen ticket. Link each to menu items or whole categories — the POS offers them when that item is tapped.
      </p>
      <div className="mb-2 flex items-center justify-between">
        <span className="text-xs font-bold uppercase tracking-wide text-slate-400">{allAddOns.length} add-on{allAddOns.length === 1 ? "" : "s"}</span>
        <button className="btn-secondary !py-1.5 text-xs" onClick={() => setEditing("new")}><Plus size={12} /> New add-on</button>
      </div>
      <div className="max-h-80 divide-y divide-slate-50 overflow-y-auto">
        {allAddOns.map((a) => (
          <div key={a.id} className={clsx("flex flex-wrap items-center gap-2 py-2", !a.active && "opacity-60")}>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5 text-sm font-bold">
                <span className="truncate">{a.name}</span>
                {!a.active && <Badge color="amber">inactive</Badge>}
              </div>
              <div className="text-[11px] text-slate-400">
                {a.links.length > 0 ? a.links.map((l) => l.menu_item?.name ?? l.menu_category?.name ?? "").filter(Boolean).slice(0, 3).join(", ") : "not linked to any item"}
                {a.links.length > 3 && "…"}
                {a.stock_ingredient && <> · stock: {a.stock_ingredient.name}</>}
              </div>
            </div>
            <span className="text-sm font-extrabold text-brand-700">{lkr(a.price)}</span>
            <button className="btn-ghost !py-1 text-xs" onClick={() => setEditing(a)}>Edit</button>
            <button
              className="btn-ghost !p-1.5 text-red-400 hover:!bg-red-50 hover:text-red-600"
              title="Remove add-on"
              onClick={() =>
                api(`/menu/add-ons/${a.id}`, { method: "DELETE" })
                  .then(() => { setError(""); reload(); })
                  .catch((e) => setError(e.message))
              }
            >
              <Trash2 size={14} />
            </button>
          </div>
        ))}
        {allAddOns.length === 0 && <Empty text="No add-ons yet — create one" />}
      </div>
      <ErrorText error={error} />
      {editing && (
        <AddOnEditor
          addOn={editing === "new" ? null : editing}
          items={allItems}
          cats={cats}
          ingredients={ingredients}
          onClose={() => { setEditing(null); reload(); }}
        />
      )}
    </Modal>
  );
}

function AddOnEditor({ addOn, items, cats, ingredients, onClose }: { addOn: AddOn | null; items: Item[]; cats: Cat[]; ingredients: Ingredient[]; onClose: () => void }) {
  const [f, setF] = useState({
    name: addOn?.name ?? "",
    price: addOn ? centsToRupees(addOn.price) : "",
    stockIngredientId: addOn?.stock_ingredient_id != null ? String(addOn.stock_ingredient_id) : "",
    itemIds: new Set<number>(addOn?.links.filter((l) => l.menu_item_id).map((l) => l.menu_item_id as number) ?? []),
    catIds: new Set<number>(addOn?.links.filter((l) => l.menu_category_id).map((l) => l.menu_category_id as number) ?? []),
  });
  const [error, setError] = useState("");

  const toggleSet = (key: "itemIds" | "catIds", id: number) =>
    setF((s) => {
      const next = new Set(s[key]);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return { ...s, [key]: next };
    });

  const save = async () => {
    setError("");
    const body = {
      name: f.name.trim(),
      price: toCents(f.price),
      stock_ingredient_id: f.stockIngredientId ? Number(f.stockIngredientId) : null,
      menu_item_ids: [...f.itemIds],
      menu_category_ids: [...f.catIds],
    };
    try {
      if (addOn) await put(`/menu/add-ons/${addOn.id}`, body);
      else await post("/menu/add-ons", body);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  return (
    <Modal open onClose={onClose} title={addOn ? `Edit add-on — ${addOn.name}` : "New add-on"} wide>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus /></Field>
        <Field label="Price (LKR)"><input className="input" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} /></Field>
        <Field label="Stock ingredient" hint="Unit-stocked (1 add-on = 1 unit). Batch expiry tracked — FEFO deduction.">
          <select className="input" value={f.stockIngredientId} onChange={(e) => setF({ ...f, stockIngredientId: e.target.value })}>
            <option value="">No stock tracking</option>
            {ingredients.map((ing) => <option key={ing.id} value={ing.id}>{ing.name} ({ing.unit})</option>)}
          </select>
        </Field>
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
          <div className="label">Offered on categories</div>
          <div className="max-h-44 space-y-1 overflow-y-auto rounded-lg bg-slate-50 p-2">
            {cats.map((c) => (
              <label key={c.id} className="flex items-center gap-2 rounded px-1.5 py-1 text-xs font-semibold hover:bg-white">
                <input type="checkbox" checked={f.catIds.has(c.id)} onChange={() => toggleSet("catIds", c.id)} />
                {c.name}
              </label>
            ))}
            {cats.length === 0 && <span className="text-xs text-slate-400">No active categories</span>}
          </div>
        </div>
        <div>
          <div className="label">Offered on items</div>
          <div className="max-h-44 space-y-1 overflow-y-auto rounded-lg bg-slate-50 p-2">
            {items.map((i) => (
              <label key={i.id} className="flex items-center gap-2 rounded px-1.5 py-1 text-xs font-semibold hover:bg-white">
                <input type="checkbox" checked={f.itemIds.has(i.id)} onChange={() => toggleSet("itemIds", i.id)} />
                <span className="truncate">{i.name}</span>
              </label>
            ))}
            {items.length === 0 && <span className="text-xs text-slate-400">No items on this page</span>}
          </div>
        </div>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={!f.name.trim() || toCents(f.price) <= 0} onClick={save}>Save add-on</button>
    </Modal>
  );
}
