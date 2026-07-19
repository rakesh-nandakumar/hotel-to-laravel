import { test, expect } from "@playwright/test";
import { fillLogin, USERS, PASSWORD } from "./fixtures";

test.describe("Login", () => {
  test("signs in with valid credentials and lands on the dashboard", async ({ page }) => {
    await fillLogin(page, USERS.manager.email, PASSWORD);
    await expect(page).toHaveURL("/");
    await expect(page.getByText(USERS.manager.name)).toBeVisible();
  });

  test("rejects a wrong password", async ({ page }) => {
    await fillLogin(page, USERS.manager.email, "wrong-password");
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByText(/invalid|incorrect|these credentials/i)).toBeVisible();
  });

  test("rejects an unknown email", async ({ page }) => {
    await fillLogin(page, "nobody@nowhere.test", PASSWORD);
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByText(/invalid|incorrect|these credentials/i)).toBeVisible();
  });

  test("blocks submission of an empty form via native required validation", async ({ page }) => {
    await page.goto("/login");
    await page.getByRole("button", { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/login/);
    const emailInvalid = await page.getByPlaceholder("you@email.com").evaluate((el: HTMLInputElement) => !el.validity.valid);
    expect(emailInvalid).toBe(true);
  });

  test("redirects an unauthenticated visitor away from a protected page", async ({ page }) => {
    await page.goto("/reservations");
    await expect(page).toHaveURL(/\/login/);
  });

  test("signs out and blocks returning to a protected page via back navigation", async ({ page }) => {
    await fillLogin(page, USERS.manager.email, PASSWORD);
    await expect(page).toHaveURL("/");

    await page.locator('button[title="Sign out"], button:has-text("Sign out")').first().click();
    await page.locator(".modal-panel").getByRole("button", { name: "Sign out" }).click();
    await expect(page).toHaveURL(/\/login/);

    await page.goto("/reservations");
    await expect(page).toHaveURL(/\/login/);
  });
});
