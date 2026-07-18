import { ReactNode, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { NavLink, useLocation, useNavigate } from "react-router-dom";
import {
  LayoutDashboard, BedDouble, CalendarDays, CalendarRange, UtensilsCrossed, ChefHat, ClipboardList,
  Wrench, Users, Building2, Clock4, Banknote, BarChart3, Settings as SettingsIcon,
  PartyPopper, Contact, LogOut, Menu as MenuIcon, X, WifiOff, ShieldCheck, Bell, Package, Sparkles, Plug, Shirt, Wallet, History, Search,
  PanelLeftClose, PanelLeft,
} from "lucide-react";
import { useAuth } from "../lib/auth";
import { useBranding, brandInitials } from "../lib/branding";
import { onQueueChange, queuedCount, flushQueue } from "../lib/offline";
import Clock from "./Clock";
import GlobalRealtimeNotifications from "./GlobalRealtimeNotifications";
import NotificationBell from "./NotificationBell";
import { ConfirmDialog, Avatar } from "./ui";
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

/** A single nav item flattened together with the section it lives under, for search. */
type FlatItem = Item & { section: string };

/**
 * Sidebar quick-search — filters the (already permission-filtered) menu by label
 * or section. Mirrors the leolanka-inertia search: Ctrl/⌘+K focuses, ↑/↓ move,
 * ↵ opens, Esc clears, click-outside closes.
 */
function SidebarSearch({ sections, onNavigate }: { sections: Section[]; onNavigate: () => void }) {
  const nav = useNavigate();
  const [query, setQuery] = useState("");
  const [activeIndex, setActiveIndex] = useState(-1);
  const [focused, setFocused] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  const flat = useMemo<FlatItem[]>(
    () => sections.flatMap((s) => s.items.map((i) => ({ ...i, section: s.title }))),
    [sections]
  );

  const results = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return flat.filter((i) => i.label.toLowerCase().includes(q) || i.section.toLowerCase().includes(q)).slice(0, 8);
  }, [query, flat]);

  const showResults = focused && query.trim().length > 0;

  // Global Ctrl/⌘+K focuses the search.
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === "k") {
        e.preventDefault();
        inputRef.current?.focus();
      }
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }, []);

  // Close the results when clicking outside.
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setFocused(false);
        setActiveIndex(-1);
      }
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  const go = useCallback(
    (item: FlatItem) => {
      nav(item.to);
      setQuery("");
      setActiveIndex(-1);
      setFocused(false);
      inputRef.current?.blur();
      onNavigate();
    },
    [nav, onNavigate]
  );

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveIndex((i) => Math.min(i + 1, results.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === "Enter") {
      e.preventDefault();
      const target = results[activeIndex] ?? results[0];
      if (target) go(target);
    } else if (e.key === "Escape") {
      setQuery("");
      setActiveIndex(-1);
      setFocused(false);
      inputRef.current?.blur();
    }
  };

  return (
    <div ref={containerRef} className="relative px-3 pb-3">
      <div className="relative">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-sidebar-fg/40" />
        <input
          ref={inputRef}
          placeholder="Search menu…"
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setActiveIndex(-1);
          }}
          onKeyDown={onKeyDown}
          onFocus={() => setFocused(true)}
          className="w-full rounded-lg border border-sidebar-border bg-white/5 py-2 pl-8 pr-14 text-sm text-white placeholder:text-sidebar-fg/40 outline-none transition focus:border-brand-500 focus:ring-1 focus:ring-brand-500/50"
        />
        <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
          {query ? (
            <button
              onClick={() => {
                setQuery("");
                setActiveIndex(-1);
                inputRef.current?.focus();
              }}
              className="rounded p-0.5 text-sidebar-fg/40 transition-colors hover:text-white"
              tabIndex={-1}
            >
              <X className="h-3.5 w-3.5" />
            </button>
          ) : (
            <kbd className="hidden rounded border border-sidebar-border bg-sidebar-accent px-1 py-0.5 text-[10px] text-sidebar-fg/40 sm:inline-flex">
              ⌘K
            </kbd>
          )}
        </div>
      </div>

      {showResults && (
        <div className="absolute inset-x-3 top-[calc(100%-4px)] z-50 overflow-hidden rounded-lg border border-sidebar-border bg-sidebar shadow-2xl">
          {results.length > 0 ? (
            <>
              <div className="border-b border-sidebar-border/60 px-3 py-1.5">
                <span className="text-[10px] font-medium uppercase tracking-wider text-sidebar-fg/40">
                  {results.length} result{results.length !== 1 ? "s" : ""}
                </span>
              </div>
              <div className="max-h-64 overflow-y-auto">
                {results.map((item, index) => {
                  const highlighted = index === activeIndex;
                  return (
                    <button
                      key={item.to}
                      onMouseDown={(e) => {
                        e.preventDefault();
                        go(item);
                      }}
                      onMouseEnter={() => setActiveIndex(index)}
                      className={clsx(
                        "flex w-full items-center gap-3 px-3 py-2.5 text-left text-sm transition-colors",
                        highlighted ? "bg-brand-600 text-white" : "text-sidebar-fg hover:bg-sidebar-accent"
                      )}
                    >
                      <div
                        className={clsx(
                          "flex h-7 w-7 shrink-0 items-center justify-center rounded-md",
                          highlighted ? "bg-white/20" : "bg-sidebar-accent"
                        )}
                      >
                        {item.icon}
                      </div>
                      <div className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate font-medium leading-tight">{item.label}</span>
                        <span
                          className={clsx(
                            "mt-0.5 truncate text-xs leading-tight",
                            highlighted ? "text-white/70" : "text-sidebar-fg/50"
                          )}
                        >
                          {item.section}
                        </span>
                      </div>
                      {highlighted && (
                        <kbd className="shrink-0 rounded border border-white/30 px-1.5 py-0.5 text-[10px] text-white/70">↵</kbd>
                      )}
                    </button>
                  );
                })}
              </div>
              <div className="border-t border-sidebar-border/60 px-3 py-1.5">
                <span className="text-[10px] text-sidebar-fg/30">↑↓ navigate · ↵ open · esc close</span>
              </div>
            </>
          ) : (
            <div className="flex flex-col items-center gap-1 px-3 py-6 text-center">
              <Search className="h-5 w-5 text-sidebar-fg/20" />
              <p className="text-sm text-sidebar-fg/40">No results for "{query}"</p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function Layout({ children }: { children: ReactNode }) {
  const { me, can, logout } = useAuth();
  const { branding } = useBranding();
  const nav = useNavigate();
  const { pathname } = useLocation();
  // Kitchen Display System needs the full screen width/height — no centering, no padding.
  const isKotBoard = pathname === "/kot";
  const [open, setOpen] = useState(false);
  // Desktop-only icon-collapse (leolanka-inertia's collapsible="icon" behaviour), persisted per device.
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem("mv.sidebarCollapsed") === "1");
  const [online, setOnline] = useState(navigator.onLine);
  const [queued, setQueued] = useState(0);
  const [confirmLogout, setConfirmLogout] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  const toggleCollapsed = () =>
    setCollapsed((c) => {
      const next = !c;
      localStorage.setItem("mv.sidebarCollapsed", next ? "1" : "0");
      return next;
    });

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
  const roleName = me.is_full_admin ? "Full Administrator" : user.role?.name ?? "Staff";
  const visible = (items: Item[]) =>
    items.filter((i) => {
      if (i.fullAdminOnly) return me.is_full_admin;
      if (!i.permission) return true;
      return Array.isArray(i.permission) ? i.permission.some(can) : can(i.permission);
    });

  const visibleSections = SECTIONS.map((s) => ({ ...s, items: visible(s.items) })).filter((s) => s.items.length > 0);

  /**
   * The sidebar contents. `mini` renders the icon-only collapsed rail (desktop);
   * the mobile drawer always renders the full-width version.
   */
  const renderSidebar = (mini: boolean) => (
    <nav className="flex h-full flex-col text-sidebar-fg">
      {/* Brand */}
      <div className={clsx("flex items-center py-4", mini ? "justify-center px-2" : "gap-2.5 px-4")}>
        {mini ? (
          branding.logo ? (
            <img src={branding.logo} alt={branding.name} className="h-9 w-9 rounded-lg bg-white/10 object-contain p-1" />
          ) : (
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-black text-white">{brandInitials(branding.name)}</div>
          )
        ) : (
          <>
            {branding.logo && (
              <img src={branding.logo} alt="" className="h-10 w-10 shrink-0 rounded-lg bg-white/10 object-contain p-1" />
            )}
            <div className="min-w-0">
              <div className="truncate text-lg font-black leading-tight text-white">{branding.name}</div>
              {branding.tagline && (
                <div className="truncate text-[11px] font-medium uppercase tracking-widest text-sidebar-fg/60">{branding.tagline}</div>
              )}
            </div>
          </>
        )}
      </div>

      {!mini && <SidebarSearch sections={visibleSections} onNavigate={() => setOpen(false)} />}

      <div className={clsx("flex-1 overflow-y-auto pb-4", mini ? "px-2" : "px-3")}>
        {visibleSections.map((section) => (
          <div key={section.title} className={mini ? "mb-2" : "mb-3"}>
            {!mini && (
              <div className="px-3 pb-1 pt-2 text-[9px] font-black uppercase tracking-[0.18em] text-sidebar-fg/40">{section.title}</div>
            )}
            <div className="space-y-0.5">
              {section.items.map((i) => (
                <NavLink
                  key={i.to}
                  to={i.to}
                  end={i.to === "/"}
                  onClick={() => setOpen(false)}
                  title={mini ? i.label : undefined}
                  className={({ isActive }) =>
                    clsx(
                      "flex items-center rounded-lg text-sm font-medium transition-all",
                      mini ? "justify-center px-0 py-2.5" : "gap-2.5 px-3 py-2",
                      isActive
                        ? "bg-brand-600 text-white shadow-sm"
                        : clsx("text-sidebar-fg/80 hover:bg-white/10 hover:text-white", !mini && "hover:translate-x-0.5")
                    )
                  }
                >
                  {i.icon}
                  {!mini && i.label}
                </NavLink>
              ))}
            </div>
          </div>
        ))}
      </div>

      {/* Account + session controls */}
      <div className={clsx("border-t border-sidebar-border", mini ? "p-2" : "p-3")}>
        {mini ? (
          <div className="flex flex-col items-center gap-2">
            <button
              onClick={() => nav("/account")}
              title="Account settings"
              className="rounded-full ring-2 ring-transparent transition hover:ring-white/20"
            >
              <Avatar name={user.name} size={34} />
            </button>
            <button
              className="btn !p-2 bg-white/10 text-white hover:bg-white/20"
              title="Sign out"
              onClick={() => setConfirmLogout(true)}
            >
              <LogOut size={16} />
            </button>
          </div>
        ) : (
          <>
            <button
              onClick={() => nav("/account")}
              className="mb-2 flex w-full items-center gap-2.5 rounded-lg p-1.5 text-left transition hover:bg-white/10"
              title="Account settings"
            >
              <Avatar name={user.name} size={36} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-bold text-white">{user.name}</div>
                <div className="truncate text-[11px] uppercase tracking-wide text-sidebar-fg/60">{roleName}</div>
              </div>
              <SettingsIcon className="h-4 w-4 shrink-0 text-sidebar-fg/50" />
            </button>
            <div className="flex gap-1.5">
              <button className="btn flex-1 bg-white/10 text-white hover:bg-white/20" onClick={() => setConfirmLogout(true)}>
                <LogOut size={15} /> Sign out
              </button>
              <button className="btn bg-white/10 text-white hover:bg-white/20" title="Switch staff (PIN)" onClick={() => nav("/login")}>
                PIN
              </button>
            </div>
          </>
        )}
      </div>
    </nav>
  );

  return (
    <div className="flex min-h-screen">
      <GlobalRealtimeNotifications />
      {/* Desktop sidebar — sticky & full-height so it scrolls independently of the page. */}
      <aside
        className={clsx(
          "hidden shrink-0 bg-gradient-to-b from-sidebar to-sidebar-deep transition-[width] duration-200 ease-out lg:sticky lg:top-0 lg:block lg:h-screen",
          collapsed ? "lg:w-16" : "lg:w-60"
        )}
      >
        {renderSidebar(collapsed)}
      </aside>
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={() => setOpen(false)} />
          <aside className="absolute left-0 top-0 h-full w-64 bg-gradient-to-b from-sidebar to-sidebar-deep shadow-2xl">{renderSidebar(false)}</aside>
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-2 backdrop-blur lg:px-6">
          {/* Mobile: open the drawer */}
          <button className="btn-ghost !p-1.5 lg:hidden" onClick={() => setOpen(!open)}>
            {open ? <X size={20} /> : <MenuIcon size={20} />}
          </button>
          {/* Desktop: collapse / expand the sidebar */}
          <button
            className="btn-ghost !p-1.5 hidden lg:inline-flex"
            onClick={toggleCollapsed}
            title={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
          >
            {collapsed ? <PanelLeft size={20} /> : <PanelLeftClose size={20} />}
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
          <button
            onClick={() => nav("/account")}
            className="hidden text-sm font-medium text-slate-500 transition hover:text-slate-800 sm:block"
            title="Account settings"
          >
            {user.name} · <span className="text-xs uppercase">{roleName}</span>
          </button>
          <NotificationBell />
          <div className="border-l border-slate-200 pl-3">
            <Clock />
          </div>
        </header>
        <main className={clsx("page-enter flex-1", isKotBoard ? "flex flex-col" : "mx-auto w-full max-w-7xl p-4 lg:p-6")}>{children}</main>
      </div>
      <ConfirmDialog
        open={confirmLogout}
        title="Sign out"
        message="Are you sure you want to sign out?"
        confirmLabel="Sign out"
        tone="danger"
        busy={loggingOut}
        onClose={() => setConfirmLogout(false)}
        onConfirm={async () => {
          setLoggingOut(true);
          try {
            await logout();
            nav("/login");
          } finally {
            setLoggingOut(false);
            setConfirmLogout(false);
          }
        }}
      />
    </div>
  );
}
