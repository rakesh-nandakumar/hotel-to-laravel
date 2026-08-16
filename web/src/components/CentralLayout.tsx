import { ReactNode } from "react";
import { Link, NavLink, useNavigate } from "react-router-dom";
import { LayoutDashboard, ShieldCheck, Users, LogOut } from "lucide-react";
import { useCentralAuth } from "../lib/centralAuth";

const NAV = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard, end: true },
  { to: "/tenants", label: "Tenants", icon: ShieldCheck },
  { to: "/admins", label: "Operators", icon: Users },
];

/** Minimal chrome for the "master control" panel — deliberately plain, distinct from any tenant's own branded Layout. */
export default function CentralLayout({ children }: { children: ReactNode }) {
  const { admin, logout } = useCentralAuth();
  const nav = useNavigate();

  return (
    <div className="min-h-screen bg-slate-100">
      <header className="flex items-center justify-between bg-slate-950 px-5 py-3 text-white">
        <Link to="/" className="flex items-center gap-2 font-black">
          <ShieldCheck size={20} className="text-brand-400" /> Master Control
        </Link>
        <nav className="flex items-center gap-1">
          {NAV.map(({ to, label, icon: Icon, end }) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                `flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold ${isActive ? "bg-slate-800 text-white" : "text-slate-300 hover:bg-slate-800 hover:text-white"}`
              }
            >
              <Icon size={14} /> {label}
            </NavLink>
          ))}
        </nav>
        <div className="flex items-center gap-3 text-sm">
          {admin && <span className="text-slate-300">{admin.name}</span>}
          <button
            className="flex items-center gap-1 rounded-lg bg-slate-800 px-3 py-1.5 font-semibold hover:bg-slate-700"
            onClick={() => logout().then(() => nav("/login"))}
          >
            <LogOut size={14} /> Sign out
          </button>
        </div>
      </header>
      <main className="mx-auto max-w-6xl p-5">{children}</main>
    </div>
  );
}
