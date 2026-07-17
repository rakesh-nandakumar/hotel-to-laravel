import { useState } from "react";
import { Plus, TriangleAlert, CalendarClock, Trash2, Search, Package, ChevronDown, ArrowDownToLine, ArrowUpFromLine } from "lucide-react";
import { api, post } from "../lib/api";
import { useFetch, fmtDate } from "../lib/util";
import { Badge, Card, Empty, ErrorText, Field, Modal, Pagination } from "../components/ui";
import { ReasonModal } from "./POS";
import { useAuth } from "../lib/auth";
import clsx from "clsx";

type Batch = { id: number; qty: number; initial_qty: number; expiry_date: string; received_at: string; note?: string | null };
type Ingredient = {
  id: number; name: string; unit: string; stock_qty: number; low_stock_threshold: number; low: boolean;
  next_expiry?: string | null; has_expired: boolean; used_in: string[]; batches: Batch[];
};
type ExpiryBatch = Batch & { days_left: number; expired: boolean; ingredient: { name: string; unit: string } };
type IngredientsPage = { ingredients: Ingredient[]; total: number; page: number; page_size: number; counts: { total: number; low: number; expiry_tracked: number; untracked: number } };

type Filter = "ALL" | "LOW" | "EXPIRING" | "UNTRACKED";

