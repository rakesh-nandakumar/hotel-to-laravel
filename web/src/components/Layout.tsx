import { ReactNode, useEffect, useState } from "react";
import { NavLink, useLocation, useNavigate } from "react-router-dom";
import {
  LayoutDashboard, BedDouble, CalendarDays, CalendarRange, UtensilsCrossed, ChefHat, ClipboardList,
  Wrench, Users, Building2, Clock4, Banknote, BarChart3, Settings as SettingsIcon,
  PartyPopper, Contact, LogOut, Menu as MenuIcon, X, WifiOff, ShieldCheck, Bell, Package, Sparkles, Plug, Shirt, Wallet, History,
} from "lucide-react";
import { useAuth } from "../lib/auth";
import { onQueueChange, queuedCount, flushQueue } from "../lib/offline";
import Clock from "./Clock";
import GlobalRealtimeNotifications from "./GlobalRealtimeNotifications";
import NotificationBell from "./NotificationBell";
import clsx from "clsx";

/** `permission` omitted means "visible to any authenticated user" (e.g. Attendance); an array means "any of". */
type Item = { to: string; label: string; icon: ReactNode; permission?: string | string[]; fullAdminOnly?: boolean };
type Section = { title: string; items: Item[] };

const SECTIONS: Section[] = [
  {
    title: "Overview",
    items: [{ to: "/", label: "Dashboard", icon: <LayoutDashboard size={18} />, permission: "dashboard.access" }],
  },
  {
    title: "Front Desk",
    items: [
      { to: "/reservations", label: "Reservations", icon: <CalendarDays size={18} />, permission: "hotel_reservations.access" },
      { to: "/calendar", label: "Calendar", icon: <CalendarRange size={18} />, permission: "hotel_reservations.access" },
      { to: "/rooms", label: "Rooms", icon: <BedDouble size={18} />, permission: "hotel_rooms.access" },
      { to: "/guests", label: "Guests", icon: <Contact size={18} />, permission: "hotel_guests.access" },
    ],
  },
  {
    title: "Restaurant",
    items: [
      { to: "/pos", label: "POS", icon: <UtensilsCrossed size={18} />, permission: "hotel_orders.access" },
      { to: "/kot", label: "Kitchen (KOT)", icon: <ChefHat size={18} />, permission: "hotel_orders.access" },
      { to: "/menu", label: "Menu", icon: <ClipboardList size={18} />, permission: "hotel_menu_items.access" },
      { to: "/inventory", label: "Inventory", icon: <Package size={18} />, permission: "hotel_ingredients.access" },
    ],
  },
  {
    title: "Events",
    items: [{ to: "/venues", label: "Venues", icon: <PartyPopper size={18} />, permission: "hotel_venues.access" }],
  },
  {
    title: "Operations",
    items: [
      { to: "/housekeeping", label: "Housekeeping", icon: <Sparkles size={18} />, permission: "hotel_housekeeping.access" },
      { to: "/laundry", label: "Laundry", icon: <Shirt size={18} />, permission: "hotel_laundry.access" },
      { to: "/maintenance", label: "Maintenance", icon: <Wrench size={18} />, permission: "hotel_maintenance.access" },
      { to: "/visitors", label: "Visitor Log", icon: <ShieldCheck size={18} />, permission: "hotel_visitors.access" },
      { to: "/attendance", label: "Attendance", icon: <Clock4 size={18} />, permission: "hotel_attendance.access" },
    ],
  },
  {
    title: "Money & Admin",
    items: [
      { to: "/shifts", label: "Cash / Shifts", icon: <Banknote size={18} />, permission: "hotel_shifts.access" },
      { to: "/corporate", label: "Corporate", icon: <Building2 size={18} />, permission: "hotel_corporate.access" },
      { to: "/reports", label: "Reports", icon: <BarChart3 size={18} />, permission: "hotel_reports.dashboard" },
      { to: "/notifications", label: "Notifications", icon: <Bell size={18} />, permission: "hotel_notifications.access" },
      // Payroll is Owner-only server-side (Managers never see salaries).
      { to: "/payroll", label: "Payroll", icon: <Wallet size={18} />, permission: "hotel_payroll.view" },
      { to: "/settings", label: "Settings", icon: <SettingsIcon size={18} />, permission: "hotel_settings.access" },
    ],
  },
  {
    title: "Administration",
    items: [
      // Owners who only set POS PINs (hotel_staff.set_pin) still reach this — the
      // page degrades to a PIN-only picker for them.
      { to: "/staff", label: "User Management", icon: <Users size={18} />, permission: ["user_management_users.access", "hotel_staff.set_pin"] },
      { to: "/roles", label: "Roles & Permissions", icon: <ShieldCheck size={18} />, permission: "user_management_roles.access" },
      { to: "/audit-log", label: "Audit Log", icon: <History size={18} />, permission: "audit_logs.access" },
    ],
  },
  {
    title: "System Admin",
    items: [
      // Integrations settings — Full Administrator only, matching the backend's
      // hard admin-only gate on the "integrations" settings category.
      { to: "/integrations", label: "Integrations", icon: <Plug size={18} />, fullAdminOnly: true },
    ],
  },
];

