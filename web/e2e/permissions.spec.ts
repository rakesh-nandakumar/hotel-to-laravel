import { test, expect } from "@playwright/test";
import { authFile } from "./fixtures";

test.describe("Role-based access boundaries", () => {
  test.describe("Housekeeper", () => {
    test.use({ storageState: authFile("housekeeper") });

    test("is blocked from Payroll and redirected to the dashboard", async ({ page }) => {
      await page.goto("/payroll");
      await expect(page).toHaveURL("/");
      await expect(page.getByRole("heading", { name: /payroll/i })).toHaveCount(0);
    });

    test("can reach their own module, Housekeeping", async ({ page }) => {
      await page.goto("/housekeeping");
      await expect(page).toHaveURL("/housekeeping");
      await expect(page.getByRole("heading", { level: 1 })).toContainText(/housekeeping/i);
    });
  });

  test.describe("Security", () => {
    test.use({ storageState: authFile("security") });

    test("is blocked from the POS entirely", async ({ page }) => {
      await page.goto("/pos");
      await expect(page).toHaveURL("/");
    });

    test("can reach the Visitor Log", async ({ page }) => {
      await page.goto("/visitors");
      await expect(page).toHaveURL("/visitors");
      await expect(page.getByRole("heading", { level: 1 })).toContainText(/visitor/i);
    });
  });

  test.describe("Manager", () => {
    test.use({ storageState: authFile("manager") });

    // Payroll is deliberately OWNER-only — Manager is excluded from every other
    // module's usual Manager+Owner grant pattern here (see backend
    // SystemRoleDefinition.php's comment on hotel_payroll).
    test("is blocked from Payroll even though they can access almost everything else", async ({ page }) => {
      await page.goto("/payroll");
      await expect(page).toHaveURL("/");
    });
  });

  test.describe("Owner", () => {
    test.use({ storageState: authFile("owner") });

    test("can access Payroll", async ({ page }) => {
      await page.goto("/payroll");
      await expect(page).toHaveURL("/payroll");
      await expect(page.getByRole("heading", { level: 1 })).toContainText(/payroll/i);
    });
  });
});
