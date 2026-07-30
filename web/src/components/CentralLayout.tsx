import { ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { ShieldCheck, LogOut } from "lucide-react";
import { useCentralAuth } from "../lib/centralAuth";

/** Minimal chrome for the "master control" panel — deliberately plain, distinct from any tenant's own branded Layout. */
export default function CentralLayout({ children }: { children: ReactNode }) {
  const { admin, logout } = useCentralAuth();
  const nav = useNavigate();

  return (
    <div className="min-h-screen bg-slate-100">
      <header className="flex items-center justify-between bg-slate-950 px-5 py-3 text-white">
        <Link to="/central/tenants" className="flex items-center gap-2 font-black">
          <ShieldCheck size={20} className="text-brand-400" /> Master Control
        </Link>
        <div className="flex items-center gap-3 text-sm">
          {admin && <span className="text-slate-300">{admin.name}</span>}
          <button
            className="flex items-center gap-1 rounded-lg bg-slate-800 px-3 py-1.5 font-semibold hover:bg-slate-700"
            onClick={() => logout().then(() => nav("/central/login"))}
          >
            <LogOut size={14} /> Sign out
          </button>
        </div>
      </header>
      <main className="mx-auto max-w-6xl p-5">{children}</main>
    </div>
  );
}
