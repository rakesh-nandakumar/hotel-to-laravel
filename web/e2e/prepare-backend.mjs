// Boots an isolated Laravel backend for the Playwright suite: a dedicated
// `.env.testing` (derived from the real `.env`, so it always has a valid
// APP_KEY etc.) pointed at a throwaway file-based SQLite database, freshly
// migrated + seeded on every run, then `php artisan serve` in the foreground.
//
// This NEVER touches the developer's real MySQL dev database, and — because
// vite.config.ts's dev proxy defaults to the live demo API — NEVER touches
// that either: playwright.config.ts passes VITE_DEV_API_PROXY_TARGET to
// point the frontend dev server at the backend this script starts instead.
import { spawn, spawnSync } from "node:child_process";
import { existsSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const backendDir = resolve(dirname(fileURLToPath(import.meta.url)), "../../backend");
const sqlitePath = resolve(backendDir, "database/testing.sqlite");
const backendPort = process.env.E2E_BACKEND_PORT || "8123";

const OVERRIDES = {
  APP_ENV: "testing",
  APP_DEBUG: "true",
  APP_URL: `http://127.0.0.1:${backendPort}`,
  DB_CONNECTION: "sqlite",
  DB_DATABASE: sqlitePath,
  SESSION_DOMAIN: "null",
  SANCTUM_STATEFUL_DOMAINS: "127.0.0.1,127.0.0.1:*,localhost,localhost:*",
  BROADCAST_CONNECTION: "null",
  QUEUE_CONNECTION: "sync",
  SESSION_DRIVER: "file",
  CACHE_STORE: "file",
  MAIL_MAILER: "array",
  BCRYPT_ROUNDS: "4",
  PULSE_ENABLED: "false",
  TELESCOPE_ENABLED: "false",
};

function buildEnvTesting() {
  const base = readFileSync(resolve(backendDir, ".env"), "utf8");
  const lines = base.split(/\r?\n/);
  const seenKeys = new Set();

  const updated = lines.map((line) => {
    const m = /^([A-Z0-9_]+)=/.exec(line);
    if (!m || !(m[1] in OVERRIDES)) return line;
    seenKeys.add(m[1]);
    return `${m[1]}=${OVERRIDES[m[1]]}`;
  });

  for (const [key, value] of Object.entries(OVERRIDES)) {
    if (!seenKeys.has(key)) updated.push(`${key}=${value}`);
  }

  writeFileSync(resolve(backendDir, ".env.testing"), updated.join("\n") + "\n");
}

function run(cmd, args) {
  console.log(`[prepare-backend] ${cmd} ${args.join(" ")}`);
  const result = spawnSync(cmd, args, {
    cwd: backendDir,
    stdio: "inherit",
    shell: process.platform === "win32",
    env: { ...process.env, APP_ENV: "testing" },
  });
  if (result.status !== 0) {
    console.error(`[prepare-backend] "${cmd} ${args.join(" ")}" failed with exit code ${result.status}`);
    process.exit(result.status ?? 1);
  }
}

// Start from a clean slate every run — stale schema/data from a previous
// interrupted run must never silently leak into this one.
rmSync(sqlitePath, { force: true });
rmSync(`${sqlitePath}-journal`, { force: true });
rmSync(`${sqlitePath}-wal`, { force: true });
rmSync(`${sqlitePath}-shm`, { force: true });
writeFileSync(sqlitePath, "");

buildEnvTesting();

run("php", ["artisan", "migrate:fresh", "--seed", "--force"]);

console.log(`[prepare-backend] starting php artisan serve on port ${backendPort}`);
const server = spawn("php", ["artisan", "serve", `--port=${backendPort}`], {
  cwd: backendDir,
  stdio: "inherit",
  shell: process.platform === "win32",
  env: { ...process.env, APP_ENV: "testing" },
});

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => {
    server.kill(signal);
    process.exit(0);
  });
}
server.on("exit", (code) => process.exit(code ?? 0));
