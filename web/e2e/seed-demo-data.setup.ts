import { test as setup, expect } from "@playwright/test";
import { authFile, fieldInput } from "./fixtures";

setup.use({ storageState: authFile("fullAdmin") });

/**
 * Runs after auth.setup.ts (alphabetically later, same "setup" project) as
 * the fully-privileged Full Administrator. Seeds one POS menu category + item
 * — data several spec files need (POS, KOT, exports) that must exist
 * regardless of which order test FILES happen to run in, rather than each
 * test file racing to create its own or depending on another having already
 * run first.
 *
 * Driven through the real UI rather than a raw page.request API call: Sanctum
 * SPA auth needs a real Origin/Referer on every stateful request (not just
 * login), which Playwright's API request context — a raw Node HTTP client —
 * doesn't attach automatically the way an in-page fetch() does. Going through
 * the UI sidesteps that entirely and is simpler to keep correct.
 */
setup("seed a demo menu category + item for POS/KOT tests", async ({ page }) => {
  await page.goto("/menu");

  await page.getByRole("button", { name: /categories/i }).click();
  const catModal = page.locator(".modal-panel");
  await catModal.getByPlaceholder(/new category name/i).fill("E2E Seed Category");
  // The category list re-renders each name into an <input defaultValue>, not plain
  // text, so it can't be asserted with getByText — wait for the create request
  // itself to round-trip instead (the "Add" button's own disabled-while-empty
  // state clearing back to enabled, plus the field, confirms the POST landed).
  const [addResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes("/api/menu/categories") && r.request().method() === "POST"),
    catModal.getByRole("button", { name: "Add" }).click(),
  ]);
  expect(addResponse.ok()).toBeTruthy();
  await catModal.getByLabel("Close").click();

  await page.getByRole("button", { name: /new item/i }).click();
  const itemModal = page.locator(".modal-panel");
  await expect(itemModal.getByText(/new menu item/i)).toBeVisible();

  console.log("div-with-Name count:", await itemModal.locator("div").filter({ has: itemModal.getByText("Name", { exact: true }) }).count());
  console.log("getByText Name count:", await itemModal.getByText("Name", { exact: true }).count());
  await fieldInput(itemModal, "Name").fill("E2E Seed Dish");
  await fieldInput(itemModal, "Category").selectOption({ label: "E2E Seed Category" });
  await fieldInput(itemModal, "Price (LKR)").fill("950");
  await itemModal.getByRole("button", { name: /^save item$/i }).click();
  await expect(itemModal).toBeHidden();
  await expect(page.getByText("E2E Seed Dish")).toBeVisible();
});
