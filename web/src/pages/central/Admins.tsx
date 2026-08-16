import { useEffect, useState } from "react";
import { api, post, put } from "../../lib/api";
import { Card, Badge, Modal, Field, ErrorText, SimpleTable } from "../../components/ui";
import { useCentralAuth } from "../../lib/centralAuth";
import { Plus, Pencil, Trash2 } from "lucide-react";

type Admin = { id: number; name: string; email: string; is_active: boolean };

export default function CentralAdmins() {
  const { admin: me } = useCentralAuth();
  const [admins, setAdmins] = useState<Admin[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<Admin | null>(null);
  const [creating, setCreating] = useState(false);

  const load = () => api<{ admins: Admin[] }>("/central/admins").then((d) => setAdmins(d.admins));

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-black text-slate-900">Platform operators</h1>
        <button className="btn-primary" onClick={() => setCreating(true)}>
          <Plus size={16} /> New operator
        </button>
      </div>

      <Card>
        {loading ? (
          <p className="py-8 text-center text-sm text-slate-400">Loading…</p>
        ) : (
          <SimpleTable<Admin>
            columns={[
              { key: "name", label: "Name", render: (a) => (
                <span className="font-semibold text-slate-800">
                  {a.name} {a.id === me?.id && <span className="text-xs font-normal text-slate-400">(you)</span>}
                </span>
              ) },
              { key: "email", label: "Email" },
              { key: "is_active", label: "Status", render: (a) => <Badge color={a.is_active ? "emerald" : "slate"}>{a.is_active ? "Active" : "Disabled"}</Badge> },
              {
                key: "actions",
                label: "",
                align: "right",
                render: (a) => (
                  <div className="flex justify-end gap-1">
                    <button className="btn-ghost" title="Edit" onClick={() => setEditing(a)}><Pencil size={14} /></button>
                    <button className="btn-ghost text-red-500" title="Delete" onClick={() => del(a)}><Trash2 size={14} /></button>
                  </div>
                ),
              },
            ]}
            rows={admins}
            rowKey={(a) => a.id}
            empty="No platform operators yet"
          />
        )}
      </Card>

      {(creating || editing) && (
        <AdminModal
          admin={editing}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSaved={() => { setCreating(false); setEditing(null); load(); }}
        />
      )}
    </div>
  );

  async function del(a: Admin) {
    if (!confirm(`Delete platform operator ${a.name}?`)) return;
    try {
      await api(`/central/admins/${a.id}`, { method: "DELETE" });
      load();
    } catch (err) {
      alert((err as Error).message);
    }
  }
}

function AdminModal({ admin, onClose, onSaved }: { admin: Admin | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(admin?.name ?? "");
  const [email, setEmail] = useState(admin?.email ?? "");
  const [password, setPassword] = useState("");
  const [isActive, setIsActive] = useState(admin?.is_active ?? true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const body = { name, email, ...(password ? { password } : {}), is_active: isActive };
      if (admin) await put(`/central/admins/${admin.id}`, body);
      else await post("/central/admins", body);
      onSaved();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={admin ? `Edit ${admin.name}` : "New platform operator"}>
      <form onSubmit={submit} className="space-y-3">
        <ErrorText error={error} />
        <Field label="Name">
          <input className="input" value={name} onChange={(e) => setName(e.target.value)} required autoFocus />
        </Field>
        <Field label="Email">
          <input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </Field>
        <Field label={admin ? "New password (blank to keep)" : "Password"} hint="At least 8 characters.">
          <input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} minLength={8} />
        </Field>
        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" className="h-4 w-4 rounded accent-brand-600" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Account active
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn-primary" disabled={busy}>{busy ? "Saving…" : "Save"}</button>
        </div>
      </form>
    </Modal>
  );
}