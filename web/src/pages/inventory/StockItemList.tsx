import { useState } from "react";
import { CalendarClock, Trash2, Search, ChevronDown } from "lucide-react";
import clsx from "clsx";
import { post } from "../../lib/api";
import { useFetch, fmtDate, lkr } from "../../lib/util";
import { Badge, Card, Empty, ErrorText, Pagination } from "../../components/ui";
import { ReasonModal } from "../POS";
import { StockItem, ExpiryBatch, StockItemsPage } from "./types";
import AdjustModal from "./AdjustModal";

type Filter = "ALL" | "LOW" | "EXPIRING" | "UNTRACKED";

/**
 * The list + stat cards + filter chips + expiry board — shared body for both
 * the Ingredients and Products tabs (both are `ingredients` rows behind a
 * `kind`). The expiry board and batch write-off are ingredient-only: a
 * product's stock corrections go through Adjust, its purchases through a GRN.
 */
export default function StockItemList({
  kind, basePath, canAdjust, canDelete, canWriteOff, canEdit, refreshKey,
}: {
  kind: "ingredient" | "product";
  basePath: string;
  canAdjust: boolean;
  canDelete: boolean;
  canWriteOff: boolean;
  canEdit: boolean;
  refreshKey: number;
}) {
  const [q, setQ] = useState("");
  const [filter, setFilter] = useState<Filter>("ALL");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const { data, reload, error } = useFetch<StockItemsPage>(
    `${basePath}?filter=${filter}&q=${encodeURIComponent(q)}&page=${page}&page_size=${pageSize}`,
    [q, filter, page, pageSize, refreshKey, basePath]
  );
  const { data: expiryData, reload: reloadExpiry } = useFetch<{ batches: ExpiryBatch[] }>(
    kind === "ingredient" ? "/ingredients/expiry" : null,
    [refreshKey]
  );
  const [adjusting, setAdjusting] = useState<StockItem | null>(null);
  const [writingOff, setWritingOff] = useState<ExpiryBatch | null>(null);
  const [woError, setWoError] = useState("");
  const [expanded, setExpanded] = useState<number | null>(null);

  const refresh = () => {
    reload();
    reloadExpiry();
  };

  const noun = kind === "product" ? "product" : "ingredient";
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
      {/* Stats */}
      <div className={clsx("grid grid-cols-2 gap-3", kind === "ingredient" ? "lg:grid-cols-4" : "lg:grid-cols-2")}>
        <div className="card p-4">
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{kind === "product" ? "Products" : "Ingredients"}</div>
          <div className="mt-1 text-2xl font-extrabold">{counts.total}</div>
        </div>
        <button className="card p-4 text-left transition hover:shadow-md" onClick={() => setFilter("LOW")}>
          <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Low stock</div>
          <div className={clsx("mt-1 text-2xl font-extrabold", counts.low > 0 ? "text-amber-600" : "text-emerald-600")}>{counts.low}</div>
        </button>
        {kind === "ingredient" && (
          <>
            <div className="card p-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Expiring soon</div>
              <div className={clsx("mt-1 text-2xl font-extrabold", counts.expiringSoon > 0 ? "text-amber-600" : "text-emerald-600")}>{counts.expiringSoon}</div>
            </div>
            <div className="card p-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">Expired</div>
              <div className={clsx("mt-1 text-2xl font-extrabold", counts.expired > 0 ? "text-red-600" : "text-emerald-600")}>{counts.expired}</div>
            </div>
          </>
        )}
      </div>

      {/* Expiry board (ingredients only — a product's batches are corrected via Adjust or a GRN, not write-off here) */}
      {kind === "ingredient" && expiring.length > 0 && (
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
                {canWriteOff && (
                  <button className="btn-danger ml-auto !py-1 text-xs" onClick={() => setWritingOff(b)}>
                    <Trash2 size={13} /> Write off
                  </button>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* Search + filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1 sm:flex-none">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-8 sm:!w-64" placeholder={`Search ${noun}s…`} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
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

      {/* Item list */}
      <div className="card divide-y divide-slate-50">
        {shown.map((r) => {
          const isOpen = expanded === r.id;
          const scale = r.low_stock_threshold > 0 ? r.low_stock_threshold * 3 : Math.max(r.stock_qty, 1);
          const pct = Math.min(100, (r.stock_qty / scale) * 100);
          return (
            <div key={r.id}>
              <div className="flex flex-wrap items-center gap-3 px-4 py-3 transition hover:bg-slate-50/60">
                <button className="flex min-w-0 flex-1 items-center gap-2 text-left" onClick={() => setExpanded(isOpen ? null : r.id)}>
                  <ChevronDown size={15} className={clsx("shrink-0 text-slate-300 transition-transform", isOpen && "rotate-180")} />
                  {r.image && <img src={r.image} alt="" className="h-8 w-8 shrink-0 rounded-lg object-cover" />}
                  <div className="min-w-0">
                    <div className="truncate text-sm font-bold">{r.name}{!r.active && <span className="ml-1.5 text-xs font-semibold text-slate-400">(inactive)</span>}</div>
                    <div className="text-[11px] text-slate-400">
                      {kind === "product"
                        ? (r.selling_price !== null ? `sells for ${lkr(r.selling_price)}` : "no selling price set")
                        : (r.used_in.length > 0 ? `in ${r.used_in.length} recipe${r.used_in.length === 1 ? "" : "s"}` : "not used in any recipe")}
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
                  {(canAdjust || canDelete) && <button className="btn-secondary !py-1.5 text-xs" onClick={() => setAdjusting(r)}>{canAdjust ? "Adjust" : "Manage"}</button>}
                </div>
              </div>
              {isOpen && (
                <div className="bg-slate-50/60 px-11 py-3 text-xs">
                  {kind === "ingredient" && r.used_in.length > 0 && (
                    <div className="mb-2 text-slate-500">
                      <b>Used in:</b> {r.used_in.join(", ")}
                    </div>
                  )}
                  {r.batches.length > 0 ? (
                    <div className="space-y-1">
                      <b className="text-slate-500">Batches:</b>
                      {r.batches.map((b) => (
                        <div key={b.id} className="flex flex-wrap gap-3 text-slate-600">
                          <span className="font-semibold tabular-nums">{b.qty.toLocaleString()}/{b.initial_qty.toLocaleString()} {r.unit}</span>
                          {b.unit_cost !== null && <span>cost {lkr(b.unit_cost)}/{r.unit}</span>}
                          {b.batch_no && <span>batch {b.batch_no}</span>}
                          {b.manufactured_at && <span>MFD {fmtDate(b.manufactured_at)}</span>}
                          <span>expiry {b.expiry_date ? fmtDate(b.expiry_date) : "—"}</span>
                          <span className="text-slate-400">received {fmtDate(b.received_at)}{b.note ? ` — ${b.note}` : ""}</span>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <span className="text-slate-400">No tracked batches yet.</span>
                  )}
                </div>
              )}
            </div>
          );
        })}
        {shown.length === 0 && <Empty text={q || filter !== "ALL" ? `No ${noun}s match` : `No ${noun}s yet`} />}
        {data && <Pagination page={data.page} pageSize={data.page_size} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {adjusting && <AdjustModal item={adjusting} basePath={basePath} canAdjust={canAdjust} canDelete={canDelete} canEdit={canEdit} onClose={() => { setAdjusting(null); refresh(); }} />}
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
