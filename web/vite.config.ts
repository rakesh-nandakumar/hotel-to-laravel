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
    // Bind every interface so tenant subdomains resolve here. Browsers send
    // *.localhost straight to 127.0.0.1 with no hosts-file entry, which is
    // what makes real subdomain tenancy testable locally.
    host: true,
    // Vite 5 refuses requests whose Host it doesn't recognise; every tenant
    // arrives on its own subdomain, so allow the whole local space.
    allowedHosts: [".localhost", "127.0.0.1"],
    proxy: {
      // Laravel API — same-origin from the browser's perspective, so Sanctum's
      // SPA cookie session + CSRF cookie need no CORS/cross-site handling.
      //
      // changeOrigin MUST stay false: it would rewrite the Host header to the
      // proxy target, and the Host is precisely how App\Http\Middleware\
      // IdentifyTenant works out which tenant a request belongs to. Rewritten,
      // every tenant subdomain reaches Laravel as "127.0.0.1" and resolves no
      // tenant at all ("Unknown host.").
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
