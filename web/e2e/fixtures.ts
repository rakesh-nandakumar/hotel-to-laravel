import { Locator, Page, expect } from "@playwright/test";

export const PASSWORD = "password";

/** The demo tenant every apartment/hotel spec rides — the URL prefix all page loads live under. */
export const TENANT_SLUG = "default";

/**
 * The real URL for a client-side path: every page load must sit under the
 * tenant's prefix (/default/…), which is what names the tenant to the API
 * (the SPA re-sends it as X-Tenant-Slug) — a root path like /login would
 * land on the apex host, which is master control, not the tenant app.
 */
export function appUrl(path: string): string {
  return `/${TENANT_SLUG}${path}`;
}

export const USERS = {
  fullAdmin: { email: "admin@vellix.com", name: "Admin User", role: "Full Administrator" },
  manager: { email: "manager@vellix.lk", name: "Operations Manager", role: "Manager" },
  owner: { email: "owner@vellix.lk", name: "Owner Account", role: "Owner" },
  housekeeper: { email: "housekeeper@vellix.lk", name: "Housekeeping Staff", role: "Housekeeper" },
  chef: { email: "chef@vellix.lk", name: "Head Chef", role: "Chef" },
  security: { email: "security@vellix.lk", name: "Security Officer", role: "Security" },
} as const;

export type RoleKey = keyof typeof USERS;

export function authFile(role: RoleKey): string {
  return `playwright/.auth/${role}.json`;
}

/** Fills and submits the email/password login form — does not wait for navigation. */
export async function fillLogin(page: Page, email: string, password: string) {
  await page.goto(appUrl("/login"));
  await page.getByPlaceholder("you@email.com").fill(email);
  await page.getByPlaceholder("Password").fill(password);
  await page.getByRole("button", { name: /sign in/i }).click();
}

/** Full UI login, waiting until the app has actually navigated away from /login. */
export async function loginAsUI(page: Page, role: RoleKey) {
  const u = USERS[role];
  await fillLogin(page, u.email, PASSWORD);
  await expect(page).not.toHaveURL(/\/login/, { timeout: 10_000 });
}

/**
 * Finds the input/select for a `<Field label="...">` (components/ui.tsx) —
 * that component doesn't associate the label with its control via `for`/
 * wrapping, so `getByLabel` can't find it.
 *
 * Was previously a div-filter heuristic (walk up to the Field's wrapper div,
 * then query its first input/select) — that timed out intermittently in
 * this environment, reproduced even on pre-existing, untouched forms (not
 * something any one form did wrong). `<Field>` always renders
 * `<label>{text}</label>` immediately followed by the control, so walking
 * straight to the label's next sibling is both simpler and reliable.
 */
export function fieldInput(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Opens a till for the current staff member through the real UI (Till.tsx)
 * if one isn't already open — idempotent, since several spec files share
 * one fixed "manager" user (see USERS above) and a till, once opened, stays
 * open for the rest of a sequential suite run (nothing closes it between
 * spec files). Needed before any cash payment: BillingService::recordPayment()
 * rejects cash with "Open a till before accepting or refunding cash"
 * otherwise, and TillSeeder only creates the till row, never a session.
 */
export async function ensureTillOpen(page: Page): Promise<void> {
  await page.goto(appUrl("/till"));
  const openBtn = page.getByRole("button", { name: /^open till$/i });
  // "My till" (Till.tsx) renders one of exactly two states once its /till/current
  // fetch resolves: the "Open till" trigger, or this Stat label when a session is
  // already open. Waiting for either first — rather than an immediate isVisible()
  // check right after goto(), which races the page's own data fetch and always
  // loses — is what makes the "already open" read trustworthy.
  const alreadyOpen = page.getByText(/expected cash in till/i);
  await expect(openBtn.or(alreadyOpen)).toBeVisible({ timeout: 10_000 });
  if (await alreadyOpen.isVisible()) return;
  await openBtn.click();
  const tillModal = page.locator(".modal-panel");
  await fieldInput(tillModal, "Opening cash in drawer (LKR)").fill("50000");
  await tillModal.getByRole("button", { name: /^open till$/i }).click();
  await expect(tillModal).toBeHidden({ timeout: 10_000 });
}

/** Collects console `error`/`pageerror` events for the duration of a callback so a test can assert none occurred. */
export async function collectConsoleErrors(page: Page, run: () => Promise<void>): Promise<string[]> {
  const errors: string[] = [];
  const onConsole = (msg: import("@playwright/test").ConsoleMessage) => {
    if (msg.type() === "error") errors.push(msg.text());
  };
  const onPageError = (err: Error) => errors.push(err.message);
  page.on("console", onConsole);
  page.on("pageerror", onPageError);
  try {
    await run();
  } finally {
    page.off("console", onConsole);
    page.off("pageerror", onPageError);
  }
  return errors;
}
