import { defineConfig, devices } from "@playwright/test";

const BACKEND_PORT = 8123;
const FRONTEND_PORT = 5174;
const BACKEND_URL = `http://127.0.0.1:${BACKEND_PORT}`;
// The suite runs on the DEMO TENANT's prefix, exactly like production: the
// browser loads http://localhost:5174/default (every page goto is wrapped by
// e2e/fixtures.ts's appUrl()), and the SPA reads the slug from its own path,
// sending it as X-Tenant-Slug on every API call — that's the whole tenancy
// identity now (the Host fallback is transitional). Each page-load also
// crosses the /api/host-context boot gate first.
const FRONTEND_URL = `http://localhost:${FRONTEND_PORT}/default`;
// Base path is the demo tenant's prefix; a base URL like this keeps
// page.goto("/default/login") style calls safe and makes any accidental
// root-relative goto land outside the prefix (and thus on master control —
// a loud, immediate failure rather than a silent wrong-tenant test).
const FRONTEND_URL_PROBE = `http://localhost:${FRONTEND_PORT}`;

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
  // `php artisan serve` (single-threaded, APP_DEBUG=true, no opcache) is
  // noticeably slower than a production server under this suite's sustained
  // request volume — individual API round-trips can exceed the 5s default,
  // which was intermittently failing otherwise-correct assertions across
  // several long, multi-step apartment specs.
  expect: {
    timeout: 10_000,
  },
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
      url: FRONTEND_URL_PROBE,
      reuseExistingServer: false,
      timeout: 60_000,
      env: { VITE_DEV_API_PROXY_TARGET: BACKEND_URL },
      stdout: "pipe",
      stderr: "pipe",
    },
  ],
});
