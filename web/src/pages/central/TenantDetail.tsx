import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { api, post, put } from "../../lib/api";
import {
  Card, Badge, Field, ErrorText, Tabs, statusColor, SimpleTable, Pagination, ConfirmDialog,
} from "../../components/ui";
import { ThemeCustomizer, ThemeColors } from "../../components/ThemeCustomizer";
import { applyTheme } from "../../lib/theme";
import { RolesTab } from "./TenantRoles";
import { KeyRound, LogIn, Plus, RefreshCw, Trash2 } from "lucide-react";

type Tenant = {
  id: number;
  name: string;
  slug: string;
  status: "trial" | "active" | "suspended" | "cancelled";
  environment: "live" | "test";
  users_count: number;
  audit_logs_count: number;
};
type OwnerAdmin = { id: number; name: string; email: string; status: string; created_at: string; impersonation_only: boolean };
type SettingRow = { key: string; type: string; category: string; label: string; hint: string | null; value: string; overridden: boolean };
type ModuleRow = { key: string; name: string; description: string; enabled: boolean };

type Tab = "overview" | "settings" | "modules" | "roles" | "test-instance" | "audit";

export default function CentralTenantDetail() {
  const { id } = useParams<{ id: string }>();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [owner, setOwner] = useState<OwnerAdmin | null>(null);
  const [tab, setTab] = useState<Tab>("overview");
  const [error, setError] = useState("");
  const [impersonateUrl, setImpersonateUrl] = useState("");
  const [busy, setBusy] = useState(false);

  const load = () =>
    api<{ tenant: Tenant; owner_admin: OwnerAdmin | null }>(`/central/tenants/${id}`).then((d) => {
      setTenant(d.tenant);
      setOwner(d.owner_admin);
    });

  useEffect(() => {
    load();
  }, [id]);

  const impersonate = async () => {
    // Opened synchronously, before the await, or the popup blocker kills it
    // (same reasoning as openPdf() in lib/api.ts). A new tab also keeps master
    // control open behind it — navigating this one away strands the operator
    // inside the tenant's app with no way back, and the token is single-use
    // so the back button can't recover it.
    const tab = window.open("", "_blank");
    setBusy(true);
    setError("");
    try {
      const { url } = await api<{ url: string }>(`/central/tenants/${id}/impersonate`, { method: "POST" });
      setImpersonateUrl(url);
      if (tab && !tab.closed) {
        tab.location.href = url;
      }
    } catch (err) {
      tab?.close();
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  if (!tenant) return <p className="py-8 text-center text-sm text-slate-400">Loading…</p>;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-black text-slate-900">{tenant.name}</h1>
          <p className="flex items-center gap-2 text-sm text-slate-500">
            {tenant.slug}
            <Badge color={statusColor(tenant.status)}>{tenant.status}</Badge>
            <Badge color={tenant.environment === "test" ? "purple" : "slate"}>{tenant.environment}</Badge>
          </p>
        </div>
        <button className="btn-primary" onClick={impersonate} disabled={busy}>
          <LogIn size={16} /> {busy ? "Starting…" : "Impersonate admin"}
        </button>
      </div>

      <ErrorText error={error} />
      {impersonateUrl && (
        <p className="text-xs text-slate-400">
          Signed in as {tenant.name}'s administrator in a new tab. If it was blocked,{" "}
          <a href={impersonateUrl} target="_blank" rel="noreferrer" className="font-semibold text-brand-600 underline">open it here</a>{" "}
          — the link is single-use and expires in 90 seconds.
        </p>
      )}

      <Tabs<Tab>
        tabs={[
          { id: "overview", label: "Overview" },
          { id: "settings", label: "Settings" },
          { id: "modules", label: "Modules" },
          { id: "roles", label: "Roles" },
          { id: "test-instance", label: "Test instance" },
          { id: "audit", label: "Audit log" },
        ]}
        active={tab}
        onChange={setTab}
      />

      {tab === "overview" && <OverviewTab tenant={tenant} owner={owner} onSaved={setTenant} />}
      {tab === "settings" && <SettingsTab tenantId={tenant.id} />}
      {tab === "modules" && <ModulesTab tenantId={tenant.id} />}
      {tab === "roles" && <RolesTab tenantId={tenant.id} tenantName={tenant.name} />}
      {tab === "test-instance" && <TestInstanceTab tenant={tenant} />}
      {tab === "audit" && <AuditLogTab tenantId={tenant.id} />}
    </div>
  );
}

function OverviewTab({
  tenant, owner, onSaved,
}: { tenant: Tenant; owner: OwnerAdmin | null; onSaved: (t: Tenant) => void }) {
  const [name, setName] = useState(tenant.name);
  const [status, setStatus] = useState(tenant.status);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [lifecycleBusy, setLifecycleBusy] = useState(false);
  const [lifecycleError, setLifecycleError] = useState("");
  const [confirmSuspend, setConfirmSuspend] = useState(false);
  const [resetPassword, setResetPassword] = useState<{ password: string; email: string } | null>(null);
  const [resetBusy, setResetBusy] = useState(false);
  const [resetError, setResetError] = useState("");

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const { tenant: updated } = await put<{ tenant: Tenant }>(`/central/tenants/${tenant.id}`, { name, status });
      onSaved(updated);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const lifecycle = async (action: "suspend" | "resume") => {
    setLifecycleBusy(true);
    setLifecycleError("");
    setConfirmSuspend(false);
    try {
      const { tenant: updated } = await post<{ tenant: Tenant }>(`/central/tenants/${tenant.id}/${action}`);
      onSaved(updated);
      setStatus(updated.status);
    } catch (err) {
      setLifecycleError((err as Error).message);
    } finally {
      setLifecycleBusy(false);
    }
  };

  const doResetPassword = async () => {
    setResetBusy(true);
    setResetError("");
    try {
      const r = await post<{ password: string; admin: { email: string } }>(`/central/tenants/${tenant.id}/reset-admin-password`);
      setResetPassword({ password: r.password, email: r.admin.email });
    } catch (err) {
      setResetError((err as Error).message);
    } finally {
      setResetBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <Card>
        <form onSubmit={save} className="max-w-sm space-y-3">
          <ErrorText error={error} />
          <Field label="Business name">
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} required />
          </Field>
          <Field label="Status">
            <select className="input" value={status} onChange={(e) => setStatus(e.target.value as Tenant["status"])}>
              <option value="trial">Trial</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </Field>
          <button className="btn-primary" disabled={busy}>{busy ? "Saving…" : "Save"}</button>
        </form>
      </Card>

      <Card title="Owner admin & credentials">
        <ErrorText error={lifecycleError} />
        {owner ? (
          <div className="space-y-2 text-sm">
            <p className="font-semibold text-slate-800">{owner.name}</p>
            <p className="text-slate-500">{owner.email}</p>
            <p className="text-xs text-slate-400">
              Credentials are never handed out — the only way in is Impersonate. A reset mints a new
              never-communicated password and forces a change on next sign-in.
            </p>
            <div className="flex flex-wrap gap-2 pt-1">
              {tenant.status === "suspended" ? (
                <button className="btn-secondary" onClick={() => lifecycle("resume")} disabled={lifecycleBusy}>
                  {lifecycleBusy ? "Working…" : "Resume"}
                </button>
              ) : tenant.status === "cancelled" ? null : (
                <button className="btn-secondary" onClick={() => setConfirmSuspend(true)} disabled={lifecycleBusy}>
                  Suspend
                </button>
              )}
              <button className="btn-secondary" onClick={doResetPassword} disabled={resetBusy}>
                <KeyRound size={14} /> {resetBusy ? "Resetting…" : "Reset admin password"}
              </button>
            </div>
          </div>
        ) : (
          <p className="text-sm text-slate-400">No administrator account found for this tenant.</p>
        )}
      </Card>

      <ConfirmDialog
        open={confirmSuspend}
        title="Suspend tenant"
        message={`Suspending ${tenant.name} blocks all sign-ins and API access until it's resumed. Continue?`}
        confirmLabel="Suspend"
        tone="danger"
        busy={lifecycleBusy}
        onConfirm={() => lifecycle("suspend")}
        onClose={() => setConfirmSuspend(false)}
      />

      {resetPassword && (
        <Card title="New password — shown once">
          <ErrorText error={resetError} />
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              {owner?.name ?? "The tenant's administrator"} ({resetPassword.email}) can sign in with:
            </p>
            <code className="block rounded-lg bg-slate-950 px-4 py-3 font-mono text-lg text-emerald-300">{resetPassword.password}</code>
            <p className="text-xs text-slate-400">
              They'll be forced to change it on first sign-in. The password is not stored anywhere else — close this
              dialog to discard it.
            </p>
            <div className="flex justify-end">
              <button className="btn-primary" onClick={() => setResetPassword(null)}>Done</button>
            </div>
          </div>
        </Card>
      )}
    </div>
  );
}

/** The three keys the ThemeCustomizer owns — rendered by it, not as plain fields. */
const THEME_KEYS = ["theme.primary", "theme.secondary", "theme.sidebar"];

/** Master control's own palette — see CentralLayout, deliberately neutral. */
const CENTRAL_THEME = { primary: "#0462d3", secondary: "#3783f0", sidebar: "#0c182a" };

function SettingsTab({ tenantId }: { tenantId: number }) {
  const [settings, setSettings] = useState<SettingRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [themeError, setThemeError] = useState("");

  useEffect(() => {
    setLoading(true);
    api<{ settings: SettingRow[] }>(`/central/tenants/${tenantId}/settings`)
      .then((d) => setSettings(d.settings))
      .finally(() => setLoading(false));
  }, [tenantId]);

  // ThemeCustomizer drives its live preview by writing CSS variables onto
  // document.documentElement, which also repaints master control's own chrome.
  // Put the neutral palette back on the way out, so one tenant's brand colors
  // don't follow the operator into the next tenant or the tenant list.
  useEffect(
    () => () => applyTheme(CENTRAL_THEME.primary, CENTRAL_THEME.secondary, CENTRAL_THEME.sidebar),
    [],
  );

  const themeValue = (key: string, fallback: string) => {
    const raw = settings.find((s) => s.key === key)?.value;
    if (raw === undefined) return fallback;
    try {
      return String(JSON.parse(raw) ?? fallback) || fallback;
    } catch {
      return String(raw) || fallback;
    }
  };

  const saveTheme = async (colors: ThemeColors) => {
    setThemeError("");
    try {
      // Sequential, not Promise.all: each write invalidates the same per-tenant
      // settings cache, and three concurrent invalidations can interleave with
      // the reads that repopulate it.
      await put(`/central/tenants/${tenantId}/settings/theme.primary`, { value: colors.primary });
      await put(`/central/tenants/${tenantId}/settings/theme.secondary`, { value: colors.secondary });
      await put(`/central/tenants/${tenantId}/settings/theme.sidebar`, { value: colors.sidebar });

      setSettings((prev) =>
        prev.map((s) =>
          s.key === "theme.primary" ? { ...s, value: JSON.stringify(colors.primary), overridden: true }
          : s.key === "theme.secondary" ? { ...s, value: JSON.stringify(colors.secondary), overridden: true }
          : s.key === "theme.sidebar" ? { ...s, value: JSON.stringify(colors.sidebar), overridden: true }
          : s,
        ),
      );
    } catch (err) {
      setThemeError((err as Error).message);
      throw err;
    }
  };

  if (loading) return <p className="py-8 text-center text-sm text-slate-400">Loading…</p>;

  const byCategory = settings
    .filter((s) => !THEME_KEYS.includes(s.key))
    .reduce<Record<string, SettingRow[]>>((acc, s) => {
      (acc[s.category] ??= []).push(s);
      return acc;
    }, {});

  return (
    <div className="space-y-4">
      <Card title="Theme & live preview">
        <ErrorText error={themeError} />
        <ThemeCustomizer
          // Remount on change so the customizer's own draft state can't
          // carry one tenant's unsaved colors onto another's.
          key={tenantId}
          initialPrimary={themeValue("theme.primary", "#0462d3")}
          initialSecondary={themeValue("theme.secondary", "#3783f0")}
          initialSidebar={themeValue("theme.sidebar", "#0c182a")}
          onSaveTheme={saveTheme}
        />
      </Card>

      {Object.entries(byCategory).map(([category, rows]) => (
        <Card key={category} title={category}>
          <div className="space-y-3">
            {rows.map((row) => (
              <SettingField key={row.key} tenantId={tenantId} row={row} />
            ))}
          </div>
        </Card>
      ))}
    </div>
  );
}

function SettingField({ tenantId, row }: { tenantId: number; row: SettingRow }) {
  const decoded = (() => {
    try {
      return JSON.parse(row.value);
    } catch {
      return row.value;
    }
  })();
  const [value, setValue] = useState<string>(decoded ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);

  const save = async () => {
    setBusy(true);
    setError("");
    setSaved(false);
    try {
      let parsed: unknown = value;
      if (row.type === "boolean") parsed = value === "true";
      if (row.type === "number" || row.type === "percent" || row.type === "money") parsed = Number(value);
      await put(`/central/tenants/${tenantId}/settings/${row.key}`, { value: parsed });
      // Without this the only feedback a save ever produces is red error text,
      // so a successful one is indistinguishable from nothing happening.
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex items-end gap-2">
      <Field label={row.label} hint={row.hint ?? undefined}>
        {row.type === "boolean" ? (
          <select className="input" value={String(value)} onChange={(e) => setValue(e.target.value)}>
            <option value="true">Yes</option>
            <option value="false">No</option>
          </select>
        ) : (
          <input
            className="input"
            type={row.type === "color" ? "color" : "text"}
            value={value}
            onChange={(e) => setValue(e.target.value)}
          />
        )}
      </Field>
      <button className="btn-secondary" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save"}</button>
      {saved && <span className="text-xs font-semibold text-emerald-600">Saved</span>}
      {error && <span className="text-xs text-red-600">{error}</span>}
    </div>
  );
}

function ModulesTab({ tenantId }: { tenantId: number }) {
  const [modules, setModules] = useState<ModuleRow[]>([]);
  const [loading, setLoading] = useState(true);

  const load = () => api<{ modules: ModuleRow[] }>(`/central/tenants/${tenantId}/modules`).then((d) => setModules(d.modules));

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, [tenantId]);

  const toggle = async (key: string, enabled: boolean) => {
    setModules((prev) => prev.map((m) => (m.key === key ? { ...m, enabled } : m)));
    try {
      await put(`/central/tenants/${tenantId}/modules/${key}`, { enabled });
    } catch {
      load(); // revert on failure
    }
  };

  if (loading) return <p className="py-8 text-center text-sm text-slate-400">Loading…</p>;

  return (
    <Card>
      <div className="divide-y divide-slate-100">
        {modules.map((m) => (
          <label key={m.key} className="flex cursor-pointer items-center justify-between gap-4 py-3">
            <span>
              <span className="block text-sm font-bold text-slate-800">{m.name}</span>
              <span className="block text-xs text-slate-500">{m.description}</span>
            </span>
            <input
              type="checkbox"
              className="h-5 w-5 rounded accent-brand-600"
              checked={m.enabled}
              onChange={(e) => toggle(m.key, e.target.checked)}
            />
          </label>
        ))}
      </div>
    </Card>
  );
}

type TestInstance = {
  id: number;
  name: string;
  slug: string;
  status: string;
  environment: "test";
  created_at: string;
};

type CloneSummary = {
  headline: Record<string, number>;
  total_rows: number;
  dropped_rows: number;
  seconds: number;
};

function TestInstanceTab({ tenant }: { tenant: Tenant }) {
  const [instance, setInstance] = useState<TestInstance | null>(null);
  const [summary, setSummary] = useState<CloneSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [confirmDestroy, setConfirmDestroy] = useState(false);

  const load = () =>
    api<{ instance: TestInstance | null }>(`/central/tenants/${tenant.id}/test-instance`).then((d) => {
      setInstance(d.instance);
      setSummary(null);
    });

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, [tenant.id]);

  const create = async () => {
    setBusy(true);
    setError("");
    try {
      const r = await post<{ instance: TestInstance }>(`/central/tenants/${tenant.id}/test-instance`);
      setInstance(r.instance);
      setSummary(null);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const sync = async () => {
    setBusy(true);
    setError("");
    try {
      const r = await post<{ instance: TestInstance; summary: CloneSummary }>(`/central/tenants/${tenant.id}/test-instance/sync`);
      setInstance(r.instance);
      setSummary(r.summary);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const destroy = async () => {
    setBusy(true);
    setError("");
    setConfirmDestroy(false);
    try {
      await api(`/central/tenants/${tenant.id}/test-instance`, { method: "DELETE" });
      setInstance(null);
      setSummary(null);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <p className="py-8 text-center text-sm text-slate-400">Loading…</p>;

  return (
    <div className="space-y-4">
      <ErrorText error={error} />

      {tenant.environment === "test" ? (
        <Card>
          <p className="text-sm text-slate-500">
            This tenant is itself a test instance — test instances can't have their own.
          </p>
        </Card>
      ) : instance ? (
        <>
          <Card>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-bold text-slate-800">{instance.name}</p>
                <p className="text-xs text-slate-500">{instance.slug} · created {new Date(instance.created_at).toLocaleString()}</p>
              </div>
              <div className="flex gap-2">
                <button className="btn-secondary" onClick={sync} disabled={busy}>
                  <RefreshCw size={14} /> {busy ? "Syncing…" : "Sync from live"}
                </button>
                <button className="btn-danger" onClick={() => setConfirmDestroy(true)} disabled={busy}>
                  <Trash2 size={14} /> Destroy
                </button>
              </div>
            </div>
          </Card>

          {summary && (
            <Card title="Last sync">
              <div className="grid grid-cols-3 gap-3 md:grid-cols-6">
                {Object.entries(summary.headline).map(([label, count]) => (
                  <div key={label} className="rounded-lg bg-slate-50 p-3 text-center">
                    <p className="text-lg font-black text-slate-800">{count.toLocaleString()}</p>
                    <p className="text-xs capitalize text-slate-400">{label.replace("_", " ")}</p>
                  </div>
                ))}
              </div>
              <p className="mt-3 text-xs text-slate-500">
                {summary.total_rows.toLocaleString()} rows cloned ({summary.dropped_rows.toLocaleString()} dropped for
                missing references) in {summary.seconds}s.
              </p>
            </Card>
          )}
        </>
      ) : (
        <Card>
          <p className="text-sm text-slate-500">
            A test instance is an isolated copy of this tenant's data — safe to play with, and re-syncable
            whenever the live data changes.
          </p>
          <button className="btn-primary mt-3" onClick={create} disabled={busy}>
            <Plus size={16} /> {busy ? "Creating…" : "Create test instance"}
          </button>
        </Card>
      )}

      <ConfirmDialog
        open={confirmDestroy}
        title="Destroy test instance"
        message={<>All data in <strong>{instance?.name}</strong> will be permanently deleted.</>}
        confirmLabel="Destroy"
        tone="danger"
        busy={busy}
        onConfirm={destroy}
        onClose={() => setConfirmDestroy(false)}
      />
    </div>
  );
}

type AuditLog = {
  id: number;
  action: string;
  description: string;
  actor: { id: number; name: string; email: string } | null;
  central_admin: string | null;
  created_at: string;
};

type AuditPage = { data: AuditLog[]; total: number; per_page: number; current_page: number };

/** Exact-match options — a curated subset of the actions that matter here. */
const AUDIT_ACTIONS = [
  "impersonation.started",
  "tenant.created", "tenant.updated", "tenant.suspended", "tenant.resumed", "tenant.admin_password_reset",
  "tenant_setting.changed", "tenant_module.toggled",
  "admin.created", "admin.updated", "admin.deleted",
  "test_instance.created", "test_instance.synced", "test_instance.destroyed",
  "role.created", "role.updated", "role.deleted", "role.duplicated", "role.toggled_active",
  "user.created", "user.updated", "user.deleted", "user.suspended", "user.reactivated", "user.deactivated",
  "user.unlocked", "user.password_reset_by_admin", "user.login", "user.logout",
];

function AuditLogTab({ tenantId }: { tenantId: number }) {
  const [page, setPage] = useState<AuditPage>({ data: [], total: 0, per_page: 25, current_page: 1 });
  const [action, setAction] = useState("");
  const [actor, setActor] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const params = new URLSearchParams({ page: String(page.current_page) });
    if (action) params.set("action", action);
    if (actor) params.set("actor", actor);
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    setLoading(true);
    api<{ logs: AuditPage }>(`/central/tenants/${tenantId}/audit-logs?${params}`)
      .then((d) => setPage(d.logs))
      .finally(() => setLoading(false));
  }, [tenantId, page.current_page, action, actor, from, to]);

  return (
    <div className="space-y-3">
      <Card>
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Action">
            <select className="input" value={action} onChange={(e) => setAction(e.target.value)}>
              <option value="">All actions</option>
              {AUDIT_ACTIONS.map((a) => <option key={a} value={a}>{a}</option>)}
            </select>
          </Field>
          <Field label="Actor">
            <input className="input" placeholder="Name or email…" value={actor} onChange={(e) => setActor(e.target.value)} />
          </Field>
          <Field label="From">
            <input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </Field>
          <Field label="To">
            <input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </Field>
        </div>
      </Card>

      <Card>
        {loading ? (
          <p className="py-8 text-center text-sm text-slate-400">Loading…</p>
        ) : (
          <>
            <SimpleTable<AuditLog>
              columns={[
                { key: "action", label: "Action", render: (l) => <code className="text-xs font-semibold text-brand-700">{l.action}</code> },
                { key: "description", label: "What happened", render: (l) => <span className="text-slate-700">{l.description}</span> },
                {
                  key: "actor",
                  label: "Who",
                  render: (l) => l.central_admin ? (
                    <span className="text-xs text-violet-700">{l.central_admin} (operator)</span>
                  ) : l.actor ? (
                    <span className="text-slate-600">{l.actor.name}</span>
                  ) : <span className="text-slate-400">—</span>,
                },
                { key: "created_at", label: "When", render: (l) => <span className="text-xs text-slate-500">{new Date(l.created_at).toLocaleString()}</span> },
              ]}
              rows={page.data}
              rowKey={(l) => l.id}
              empty="No audit entries match these filters"
            />
            <Pagination
              page={page.current_page}
              pageSize={page.per_page}
              total={page.total}
              onPage={(p) => setPage((prev) => ({ ...prev, current_page: p }))}
              onPageSize={(s) => setPage((prev) => ({ ...prev, per_page: s, current_page: 1 }))}
            />
          </>
        )}
      </Card>
    </div>
  );
}