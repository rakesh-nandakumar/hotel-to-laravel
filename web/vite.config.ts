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
    proxy: {
      // Laravel API — same-origin from the browser's perspective, so Sanctum's
      // SPA cookie session + CSRF cookie need no CORS/cross-site handling.
      // "/api": { target: "http://localhost:8888", changeOrigin: true },
      // "/sanctum": { target: "http://localhost:8888", changeOrigin: true },
      "/api": {
        target: apiProxyTarget,
        changeOrigin: true,
      },
      "/sanctum": {
        target: apiProxyTarget,
        changeOrigin: true,
      },
      // Reverb (realtime) is connected to directly by the browser — see lib/socket.ts.
    },
  },
  build: { outDir: "dist" },
});
