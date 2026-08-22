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
 * Standard print — fetch a server-generated PDF and trigger the browser's
 * print dialog. The PDF is opened in a new tab (captured synchronously to
 * avoid popup blocking) and automatically prints. If the popup is blocked,
 * a hidden iframe in the current page is used instead.
 *
 * The tab is opened SYNCHRONOUSLY, before the `await fetch(...)`, so it
 * counts as a direct response to the click for the popup blocker. If the
 * caller already opened a tab itself (POS walk-in slip — needs to open
 * before its own `await`), it passes that tab in.
 */
export async function openPdf(path: string, tab: Window | null = window.open("", "_blank")) {
  let blobUrl: string | null = null;
  try {
    const res = await fetch(`${API_ORIGIN}/api${path}`, { credentials: "include", headers: { Accept: "application/pdf" } });
    if (!res.ok) throw new ApiFail(res.status, "Could not generate PDF");
    const blob = await res.blob();
    blobUrl = URL.createObjectURL(blob);

    if (tab && !tab.closed) {
      // Navigate the (already gesture-approved) tab to the PDF blob and
      // auto-print once the PDF has loaded in the viewer.
      tab.location.href = blobUrl;
      tab.focus();
      // Give the PDF viewer a moment to render, then trigger the standard
      // browser print dialog. Wrapped in try/catch — some viewers block scripting.
      setTimeout(() => {
        try {
          tab.focus();
          tab.print();
        } catch {}
      }, 600);
      // Fallback if viewer doesn't fire print: try iframe inside the new tab.
      setTimeout(() => {
        try {
          if (tab.closed) return;
          // If user hasn't printed yet, ensure the PDF is at least visible (blob url).
          // Revoke after a minute — after the print dialog has had time to spool.
          URL.revokeObjectURL(blobUrl!);
        } catch {}
      }, 60000);
      // Revoke is delayed — immediately revoking breaks Chrome's PDF viewer.
      setTimeout(() => {
        try { URL.revokeObjectURL(blobUrl!); } catch {}
      }, 60000);
      return;
    }

    // Popup blocked — fall back to hidden iframe print in the current window.
    const iframe = document.createElement("iframe");
    iframe.style.position = "fixed";
    iframe.style.right = "0";
    iframe.style.bottom = "0";
    iframe.style.width = "0";
    iframe.style.height = "0";
    iframe.style.border = "0";
    iframe.src = blobUrl;

    iframe.onload = () => {
      setTimeout(() => {
        try {
          iframe.contentWindow?.focus();
          iframe.contentWindow?.print();
        } catch {
          window.open(blobUrl!, "_blank");
        }
        setTimeout(() => { try { URL.revokeObjectURL(blobUrl!); } catch {} }, 60000);
        setTimeout(() => iframe.remove(), 120000);
      }, 400);
    };
    iframe.onerror = () => {
      window.open(blobUrl!, "_blank");
      setTimeout(() => { try { URL.revokeObjectURL(blobUrl!); } catch {} }, 60000);
      iframe.remove();
    };
    document.body.appendChild(iframe);
    setTimeout(() => {
      if (document.body.contains(iframe)) {
        try { iframe.contentWindow?.print(); } catch {}
      }
    }, 1500);
  } catch (e) {
    if (blobUrl) try { URL.revokeObjectURL(blobUrl); } catch {}
    if (tab && !tab.closed) try { tab.close(); } catch {}
    throw e;
  }
}
