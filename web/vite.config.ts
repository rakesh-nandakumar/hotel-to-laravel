import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      // Laravel API — same-origin from the browser's perspective, so Sanctum's
      // SPA cookie session + CSRF cookie need no CORS/cross-site handling.
      "/api": { target: "http://localhost:8888", changeOrigin: true },
      "/sanctum": { target: "http://localhost:8888", changeOrigin: true },
      // Reverb (realtime) is connected to directly by the browser — see lib/socket.ts.
    },
  },
  build: { outDir: "dist" },
});
