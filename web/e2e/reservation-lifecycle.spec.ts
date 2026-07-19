import { test, expect } from "@playwright/test";
import { authFile, collectConsoleErrors } from "./fixtures";

test.use({ storageState: authFile("manager") });

/**
 * Core front-desk flow end to end: create a booking, check the guest in,
 * check them out (paying the balance), then confirm the room's resulting
 * cleaning task shows up correctly on the Housekeeping page — the exact
 * data path that used to crash with "Cannot read properties of undefined
 * (reading 'code')" before the `status` relation was eager-loaded.
 */
test("books a room, checks a guest in and out, and the resulting cleaning task appears", async ({ page }) => {
  const guestName = `E2E Guest ${Date.now()}`;

  await page.goto("/reservations");
  await page.getByRole("button", { name: /new booking/i }).click();

  const modal = page.locator(".modal-panel");
  await expect(modal.getByText(/checking availability/i)).toBeHidden({ timeout: 10_000 });
  const roomButtons = modal.locator("button", { hasText: "Room " });
  await expect(roomButtons.first()).toBeVisible();
  const roomLabel = (await roomButtons.first().locator("div").first().innerText()).trim();
  await roomButtons.first().click();

  await modal.getByPlaceholder(/new guest: full name/i).fill(guestName);
  await modal.getByRole("button", { name: /create booking/i }).click();

  await expect(page).toHaveURL(/\/reservations\/\d+/, { timeout: 10_000 });
  await expect(page.getByText(guestName)).toBeVisible();

  // ── Check in ──────────────────────────────────────────────────────────
  await page.getByRole("button", { name: /check in/i }).click();
  const checkinModal = page.locator(".modal-panel");
  await checkinModal.getByPlaceholder(/NIC or passport/i).fill("912345678V");
  await checkinModal.getByRole("button", { name: /confirm check-in/i }).click();
  await expect(page.getByRole("button", { name: /check out/i })).toBeVisible({ timeout: 10_000 });

  // ── Check out (cover the full balance with one cash payment) ───────────
  await page.getByRole("button", { name: /check out/i }).click();
  const checkoutModal = page.locator(".modal-panel");
  await expect(checkoutModal.getByText(/balance due now|refund due to guest/i)).toBeVisible({ timeout: 10_000 });

  const addPaymentBtn = checkoutModal.getByRole("button", { name: /add payment/i });
  if (await addPaymentBtn.isVisible()) {
    await addPaymentBtn.click();
    await expect(checkoutModal.getByText("Fully covered ✓")).toBeVisible();
  }
  await checkoutModal.getByRole("button", { name: /complete checkout|check out & refund/i }).click();
  await expect(checkoutModal.getByText("Checked out ✓")).toBeVisible({ timeout: 10_000 });
  await checkoutModal.getByRole("button", { name: "Done" }).click();

  // ── The checkout auto-creates a housekeeping task for this room ────────
  const errors = await collectConsoleErrors(page, async () => {
    await page.goto("/housekeeping");
    await page.waitForLoadState("networkidle");
  });
  expect(errors, `console/page errors on /housekeeping:\n${errors.join("\n")}`).toEqual([]);
  await expect(page.getByText(roomLabel, { exact: true })).toBeVisible();
});
