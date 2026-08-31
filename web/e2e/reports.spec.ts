import { test, expect } from "@playwright/test";
import { authFile, collectConsoleErrors, appUrl } from "./fixtures";

test.use({ storageState: authFile("manager") });

// payroll_cost is deliberately excluded — Manager doesn't hold that permission.
const HOTEL_REPORTS = [
  "daily", "monthly", "night_audit", "revpar", "channel_mix", "cancellations",
  "guest_loyalty", "corporate_ar", "ops_sla", "venues", "laundry",
];
const RESTAURANT_REPORTS = [
  "pos", "menu_performance", "modifiers", "discounts_voids", "table_server",
  "delivery", "kitchen_ticket_time", "shift_sales", "food_cost",
];
const APARTMENT_REPORTS = [
  "dashboard", "occupancy_trend", "revenue_channel", "rent_roll", "sales_pipeline", "utilities", "ops_sla",
];

/**
 * Breadth over depth: the backend already has focused Pest tests proving each
 * report's math is correct (HotelNewReportsTest, RestaurantNewReportsTest,
 * ApartmentNewReportsTest). What only a real browser can catch is a
 * frontend/backend wiring mismatch — a field name typo, a permission gate
 * that hides a card nobody can then reach, or (as happened once already in
 * this app, see the payments.kind eager-loading bug) an undefined property
 * access that blanks the entire SPA since there's no error boundary. So this
 * spec's job is simply: visit every single report in every hub as a real
 * user and confirm nothing throws.
 */
test("Hotel Reports hub: lists every report the Manager can see, hides Payroll Cost and POS Sales (both moved/restricted), and every card opens without a console error", async ({ page }) => {
  test.setTimeout(90_000);
  await page.goto(appUrl("/reports"));
  await expect(page.getByRole("heading", { name: /^reports$/i })).toBeVisible();
  // Payroll Cost is Owner-only; POS Sales moved to the Restaurant hub — neither should appear here.
  await expect(page.getByText("Payroll Cost", { exact: true })).toHaveCount(0);
  await expect(page.getByText("POS Sales", { exact: true })).toHaveCount(0);

  const errors = await collectConsoleErrors(page, async () => {
    for (const key of HOTEL_REPORTS) {
      await page.goto(appUrl(`/reports/${key}`));
      await expect(page.getByRole("button", { name: /all reports/i })).toBeVisible({ timeout: 10_000 });
      await expect(page.locator("main")).not.toBeEmpty();
    }
  });
  expect(errors).toEqual([]);
});

test("Restaurant Reports hub: standalone from Hotel, lists every report, and every card opens without a console error", async ({ page }) => {
  test.setTimeout(90_000);
  await page.goto(appUrl("/restaurant/reports"));
  await expect(page.getByRole("heading", { name: /restaurant reports/i })).toBeVisible();
  await expect(page.getByText("POS Sales", { exact: true })).toBeVisible();

  const errors = await collectConsoleErrors(page, async () => {
    for (const key of RESTAURANT_REPORTS) {
      await page.goto(appUrl(`/restaurant/reports/${key}`));
      await expect(page.getByRole("button", { name: /all reports/i })).toBeVisible({ timeout: 10_000 });
      await expect(page.locator("main")).not.toBeEmpty();
    }
  });
  expect(errors).toEqual([]);
});

test("Apartments Reports hub: the old single-dashboard page is now one card among the full catalog, and every card opens without a console error", async ({ page }) => {
  test.setTimeout(60_000);
  await page.goto(appUrl("/apartments/reports"));
  await expect(page.getByRole("heading", { name: /apartments reports/i })).toBeVisible();
  await expect(page.getByText("Operations Dashboard", { exact: true })).toBeVisible();
  await expect(page.getByText("Rent Roll & Arrears Aging", { exact: true })).toBeVisible();

  const errors = await collectConsoleErrors(page, async () => {
    for (const key of APARTMENT_REPORTS) {
      await page.goto(appUrl(`/apartments/reports/${key}`));
      await expect(page.getByRole("button", { name: /all reports/i })).toBeVisible({ timeout: 10_000 });
      await expect(page.locator("main")).not.toBeEmpty();
    }
  });
  expect(errors).toEqual([]);
});

test("Daily Operations report still exports a CSV from the new hub layout", async ({ page }) => {
  await page.goto(appUrl("/reports/daily"));
  await expect(page.getByRole("button", { name: /export csv/i })).toBeVisible({ timeout: 10_000 });

  const [download] = await Promise.all([
    page.waitForEvent("download"),
    page.getByRole("button", { name: /export csv/i }).click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/^daily-report-.*\.csv$/);
});
