import { ReactNode } from "react";
import { NavLink } from "react-router-dom";
import clsx from "clsx";

/** Account settings sub-navigation — mirrors leolanka-inertia's settings/layout. */
const NAV: { to: string; label: string; end?: boolean }[] = [
  { to: "/account", label: "Profile", end: true },
  { to: "/account/password", label: "Password" },
  { to: "/account/two-factor", label: "Two-factor auth" },
];

/**
 * The shell every account page renders inside: a "Settings" heading and a left
 * sub-nav, with the page's own sections on the right.
 */
export default function AccountLayout({ children }: { children: ReactNode }) {
  return (
    <div className="mx-auto max-w-5xl">
      <div>
        <h1 className="text-xl font-black tracking-tight text-slate-900">Settings</h1>
        <p className="mt-1 text-sm text-slate-500">Manage your profile and account settings</p>
      </div>

      <div className="mt-6 flex flex-col gap-8 lg:flex-row lg:gap-12">
        <aside className="shrink-0 lg:w-52">
          <nav className="flex gap-1 overflow-x-auto lg:flex-col">
            {NAV.map((n) => (
              <NavLink
                key={n.to}
                to={n.to}
                end={n.end}
                className={({ isActive }) =>
                  clsx(
                    "whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition",
                    isActive ? "bg-brand-50 text-brand-700" : "text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                  )
                }
              >
                {n.label}
              </NavLink>
            ))}
          </nav>
        </aside>

        <div className="min-w-0 flex-1 lg:max-w-2xl">
          <div className="space-y-10">{children}</div>
        </div>
      </div>
    </div>
  );
}

/** A titled section within an account page. */
export function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <section className="space-y-4">
      <div>
        <h2 className="text-base font-bold text-slate-900">{title}</h2>
        {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
      </div>
      {children}
    </section>
  );
}
