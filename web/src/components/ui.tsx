/** Minimal shadcn-style UI kit — Tailwind-only, zero runtime deps beyond lucide icons. */
import { ReactNode, useEffect } from "react";
import { X, ChevronLeft, ChevronRight } from "lucide-react";
import clsx from "clsx";

/** Shared list type every paginated endpoint returns when `page` is passed. */
export type Paged<T> = { rows: T[]; total: number; page: number; pageSize: number };

const PAGE_SIZES = [10, 25, 50, 100];

/** Pager with a "12–31 of 214" range label, an items-per-page selector, and Prev/Next — used by every paginated table. */
export function Pagination({
  page, pageSize, total, onPage, onPageSize,
}: {
  page: number; pageSize: number; total: number; onPage: (p: number) => void; onPageSize: (size: number) => void;
}) {
  if (total === 0) return null;
  const totalPages = Math.max(1, Math.ceil(total / pageSize));
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 px-1 py-2 text-sm">
      <div className="flex items-center gap-2 text-slate-500">
        <span>
          {(page - 1) * pageSize + 1}–{Math.min(page * pageSize, total)} of {total}
        </span>
        <select
          className="input !w-auto !py-1 !text-xs"
          value={pageSize}
          onChange={(e) => onPageSize(Number(e.target.value))}
        >
          {PAGE_SIZES.map((n) => (
            <option key={n} value={n}>{n} / page</option>
          ))}
        </select>
      </div>
      {totalPages > 1 && (
        <div className="flex items-center gap-2">
          <button className="btn-secondary !py-1.5" disabled={page <= 1} onClick={() => onPage(page - 1)}>
            <ChevronLeft size={14} /> Prev
          </button>
          <span className="text-xs text-slate-400">Page {page} / {totalPages}</span>
          <button className="btn-secondary !py-1.5" disabled={page >= totalPages} onClick={() => onPage(page + 1)}>
            Next <ChevronRight size={14} />
          </button>
        </div>
      )}
    </div>
  );
}

export function Card({ title, actions, children, className }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <div className={clsx("card", className)}>
      {(title || actions) && (
        <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
          <h3 className="text-sm font-bold text-slate-800">{title}</h3>
          <div className="flex items-center gap-2">{actions}</div>
        </div>
      )}
      <div className="p-4">{children}</div>
    </div>
  );
}

export function Badge({ color = "slate", children }: { color?: string; children: ReactNode }) {
  const colors: Record<string, string> = {
    slate: "bg-slate-100 text-slate-700",
    green: "bg-emerald-100 text-emerald-800",
    red: "bg-red-100 text-red-700",
    amber: "bg-amber-100 text-amber-800",
    blue: "bg-sky-100 text-sky-800",
    purple: "bg-purple-100 text-purple-800",
    brand: "bg-brand-100 text-brand-700",
  };
  return <span className={clsx("inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold", colors[color] ?? colors.slate)}>{children}</span>;
}

/**
 * Status → badge color. The backend's lookup codes are lowercase snake_case
 * (e.g. "checked_in", "charged_to_room") — this app's established visual
 * convention displays them upper-cased, so the lookup normalizes case rather
 * than requiring every call site to remember to transform it.
 */
export const statusColor = (s: string): string =>
  ({
    AVAILABLE: "green", OCCUPIED: "blue", DIRTY: "amber", MAINTENANCE: "red",
    CONFIRMED: "blue", PENDING: "amber", CHECKED_IN: "green", CHECKED_OUT: "slate", CANCELLED: "red", NO_SHOW: "red",
    OPEN: "blue", PARKED: "amber", SETTLED: "green", CHARGED_TO_ROOM: "purple", VOID: "red",
    NEW: "red", PREPARING: "amber", READY: "green", SERVED: "slate",
    INQUIRY: "amber", COMPLETED: "green", DONE: "green", IN_PROGRESS: "amber", RESOLVED: "green",
    QUEUED: "amber", SENT: "green", FAILED: "red",
    DRAFT: "amber", FINALIZED: "green",
    ACTIVE: "green", SUSPENDED: "amber", INACTIVE: "slate",
  })[s.toUpperCase()] ?? "slate";

export function Modal({ open, onClose, title, children, wide }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    const esc = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    if (open) {
      window.addEventListener("keydown", esc);
      document.body.style.overflow = "hidden"; // lock page scroll behind modal
    }
    return () => {
      window.removeEventListener("keydown", esc);
      document.body.style.overflow = "";
    };
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" onClick={onClose}>
      <div
        className={clsx("modal-panel max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl", wide ? "sm:max-w-3xl" : "sm:max-w-lg")}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white px-4 py-3">
          <h3 className="text-base font-bold">{title}</h3>
          <button className="btn-ghost !p-1.5" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>
        <div className="p-4">{children}</div>
      </div>
    </div>
  );
}

/** Confirmation prompt for a destructive or state-changing action. `tone="danger"` reddens the confirm button. */
export function ConfirmDialog({
  open, title, message, confirmLabel = "Confirm", tone = "brand", busy, onConfirm, onClose,
}: {
  open: boolean; title: ReactNode; message: ReactNode; confirmLabel?: string;
  tone?: "brand" | "danger"; busy?: boolean; onConfirm: () => void; onClose: () => void;
}) {
  if (!open) return null;
  return (
    <Modal open onClose={onClose} title={title}>
      <div className="text-sm leading-relaxed text-slate-600">{message}</div>
      <div className="mt-5 flex justify-end gap-2">
        <button className="btn-secondary" onClick={onClose} disabled={busy}>Cancel</button>
        <button className={tone === "danger" ? "btn-danger" : "btn-primary"} onClick={onConfirm} disabled={busy}>
          {busy ? "Working…" : confirmLabel}
        </button>
      </div>
    </Modal>
  );
}

/** Initials avatar (brand gradient). Mirrors User::initials() server-side. */
export function Avatar({ name, size = 36 }: { name: string; size?: number }) {
  const initials =
    (name || "?")
      .trim()
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((p) => p[0]!.toUpperCase())
      .join("") || "?";
  return (
    <span
      className="inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 font-bold text-white"
      style={{ width: size, height: size, fontSize: size * 0.4 }}
    >
      {initials}
    </span>
  );
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <div>
      <label className="label">{label}</label>
      {children}
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}

export function Empty({ text }: { text: string }) {
  return <div className="py-8 text-center text-sm text-slate-400">{text}</div>;
}

export function ErrorText({ error }: { error: string }) {
  if (!error) return null;
  return <div className="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{error}</div>;
}

export function Stat({ label, value, sub, color }: { label: string; value: ReactNode; sub?: ReactNode; color?: string }) {
  return (
    <div className="card p-4">
      <div className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={clsx("mt-1 text-2xl font-extrabold", color ?? "text-slate-900")}>{value}</div>
      {sub && <div className="mt-0.5 text-xs text-slate-500">{sub}</div>}
    </div>
  );
}

export function Tabs<T extends string>({ tabs, active, onChange }: { tabs: { id: T; label: string }[]; active: T; onChange: (t: T) => void }) {
  return (
    <div className="flex flex-wrap gap-1 rounded-xl bg-slate-200/70 p-1">
      {tabs.map((t) => (
        <button
          key={t.id}
          onClick={() => onChange(t.id)}
          className={clsx(
            "rounded-lg px-3 py-1.5 text-sm font-semibold transition",
            active === t.id ? "bg-white text-slate-900 shadow-sm" : "text-slate-500 hover:text-slate-800"
          )}
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}