export default function Layout({ children }: { children: ReactNode }) {
  const { me, can, logout } = useAuth();
  const nav = useNavigate();
  const { pathname } = useLocation();
  // Kitchen Display System needs the full screen width/height — no centering, no padding.
  const isKotBoard = pathname === "/kot";
  const [open, setOpen] = useState(false);
  const [online, setOnline] = useState(navigator.onLine);
  const [queued, setQueued] = useState(0);

  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener("online", on);
    window.addEventListener("offline", off);
    const update = () => queuedCount().then(setQueued);
    update();
    const unsub = onQueueChange(update);
    return () => {
      window.removeEventListener("online", on);
      window.removeEventListener("offline", off);
      unsub();
    };
  }, []);

  if (!me) return null;
  const { user } = me;
  const visible = (items: Item[]) =>
    items.filter((i) => {
      if (i.fullAdminOnly) return me.is_full_admin;
      if (!i.permission) return true;
      return Array.isArray(i.permission) ? i.permission.some(can) : can(i.permission);
    });

  const sidebar = (
    <nav className="flex h-full flex-col">
      <div className="px-4 py-4">
        <div className="text-lg font-black leading-tight text-white">Mount View</div>
        <div className="text-[11px] font-medium uppercase tracking-widest text-brand-100/70">Hotel · Badulla</div>
      </div>
      <div className="flex-1 overflow-y-auto px-2 pb-4">
        {SECTIONS.map((section) => {
          const items = visible(section.items);
          if (items.length === 0) return null;
          return (
            <div key={section.title} className="mb-3">
              <div className="px-3 pb-1 pt-2 text-[9px] font-black uppercase tracking-[0.18em] text-brand-100/40">{section.title}</div>
              <div className="space-y-0.5">
                {items.map((i) => (
                  <NavLink
                    key={i.to}
                    to={i.to}
                    end={i.to === "/"}
                    onClick={() => setOpen(false)}
                    className={({ isActive }) =>
                      clsx(
                        "flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all",
                        isActive
                          ? "bg-white/15 text-white shadow-sm"
                          : "text-brand-100/80 hover:translate-x-0.5 hover:bg-white/10 hover:text-white"
                      )
                    }
                  >
                    {i.icon}
                    {i.label}
                  </NavLink>
                ))}
              </div>
            </div>
          );
        })}
      </div>
      <div className="border-t border-white/10 p-3">
        <div className="mb-2 px-1">
          <div className="text-sm font-bold text-white">{user.name}</div>
          <div className="text-[11px] uppercase tracking-wide text-brand-100/70">{me.is_full_admin ? "Full Administrator" : user.role?.name ?? "Staff"}</div>
        </div>
        <div className="flex gap-1.5">
          <button
            className="btn flex-1 bg-white/10 text-white hover:bg-white/20"
            onClick={async () => {
              await logout();
              nav("/login");
            }}
          >
            <LogOut size={15} /> Sign out
          </button>
          <button className="btn bg-white/10 text-white hover:bg-white/20" title="Switch staff (PIN)" onClick={() => nav("/login")}>
            PIN
          </button>
        </div>
      </div>
    </nav>
  );

  return (
    <div className="flex min-h-screen">
      <GlobalRealtimeNotifications />
      <aside className="hidden w-60 shrink-0 bg-gradient-to-b from-brand-900 to-[#062019] lg:block">{sidebar}</aside>
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={() => setOpen(false)} />
          <aside className="absolute left-0 top-0 h-full w-64 bg-gradient-to-b from-brand-900 to-[#062019] shadow-2xl">{sidebar}</aside>
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-2 backdrop-blur lg:px-6">
          <button className="btn-ghost !p-1.5 lg:hidden" onClick={() => setOpen(!open)}>
            {open ? <X size={20} /> : <MenuIcon size={20} />}
          </button>
          <div className="flex-1" />
          {(!online || queued > 0) && (
            <button
              onClick={() => flushQueue()}
              className={clsx(
                "flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold",
                online ? "bg-amber-100 text-amber-800" : "bg-red-100 text-red-700"
              )}
            >
              <WifiOff size={13} />
              {online ? `${queued} queued — syncing…` : `OFFLINE${queued ? ` · ${queued} queued` : ""}`}
            </button>
          )}
          <span className="hidden text-sm font-medium text-slate-500 sm:block">
            {user.name} · <span className="text-xs uppercase">{me.is_full_admin ? "Full Administrator" : user.role?.name ?? "Staff"}</span>
          </span>
          <NotificationBell />
          <div className="border-l border-slate-200 pl-3">
            <Clock />
          </div>
        </header>
        <main className={clsx("page-enter flex-1", isKotBoard ? "flex flex-col" : "mx-auto w-full max-w-7xl p-4 lg:p-6")}>{children}</main>
      </div>
    </div>
  );
}
