import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Overridable so the Playwright E2E suite (playwright.config.ts) can point this
// dev server at its own isolated local backend/test database, or so a local
// `.env` can repoint it elsewhere. Defaults to the local `php artisan serve`
// backend — NEVER a live remote API — so a fresh clone with no `.env` still
// boots against something reachable instead of silently hitting production.
const apiProxyTarget = process.env.VITE_DEV_API_PROXY_TARGET || "http://127.0.0.1:8000";

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    // Bind every interface so `*.localhost` names still reach the dev server
    // (old-style subdomain browsing keeps working through the cutover window).
    host: true,
    allowedHosts: [".localhost", "127.0.0.1"],
    proxy: {
      // Laravel API — same-origin from the browser's perspective, so Sanctum's
      // SPA cookie session + CSRF cookie need no CORS/cross-site handling.
      //
      // changeOrigin MUST stay false: tenant requests identify themselves via
      // X-Tenant-Slug (the SPA reads it from its path prefix), but central /
      // admin requests carry NO such header and are resolved from the Host.
      // Rewriting the Host would deliver every browser at localhost:5173 to
      // Laravel as "127.0.0.1" — an IP literal that resolves no tenant and no
      // central panel ("Unknown host."), killing master control in dev.
      //
      // A *.localhost subdomain visiting the proxy (cutover window) has the
      // same property: without a header the Host is what names the tenant.
      "/api": {
        target: apiProxyTarget,
        changeOrigin: false,
      },
      "/sanctum": {
        target: apiProxyTarget,
        changeOrigin: false,
      },
      // Reverb (realtime) is connected to directly by the browser — see lib/socket.ts.
    },
  },
  build: { outDir: "dist" },
});
