import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { api, put } from "../../lib/api";
import { Card, Badge, Field, ErrorText, Tabs, statusColor } from "../../components/ui";
import { ThemeCustomizer, ThemeColors } from "../../components/ThemeCustomizer";
import { applyTheme } from "../../lib/theme";
import { LogIn } from "lucide-react";

type Tenant = { id: number; name: string; slug: string; status: "trial" | "active" | "suspended" };
type SettingRow = { key: string; type: string; category: string; label: string; hint: string | null; value: string; overridden: boolean };
type ModuleRow = { key: string; name: string; description: string; enabled: boolean };

type Tab = "overview" | "settings" | "modules";

export default function CentralTenantDetail() {
  const { id } = useParams<{ id: string }>();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [tab, setTab] = useState<Tab>("overview");
  const [error, setError] = useState("");
  const [impersonateUrl, setImpersonateUrl] = useState("");
  const [busy, setBusy] = useState(false);

  const load = () => api<{ tenant: Tenant }>(`/central/tenants/${id}/settings`).then((d) => setTenant(d.tenant));

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
          <p className="text-sm text-slate-500">
            {tenant.slug} <Badge color={statusColor(tenant.status)}>{tenant.status}</Badge>
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
        ]}
        active={tab}
        onChange={setTab}
      />

      {tab === "overview" && <OverviewTab tenant={tenant} onSaved={setTenant} />}
      {tab === "settings" && <SettingsTab tenantId={tenant.id} />}
      {tab === "modules" && <ModulesTab tenantId={tenant.id} />}
    </div>
  );
}

function OverviewTab({ tenant, onSaved }: { tenant: Tenant; onSaved: (t: Tenant) => void }) {
  const [name, setName] = useState(tenant.name);
  const [status, setStatus] = useState(tenant.status);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

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

  return (
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
          </select>
        </Field>
        <button className="btn-primary" disabled={busy}>{busy ? "Saving…" : "Save"}</button>
      </form>
    </Card>
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
          // Remount on tenant change so the customizer's own draft state can't
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
