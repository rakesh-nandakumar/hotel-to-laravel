import { useState } from "react";
import { Plus, KeyRound } from "lucide-react";
import { post, put } from "../lib/api";
import { useFetch } from "../lib/util";
import { Badge, Empty, ErrorText, Field, Modal, Pagination, statusColor } from "../components/ui";
import { useAuth } from "../lib/auth";

type Role = { id: number; name: string };
type StaffLite = { id: number; name: string; roles: Role[] };
type FullUser = { id: number; name: string; email: string; phone: string | null; status: string; roles: Role[] };
type Paginator<T> = { data: T[]; current_page: number; per_page: number; total: number };

/**
 * "Staff & Access" — full CRUD (name/email/phone/status/role) is Phase 1's
 * User Management module underneath (multi-role RBAC), gated on
 * `user_management_users.access`; PIN quick-unlock is this module's own
 * `hotel_staff.set_pin`. Some roles (e.g. Owner) hold only the PIN
 * permission, so this page degrades to a lightweight PIN-only picker for them
 * rather than being unreachable.
 */
export default function StaffPage() {
  const { can } = useAuth();
  return can("user_management_users.access") ? <FullStaffManagement /> : <PinOnlyList />;
}

// ── Full management (Manager / anyone with user_management_users.access) ──────
function FullStaffManagement() {
  const { can } = useAuth();
  const canCreate = can("user_management_users.create");
  const canEdit = can("user_management_users.edit");
  const canSetPin = can("hotel_staff.set_pin");
  const [page, setPage] = useState(1);
  const { data, reload } = useFetch<{ users: Paginator<FullUser>; roles: Role[] }>(`/user-management/users?page=${page}`, [page]);
  const [edit, setEdit] = useState<FullUser | "new" | null>(null);
  const [pinFor, setPinFor] = useState<FullUser | null>(null);

  const users = data?.users.data ?? [];
  const roles = data?.roles ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-extrabold">Staff & Access</h1>
        {canCreate && <button className="btn-primary" onClick={() => setEdit("new")}><Plus size={16} /> New staff</button>}
      </div>
      <p className="text-xs text-slate-500">Every staff member gets an individual login + optional 4–6 digit POS PIN. Role-based access is enforced on the server for every action.</p>
      <div className="card overflow-x-auto">
        <table className="w-full min-w-[560px]">
          <thead className="border-b border-slate-100">
            <tr><th className="th">Name</th><th className="th">Email</th><th className="th">Role</th><th className="th">Status</th><th className="th" /></tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {users.map((s) => (
              <tr key={s.id}>
                <td className="td font-semibold">{s.name}</td>
                <td className="td text-xs">{s.email}</td>
                <td className="td">{s.roles.map((r) => <Badge key={r.id} color="brand">{r.name}</Badge>)}</td>
                <td className="td"><Badge color={statusColor(s.status)}>{s.status.toUpperCase()}</Badge></td>
                <td className="td text-right">
                  <div className="flex justify-end gap-1.5">
                    {canSetPin && <button className="btn-secondary !py-1 text-xs" onClick={() => setPinFor(s)}><KeyRound size={12} /> PIN</button>}
                    {canEdit && <button className="btn-secondary !py-1 text-xs" onClick={() => setEdit(s)}>Edit</button>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {users.length === 0 && <Empty text="No staff" />}
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

      {edit && <StaffEditor user={edit === "new" ? null : edit} roles={roles} onClose={() => { setEdit(null); reload(); }} />}
      {pinFor && <PinModal user={pinFor} onClose={() => { setPinFor(null); reload(); }} />}
    </div>
  );
}

function StaffEditor({ user, roles, onClose }: { user: FullUser | null; roles: Role[]; onClose: () => void }) {
  const [f, setF] = useState({
    name: user?.name ?? "",
    email: user?.email ?? "",
    phone: user?.phone ?? "",
    status: user?.status ?? "active",
    roleId: user?.roles[0] ? String(user.roles[0].id) : roles[0] ? String(roles[0].id) : "",
    password: "",
    passwordConfirmation: "",
  });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    setError("");
    const body: Record<string, unknown> = {
      name: f.name.trim(),
      email: f.email.trim(),
      phone: f.phone.trim() || undefined,
      status: f.status,
      role_ids: f.roleId ? [Number(f.roleId)] : [],
    };
    if (f.password) {
      body.password = f.password;
      body.password_confirmation = f.passwordConfirmation;
    }
    try {
      if (user) await put(`/user-management/users/${user.id}`, body);
      else await post("/user-management/users", body);
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open onClose={onClose} title={user ? `Edit ${user.name}` : "New staff member"}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name *"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus /></Field>
        <Field label="Email *"><input className="input" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="Phone"><input className="input" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        <Field label="Role">
          <select className="input" value={f.roleId} onChange={(e) => setF({ ...f, roleId: e.target.value })}>
            {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
        </Field>
        <Field label="Status">
          <select className="input" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
      </div>
      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <Field label={user ? "New password (leave blank to keep current)" : "Password *"} hint="Min 12 chars, upper + lower case, a number.">
          <input className="input" type="password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
        </Field>
        <Field label="Confirm password">
          <input className="input" type="password" value={f.passwordConfirmation} onChange={(e) => setF({ ...f, passwordConfirmation: e.target.value })} />
        </Field>
      </div>
      <ErrorText error={error} />
      <button className="btn-primary mt-4 w-full" disabled={busy || !f.name.trim() || !f.email.trim() || (!user && !f.password)} onClick={save}>
        {busy ? "Saving…" : user ? "Save staff member" : "Create staff member"}
      </button>
    </Modal>
  );
}

function PinModal({ user, onClose }: { user: FullUser | StaffLite; onClose: () => void }) {
  const [pin, setPin] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (value: string | null) => {
    setBusy(true);
    setError("");
    try {
      await put(`/staff/${user.id}/pin`, { pin: value });
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

// ── PIN-only fallback (roles that hold hotel_staff.set_pin but not User Management access) ──
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
