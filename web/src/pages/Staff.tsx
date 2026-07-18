import { useEffect, useMemo, useState, ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import {
  Plus, KeyRound, MoreHorizontal, Eye, Pencil, Lock, Unlock, UserCheck, UserX, Ban, Trash2, ShieldCheck, Search,
} from "lucide-react";
import { post, put, api } from "../lib/api";
import { useFetch } from "../lib/util";
import {
  Avatar, Badge, ConfirmDialog, Empty, ErrorText, Field, Modal, Pagination, statusColor,
} from "../components/ui";
import PermissionMatrix, { MatrixSection } from "../components/PermissionMatrix";
import { usePermissions } from "../lib/auth";
import { useToast } from "../lib/toast";

type Ref = { id: number; name: string };
type StaffLite = { id: number; name: string; roles: Ref[] };
type Paginator<T> = { data: T[]; current_page: number; per_page: number; total: number };

/**
 * "User Management" — the Administration group's user surface (multi-role RBAC,
 * per-user permission overrides, status/lock actions and an audit view), gated
 * on `user_management_users.access`. Roles that hold only `hotel_staff.set_pin`
 * (e.g. an Owner who just sets POS PINs) get the lightweight PIN-only picker.
 * Role administration lives on its own page (pages/Roles.tsx).
 */
export default function StaffPage() {
  const { can } = usePermissions();
  return can("user_management_users.access") ? <UsersPanel /> : <PinOnlyList />;
}

// ── Users ────────────────────────────────────────────────────────────────────

type UserRow = {
  id: number; name: string; email: string; phone: string | null; status: string;
  last_login_at: string | null; locked_until: string | null; roles: Ref[];
};

const isLocked = (u: UserRow) => !!u.locked_until && new Date(u.locked_until) > new Date();

function UsersPanel() {
  const { can } = usePermissions();
  const nav = useNavigate();
  const location = useLocation();

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [roleId, setRoleId] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => { setSearch(searchInput); setPage(1); }, 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  const query = useMemo(() => {
    const p = new URLSearchParams({ page: String(page) });
    if (search) p.set("search", search);
    if (roleId) p.set("role_id", roleId);
    if (status) p.set("status", status);
    return p.toString();
  }, [page, search, roleId, status]);

  const { data, error, reload } = useFetch<{ users: Paginator<UserRow>; roles: Ref[] }>(`/user-management/users?${query}`, [query]);

  const [editorFor, setEditorFor] = useState<number | "new" | null>(null);
  const [sheetFor, setSheetFor] = useState<UserRow | null>(null);
  const [resetFor, setResetFor] = useState<UserRow | null>(null);
  const [pinFor, setPinFor] = useState<UserRow | null>(null);
  const [confirm, setConfirm] = useState<{ user: UserRow; kind: StatusAction } | null>(null);

  // Deep link from the audit screen's "Edit" button.
  useEffect(() => {
    const id = (location.state as { editUserId?: number } | null)?.editUserId;
    if (id) {
      setEditorFor(id);
      nav(location.pathname, { replace: true, state: null });
    }
  }, [location, nav]);

  const users = data?.users.data ?? [];
  const roles = data?.roles ?? [];
  const canCreate = can("user_management_users.create");

  return (
    <div className="space-y-3">
      <h1 className="text-xl font-extrabold">User Management</h1>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[12rem] flex-1">
          <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !pl-9" placeholder="Search name or email…" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
        </div>
        <select className="input !w-auto" value={roleId} onChange={(e) => { setRoleId(e.target.value); setPage(1); }}>
          <option value="">All roles</option>
          {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
        </select>
        <select className="input !w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="inactive">Inactive</option>
        </select>
        {canCreate && <button className="btn-primary" onClick={() => setEditorFor("new")}><Plus size={16} /> New staff</button>}
      </div>

      <ErrorText error={error} />

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[640px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Name</th><th className="th">Roles</th><th className="th">Status</th><th className="th" /></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {users.map((u) => (
              <tr key={u.id} className="hover:bg-slate-50/60">
                <td className="td">
                  <div className="flex items-center gap-2.5">
                    <Avatar name={u.name} />
                    <div className="min-w-0">
                      <button
                        className="truncate font-semibold text-slate-800 hover:text-brand-700 disabled:hover:text-slate-800"
                        disabled={!can("user_management_users.view")}
                        onClick={() => nav(`/staff/users/${u.id}`)}
                      >
                        {u.name}
                      </button>
                      <div className="truncate text-xs text-slate-400">{u.email}</div>
                    </div>
                  </div>
                </td>
                <td className="td">
                  <div className="flex flex-wrap gap-1">
                    {u.roles.length ? u.roles.map((r) => <Badge key={r.id} color="brand">{r.name}</Badge>) : <span className="text-xs text-slate-400">—</span>}
                  </div>
                </td>
                <td className="td">
                  <div className="flex items-center gap-1.5">
                    <Badge color={statusColor(u.status)}>{u.status.toUpperCase()}</Badge>
                    {isLocked(u) && <span title="Temporarily locked (failed logins)" className="inline-flex items-center gap-0.5 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700"><Lock size={10} /> LOCKED</span>}
                  </div>
                </td>
                <td className="td text-right">
                  <button className="btn-ghost !p-1.5" onClick={() => setSheetFor(u)} aria-label="Actions"><MoreHorizontal size={18} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {users.length === 0 && <Empty text="No staff match your filters" />}
        {data && (
          <Pagination
            page={data.users.current_page}
            pageSize={data.users.per_page}
            total={data.users.total}
            onPage={setPage}
            onPageSize={() => {}}
          />
        )}
      </div>

      {sheetFor && (
        <UserActionsSheet
          user={sheetFor}
          onClose={() => setSheetFor(null)}
          onView={() => { nav(`/staff/users/${sheetFor.id}`); setSheetFor(null); }}
          onEdit={() => { setEditorFor(sheetFor.id); setSheetFor(null); }}
          onPin={() => { setPinFor(sheetFor); setSheetFor(null); }}
          onReset={() => { setResetFor(sheetFor); setSheetFor(null); }}
          onStatus={(kind) => { setConfirm({ user: sheetFor, kind }); setSheetFor(null); }}
        />
      )}

      {editorFor && (
        <UserEditor userId={editorFor === "new" ? null : editorFor} onClose={(changed) => { setEditorFor(null); if (changed) reload(); }} />
      )}

      {resetFor && <ResetPasswordModal user={resetFor} onClose={() => setResetFor(null)} />}
      {pinFor && <PinModal user={pinFor} onClose={() => setPinFor(null)} />}

      {confirm && (
        <StatusConfirm
          user={confirm.user}
          kind={confirm.kind}
          onClose={() => setConfirm(null)}
          onDone={() => { setConfirm(null); reload(); }}
        />
      )}
    </div>
  );
}

type StatusAction = "reactivate" | "suspend" | "deactivate" | "unlock" | "delete";

function UserActionsSheet({
  user, onClose, onView, onEdit, onPin, onReset, onStatus,
}: {
  user: UserRow; onClose: () => void; onView: () => void; onEdit: () => void; onPin: () => void;
  onReset: () => void; onStatus: (k: StatusAction) => void;
}) {
  const { can } = usePermissions();
  const canEdit = can("user_management_users.edit");
  const Item = ({ icon, label, onClick, danger }: { icon: ReactNode; label: string; onClick: () => void; danger?: boolean }) => (
    <button
      className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition hover:bg-slate-50 ${danger ? "text-red-600" : "text-slate-700"}`}
      onClick={onClick}
    >
      {icon} {label}
    </button>
  );

  return (
    <Modal open onClose={onClose} title={user.name}>
      <div className="-mx-1 flex flex-col">
        {can("user_management_users.view") && <Item icon={<Eye size={16} />} label="View permissions & audit" onClick={onView} />}
        {canEdit && <Item icon={<Pencil size={16} />} label="Edit user" onClick={onEdit} />}
        {can("hotel_staff.set_pin") && <Item icon={<KeyRound size={16} />} label="Set / clear POS PIN" onClick={onPin} />}
        {canEdit && user.status !== "active" && <Item icon={<UserCheck size={16} />} label="Activate" onClick={() => onStatus("reactivate")} />}
        {canEdit && user.status !== "suspended" && <Item icon={<Ban size={16} />} label="Suspend" onClick={() => onStatus("suspend")} />}
        {canEdit && user.status !== "inactive" && <Item icon={<UserX size={16} />} label="Deactivate" onClick={() => onStatus("deactivate")} />}
        {can("user_management_users.unlock") && isLocked(user) && <Item icon={<Unlock size={16} />} label="Unlock account" onClick={() => onStatus("unlock")} />}
        {can("user_management_users.reset_password") && <Item icon={<KeyRound size={16} />} label="Reset password" onClick={onReset} />}
        {can("user_management_users.delete") && <Item icon={<Trash2 size={16} />} label="Delete user" onClick={() => onStatus("delete")} danger />}
      </div>
    </Modal>
  );
}

const STATUS_COPY: Record<StatusAction, { title: string; label: string; body: string; danger?: boolean; path: (id: number) => string }> = {
  reactivate: { title: "Activate user", label: "Activate", body: "Restore this user's access. They will be able to sign in again.", path: (id) => `/user-management/users/${id}/reactivate` },
  suspend: { title: "Suspend user", label: "Suspend", body: "Suspend this user. Their sessions keep failing permission checks until reactivated.", path: (id) => `/user-management/users/${id}/suspend` },
  deactivate: { title: "Deactivate user", label: "Deactivate", body: "Set this user to inactive. Use this for staff who have left.", path: (id) => `/user-management/users/${id}/deactivate` },
  unlock: { title: "Unlock account", label: "Unlock", body: "Clear the temporary lockout and reset the failed-login counter.", path: (id) => `/user-management/users/${id}/unlock` },
  delete: { title: "Delete user", label: "Delete", body: "Soft-delete this user. This can be undone by an administrator via the database.", danger: true, path: (id) => `/user-management/users/${id}` },
};

function StatusConfirm({ user, kind, onClose, onDone }: { user: UserRow; kind: StatusAction; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const copy = STATUS_COPY[kind];

  const run = async () => {
    setBusy(true);
    try {
      const path = copy.path(user.id);
      const res = kind === "delete" ? await api<{ message: string }>(path, { method: "DELETE" }) : await post<{ message: string }>(path);
      toast.success(res.message ?? "Done");
      onDone();
    } catch (e) {
      toast.error("Action failed", (e as Error).message);
      onClose();
    } finally {
      setBusy(false);
    }
  };

  return (
    <ConfirmDialog
      open
      title={copy.title}
      message={<><span className="font-semibold text-slate-700">{user.name}</span> — {copy.body}</>}
      confirmLabel={copy.label}
      tone={copy.danger ? "danger" : "brand"}
      busy={busy}
      onConfirm={run}
      onClose={onClose}
    />
  );
}

function ResetPasswordModal({ user, onClose }: { user: UserRow; onClose: () => void }) {
  const toast = useToast();
  const [pw, setPw] = useState("");
  const [confirmPw, setConfirmPw] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true); setError("");
    try {
      const res = await post<{ message: string }>(`/user-management/users/${user.id}/reset-password`, { password: pw, password_confirmation: confirmPw });
      toast.success(res.message ?? "Password reset", `${user.name} must set a new password at next login.`);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`Reset password — ${user.name}`}>
      <div className="space-y-3">
        <Field label="New password" hint="Min 12 chars, upper + lower case, a number. The user is forced to change it at next login.">
          <input className="input" type="password" value={pw} onChange={(e) => setPw(e.target.value)} autoFocus />
        </Field>
        <Field label="Confirm password">
          <input className="input" type="password" value={confirmPw} onChange={(e) => setConfirmPw(e.target.value)} />
        </Field>
        <ErrorText error={error} />
        <button className="btn-primary w-full" disabled={busy || pw.length < 12 || pw !== confirmPw} onClick={save}>
          {busy ? "Resetting…" : "Reset password"}
        </button>
      </div>
    </Modal>
  );
}

// ── User editor (multi-role + permission overrides) ──────────────────────────

type RoleOption = { id: number; name: string; is_full_admin: boolean; description: string | null };
type UserFormData = {
  matrix: MatrixSection[];
  roles: RoleOption[];
  rolePermissions: Record<number, string[]>;
  warehouses: Ref[];
  grantable_permissions: string[] | null;
  is_full_admin: boolean;
  user?: {
    id: number; name: string; email: string; phone: string | null; status: string;
    role_ids: number[]; two_factor_required: boolean; permissions: string[]; warehouse_ids: number[];
  };
};

function UserEditor({ userId, onClose }: { userId: number | null; onClose: (changed: boolean) => void }) {
  const { data, error } = useFetch<UserFormData>(userId ? `/user-management/users/${userId}/edit` : "/user-management/users/create", [userId]);

  return (
    <Modal open onClose={() => onClose(false)} title={userId ? "Edit staff member" : "New staff member"} wide>
      {error && <ErrorText error={error} />}
      {data ? <UserEditorForm data={data} userId={userId} onClose={onClose} /> : !error && <div className="py-10 text-center text-sm text-slate-400">Loading…</div>}
    </Modal>
  );
}

function UserEditorForm({ data, userId, onClose }: { data: UserFormData; userId: number | null; onClose: (changed: boolean) => void }) {
  const toast = useToast();
  const u = data.user;
  const rolePermissions = data.rolePermissions;

  const [name, setName] = useState(u?.name ?? "");
  const [email, setEmail] = useState(u?.email ?? "");
  const [phone, setPhone] = useState(u?.phone ?? "");
  const [statusValue, setStatusValue] = useState(u?.status ?? "active");
  const [password, setPassword] = useState("");
  const [confirmPw, setConfirmPw] = useState("");
  const [roleIds, setRoleIds] = useState<number[]>(u?.role_ids ?? []);
  const [warehouseIds, setWarehouseIds] = useState<number[]>(u?.warehouse_ids ?? []);
  const [twoFactorRequired, setTwoFactorRequired] = useState(u?.two_factor_required ?? false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const baseline = useMemo(() => new Set(roleIds.flatMap((id) => rolePermissions[id] ?? [])), [roleIds, rolePermissions]);

  // Explicit per-user deviations from the role baseline (mirrors the backend's
  // allow/deny override model), preserved as roles are toggled.
  const [allow, setAllow] = useState<Set<string>>(() => new Set((u?.permissions ?? []).filter((p) => !new Set((u?.role_ids ?? []).flatMap((id) => rolePermissions[id] ?? [])).has(p))));
  const [deny, setDeny] = useState<Set<string>>(() => {
    const base = new Set((u?.role_ids ?? []).flatMap((id) => rolePermissions[id] ?? []));
    const eff = new Set(u?.permissions ?? []);
    return new Set([...base].filter((p) => !eff.has(p)));
  });

  const effective = useMemo(() => {
    const set = new Set(baseline);
    for (const a of allow) set.add(a);
    for (const d of deny) set.delete(d);
    return [...set];
  }, [baseline, allow, deny]);

  const onMatrixChange = (next: string[]) => {
    const nextSet = new Set(next);
    setAllow(new Set(next.filter((n) => !baseline.has(n))));
    setDeny(new Set([...baseline].filter((n) => !nextSet.has(n))));
  };

  const selectedFullAdmin = roleIds.some((id) => data.roles.find((r) => r.id === id)?.is_full_admin);

  const toggleRole = (id: number) => setRoleIds((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));
  const toggleWarehouse = (id: number) => setWarehouseIds((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));

  const save = async () => {
    setBusy(true); setError("");
    const body: Record<string, unknown> = {
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim() || undefined,
      status: statusValue,
      role_ids: roleIds,
      permissions: effective,
      warehouse_ids: warehouseIds,
      two_factor_required: twoFactorRequired,
    };
    if (password) { body.password = password; body.password_confirmation = confirmPw; }
    try {
      const res = userId
        ? await put<{ message: string }>(`/user-management/users/${userId}`, body)
        : await post<{ message: string }>("/user-management/users", body);
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
        <Field label="Name *"><input className="input" value={name} onChange={(e) => setName(e.target.value)} autoFocus /></Field>
        <Field label="Email *"><input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
        <Field label="Phone"><input className="input" value={phone} onChange={(e) => setPhone(e.target.value)} /></Field>
        <Field label="Status">
          <select className="input" value={statusValue} onChange={(e) => setStatusValue(e.target.value)}>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
        <Field label={userId ? "New password (blank = keep current)" : "Password *"} hint="Min 12 chars, upper + lower case, a number.">
          <input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        <Field label="Confirm password">
          <input className="input" type="password" value={confirmPw} onChange={(e) => setConfirmPw(e.target.value)} />
        </Field>
      </div>

      <div>
        <div className="label">Roles</div>
        <div className="flex flex-wrap gap-1.5">
          {data.roles.map((r) => (
            <button
              key={r.id}
              type="button"
              title={r.description ?? undefined}
              onClick={() => toggleRole(r.id)}
              className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition ${roleIds.includes(r.id) ? "border-brand-500 bg-brand-50 text-brand-700" : "border-slate-200 bg-white text-slate-500 hover:border-slate-300"}`}
            >
              {r.is_full_admin && <ShieldCheck size={12} />}
              {r.name}
            </button>
          ))}
          {data.roles.length === 0 && <span className="text-xs text-slate-400">No assignable roles.</span>}
        </div>
      </div>

      {data.warehouses.length > 0 && (
        <div>
          <div className="label">Branch access <span className="font-normal normal-case text-slate-400">(none = all branches)</span></div>
          <div className="flex flex-wrap gap-1.5">
            {data.warehouses.map((w) => (
              <button
                key={w.id}
                type="button"
                onClick={() => toggleWarehouse(w.id)}
                className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${warehouseIds.includes(w.id) ? "border-brand-500 bg-brand-50 text-brand-700" : "border-slate-200 bg-white text-slate-500 hover:border-slate-300"}`}
              >
                {w.name}
              </button>
            ))}
          </div>
        </div>
      )}

      <label className="flex items-center gap-2 text-sm text-slate-600">
        <input type="checkbox" className="h-4 w-4 rounded border-slate-300" checked={twoFactorRequired} onChange={(e) => setTwoFactorRequired(e.target.checked)} />
        Require two-factor authentication for this user
      </label>

      <div>
        <div className="mb-1.5 flex items-center justify-between">
          <div className="label !mb-0">Permissions <span className="font-normal normal-case text-slate-400">— roles set the baseline; toggles below add/remove per-user overrides</span></div>
          {!selectedFullAdmin && <span className="text-xs text-slate-400">{effective.length} effective</span>}
        </div>
        {selectedFullAdmin ? (
          <div className="rounded-xl border border-brand-500/40 bg-brand-50 p-4 text-sm text-brand-700">
            <div className="flex items-center gap-2 font-semibold"><ShieldCheck size={16} /> Full Administrator</div>
            <p className="mt-1 text-brand-700/80">A full-admin role bypasses every permission check — the matrix below does not apply.</p>
          </div>
        ) : (
          <PermissionMatrix matrix={data.matrix} value={effective} onChange={onMatrixChange} grantable={data.grantable_permissions} />
        )}
      </div>

      <ErrorText error={error} />
      <button
        className="btn-primary w-full"
        disabled={busy || !name.trim() || !email.trim() || (!userId && !password)}
        onClick={save}
      >
        {busy ? "Saving…" : userId ? "Save changes" : "Create staff member"}
      </button>
    </div>
  );
}

// ── PIN quick-unlock (shared) ────────────────────────────────────────────────

function PinModal({ user, onClose }: { user: { id: number; name: string }; onClose: () => void }) {
  const toast = useToast();
  const [pin, setPin] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (value: string | null) => {
    setBusy(true); setError("");
    try {
      await put(`/staff/${user.id}/pin`, { pin: value });
      toast.success(value ? "PIN set" : "PIN cleared", user.name);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={`${user.name} — POS PIN`}>
      <Field label="New 4–6 digit PIN" hint="Used for quick sign-in on shared POS terminals.">
        <input className="input" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, "").slice(0, 6))} placeholder="e.g. 4821" />
      </Field>
      <ErrorText error={error} />
      <div className="mt-4 flex gap-2">
        <button className="btn-primary flex-1" disabled={busy || pin.length < 4} onClick={() => submit(pin)}>Set PIN</button>
        <button className="btn-secondary flex-1" disabled={busy} onClick={() => submit(null)}>Clear PIN</button>
      </div>
    </Modal>
  );
}

// ── PIN-only fallback (roles with hotel_staff.set_pin but not User Management) ─

function PinOnlyList() {
  const { data } = useFetch<{ staff: StaffLite[] }>("/staff");
  const [pinFor, setPinFor] = useState<StaffLite | null>(null);
  const staff = data?.staff ?? [];

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-extrabold">Staff — POS PINs</h1>
      <p className="text-xs text-slate-500">Set or clear a staff member's quick-unlock PIN for shared POS terminals. Full account management lives with your Manager.</p>
      <div className="card divide-y divide-slate-50">
        {staff.map((s) => (
          <div key={s.id} className="flex items-center justify-between px-4 py-2.5">
            <div>
              <div className="text-sm font-semibold">{s.name}</div>
              <div className="text-xs text-slate-400">{s.roles.map((r) => r.name).join(", ")}</div>
            </div>
            <button className="btn-secondary !py-1 text-xs" onClick={() => setPinFor(s)}><KeyRound size={12} /> PIN</button>
          </div>
        ))}
        {staff.length === 0 && <Empty text="No staff" />}
      </div>
      {pinFor && <PinModal user={pinFor} onClose={() => setPinFor(null)} />}
    </div>
  );
}
