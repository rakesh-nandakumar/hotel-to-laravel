import { useState, useEffect, useCallback } from "react";
import {
  AlertTriangle, ChevronDown, ChevronRight, Clock, Database,
  Download, Eye, Info, ShieldAlert, Trash2, Undo2,
} from "lucide-react";
import { api, post } from "../../lib/api";
import { useToast } from "../../lib/toast";
import { Badge, Card, DangerConfirmDialog, Empty, ErrorText, Field, Modal, SimpleTable } from "../../components/ui";
import clsx from "clsx";

type FilterSpec = { key: string; label: string; type: "date" | "month" | "lookup"; options?: { value: string; label: string }[] };
type CategoryRow = { key: string; label: string; description: string; module: string; master: boolean; count: number; filters: FilterSpec[] };
type ModuleGroup = { key: string; label: string; categories: CategoryRow[] };
type CatalogResponse = { modules: ModuleGroup[]; retention_days: number; min_confirm_seconds: number };
type PlanTableRow = { table: string; label: string; count: number; reason?: string };
type NullifyRow = { table: string; label: string; count: number; column: string };
type ReconcileRow = { kind: string; count: number; description: string };
type Warning = { message: string };
type PlanSummary = {
  categories: Record<string, { label: string; total: number; roots: Record<string, number> }>;
  total_rows: number; tables: PlanTableRow[]; nullify: NullifyRow[];
  auto_included: { category: string; table: string; label: string; count: number; reason: string }[];
  reconcile: ReconcileRow[]; warnings: Warning[];
};
type PurgeRow = {
  id: number; uuid: string; status: "completed" | "restored" | "expired"; created_at: string;
  total_rows: number; summary: PlanSummary & { numbering: string }; note: string | null;
  continue_numbering: boolean; operator: { id: number; name: string; email: string } | null;
  backup_bytes: number; expires_at: string | null; restorable: boolean;
  restored_at: string | null; restored_by: { id: number; name: string; email: string } | null;
  restore_summary: Record<string, unknown> | null;
};

function fmtBytes(b: number): string {
  if (b < 1024) return ${b} B;
  if (b < 1024 * 1024) return ${(b / 1024).toFixed(1)} KB;
  return ${(b / (1024 * 1024)).toFixed(2)} MB;
}
function fmtDate(d: string): string { return new Date(d).toLocaleString(); }

