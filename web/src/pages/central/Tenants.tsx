import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, post } from "../../lib/api";
import { tenantUrl } from "../../lib/tenancy";
import { slugify } from "../../lib/util";
import { Card, Badge, Modal, Field, ErrorText, SimpleTable, statusColor } from "../../components/ui";
import { Plus } from "lucide-react";

type Tenant = {
  id: number;
  name: string;
  slug: string;
  status: "trial" | "active" | "suspended" | "cancelled";
  environment: "live" | "test";
  users_count: number;
};

export default function CentralTenants() {
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);

  const load = () => api<{ tenants: Tenant[] }>("/central/tenants").then((d) => setTenants(d.tenants));

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-black text-slate-900">Tenants</h1>
        <button className="btn-primary" onClick={() => setShowCreate(true)}>
          <Plus size={16} /> New tenant
        </button>
      </div>

      <Card>
        {loading ? (
          <p className="py-8 text-center text-sm text-slate-400">Loading…</p>
        ) : (
          <SimpleTable<Tenant>
            columns={[
              { key: "name", label: "Business", render: (t) => (
                <Link to={`/tenants/${t.id}`} className="font-semibold text-brand-600 hover:underline">{t.name}</Link>
              ) },
              { key: "slug", label: "URL", render: (t) => <span className="text-slate-500">{tenantUrl(t.slug)}</span> },
              { key: "status", label: "Status", render: (t) => <Badge color={statusColor(t.status)}>{t.status}</Badge> },
              { key: "environment", label: "Env", render: (t) => <Badge color={t.environment === "test" ? "purple" : "slate"}>{t.environment}</Badge> },
              { key: "users_count", label: "Users", align: "right" },
            ]}
            rows={tenants}
            rowKey={(t) => t.id}
            empty="No tenants yet"
          />
        )}
      </Card>

      <CreateTenantModal
        open={showCreate}
        onClose={() => setShowCreate(false)}
        onCreated={() => {
          setShowCreate(false);
          load();
        }}
      />
    </div>
  );
}

function CreateTenantModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [adminEmail, setAdminEmail] = useState("");
  const [adminName, setAdminName] = useState("");
  const [slugTouched, setSlugTouched] = useState(false);
  const [emailTouched, setEmailTouched] = useState(false);
  const [nameTouched, setNameTouched] = useState(false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const reset = () => {
    setName("");
    setSlug("");
    setAdminEmail("");
    setAdminName("");
    setSlugTouched(false);
    setEmailTouched(false);
    setNameTouched(false);
    setError("");
  };

  // As the business name is typed, pre-fill the URL prefix, admin email and
  // admin name — but only while the operator hasn't overridden each field.
  const onNameChange = (value: string) => {
    setName(value);
    const derived = slugify(value);
    if (!slugTouched) setSlug(derived);
    if (!emailTouched) setAdminEmail(derived ? `admin@${derived}.com` : "");
    if (!nameTouched) setAdminName(value ? `${value} Admin` : "");
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await post("/central/tenants", { name, slug, admin_email: adminEmail, admin_name: adminName || undefined });
      reset();
      onCreated();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="New tenant">
      <form onSubmit={submit} className="space-y-3">
        <ErrorText error={error} />
        <Field label="Business name">
          <input className="input" value={name} onChange={(e) => onNameChange(e.target.value)} required autoFocus />
        </Field>
        <Field label="URL prefix" hint="Lowercase letters, numbers and dashes only — the tenant app lives at yourdomain.com/acme.">
          <input
            className="input"
            value={slug}
            onChange={(e) => {
              setSlugTouched(true);
              setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ""));
            }}
            required
          />
        </Field>
        <Field label="Admin email" hint="The tenant's first Full Administrator account is created automatically — you'll access it via Impersonate, never a password.">
          <input
            className="input"
            type="email"
            value={adminEmail}
            onChange={(e) => {
              setEmailTouched(true);
              setAdminEmail(e.target.value);
            }}
            required
          />
        </Field>
        <Field label="Admin name (optional)">
          <input
            className="input"
            value={adminName}
            onChange={(e) => {
              setNameTouched(true);
              setAdminName(e.target.value);
            }}
            placeholder={`${name || "Business"} Admin`}
          />
        </Field>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn-primary" disabled={busy}>{busy ? "Creating…" : "Create tenant"}</button>
        </div>
      </form>
    </Modal>
  );
}
