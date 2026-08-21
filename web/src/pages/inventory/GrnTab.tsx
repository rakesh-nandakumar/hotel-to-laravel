import { useState } from "react";
import { Plus, ClipboardList, Search } from "lucide-react";
import clsx from "clsx";
import { api } from "../../lib/api";
import { usePagedFetch, fmtDate, lkr } from "../../lib/util";
import { useAuth } from "../../lib/auth";
import { useToast } from "../../lib/toast";
import { Badge, Empty, ErrorText, Pagination, statusColor } from "../../components/ui";
import { InventoryNav } from "./InventoryNav";
import GrnEditor from "./GrnEditor";

type GrnRow = {
  id: number;
  grn_no: string;
  reference: string | null;
  received_at: string;
  total_cost: number;
  status: { code: string };
  creator: { id: number; name: string } | null;
  lines_count: number;
};

type StatusFilter = "ALL" | "draft" | "received" | "cancelled";

export default function GrnTab() {
  const { can } = useAuth();
  const toast = useToast();
  const canCreate = can("hotel_grn.create");
  const [status, setStatus] = useState<StatusFilter>("ALL");
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [openId, setOpenId] = useState<number | null | "new">(null);
  const [error, setError] = useState("");

  const { data, reload } = usePagedFetch<GrnRow>(
    `/grns?status=${status === "ALL" ? "" : status}&q=${encodeURIComponent(q)}&page=${page}&page_size=${pageSize}`,
    "grns",
    [status, q, page, pageSize]
  );
  const rows = data?.rows ?? [];

  const cancel = async (grn: GrnRow) => {
    setError("");
    try {
      await api(`/grns/${grn.id}/cancel`, { method: "POST", body: {} });
      toast.info(`GRN ${grn.grn_no} cancelled`);
      reload();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  const destroy = async (grn: GrnRow) => {
    setError("");
    try {
      await api(`/grns/${grn.id}`, { method: "DELETE" });
      toast.info(`GRN ${grn.grn_no} deleted`);
      reload();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  const STATUS_FILTERS: { id: StatusFilter; label: string }[] = [
    { id: "ALL", label: "All" },
    { id: "draft", label: "Draft" },
    { id: "received", label: "Received" },
    { id: "cancelled", label: "Cancelled" },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><ClipboardList /> Goods Received Notes</h1>
        <div className="flex items-center gap-2">
          <InventoryNav active="grn" />
          {canCreate && <button className="btn-primary" onClick={() => setOpenId("new")}><Plus size={16} /> New GRN</button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1 sm:flex-none">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-8 sm:!w-64" placeholder="Search GRN no. / reference…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        </div>
        <div className="flex gap-1 rounded-xl bg-slate-200/70 p-1">
          {STATUS_FILTERS.map((f) => (
            <button
              key={f.id}
              onClick={() => { setStatus(f.id); setPage(1); }}
              className={clsx("rounded-lg px-3 py-1.5 text-xs font-semibold transition", status === f.id ? "bg-white shadow-sm" : "text-slate-500 hover:text-slate-800")}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>
      <ErrorText error={error} />

      <div className="card divide-y divide-slate-50">
        {rows.map((g) => (
          <div key={g.id} className="flex flex-wrap items-center gap-3 px-4 py-3 transition hover:bg-slate-50/60">
            <button className="min-w-0 flex-1 text-left" onClick={() => setOpenId(g.id)}>
              <div className="flex items-center gap-2">
                <span className="font-mono text-sm font-bold">{g.grn_no}</span>
                <Badge color={statusColor(g.status.code)}>{g.status.code.toUpperCase()}</Badge>
              </div>
              <div className="text-[11px] text-slate-400">
                {g.reference && <>{g.reference} · </>}
                {g.lines_count} line{g.lines_count === 1 ? "" : "s"} · received {fmtDate(g.received_at)}
                {g.creator && <> · by {g.creator.name}</>}
              </div>
            </button>
            <span className="font-bold text-brand-700">{lkr(g.total_cost)}</span>
            <div className="flex items-center gap-1.5">
              {g.status.code === "draft" && can("hotel_grn.receive") && (
                <button className="btn-secondary !py-1.5 !text-emerald-700 text-xs" onClick={() => setOpenId(g.id)}>Review &amp; receive</button>
              )}
              {g.status.code === "draft" && can("hotel_grn.edit") && (
                <button className="btn-ghost !py-1.5 text-xs" onClick={() => cancel(g)}>Cancel</button>
              )}
              {g.status.code === "draft" && can("hotel_grn.delete") && (
                <button className="btn-ghost !py-1.5 text-xs !text-red-500" onClick={() => destroy(g)}>Delete</button>
              )}
            </div>
          </div>
        ))}
        {rows.length === 0 && <Empty text="No GRNs yet — create one when stock is delivered" />}
        {data && <Pagination page={data.page} pageSize={data.pageSize} total={data.total} onPage={setPage} onPageSize={(n) => { setPageSize(n); setPage(1); }} />}
      </div>

      {openId !== null && (
        <GrnEditor
          grnId={openId === "new" ? null : openId}
          onClose={() => { setOpenId(null); reload(); }}
          onReceive={() => toast.success("GRN received", "Stock and batches updated")}
        />
      )}
    </div>
  );
}
