import { Locator, Page, expect } from "@playwright/test";

export const PASSWORD = "password";

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
  await page.goto("/login");
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
 * wrapping, so `getByLabel` can't find it; this finds the field's wrapper div
 * by its label text and returns the control inside.
 */
export function fieldInput(scope: Locator, label: string): Locator {
  // Every ANCESTOR div of the label also "contains" its text, so .filter() matches
  // several nested divs, not just the Field's own wrapper — .last() picks the
  // innermost (most specific) one, since document order lists outer divs first.
  return scope
    .locator("div")
    .filter({ has: scope.getByText(label, { exact: true }) })
    .last()
    .locator("input, select");
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
