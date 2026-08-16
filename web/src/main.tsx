import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import CentralApp from "./CentralApp";
import { setBrandingBootHint, type Branding } from "./lib/branding";
import "./index.css";

/**
 * What the /api/host-context boot gate resolved this host to.
 * null = this host owns nothing — render the unavailable page, not the app.
 */
type BootContext =
  | { mode: "central" }
  | ({ mode: "tenant" } & Partial<Branding>)
  | null;

/** Plain, unstyled fallback for hosts nginx decided not to serve the app on. */
function Unavailable() {
  return (
    <div style={{ fontFamily: "system-ui, sans-serif", color: "#334155", background: "#fff", minHeight: "100vh", display: "flex", alignItems: "center", justifyContent: "center" }}>
      This site isn&apos;t available.
    </div>
  );
}

async function boot(): Promise<BootContext> {
  try {
    const res = await fetch("/api/host-context");
    if (!res.ok) return null;
    return (await res.json()) as Exclude<BootContext, null>;
  } catch {
    return null;
  }
}

// The backend resolves which shell this host is (master control vs. a tenant)
// from the Host header — see App\Http\Controllers\HostContextController. Wait
// for it before mounting so the wrong tree never paints, even briefly. On a
// tenant host the payload IS the public branding, so seed it here and skip
// the separate /api/public/branding fetch.
boot().then((ctx) => {
  setBrandingBootHint(ctx !== null && ctx.mode === "tenant" ? ctx : null);
  ReactDOM.createRoot(document.getElementById("root")!).render(
    <React.StrictMode>
      {ctx === null ? <Unavailable /> : ctx.mode === "central" ? <CentralApp /> : <App />}
    </React.StrictMode>
  );
});
