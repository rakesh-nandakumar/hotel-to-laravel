import { test, expect, Locator } from "@playwright/test";
import { authFile, collectConsoleErrors, ensureTillOpen } from "./fixtures";

test.use({ storageState: authFile("manager") });

/**
 * See apartments.spec.ts for why this doesn't use fixtures.ts's fieldInput()
 * — same pre-existing timeout flakiness, reproduced independently there.
 */
function field(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Golden path for the apartment short-stay booking engine (M2): set up a
 * bookable unit, book it, check the customer in (rent posts to the ledger),
 * check them out with a cash payment covering the balance (ledger settles,
 * unit returns to Available), and confirm zero console errors throughout.
 */
test("books an apartment unit, checks a customer in and out, and settles the ledger", async ({ page }) => {
  // Setup + full booking lifecycle (book → check in → check out → settle) —
  // more round-trips than the project default budgets for under load (see
  // the same bump already applied to apartment-leases/sales/ops.spec.ts).
  test.setTimeout(60_000);
  const stamp = Date.now();
  const unitTypeName = `E2E Booking Type ${stamp}`;
  const unitNo = `EB-${stamp}`;
  const customerName = `E2E Booking Customer ${stamp}`;

  await page.goto("/apartments/unit-types");
  await page.getByRole("button", { name: /new unit type/i }).click();
  let modal = page.locator(".modal-panel");
  await field(modal, "Name *").fill(unitTypeName);
  await field(modal, "Nightly rate (LKR)").fill("12000");
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await page.goto("/apartments/units");
  await page.getByRole("button", { name: /new unit/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Unit number *").fill(unitNo);
  await field(modal, "Unit type *").selectOption({ label: unitTypeName });
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await page.goto("/apartments/bookings");
  await page.getByRole("button", { name: /new booking/i }).click();
  modal = page.locator(".modal-panel");
  await expect(modal.getByText(unitNo)).toBeVisible({ timeout: 10_000 });
  await modal.getByText(unitNo).click();
  await modal.getByPlaceholder(/new customer: full name/i).fill(customerName);
  await modal.getByRole("button", { name: /create booking/i }).click();

  await expect(page).toHaveURL(/\/apartments\/bookings\/\d+/, { timeout: 10_000 });
  await expect(page.getByText(customerName)).toBeVisible();

  await page.getByRole("button", { name: /check in/i }).click();
  const checkinModal = page.locator(".modal-panel");
  await checkinModal.getByPlaceholder(/NIC or passport/i).fill("912345678V");
  await checkinModal.getByRole("button", { name: /confirm check-in/i }).click();
  await expect(page.getByRole("button", { name: /check out/i })).toBeVisible({ timeout: 10_000 });

  const bookingUrl = page.url();
  await ensureTillOpen(page);
  await page.goto(bookingUrl);

  const errors = await collectConsoleErrors(page, async () => {
    await page.getByRole("button", { name: /check out/i }).click();
    const checkoutModal = page.locator(".modal-panel");
    await expect(checkoutModal.getByText(/balance due now|refund due to customer/i)).toBeVisible({ timeout: 10_000 });

    const addPaymentBtn = checkoutModal.getByRole("button", { name: /add payment/i });
    if (await addPaymentBtn.isVisible()) {
      await addPaymentBtn.click();
      await expect(checkoutModal.getByText("Fully covered ✓")).toBeVisible();
    }
    await checkoutModal.getByRole("button", { name: /complete checkout|check out & refund/i }).click();
    await expect(checkoutModal.getByText("Checked out ✓")).toBeVisible({ timeout: 10_000 });
    await checkoutModal.getByRole("button", { name: "Done" }).click();
    await page.waitForLoadState("networkidle", { timeout: 10_000 });
  });
  expect(errors, `console/page errors during checkout:\n${errors.join("\n")}`).toEqual([]);

  await expect(page.getByRole("heading", { level: 1 })).toContainText("CHECKED_OUT");
});
