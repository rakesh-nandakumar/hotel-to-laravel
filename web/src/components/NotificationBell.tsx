import { useEffect, useRef, useState } from "react";
import { Bell } from "lucide-react";
import { useToast, TOAST_STYLE } from "../lib/toast";
import clsx from "clsx";

function timeAgo(ts: number): string {
  const s = Math.max(0, Math.round((Date.now() - ts) / 1000));
  if (s < 60) return "just now";
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

/** Header bell — persistent notification history, since individual popups auto-dismiss. */
export default function NotificationBell() {
  const { history, unreadCount, markAllRead } = useToast();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  return (
    <div className="relative" ref={ref}>
      <button
        className="relative flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100"
        onClick={() => {
          setOpen((o) => !o);
          if (!open) markAllRead();
        }}
        aria-label="Notifications"
      >
        <Bell size={18} />
        {unreadCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-black text-white">
            {unreadCount > 9 ? "9+" : unreadCount}
          </span>
        )}
      </button>
      {open && (
        <div className="modal-panel absolute right-0 top-full z-50 mt-2 max-h-[70vh] w-80 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
          <div className="sticky top-0 border-b border-slate-100 bg-white px-3 py-2 text-xs font-black uppercase tracking-wide text-slate-500">
            Notifications
          </div>
          {history.length === 0 ? (
            <div className="px-4 py-8 text-center text-sm text-slate-400">Nothing yet</div>
          ) : (
            <div className="divide-y divide-slate-50">
              {history.map((h) => {
                const s = TOAST_STYLE[h.type];
                const Icon = s.icon;
                return (
                  <div key={h.id} className="flex items-start gap-2.5 px-3 py-2.5">
                    <Icon size={16} className={clsx("mt-0.5 shrink-0", s.iconColor)} />
                    <div className="min-w-0 flex-1">
                      <div className="text-sm font-semibold leading-tight text-slate-800">{h.title}</div>
                      {h.message && <div className="text-xs text-slate-500">{h.message}</div>}
                      <div className="mt-0.5 text-[10px] text-slate-400">{timeAgo(h.at)}</div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
