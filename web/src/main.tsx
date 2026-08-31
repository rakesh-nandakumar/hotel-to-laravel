import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import CentralApp from "./CentralApp";
import { setBrandingBootHint, type Branding } from "./lib/branding";
import { CENTRAL_PREFIX, mountBasename, tenantSlugFromPath } from "./lib/tenancy";
import "./index.css";

/**
 * What the /api/host-context boot gate resolved this URL to.
 * null = this path owns nothing — render the unavailable page, not the app.
 */
type BootContext =
  | { mode: "central" }
  | ({ mode: "tenant" } & Partial<Branding>)
  | null;

/** Plain, unstyled fallback for paths the backend decided not to serve the app on. */
function Unavailable() {
  return (
    <div style={{ fontFamily: "system-ui, sans-serif", color: "#334155", background: "#fff", minHeight: "100vh", display: "flex", alignItems: "center", justifyContent: "center" }}>
      This site isn&apos;t available.
    </div>
  );
}

/** Inline redirect — no client-side router has mounted yet. */
const pathname = window.location.pathname;
if (pathname === "/" || pathname === "") {
  window.location.replace(`/${CENTRAL_PREFIX}`);
}

/**
 * The slug this URL names, passed as X-Tenant-Slug so the boot gate and every
 * API call resolve the tenant from it regardless of the Host header — the
 * tenant prefix on one origin is all the identity a request has.
 */
const slug = tenantSlugFromPath(pathname);

async function boot(slug: string | null): Promise<BootContext> {
  try {
    const res = await fetch("/api/host-context", {
      headers: slug ? { "X-Tenant-Slug": slug } : {},
    });
    if (!res.ok) return null;
    return (await res.json()) as Exclude<BootContext, null>;
  } catch {
    return null;
  }
}

// The backend resolves which shell this URL is (master control vs. a tenant)
// from the X-Tenant-Slug header / path prefix — see
// App\Http\Controllers\HostContextController. Wait for it before mounting so
// the wrong tree never paints, even briefly. On a tenant path the payload IS
// the public branding, so seed it here and skip the separate
// /api/public/branding fetch.
boot(slug).then((ctx) => {
  setBrandingBootHint(ctx !== null && ctx.mode === "tenant" ? ctx : null);
  ReactDOM.createRoot(document.getElementById("root")!).render(
    <React.StrictMode>
      {ctx === null ? <Unavailable /> : ctx.mode === "central" ? <CentralApp basename={`/${CENTRAL_PREFIX}`} /> : <App basename={mountBasename(pathname)} />}
    </React.StrictMode>
  );
});
