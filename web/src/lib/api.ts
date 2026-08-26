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
 * Standard print — fetch the server-rendered HTML document (same branded
 * Blade view the PDF download uses, ?output=html instead of a dompdf
 * stream) into a hidden same-origin iframe and call the browser's own
 * print() on it. A real window.print() on a real HTML document is reliable
 * across Chrome, Firefox, Safari and mobile — scripting a `print()` call
 * into an embedded PDF viewer (the previous approach) silently no-ops on
 * Firefox and most mobile browsers, so this replaces that entirely rather
 * than layering another fallback on top of it.
 */
export async function printDocument(path: string): Promise<void> {
  const url = `${API_ORIGIN}/api${path}${path.includes("?") ? "&" : "?"}output=html`;
  const res = await fetch(url, { credentials: "include", headers: { Accept: "text/html" } });
  if (!res.ok) throw new ApiFail(res.status, "Could not generate the document");
  const html = await res.text();

  const iframe = document.createElement("iframe");
  iframe.setAttribute("aria-hidden", "true");
  iframe.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(iframe);

  await new Promise<void>((resolve) => {
    iframe.onload = () => resolve();
    iframe.srcdoc = html;
  });

  const win = iframe.contentWindow;
  if (win) {
    win.focus();
    win.print();
  }
  // onafterprint isn't implemented everywhere, so this is a backstop, not the
  // primary cleanup path — it just guarantees the iframe doesn't linger.
  setTimeout(() => iframe.remove(), 60000);
}

/** Server-generated PDF, saved to disk (report "Download PDF" buttons — printDocument() is the print path). */
export async function downloadFile(path: string, filename: string): Promise<void> {
  const res = await fetch(`${API_ORIGIN}/api${path}`, { credentials: "include", headers: { Accept: "application/pdf" } });
  if (!res.ok) throw new ApiFail(res.status, "Could not generate the document");
  const blob = await res.blob();
  const blobUrl = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = blobUrl;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
}
