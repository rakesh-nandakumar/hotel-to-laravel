import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { api, put } from "../../lib/api";
import { Card, Badge, Field, ErrorText, Tabs, statusColor } from "../../components/ui";
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
    setBusy(true);
    setError("");
    try {
      const { url } = await api<{ url: string }>(`/central/tenants/${id}/impersonate`, { method: "POST" });
      setImpersonateUrl(url);
      window.location.href = url;
    } catch (err) {
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
          If the redirect didn't happen automatically,{" "}
          <a href={impersonateUrl} className="font-semibold text-brand-600 underline">click here</a>.
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

function SettingsTab({ tenantId }: { tenantId: number }) {
  const [settings, setSettings] = useState<SettingRow[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<{ settings: SettingRow[] }>(`/central/tenants/${tenantId}/settings`)
      .then((d) => setSettings(d.settings))
      .finally(() => setLoading(false));
  }, [tenantId]);

  if (loading) return <p className="py-8 text-center text-sm text-slate-400">Loading…</p>;

  const byCategory = settings.reduce<Record<string, SettingRow[]>>((acc, s) => {
    (acc[s.category] ??= []).push(s);
    return acc;
  }, {});

  return (
    <div className="space-y-4">
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

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      let parsed: unknown = value;
      if (row.type === "boolean") parsed = value === "true";
      if (row.type === "number" || row.type === "percent" || row.type === "money") parsed = Number(value);
      await put(`/central/tenants/${tenantId}/settings/${row.key}`, { value: parsed });
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
          <input className="input" value={value} onChange={(e) => setValue(e.target.value)} />
        )}
      </Field>
      <button className="btn-secondary" onClick={save} disabled={busy}>{busy ? "Saving…" : "Save"}</button>
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
