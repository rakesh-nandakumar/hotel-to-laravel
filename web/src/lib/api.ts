/**
 * API client — Laravel Sanctum SPA (cookie/session) auth, not Bearer tokens.
 * Every request carries the session cookie (`credentials: "include"`); state-
 * changing requests also carry Sanctum's CSRF cookie as a header. POS write
 * endpoints go through the offline queue instead (see offline.ts).
 */

const MUTATING = new Set(["POST", "PUT", "PATCH", "DELETE"]);

/**
 * Absolute origin of the Laravel API — e.g. "https://api.demo.hms.vellixglobal.com".
 * Set via VITE_API_URL at build time when the SPA and API live on different
 * origins (e.g. SPA on demo.hms…, API on api.demo.hms…). Left empty in local
 * dev, where Vite proxies /api and /sanctum to the backend so same-origin
 * relative paths just work. Trailing slashes are trimmed so paths concatenate cleanly.
 */
export const API_ORIGIN = (import.meta.env.VITE_API_URL ?? "").replace(/\/+$/, "");

export class ApiFail extends Error {
  status: number;
  errors?: Record<string, string[]>;
  errorCode?: string;
  constructor(status: number, message: string, errors?: Record<string, string[]>, errorCode?: string) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.errorCode = errorCode;
  }
}

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

/** Sanctum CSRF header for a hand-rolled mutating fetch (e.g. the offline POS queue). */
export function xsrfHeader(): Record<string, string> {
  return { "X-XSRF-TOKEN": getCookie("XSRF-TOKEN") ?? "" };
}

/** Sanctum SPA CSRF handshake — fetched once, re-fetched after logout invalidates it. */
let csrfPromise: Promise<void> | null = null;
export function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && getCookie("XSRF-TOKEN")) return Promise.resolve();
  csrfPromise ??= fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, { credentials: "include" })
    .then(() => undefined)
    .finally(() => {
      csrfPromise = null;
    });
  return csrfPromise;
}

export async function api<T = unknown>(path: string, opts: { method?: string; body?: unknown; silent401?: boolean; _retried?: boolean } = {}): Promise<T> {
  const method = opts.method || (opts.body !== undefined ? "POST" : "GET");
  if (MUTATING.has(method)) await ensureCsrfCookie();

  const res = await fetch(`${API_ORIGIN}/api${path}`, {
    method,
    credentials: "include",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(MUTATING.has(method) ? { "X-XSRF-TOKEN": getCookie("XSRF-TOKEN") ?? "" } : {}),
    },
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  });

  // The XSRF-TOKEN cookie is only re-fetched when absent, so a token that
  // went stale server-side (session regenerated, expired mid-edit, etc.)
  // gets reused as-is and rejected with 419. Slow, deliberate interactions —
  // picking/dragging/pasting a logo, editing text before saving — are the
  // most likely to outlive it. Refresh the cookie and retry exactly once
  // before surfacing an error, instead of making the user re-submit by hand.
  if (res.status === 419 && MUTATING.has(method) && !opts._retried) {
    await ensureCsrfCookie(true);
    return api<T>(path, { ...opts, _retried: true });
  }

  // A mid-session expiry on a real request hard-redirects to /login. The auth
  // probe (`/me`) passes silent401 so a logged-out boot is handled client-side
  // by the router instead — a hard reload would flash login, then 404 on any
  // host without the SPA fallback rewrite (see vercel.json).
  if (res.status === 401 && !opts.silent401 && !window.location.pathname.startsWith("/login")) {
    window.location.href = "/login";
    throw new ApiFail(401, "Session expired");
  }
  if (!res.ok) {
    let message = res.statusText;
    let errors: Record<string, string[]> | undefined;
    let errorCode: string | undefined;
    try {
      const body = await res.json();
      message = body.message ?? message;
      errors = body.errors;
      errorCode = body.error_code;
    } catch {}
    throw new ApiFail(res.status, message, errors, errorCode);
  }
  if (res.headers.get("content-type")?.includes("pdf")) return (await res.blob()) as T;
  if (res.status === 204) return undefined as T;
  return res.json();
}

export const put = <T = unknown>(path: string, body: unknown) => api<T>(path, { method: "PUT", body });
export const post = <T = unknown>(path: string, body?: unknown) => api<T>(path, { method: "POST", body: body ?? {} });

/**
 * Open a server-generated PDF (receipt/invoice) in a new tab for printing.
 *
 * The tab is opened SYNCHRONOUSLY, before the `await fetch(...)` below, so it
 * still counts as a direct response to the triggering click for the browser's
 * popup blocker — opening it only after the network round-trip resolves (the
 * previous implementation) falls outside that window and gets silently
 * blocked on Chrome/Edge/Firefox with no visible error.
 *
 * If the caller already needs to `await` something else (e.g. creating the
 * order) before it knows which PDF to show, it should open the tab itself
 * at the top of its own click handler — synchronously, before its first
 * `await` — and pass it in as `tab`, otherwise the same popup-blocking
 * problem just moves one level up (Firefox in particular blocks `window.open`
 * the moment it's called after ANY prior `await`, not just this one).
 */
export async function openPdf(path: string, tab: Window | null = window.open("", "_blank")) {
  try {
    const res = await fetch(`${API_ORIGIN}/api${path}`, { credentials: "include", headers: { Accept: "application/pdf" } });
    if (!res.ok) throw new ApiFail(res.status, "Could not generate PDF");
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    if (tab && !tab.closed) {
      tab.location.href = url;
    } else {
      // Popup blocked even with the synchronous open (e.g. a browser setting that blocks all popups) — fall back to a direct download.
      const a = document.createElement("a");
      a.href = url;
      a.download = (path.split("/").pop() || "document") + ".pdf";
      a.click();
    }
  } catch (e) {
    tab?.close();
    throw e;
  }
}
