import { test, expect } from "@playwright/test";
import { authFile } from "./fixtures";

test.use({ storageState: authFile("manager") });

test("a POS order sent to the kitchen appears on the KOT board and can be advanced", async ({ page, context }) => {
  await page.goto("/pos");
  await page.locator("button.card", { hasText: "E2E Seed Dish" }).first().click();

  const [slipTab] = await Promise.all([
    context.waitForEvent("page"),
    page.getByRole("button", { name: /send to kitchen/i }).click(),
  ]);
  await slipTab.waitForLoadState().catch(() => {});
  await slipTab.close().catch(() => {});

  await page.goto("/kot");
  const ticketCard = page.locator(".rounded-2xl.border-2", { hasText: "E2E Seed Dish" });
  await expect(ticketCard).toBeVisible({ timeout: 10_000 });

  // ── The chime needs one real page interaction before the browser allows
  // audio playback — verify the KOT toolbar reflects that honestly instead
  // of silently claiming "Sound on" while nothing can actually play.
  await expect(page.getByRole("button", { name: /tap to enable sound/i })).toBeVisible();
  await page.mouse.click(10, 10);
  await expect(page.getByRole("button", { name: /^sound on$/i })).toBeVisible({ timeout: 5_000 });

  // ── Advance the ticket: NEW → PREPARING ─────────────────────────────────
  await ticketCard.getByRole("button", { name: /start preparing/i }).click();
  await expect(ticketCard.getByRole("button", { name: /mark ready/i })).toBeVisible({ timeout: 10_000 });
});
