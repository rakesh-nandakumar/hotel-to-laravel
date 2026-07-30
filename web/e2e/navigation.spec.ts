import { test, expect } from "@playwright/test";
import { authFile, collectConsoleErrors } from "./fixtures";

test.use({ storageState: authFile("fullAdmin") });

// Every route in the sidebar (see components/Layout.tsx) — a broad smoke
// sweep that renders each page as Full Administrator and asserts it doesn't
// crash. This is exactly the kind of test that would have caught the
// Housekeeping page's "Cannot read properties of undefined (reading 'code')"
// crash (missing eager-loaded relation) across the WHOLE app in one pass,
// not just the one page a developer happened to click into.
const ROUTES: [string, string | RegExp][] = [
  // Dashboard's <h1> is a time-of-day greeting, never the literal word
  // "dashboard" — match the greeting itself so a real render crash still fails this.
  ["/", /good (morning|afternoon|evening)/i],
  ["/reservations", /reservations/i],
  ["/calendar", /calendar/i],
  ["/rooms", /rooms/i],
  ["/guests", /guests/i],
  ["/pos", /point of sale|pos/i],
  ["/kot", /kitchen/i],
  ["/menu", /menu/i],
  ["/inventory", /inventory/i],
  // Venues' <h1> is this hotel's own venue branding line, not the word "venues".
  ["/venues", /wedding halls/i],
  ["/housekeeping", /housekeeping/i],
  ["/laundry", /laundry/i],
  ["/maintenance", /maintenance/i],
  ["/visitors", /visitor/i],
  ["/attendance", /attendance/i],
  ["/shifts", /shift|cash/i],
  ["/corporate", /corporate/i],
  ["/reports", /reports/i],
  ["/notifications", /notification/i],
  ["/payroll", /payroll/i],
  ["/staff", /staff|user/i],
  ["/roles", /role/i],
  ["/audit-log", /audit/i],
  ["/apartments/properties", /propert/i],
  ["/apartments/unit-types", /unit types/i],
  ["/apartments/units", /units/i],
  ["/apartments/customers", /customers/i],
  ["/apartments/bookings", /bookings/i],
  ["/apartments/leases", /leases/i],
  ["/apartments/sales", /sales/i],
  ["/apartments/housekeeping", /housekeeping/i],
  ["/apartments/maintenance", /maintenance/i],
  ["/apartments/reports", /reports/i],
];

for (const [path, heading] of ROUTES) {
  test(`${path} renders without crashing`, async ({ page }) => {
    const errors = await collectConsoleErrors(page, async () => {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1 })).toContainText(heading, { timeout: 10_000 });
      // Let any deferred fetches/effects settle so a delayed crash still counts.
      await page.waitForLoadState("networkidle");
    });

    expect(errors, `console/page errors on ${path}:\n${errors.join("\n")}`).toEqual([]);
  });
}
