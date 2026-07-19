import { defineConfig, devices } from "@playwright/test";

const BACKEND_PORT = 8123;
const FRONTEND_PORT = 5174;
const BACKEND_URL = `http://127.0.0.1:${BACKEND_PORT}`;
// Vite's dev server (Node) only accepts connections via the `localhost` hostname on
// some Windows setups, not the `127.0.0.1` literal (a real IPv4-vs-IPv6 binding quirk
// hit while building this config) — unlike the PHP backend above, which answers on
// both. Both hostnames are already covered by prepare-backend.mjs's SANCTUM_STATEFUL_DOMAINS.
const FRONTEND_URL = `http://localhost:${FRONTEND_PORT}`;

/**
 * Runs against an isolated local stack ONLY: e2e/prepare-backend.mjs spins up
 * a throwaway SQLite-backed Laravel server (never the real dev MySQL DB, never
 * the live demo API vite.config.ts otherwise proxies to), and the Vite dev
 * server here is pointed at it via VITE_DEV_API_PROXY_TARGET. See that file
 * for details.
 */
export default defineConfig({
  testDir: "./e2e",
  // Tests share one backend database and log in as fixed seeded users — running
  // them concurrently would let one test's data/session bleed into another's.
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [["list"], ["html", { open: "never" }]],
  timeout: 30_000,
  use: {
    baseURL: FRONTEND_URL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  projects: [
    { name: "setup", testMatch: /.*\.setup\.ts/ },
    { name: "chromium", use: { ...devices["Desktop Chrome"] }, dependencies: ["setup"] },
  ],
  webServer: [
    {
      command: `node e2e/prepare-backend.mjs`,
      url: `${BACKEND_URL}/up`,
      reuseExistingServer: false,
      timeout: 120_000,
      env: { E2E_BACKEND_PORT: String(BACKEND_PORT) },
      stdout: "pipe",
      stderr: "pipe",
    },
    {
      command: `npm run dev -- --port ${FRONTEND_PORT} --strictPort`,
      url: FRONTEND_URL,
      reuseExistingServer: false,
      timeout: 60_000,
      env: { VITE_DEV_API_PROXY_TARGET: BACKEND_URL },
      stdout: "pipe",
      stderr: "pipe",
    },
  ],
});