export default function Inventory() {
  const { can } = useAuth();
  const canDelete = can("hotel_ingredients.delete");
  const [q, setQ] = useState("");
  const [filter, setFilter] = useState<Filter>("ALL");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = useFetch<IngredientsPage>(
    `/ingredients?filter=${filter}&q=${encodeURIComponent(q)}&page=${page}&page_size=${pageSize}`,
    [q, filter, page, pageSize]
  );
  const { data: expiryData, reload: reloadExpiry } = useFetch<{ batches: ExpiryBatch[] }>("/ingredients/expiry");
  const [adjusting, setAdjusting] = useState<Ingredient | null>(null);
  const [openNew, setOpenNew] = useState(false);
  const [writingOff, setWritingOff] = useState<ExpiryBatch | null>(null);
  const [woError, setWoError] = useState("");
  const [expanded, setExpanded] = useState<number | null>(null);

  const refresh = () => {
    reload();
    reloadExpiry();
  };

  const shown = data?.ingredients ?? [];
  const expiring = expiryData?.batches ?? [];
  const counts = {
    total: data?.counts.total ?? 0,
    low: data?.counts.low ?? 0,
    expiringSoon: expiring.filter((b) => !b.expired).length,
    expired: expiring.filter((b) => b.expired).length,
  };

  const FILTERS: { id: Filter; label: string; n: number }[] = [
    { id: "ALL", label: "All", n: counts.total },
    { id: "LOW", label: "Low stock", n: counts.low },
    { id: "EXPIRING", label: "Expiry tracked", n: data?.counts.expiry_tracked ?? 0 },
    { id: "UNTRACKED", label: "No expiry data", n: data?.counts.untracked ?? 0 },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><Package /> Kitchen Inventory</h1>
        <button className="btn-primary" onClick={() => setOpenNew(true)}><Plus size={16} /> New ingredient</button>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Ingredients</div>
          <div className="mt-1 text-2xl font-extrabold">{counts.total}</div>
        </div>
        <button className="card p-4 text-left transition hover:shadow-md" onClick={() => setFilter("LOW")}>
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Low stock</div>
          <div className={clsx("mt-1 text-2xl font-extrabold", counts.low > 0 ? "text-amber-600" : "text-emerald-600")}>{counts.low}</div>
        </button>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Expiring soon</div>
          <div className={clsx("mt-1 text-2xl font-extrabold", counts.expiringSoon > 0 ? "text-amber-600" : "text-emerald-600")}>{counts.expiringSoon}</div>
        </div>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Expired</div>
          <div className={clsx("mt-1 text-2xl font-extrabold", counts.expired > 0 ? "text-red-600" : "text-emerald-600")}>{counts.expired}</div>
        </div>
      </div>

      {/* Expiry board */}
      {expiring.length > 0 && (
        <Card title={<span className="flex items-center gap-2"><CalendarClock size={16} className="text-red-500" /> Expiry alerts — use first or write off</span>}>
          <ErrorText error={woError} />
          <div className="space-y-2">
            {expiring.map((b) => (
              <div key={b.id} className={clsx("flex flex-wrap items-center gap-3 rounded-xl border px-3 py-2 text-sm", b.expired ? "border-red-200 bg-red-50" : "border-amber-200 bg-amber-50")}>
                <Badge color={b.expired ? "red" : "amber"}>
                  {b.expired ? `EXPIRED ${-b.days_left > 0 ? `${-b.days_left}d ago` : "today"}` : b.days_left === 0 ? "EXPIRES TODAY" : `${b.days_left}d left`}
                </Badge>
                <span className="font-bold">{b.ingredient.name}</span>
                <span>{b.qty.toLocaleString()} {b.ingredient.unit}</span>
                <span className="hidden text-xs text-slate-500 sm:inline">expiry {fmtDate(b.expiry_date)} · received {fmtDate(b.received_at)}</span>
                <button className="btn-danger ml-auto !py-1 text-xs" onClick={() => setWritingOff(b)}>
                  <Trash2 size={13} /> Write off
                </button>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* Search + filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1 sm:flex-none">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-8 sm:!w-64" placeholder="Search ingredients…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <div className="flex gap-1 rounded-xl bg-slate-200/70 p-1">
          {FILTERS.map((f) => (
            <button
              key={f.id}
              onClick={() => { setFilter(f.id); setPage(1); }}
              className={clsx("rounded-lg px-3 py-1.5 text-xs font-semibold transition", filter === f.id ? "bg-white shadow-sm" : "text-slate-500 hover:text-slate-800")}
            >
              {f.label} <span className="opacity-50">{f.n}</span>
            </button>
          ))}
        </div>
      </div>
      <ErrorText error={error} />

      {/* Ingredient list */}
      <div className="card divide-y divide-slate-50">
        {shown.map((r) => {
          const isOpen = expanded === r.id;
          // stock bar: threshold marker at 1/3 of the bar
          const scale = r.low_stock_threshold > 0 ? r.low_stock_threshold * 3 : Math.max(r.stock_qty, 1);
          const pct = Math.min(100, (r.stock_qty / scale) * 100);
          return (
            <div key={r.id}>
              <div className="flex flex-wrap items-center gap-3 px-4 py-3 transition hover:bg-slate-50/60">
                <button className="flex min-w-0 flex-1 items-center gap-2 text-left" onClick={() => setExpanded(isOpen ? null : r.id)}>
                  <ChevronDown size={15} className={clsx("shrink-0 text-slate-300 transition-transform", isOpen && "rotate-180")} />
                  <div className="min-w-0">
                    <div className="truncate text-sm font-bold">{r.name}</div>
                    <div className="text-[11px] text-slate-400">
                      {r.used_in.length > 0 ? `in ${r.used_in.length} recipe${r.used_in.length === 1 ? "" : "s"}` : "not used in any recipe"}
                      {r.next_expiry && <> · next expiry <span className={r.has_expired ? "font-bold text-red-500" : ""}>{fmtDate(r.next_expiry)}</span></>}
                    </div>
                  </div>
                </button>
                <div className="w-40">
                  <div className="flex items-baseline justify-between text-xs">
                    <span className="font-bold tabular-nums">{r.stock_qty.toLocaleString()} {r.unit}</span>
                    <span className="text-slate-400">min {r.low_stock_threshold.toLocaleString()}</span>
                  </div>
                  <div className="relative mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div className={clsx("h-full rounded-full transition-all", r.low ? "bg-red-400" : pct < 55 ? "bg-amber-400" : "bg-emerald-500")} style={{ width: `${pct}%` }} />
                    {r.low_stock_threshold > 0 && <div className="absolute top-0 h-full w-px bg-slate-400/60" style={{ left: "33.3%" }} />}
                  </div>
                </div>
                <div className="flex items-center gap-1.5">
                  {r.low && <Badge color="red">LOW</Badge>}
                  {r.has_expired && <Badge color="red">EXPIRED</Badge>}
                  {!r.low && !r.has_expired && <Badge color="green">OK</Badge>}
                  <button className="btn-secondary !py-1.5 text-xs" onClick={() => setAdjusting(r)}>Adjust</button>
                </div>
              </div>
              {isOpen && (
                <div className="bg-slate-50/60 px-11 py-3 text-xs">
                  {r.used_in.length > 0 && (
                    <div className="mb-2 text-slate-500">
                      <b>Used in:</b> {r.used_in.join(", ")}
                    </div>
                  )}
                  {r.batches.length > 0 ? (
                    <div className="space-y-1">
                      <b className="text-slate-500">Expiry-tracked batches:</b>
                      {r.batches.map((b) => (
                        <div key={b.id} className="flex flex-wrap gap-3 text-slate-600">
                          <span className="font-semibold tabular-nums">{b.qty.toLocaleString()}/{b.initial_qty.toLocaleString()} {r.unit}</span>
                          <span>expiry {fmtDate(b.expiry_date)}</span>
                          <span className="text-slate-400">received {fmtDate(b.received_at)}{b.note ? ` — ${b.note}` : ""}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <span className="text-slate-400">No expiry-tracked batches — add an expiry date when receiving stock.</span>
                  )}
                </div>
              )}
            </div>
          );
        })}
        {shown.length === 0 && <Empty text={q || filter !== "ALL" ? "No ingredients match" : "No ingredients yet"} />}
        {data && <Pagination page={data.page} pageSize={data.page_size} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {adjusting && <AdjustModal ing={adjusting} canDelete={canDelete} onClose={() => { setAdjusting(null); refresh(); }} />}
      {openNew && <NewIngredient onClose={() => { setOpenNew(false); refresh(); }} />}
      {writingOff && (
        <ReasonModal
          title={`Write off ${writingOff.qty}${writingOff.ingredient.unit} of ${writingOff.ingredient.name}`}
          onSubmit={async (reason) => {
            try {
              await post(`/ingredients/batches/${writingOff.id}/write-off`, { reason });
              setWoError("");
              setWritingOff(null);
              refresh();
            } catch (e) {
              setWoError((e as Error).message);
              setWritingOff(null);
            }
          }}
          onClose={() => setWritingOff(null)}
        />
      )}
    </div>
  );
}

// ── Adjust modal: receive / write-down / remove ───────────────────────────────
function AdjustModal({ ing, canDelete, onClose }: { ing: Ingredient; canDelete: boolean; onClose: () => void }) {
  const [mode, setMode] = useState<"IN" | "OUT">("IN");
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [expiryDate, setExpiryDate] = useState("");
  const [error, setError] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [busy, setBusy] = useState(false);
  const n = parseFloat(amount) || 0;

  const apply = async () => {
    setBusy(true);
    setError("");
    try {
      await post(`/ingredients/${ing.id}/adjust`, {
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
      await api(`/ingredients/${ing.id}`, { method: "DELETE" });
      onClose();
    } catch (e) {
      setError((e as Error).message);
      setConfirmDelete(false);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`${ing.name} — ${ing.stock_qty.toLocaleString()} ${ing.unit} in stock`}>
      <div className="mb-3 flex gap-1 rounded-xl bg-slate-100 p-1">
        <button className={clsx("flex-1 rounded-lg px-2 py-2 text-sm font-semibold", mode === "IN" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setMode("IN")}>
          <ArrowDownToLine size={14} className="mr-1 inline" /> Receive stock
        </button>
        <button className={clsx("flex-1 rounded-lg px-2 py-2 text-sm font-semibold", mode === "OUT" ? "bg-white shadow-sm" : "text-slate-500")} onClick={() => setMode("OUT")}>
          <ArrowUpFromLine size={14} className="mr-1 inline" /> Write down
        </button>
      </div>

      <div className="space-y-3">
        <Field label={`Quantity (${ing.unit})`}>
          <input className="input" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={mode === "IN" ? "e.g. 5000" : "e.g. 200"} autoFocus />
        </Field>
        {mode === "IN" && (
          <Field label="Expiry date (recommended — enables alerts & first-expiring-first-out)">
            <input type="date" className="input" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} />
          </Field>
        )}
        <Field label="Reason (required, audited)">
          <input className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={mode === "IN" ? "e.g. weekly market purchase" : "e.g. spoilage / spillage"} />
        </Field>
        <ErrorText error={error} />
        <button className={clsx("w-full !py-3", mode === "IN" ? "btn-primary" : "btn-danger")} disabled={busy || !reason.trim() || n <= 0} onClick={apply}>
          {mode === "IN" ? `Receive +${n || 0} ${ing.unit}` : `Write down −${n || 0} ${ing.unit}`}
        </button>
      </div>

      {canDelete && (
        <div className="mt-5 rounded-xl border border-red-100 bg-red-50/50 p-3">
          <div className="text-xs font-bold uppercase tracking-wide text-red-400">Danger zone</div>
          {ing.used_in.length > 0 ? (
            <p className="mt-1 text-xs text-slate-500">
              This ingredient can't be removed while it's used in <b>{ing.used_in.length}</b> recipe{ing.used_in.length === 1 ? "" : "s"} ({ing.used_in.slice(0, 4).join(", ")}{ing.used_in.length > 4 ? "…" : ""}). Edit those menu items first.
            </p>
          ) : !confirmDelete ? (
            <button className="btn-secondary mt-2 !py-1.5 text-xs !text-red-600" onClick={() => setConfirmDelete(true)}>
              <Trash2 size={13} /> Remove ingredient…
            </button>
          ) : (
            <div className="mt-2 space-y-2">
              <p className="text-xs font-semibold text-red-700">
                Permanently remove “{ing.name}” and its {ing.batches.length} tracked batch(es)? This cannot be undone.
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
          post("/ingredients", { name: f.name.trim(), unit: f.unit, stock_qty: parseFloat(f.stockQty) || 0, low_stock_threshold: parseFloat(f.lowStockThreshold) || 0 })
            .then(onClose)
            .catch((e) => setError(e.message))
        }
      >
        Create
      </button>
    </Modal>
  );
}
