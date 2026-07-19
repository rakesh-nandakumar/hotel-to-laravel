import { test, expect } from "@playwright/test";
import { authFile } from "./fixtures";

test.use({ storageState: authFile("manager") });

test.describe("Settings", () => {
  test("a numeric field keeps a value typed keystroke-by-keystroke, including one that starts with 0", async ({ page }) => {
    await page.goto("/settings");
    await page.getByRole("button", { name: /policies/i }).click();

    const rulesCard = page.locator(".card", { hasText: "Cancellation Refund Tiers" });
    await expect(rulesCard).toBeVisible();
    const daysInput = rulesCard.locator('input[inputMode="numeric"]').first();

    // Real keystroke-by-keystroke typing (not .fill(), which sets the whole
    // value in one shot and would never have reproduced the bug: an
    // onChange-time parseInt() on a CONTROLLED input snapped the field back
    // to "0" every time it was cleared, so the leading digit of whatever the
    // user typed next was effectively unusable).
    await daysInput.click();
    await daysInput.press("Control+A");
    await daysInput.pressSequentially("200", { delay: 30 });
    await expect(daysInput).toHaveValue("200");
    await daysInput.blur();
    await expect(daysInput).toHaveValue("200");

    // Persisted server-side, not just held in local component state.
    await page.reload();
    await page.getByRole("button", { name: /policies/i }).click();
    const reloadedInput = page.locator(".card", { hasText: "Cancellation Refund Tiers" }).locator('input[inputMode="numeric"]').first();
    await expect(reloadedInput).toHaveValue("200");
  });

  test("changing the primary theme color re-colors the UI live, without a page reload", async ({ page }) => {
    await page.goto("/settings");
    const themeCard = page.locator(".card", { hasText: "Primary color" });
    await expect(themeCard).toBeVisible();

    const hexInput = themeCard.locator("input.font-mono");
    await hexInput.click();
    await hexInput.press("Control+A");
    await hexInput.fill("#ff8800");
    await hexInput.blur();
    await expect(themeCard.getByText("saved ✓")).toBeVisible({ timeout: 5_000 });

    // The active category tab button uses bg-brand-600 — the exact CSS
    // variable stop the picked color maps to 1:1 (see lib/theme.ts).
    const activeTab = page.getByRole("button", { name: /hotel identity/i });
    await expect(activeTab).toHaveCSS("background-color", "rgb(255, 136, 0)");

    // Reset so this shared-database setting doesn't leak into other tests.
    await hexInput.click();
    await hexInput.press("Control+A");
    await hexInput.fill("#0462d3");
    await hexInput.blur();
    await expect(themeCard.getByText("saved ✓")).toBeVisible({ timeout: 5_000 });
  });
});