function CategoryCard({ cat, selected, filters, onChange, onFilterChange }: {
  cat: CategoryRow; selected: boolean; filters: Record<string, string>;
  onChange: (checked: boolean) => void; onFilterChange: (key: string, value: string) => void;
}) {
  return (
    <div className={clsx("rounded-xl border p-3 transition", selected ? "border-brand-400 bg-brand-50 shadow-sm" : "border-slate-200 bg-white hover:border-slate-300")}>
      <label className="flex cursor-pointer items-start gap-3">
        <input type="checkbox" className="mt-0.5 h-4 w-4 rounded accent-brand-600" checked={selected} onChange={(e) => onChange(e.target.checked)} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-sm font-semibold text-slate-800">{cat.label}</span>
            {cat.master && (<Badge color="red"><ShieldAlert size={10} className="mr-0.5" />Master data</Badge>)}
            <span className="ml-auto text-xs font-semibold text-slate-400">{cat.count.toLocaleString()} rows</span>
          </div>
        </div>
      </label>
      {selected && cat.filters.length > 0 && (
        <div className="mt-2 grid gap-2 pl-7 sm:grid-cols-2">
          {cat.filters.map((f) => (
            <div key={f.key}>
              <label className="label text-[11px]">{f.label}</label>
              {f.type === "lookup" ? (
                <select className="input !py-1 text-xs" value={filters[f.key] ?? ""} onChange={(e) => onFilterChange(f.key, e.target.value)}>
                  <option value="">All</option>
                  {f.options?.map((o) => (<option key={o.value} value={o.value}>{o.label}</option>))}
                </select>
              ) : (
                <input type={f.type === "month" ? "month" : "date"} className="input !py-1 text-xs" value={filters[f.key] ?? ""} onChange={(e) => onFilterChange(f.key, e.target.value)} />
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function PlanPreview({ plan }: { plan: PlanSummary }) {
  const [expanded, setExpanded] = useState(false);
  return (
    <div className="space-y-4">
      {plan.warnings.map((w, i) => (
        <div key={i} className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
          <AlertTriangle size={16} className="mt-0.5 shrink-0 text-amber-500" />{w.message}
        </div>
      ))}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {Object.values(plan.categories).map((cat) => (
          <div key={cat.label} className="rounded-lg bg-slate-100 px-3 py-2 text-center">
            <div className="text-lg font-black text-slate-900">{cat.total.toLocaleString()}</div>
            <div className="text-[11px] text-slate-500">{cat.label}</div>
          </div>
        ))}
      </div>
      <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-2">
        <span className="text-sm font-bold text-red-700">Total: {plan.total_rows.toLocaleString()} records will be permanently deleted</span>
      </div>
      {plan.auto_included.length > 0 && (
        <div>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Also deleted automatically</p>
          <div className="space-y-1">
            {plan.auto_included.map((a, i) => (
              <div key={i} className="flex items-center gap-2 text-xs text-slate-600">
                <span className="font-semibold">{a.label}</span><span className="text-slate-400">({a.count.toLocaleString()})</span>
                <span className="text-slate-400">—</span><span>{a.reason}</span>
              </div>
            ))}
          </div>
        </div>
      )}
      {plan.nullify.length > 0 && (
        <div>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Links that will be cleared (not deleted)</p>
          <div className="space-y-1">
            {plan.nullify.map((n, i) => (
              <div key={i} className="text-xs text-slate-600">
                <span className="font-semibold">{n.label}</span>
                <span className="text-slate-400"> · {n.column} set to NULL on {n.count.toLocaleString()} row(s)</span>
              </div>
            ))}
          </div>
        </div>
      )}
      {plan.reconcile.length > 0 && (
        <div>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">State repairs</p>
          <div className="space-y-1">{plan.reconcile.map((r, i) => <div key={i} className="text-xs text-slate-600">{r.description}</div>)}</div>
        </div>
      )}
      <button className="flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-800" onClick={() => setExpanded((x) => !x)}>
        {expanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}{expanded ? "Hide" : "Show"} per-table breakdown
      </button>
      {expanded && (
        <SimpleTable<PlanTableRow>
          columns={[{ key: "label", label: "Table" }, { key: "count", label: "Rows", align: "right", render: (r) => r.count.toLocaleString() }, { key: "reason", label: "Included because" }]}
          rows={plan.tables} rowKey={(r) => r.table}
        />
      )}
    </div>
  );
}

function RestorePreviewModal({ open, onClose, purge, tenantId }: { open: boolean; onClose: () => void; purge: PurgeRow; tenantId: number }) {
  const [loading, setLoading] = useState(false);
  const [report, setReport] = useState<Record<string, unknown> | null>(null);
  const [token, setToken] = useState("");
  const [minSeconds, setMinSeconds] = useState(3);
  const [password, setPassword] = useState("");
  const [pwError, setPwError] = useState("");
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState("");
  const toast = useToast();

  useEffect(() => {
    if (!open) { setReport(null); setToken(""); setPassword(""); setPwError(""); setError(""); setConfirming(false); return; }
    setLoading(true);
    api<{ report: Record<string, unknown>; token: string; min_confirm_seconds: number }>(
      /central/tenants//data/purges//restore-preview, { method: "POST", body: {} },
    ).then((d) => { setReport(d.report); setToken(d.token); setMinSeconds(d.min_confirm_seconds); })
      .catch((e) => setError((e as Error).message)).finally(() => setLoading(false));
  }, [open, purge.id, tenantId]);

  const doRestore = async () => {
    setPwError(""); setBusy(true);
    try {
      const res = await post<{ message: string }>(/central/tenants//data/purges//restore, { token, password });
      toast.success(res.message ?? "Restored"); onClose();
    } catch (e: unknown) {
      const err = e as { errors?: Record<string, string[]>; message?: string };
      if (err.errors?.password) setPwError(err.errors.password[0]);
      else setError(err.message ?? "Restore failed");
    } finally { setBusy(false); }
  };

  const renumbered = (report?.renumbered as unknown[]) ?? [];
  const skipped = (report?.skipped as unknown[]) ?? [];
  const totalRestored = (report?.total_restored as number) ?? 0;

  return (
    <Modal open={open} onClose={onClose} title={Restore backup — …} wide>
      <div className="space-y-4 text-sm">
        {loading && <p className="py-4 text-center text-slate-400">Analysing backup…</p>}
        <ErrorText error={error} />
        {report && !loading && (
          <>
            <div className="rounded-lg bg-brand-50 px-4 py-2 text-brand-800">
              <span className="font-bold">{totalRestored.toLocaleString()}</span> records would be restored.
              {renumbered.length > 0 && <span> {renumbered.length} document number(s) will be renumbered.</span>}
              {skipped.length > 0 && <span className="text-amber-700"> {skipped.length} document(s) cannot be restored.</span>}
            </div>
            {renumbered.length > 0 && (
              <details><summary className="cursor-pointer text-xs font-semibold text-slate-500">Renumbered ({renumbered.length})</summary>
                <ul className="mt-1 space-y-0.5 pl-4">
                  {renumbered.slice(0, 20).map((r: unknown, i) => { const item = r as { old: string; new: string }; return <li key={i} className="text-xs text-slate-600"><code>{item.old}</code> → <code>{item.new}</code></li>; })}
                </ul>
              </details>
            )}
            {skipped.length > 0 && (
              <details><summary className="cursor-pointer text-xs font-semibold text-amber-700">Skipped ({skipped.length})</summary>
                <ul className="mt-1 space-y-0.5 pl-4">
                  {skipped.slice(0, 20).map((s: unknown, i) => { const item = s as { table: string; id: number; reason: string }; return <li key={i} className="text-xs text-slate-600">{item.table}#{item.id}: {item.reason}</li>; })}
                </ul>
              </details>
            )}
            {!confirming && (
              <div className="flex justify-end gap-2">
                <button className="btn-secondary" onClick={onClose}>Cancel</button>
                <button className="btn-primary" onClick={() => setConfirming(true)}><Undo2 size={14} /> Proceed to restore</button>
              </div>
            )}
            {confirming && (
              <DangerConfirmDialog open title="Confirm restore"
                message={<span>This will restore <strong>{totalRestored.toLocaleString()}</strong> records for this tenant. Data added since the purge will not be touched.</span>}
                confirmLabel="Restore backup" minSeconds={minSeconds} countdownKey={purge.id}
                password={password} onPasswordChange={setPassword} passwordError={pwError}
                busy={busy} onConfirm={doRestore} onClose={() => setConfirming(false)}
              />
            )}
          </>
        )}
      </div>
    </Modal>
  );
}

function HistoryTab({ tenantId }: { tenantId: number }) {
  const [purges, setPurges] = useState<PurgeRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [restoreFor, setRestoreFor] = useState<PurgeRow | null>(null);
  const toast = useToast();

  const load = useCallback(() => {
    setLoading(true);
    api<{ purges: PurgeRow[] }>(/central/tenants//data/purges)
      .then((d) => setPurges(d.purges)).catch((e) => setError((e as Error).message)).finally(() => setLoading(false));
  }, [tenantId]);

  useEffect(() => { load(); }, [load]);

  const purgeStatusColor = (s: string) => ({ completed: "green", restored: "blue", expired: "slate" }[s] ?? "slate");

  return (
    <div className="space-y-3">
      <ErrorText error={error} />
      {loading ? <p className="py-8 text-center text-sm text-slate-400">Loading…</p>
        : purges.length === 0 ? <Empty text="No purges yet for this tenant" />
        : (
          <div className="card overflow-x-auto">
            <table className="w-full min-w-[700px] text-sm">
              <thead className="border-b border-slate-100 text-left text-[11px] font-bold uppercase tracking-wide text-slate-400">
                <tr><th className="px-3 py-2">Date</th><th className="px-3 py-2">Operator</th><th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2 text-right">Rows</th><th className="px-3 py-2">Backup</th><th className="px-3 py-2">Expires</th><th className="px-3 py-2" /></tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {purges.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50">
                    <td className="px-3 py-2 text-xs text-slate-500">{fmtDate(p.created_at)}</td>
                    <td className="px-3 py-2 text-xs">{p.operator?.name ?? "—"}</td>
                    <td className="px-3 py-2">
                      <Badge color={purgeStatusColor(p.status)}>{p.status}</Badge>
                      {p.restored_at && <span className="ml-1 text-[10px] text-slate-400">by {p.restored_by?.name} {fmtDate(p.restored_at)}</span>}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums text-xs">{p.total_rows.toLocaleString()}</td>
                    <td className="px-3 py-2 text-xs text-slate-400">{p.backup_bytes ? fmtBytes(p.backup_bytes) : "—"}</td>
                    <td className="px-3 py-2 text-xs text-slate-400">{p.expires_at ? new Date(p.expires_at).toLocaleDateString() : "—"}</td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-1 justify-end">
                        {p.status !== "expired" && p.backup_bytes > 0 && (
                          <a href={/api/central/tenants//data/purges//download} target="_blank" rel="noreferrer"
                            className="btn-secondary !py-1 !px-2 text-xs" title="Download backup"><Download size={12} /></a>
                        )}
                        {p.restorable && (
                          <button className="btn-secondary !py-1 !px-2 text-xs" onClick={() => setRestoreFor(p)}><Undo2 size={12} /> Restore…</button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      {restoreFor && <RestorePreviewModal open purge={restoreFor} tenantId={tenantId} onClose={() => { setRestoreFor(null); load(); }} />}
    </div>
  );
}

type DataView = "select" | "preview" | "history";

export function DataTab({ tenantId, tenantName }: { tenantId: number; tenantName: string }) {
  const [view, setView] = useState<DataView>("select");
  const [catalog, setCatalog] = useState<CatalogResponse | null>(null);
  const [catalogError, setCatalogError] = useState("");
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [selections, setSelections] = useState<Record<string, boolean>>({});
  const [filterValues, setFilterValues] = useState<Record<string, Record<string, string>>>({});
  const [planSummary, setPlanSummary] = useState<PlanSummary | null>(null);
  const [planToken, setPlanToken] = useState("");
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [password, setPassword] = useState("");
  const [pwError, setPwError] = useState("");
  const [continueNumbering, setContinueNumbering] = useState(false);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [minSeconds, setMinSeconds] = useState(3);
  const toast = useToast();

  useEffect(() => {
    setCatalogLoading(true);
    api<CatalogResponse>(/central/tenants//data/catalog)
      .then((d) => setCatalog(d)).catch((e) => setCatalogError((e as Error).message)).finally(() => setCatalogLoading(false));
  }, [tenantId]);

  const selectedKeys = Object.entries(selections).filter(([, v]) => v).map(([k]) => k);
  const buildSelections = () => selectedKeys.map((key) => ({ key, filters: filterValues[key] ?? {} }));

  const preview = async () => {
    setPreviewError(""); setPreviewLoading(true);
    try {
      const res = await post<{ plan: PlanSummary; token: string; min_confirm_seconds: number }>(
        /central/tenants//data/preview, { selections: buildSelections() },
      );
      setPlanSummary(res.plan); setPlanToken(res.token); setMinSeconds(res.min_confirm_seconds); setView("preview");
    } catch (e) { setPreviewError((e as Error).message); } finally { setPreviewLoading(false); }
  };

  const purge = async () => {
    setPwError(""); setBusy(true);
    try {
      const res = await post<{ message: string }>(/central/tenants//data/purge, { token: planToken, password, continue_numbering: continueNumbering, note: note || null });
      toast.success(res.message ?? "Purge completed");
      setConfirming(false); setView("history"); setSelections({}); setFilterValues({}); setPlanSummary(null);
    } catch (e: unknown) {
      const err = e as { status?: number; errors?: Record<string, string[]>; message?: string };
      if (err.errors?.password) setPwError(err.errors.password[0]);
      else if (err.status === 409) { toast.error("Data changed", err.message ?? "Preview again."); setConfirming(false); setView("select"); setPlanSummary(null); }
      else setPwError(err.message ?? "Purge failed");
    } finally { setBusy(false); }
  };

  const selectAllTransactional = (moduleKey: string) => {
    if (!catalog) return;
    const mod = catalog.modules.find((m) => m.key === moduleKey);
    if (!mod) return;
    setSelections((prev) => { const next = { ...prev }; mod.categories.filter((c) => !c.master).forEach((c) => { next[c.key] = true; }); return next; });
  };
  const selectAllTransactionalGlobal = () => {
    if (!catalog) return;
    setSelections((prev) => { const next = { ...prev }; catalog.modules.forEach((m) => m.categories.filter((c) => !c.master).forEach((c) => { next[c.key] = true; })); return next; });
  };
  const clearAll = () => setSelections({});

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-start gap-2 text-sm text-slate-600">
            <Info size={16} className="mt-0.5 shrink-0 text-brand-500" />
            <span>Bulk-delete this tenant's data by category. Every purge creates a restorable backup kept for <strong>{catalog?.retention_days ?? 90} days</strong>.</span>
          </div>
          <div className="flex gap-2">
            <button className={clsx("btn-secondary !py-1.5 text-sm", view === "select" && "bg-slate-200")} onClick={() => setView("select")}><Database size={14} /> Select data</button>
            <button className={clsx("btn-secondary !py-1.5 text-sm", view === "history" && "bg-slate-200")} onClick={() => setView("history")}><Clock size={14} /> History</button>
          </div>
        </div>
      </Card>
      <ErrorText error={catalogError} />
      {view === "select" && (
        <>
          {catalogLoading ? <p className="py-8 text-center text-sm text-slate-400">Loading categories…</p>
            : catalog && (
              <>
                <Card>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-semibold text-slate-500">Quick select:</span>
                    <button className="btn-secondary !py-1 text-xs" onClick={selectAllTransactionalGlobal}>All transactional (keep catalog)</button>
                    <button className="btn-secondary !py-1 text-xs" onClick={clearAll}>Clear all</button>
                    {catalog.modules.map((m) => (<button key={m.key} className="btn-secondary !py-1 text-xs" onClick={() => selectAllTransactional(m.key)}>{m.label} transactional</button>))}
                  </div>
                </Card>
                {catalog.modules.map((mod) => (
                  <Card key={mod.key} title={mod.label}>
                    <div className="grid gap-2 sm:grid-cols-2">
                      {mod.categories.map((cat) => (
                        <CategoryCard key={cat.key} cat={cat} selected={!!selections[cat.key]} filters={filterValues[cat.key] ?? {}}
                          onChange={(checked) => setSelections((prev) => ({ ...prev, [cat.key]: checked }))}
                          onFilterChange={(fk, fv) => setFilterValues((prev) => ({ ...prev, [cat.key]: { ...(prev[cat.key] ?? {}), [fk]: fv } }))}
                        />
                      ))}
                    </div>
                  </Card>
                ))}
                <div className="flex items-center justify-between">
                  <span className="text-sm text-slate-500">{selectedKeys.length} categor{selectedKeys.length === 1 ? "y" : "ies"} selected</span>
                  <button id="data-tab-preview-btn" className="btn-primary" disabled={selectedKeys.length === 0 || previewLoading} onClick={preview}>
                    <Eye size={15} />{previewLoading ? "Calculating…" : "Preview deletion"}
                  </button>
                </div>
                <ErrorText error={previewError} />
              </>
            )}
        </>
      )}
      {view === "preview" && planSummary && (
        <>
          <Card title="Deletion preview"><PlanPreview plan={planSummary} /></Card>
          <div className="flex items-center justify-between gap-3">
            <button className="btn-secondary" onClick={() => setView("select")}>← Back to selection</button>
            <button id="data-tab-confirm-btn" className="btn-danger" onClick={() => { setPassword(""); setPwError(""); setNote(""); setConfirming(true); }}>
              <Trash2 size={15} /> Delete {planSummary.total_rows.toLocaleString()} records…
            </button>
          </div>
          <DangerConfirmDialog
            open={confirming}
            title={<span className="flex items-center gap-2 text-red-700"><ShieldAlert size={18} /> Delete {planSummary.total_rows.toLocaleString()} records from {tenantName}</span>}
            message={<span>This will permanently delete <strong>{planSummary.total_rows.toLocaleString()}</strong> records from <strong>{tenantName}</strong>. A backup is kept for <strong>{catalog?.retention_days ?? 90} days</strong> and can be restored from the History tab.</span>}
            confirmLabel={Delete  records}
            minSeconds={minSeconds} countdownKey={planToken}
            password={password} onPasswordChange={setPassword} passwordError={pwError}
            busy={busy} onConfirm={purge} onClose={() => setConfirming(false)}
            extra={
              <div className="space-y-3">
                <label className="flex cursor-pointer items-start gap-2 text-sm text-slate-600">
                  <input type="checkbox" className="mt-0.5 accent-brand-600" checked={continueNumbering} onChange={(e) => setContinueNumbering(e.target.checked)} />
                  <div>
                    <span className="font-semibold">Continue numbering</span>
                    <p className="text-xs text-slate-400">If checked, the next invoice number will be higher than the deleted ones. If unchecked, numbering restarts from 1.</p>
                  </div>
                </label>
                <Field label="Optional note (for audit log)">
                  <input className="input" placeholder="e.g. Client requested full wipe of test bookings" value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
                </Field>
              </div>
            }
          />
        </>
      )}
      {view === "history" && <HistoryTab tenantId={tenantId} />}
    </div>
  );
}
