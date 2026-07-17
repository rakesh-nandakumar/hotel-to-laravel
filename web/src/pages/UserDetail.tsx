import { useParams, Link, useNavigate } from "react-router-dom";
import { ArrowLeft, ShieldBan, Pencil } from "lucide-react";
import { useFetch, fmtDateTime } from "../lib/util";
import { Avatar, Badge, Card, Empty, ErrorText, statusColor } from "../components/ui";
import { usePermissions } from "../lib/auth";

type Ref = { id: number; name: string };

type PermissionSources = {
  /** permission name → role names that grant it */
  roles: Record<string, string[]>;
  allow: string[];
  deny: string[];
  effective: string[];
};

type ShowResponse = {
  user: {
    id: number; name: string; email: string; phone: string | null; status: string;
    last_login_at: string | null; roles: Ref[]; warehouses: Ref[];
  };
  permission_sources: PermissionSources;
};

/** module_key of "hotel_orders.checkout" → "hotel_orders". */
const moduleOf = (name: string) => name.slice(0, name.lastIndexOf("."));
const actionOf = (name: string) => name.slice(name.lastIndexOf(".") + 1);

/**
 * Read-only audit view for one user — the *effective* permission set paired
 * with each permission's provenance (which role granted it, or a direct allow),
 * plus any explicit deny exceptions that override the user's roles.
 */
export default function UserDetail() {
  const { id } = useParams();
  const nav = useNavigate();
  const { can } = usePermissions();
  const { data, error } = useFetch<ShowResponse>(`/user-management/users/${id}`, [id]);

  if (error) {
    return (
      <div className="space-y-4">
        <BackLink />
        <ErrorText error={error} />
      </div>
    );
  }
  if (!data) return <div className="py-16 text-center text-sm text-slate-400">Loading…</div>;

  const { user, permission_sources: sources } = data;

  // Group the effective set by module for a scannable layout.
  const byModule = new Map<string, string[]>();
  for (const name of [...sources.effective].sort()) {
    const key = moduleOf(name);
    byModule.set(key, [...(byModule.get(key) ?? []), name]);
  }
  const allowSet = new Set(sources.allow);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <BackLink />
        {can("user_management_users.edit") && (
          <Link to="/staff" onClick={(e) => { e.preventDefault(); nav("/staff", { state: { editUserId: user.id } }); }} className="btn-secondary !py-1.5 text-xs">
            <Pencil size={13} /> Edit
          </Link>
        )}
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-4">
          <Avatar name={user.name} size={56} />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl font-extrabold">{user.name}</h1>
              <Badge color={statusColor(user.status)}>{user.status.toUpperCase()}</Badge>
            </div>
            <div className="mt-0.5 text-sm text-slate-500">{user.email}{user.phone ? ` · ${user.phone}` : ""}</div>
            <div className="mt-0.5 text-xs text-slate-400">Last login: {fmtDateTime(user.last_login_at)}</div>
          </div>
        </div>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <div className="label">Roles</div>
            <div className="flex flex-wrap gap-1">
              {user.roles.length ? user.roles.map((r) => <Badge key={r.id} color="brand">{r.name}</Badge>) : <span className="text-sm text-slate-400">None</span>}
            </div>
          </div>
          <div>
            <div className="label">Branch access</div>
            <div className="flex flex-wrap gap-1">
              {user.warehouses.length ? user.warehouses.map((w) => <Badge key={w.id}>{w.name}</Badge>) : <span className="text-sm text-slate-400">All / unrestricted</span>}
            </div>
          </div>
        </div>
      </Card>

      {sources.deny.length > 0 && (
        <div className="rounded-xl border border-red-200 bg-red-50/60 p-4">
          <div className="flex items-center gap-2 text-sm font-bold text-red-700">
            <ShieldBan size={16} /> Explicit deny exceptions ({sources.deny.length})
          </div>
          <p className="mt-1 text-xs text-red-600/80">Granted by an assigned role but manually revoked for this user — these are <em>not</em> effective.</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {[...sources.deny].sort().map((name) => (
              <span key={name} className="inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-white px-2.5 py-1 text-xs">
                <span className="font-mono text-red-700 line-through">{name}</span>
                {sources.roles[name]?.length ? <span className="text-red-400">from {sources.roles[name].join(", ")}</span> : null}
              </span>
            ))}
          </div>
        </div>
      )}

      <Card title={`Effective permissions (${sources.effective.length})`}>
        {sources.effective.length === 0 ? (
          <Empty text="This user has no effective permissions." />
        ) : (
          <div className="space-y-4">
            {[...byModule.entries()].map(([mod, names]) => (
              <div key={mod}>
                <div className="mb-1.5 font-mono text-xs font-bold uppercase tracking-wide text-slate-400">{mod}</div>
                <div className="overflow-hidden rounded-lg border border-slate-100">
                  <table className="w-full">
                    <tbody className="divide-y divide-slate-50">
                      {names.map((name) => {
                        const viaRoles = sources.roles[name] ?? [];
                        const viaAllow = allowSet.has(name);
                        return (
                          <tr key={name}>
                            <td className="td font-mono text-xs text-slate-700">{actionOf(name)}</td>
                            <td className="td text-right">
                              <div className="flex flex-wrap justify-end gap-1">
                                {viaRoles.map((r) => <Badge key={r} color="brand">{r}</Badge>)}
                                {viaAllow && <Badge color="green">Direct allow</Badge>}
                                {viaRoles.length === 0 && !viaAllow && <span className="text-xs text-slate-400">—</span>}
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

function BackLink() {
  return (
    <Link to="/staff" className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800">
      <ArrowLeft size={16} /> Back to Staff &amp; Access
    </Link>
  );
}
