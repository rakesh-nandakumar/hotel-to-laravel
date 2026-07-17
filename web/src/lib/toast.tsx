import { createContext, useCallback, useContext, useRef, useState, ReactNode } from "react";
import { CheckCircle2, XCircle, Info, AlertTriangle, X } from "lucide-react";
import clsx from "clsx";

/**
 * App-wide popup notifications ("Order #34 settled", "Booking RSV-0012 created"…).
 * Any page calls useToast() and fires .success()/.error()/.info()/.warning() right
 * after a mutation succeeds. Complements the realtime cross-page pings in
 * components/GlobalRealtimeNotifications.tsx (socket-driven, for events other
 * staff trigger elsewhere in the system).
 *
 * Every toast is also appended to a capped `history` (50 entries) so the bell
 * button in the header (Layout.tsx) can show what was missed — the popup
 * itself is transient, the history isn't.
 */
type ToastType = "success" | "error" | "info" | "warning";
export type ToastEntry = { id: number; type: ToastType; title: string; message?: string; at: number };

type ToastApi = {
  success: (title: string, message?: string) => void;
  error: (title: string, message?: string) => void;
  info: (title: string, message?: string) => void;
  warning: (title: string, message?: string) => void;
  history: ToastEntry[];
  unreadCount: number;
  markAllRead: () => void;
};

const Ctx = createContext<ToastApi>(null as never);
export const useToast = () => useContext(Ctx);

const STYLE: Record<ToastType, { icon: typeof CheckCircle2; bar: string; iconColor: string }> = {
  success: { icon: CheckCircle2, bar: "bg-emerald-500", iconColor: "text-emerald-500" },
  error: { icon: XCircle, bar: "bg-red-500", iconColor: "text-red-500" },
  info: { icon: Info, bar: "bg-sky-500", iconColor: "text-sky-500" },
  warning: { icon: AlertTriangle, bar: "bg-amber-500", iconColor: "text-amber-500" },
};
export const TOAST_STYLE = STYLE;

const DURATION_MS = 5000;
const HISTORY_CAP = 50;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastEntry[]>([]);
  const [history, setHistory] = useState<ToastEntry[]>([]);
  const [lastReadId, setLastReadId] = useState(0);
  const idRef = useRef(0);

  const dismiss = useCallback((id: number) => setToasts((t) => t.filter((x) => x.id !== id)), []);

  const push = useCallback(
    (type: ToastType, title: string, message?: string) => {
      const entry: ToastEntry = { id: ++idRef.current, type, title, message, at: Date.now() };
      setToasts((t) => [...t.slice(-4), entry]); // cap the visible stack at 5
      setHistory((h) => [entry, ...h].slice(0, HISTORY_CAP));
      setTimeout(() => dismiss(entry.id), DURATION_MS);
    },
    [dismiss]
  );

  const api: ToastApi = {
    success: (title, message) => push("success", title, message),
    error: (title, message) => push("error", title, message),
    info: (title, message) => push("info", title, message),
    warning: (title, message) => push("warning", title, message),
    history,
    unreadCount: history.filter((h) => h.id > lastReadId).length,
    markAllRead: () => setLastReadId(idRef.current),
  };

  return (
    <Ctx.Provider value={api}>
      {children}
      {/* Bottom-right popup stack */}
      <div className="pointer-events-none fixed bottom-3 right-3 z-[100] flex w-[calc(100%-1.5rem)] max-w-sm flex-col-reverse gap-2 sm:bottom-4 sm:right-4">
        {toasts.map((t) => {
          const s = STYLE[t.type];
          const Icon = s.icon;
          return (
            <div key={t.id} className="toast-in pointer-events-auto overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
              <div className="flex items-start gap-2.5 p-3">
                <Icon size={19} className={clsx("mt-0.5 shrink-0", s.iconColor)} />
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-bold leading-tight text-slate-800">{t.title}</div>
                  {t.message && <div className="mt-0.5 text-xs leading-snug text-slate-500">{t.message}</div>}
                </div>
                <button className="shrink-0 text-slate-300 transition hover:text-slate-500" onClick={() => dismiss(t.id)} aria-label="Dismiss">
                  <X size={15} />
                </button>
              </div>
              <div className={clsx("toast-bar h-0.5", s.bar)} />
            </div>
          );
        })}
      </div>
    </Ctx.Provider>
  );
}
