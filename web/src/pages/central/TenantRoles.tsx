import { useState } from "react";
import { Plus, Pencil, Copy, Power, Trash2, ShieldCheck, Info } from "lucide-react";
import { api, post, put } from "../../lib/api";
import { useFetch } from "../../lib/util";
import { Badge, Card, ConfirmDialog, Empty, ErrorText, Field, Modal } from "../../components/ui";
import PermissionMatrix, { MatrixGroup, MatrixSection } from "../../components/PermissionMatrix";
import { useToast } from "../../lib/toast";

type RoleRow = {
  id: number; name: string; description: string | null; is_system: boolean; is_full_admin: boolean;
  is_active: boolean; users_count: number; permissions_count: number; updated_at: string;
};

/**
 * "Roles" tab of a tenant's page in master control — the platform operator
 * manages that tenant's roles exactly like the tenant's own Roles page, with
 * one extra power: the permission matrix shows the licensable module groups
 * (Hotel Operations, Restaurant / POS, …) with group-level grant/revoke.
 * Licensing still outranks everything: an unlicensed module never appears.
 */
export function RolesTab({ tenantId, tenantName }: { tenantId: number; tenantName: string }) {
  const { data, error, reload } = useFetch<{ roles: RoleRow[] }>(`/central/tenants/${tenantId}/roles`, [tenantId]);

  const [editorFor, setEditorFor] = useState<number | "new" | null>(null);
  const [deleteFor, setDeleteFor] = useState<RoleRow | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const toast = useToast();

  const roles = data?.roles ?? [];

  const toggleActive = async (role: RoleRow) => {
    setBusyId(role.id);
    try {
      const res = await post<{ message: string }>(`/central/tenants/${tenantId}/roles/${role.id}/toggle-active`);
      toast.success(res.message ?? "Updated");
      reload();
    } catch (e) {
      toast.error("Could not update role", (e as Error).message);
    } finally {
      setBusyId(null);
    }
  };

  const duplicate = async (role: RoleRow) => {
    setBusyId(role.id);
    try {
      const res = await post<{ message: string }>(`/central/tenants/${tenantId}/roles/${role.id}/duplicate`);
      toast.success(res.message ?? "Duplicated");
      reload();
    } catch (e) {
      toast.error("Could not duplicate role", (e as Error).message);
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-3">
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="flex items-start gap-1.5 text-xs text-slate-500">
            <Info size={14} className="mt-0.5 shrink-0" />
            <span>
              Roles are per-tenant and shared with {tenantName}'s own app — changes here take effect for their staff
              immediately. Permissions are grouped by module: grant or revoke a whole group at once.
            </span>
          </p>
          <button className="btn-primary" onClick={() => setEditorFor("new")}><Plus size={16} /> New role</button>
        </div>
      </Card>

      <ErrorText error={error} />

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[680px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Role</th><th className="th">Members</th><th className="th">Permissions</th><th className="th">Status</th><th className="th" /></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {roles.map((r) => (
              <tr key={r.id} className="hover:bg-slate-50/60">
                <td className="td">
                  <div className="flex items-center gap-2">
                    <span className="font-semibold text-slate-800">{r.name}</span>
                    {r.is_full_admin && <Badge color="purple">Full admin</Badge>}
                    {r.is_system && <Badge>System</Badge>}
                  </div>
                  {r.description && <div className="text-xs text-slate-400">{r.description}</div>}
                </td>
                <td className="td text-slate-600">{r.users_count}</td>
                <td className="td text-slate-600">{r.is_full_admin ? "All" : r.permissions_count}</td>
                <td className="td"><Badge color={r.is_active ? "green" : "slate"}>{r.is_active ? "ACTIVE" : "INACTIVE"}</Badge></td>
                <td className="td text-right">
                  <div className="flex justify-end gap-1">
                    <button className="btn-ghost !p-1.5" title="Edit" onClick={() => setEditorFor(r.id)}><Pencil size={15} /></button>
                    <button className="btn-ghost !p-1.5" title="Duplicate" disabled={busyId === r.id} onClick={() => duplicate(r)}><Copy size={15} /></button>
                    {!r.is_full_admin && (
                      <button className="btn-ghost !p-1.5" title={r.is_active ? "Deactivate" : "Activate"} disabled={busyId === r.id} onClick={() => toggleActive(r)}><Power size={15} /></button>
                    )}
                    {!r.is_system && !r.is_full_admin && (
                      <button className="btn-ghost !p-1.5 text-red-500" title="Delete" onClick={() => setDeleteFor(r)}><Trash2 size={15} /></button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {roles.length === 0 && <Empty text="This tenant has no roles yet" />}
      </div>

      {editorFor && (
        <RoleEditor tenantId={tenantId} roleId={editorFor === "new" ? null : editorFor} onClose={(changed) => { setEditorFor(null); if (changed) reload(); }} />
      )}

      {deleteFor && <RoleDeleteConfirm tenantId={tenantId} role={deleteFor} onClose={() => setDeleteFor(null)} onDone={() => { setDeleteFor(null); reload(); }} />}
    </div>
  );
}

function RoleDeleteConfirm({ tenantId, role, onClose, onDone }: { tenantId: number; role: RoleRow; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const run = async () => {
    setBusy(true);
    try {
      const res = await api<{ message: string }>(`/central/tenants/${tenantId}/roles/${role.id}`, { method: "DELETE" });
      toast.success(res.message ?? "Role deleted");
      onDone();
    } catch (e) {
      toast.error("Could not delete role", (e as Error).message);
      onClose();
    } finally {
      setBusy(false);
    }
  };
  return (
    <ConfirmDialog
      open
      title="Delete role"
      message={role.users_count > 0
        ? <><span className="font-semibold">{role.name}</span> is assigned to {role.users_count} user(s). Deleting it removes that access from them.</>
        : <>Delete the <span className="font-semibold">{role.name}</span> role?</>}
      confirmLabel="Delete"
      tone="danger"
      busy={busy}
      onConfirm={run}
      onClose={onClose}
    />
  );
}

type RoleFormData = {
  matrix: MatrixSection[];
  groups: MatrixGroup[];
  grantable_permissions: string[] | null;
  is_full_admin: boolean;
  role?: {
    id: number; name: string; description: string | null; is_system: boolean;
    is_full_admin: boolean; is_active: boolean; permissions: string[]; assigned_user_count: number;
  };
};

function RoleEditor({ tenantId, roleId, onClose }: { tenantId: number; roleId: number | null; onClose: (changed: boolean) => void }) {
  const { data, error } = useFetch<RoleFormData>(roleId ? `/central/tenants/${tenantId}/roles/${roleId}/edit` : `/central/tenants/${tenantId}/roles/create`, [tenantId, roleId]);
  return (
    <Modal open onClose={() => onClose(false)} title={roleId ? "Edit role" : "New role"} wide>
      {error && <ErrorText error={error} />}
      {data ? <RoleEditorForm data={data} tenantId={tenantId} roleId={roleId} onClose={onClose} /> : !error && <div className="py-10 text-center text-sm text-slate-400">Loading…</div>}
    </Modal>
  );
}

function RoleEditorForm({ data, tenantId, roleId, onClose }: { data: RoleFormData; tenantId: number; roleId: number | null; onClose: (changed: boolean) => void }) {
  const toast = useToast();
  const role = data.role;
  const isFullAdmin = role?.is_full_admin ?? false;

  const [name, setName] = useState(role?.name ?? "");
  const [description, setDescription] = useState(role?.description ?? "");
  const [isActive, setIsActive] = useState(role?.is_active ?? true);
  const [perms, setPerms] = useState<string[]>(role?.permissions ?? []);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true); setError("");
    const body = { name: name.trim(), description: description.trim() || null, is_active: isFullAdmin ? true : isActive, permissions: perms };
    try {
      const res = roleId
        ? await put<{ message: string }>(`/central/tenants/${tenantId}/roles/${roleId}`, body)
        : await post<{ message: string }>(`/central/tenants/${tenantId}/roles`, body);
      toast.success(res.message ?? "Saved");
      onClose(true);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Role name *"><input className="input" value={name} onChange={(e) => setName(e.target.value)} autoFocus /></Field>
        <Field label="Description"><input className="input" value={description} onChange={(e) => setDescription(e.target.value)} /></Field>
      </div>

      {!isFullAdmin && (
        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" className="h-4 w-4 rounded border-slate-300" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Active <span className="text-xs text-slate-400">— inactive roles grant no permissions to their members</span>
        </label>
      )}

      {role && role.assigned_user_count > 0 && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
          Editing affects {role.assigned_user_count} assigned user(s) immediately — permissions are computed, not copied.
        </p>
      )}

      <div>
        <div className="label">Permissions</div>
        {isFullAdmin ? (
          <div className="rounded-xl border border-brand-500/40 bg-brand-50 p-4 text-sm text-brand-700">
            <div className="flex items-center gap-2 font-semibold"><ShieldCheck size={16} /> Full Administrator</div>
            <p className="mt-1 text-brand-700/80">This role bypasses every permission check, so individual permissions can't be edited.</p>
          </div>
        ) : (
          <PermissionMatrix matrix={data.matrix} value={perms} onChange={setPerms} grantable={data.grantable_permissions} groups={data.groups} />
        )}
      </div>

      <ErrorText error={error} />
      <button className="btn-primary w-full" disabled={busy || !name.trim()} onClick={save}>
        {busy ? "Saving…" : roleId ? "Save role" : "Create role"}
      </button>
    </div>
  );
}