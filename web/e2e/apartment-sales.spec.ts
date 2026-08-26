import { test, expect, Locator } from "@playwright/test";
import { authFile, collectConsoleErrors, ensureTillOpen } from "./fixtures";

test.use({ storageState: authFile("manager") });

/** See apartments.spec.ts for why this doesn't use fixtures.ts's fieldInput(). */
function field(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Golden path for the sale/purchase pipeline (M4): list a unit for sale,
 * open an inquiry, reserve it (unit locks out of rental — verified on the
 * Units screen), sign the agreement, pay the balance in full, complete the
 * sale, and confirm the unit is permanently marked Sold — zero console errors.
 */
test("takes a unit through inquiry, reservation, agreement, full payment, and completion", async ({ page }) => {
  // Full sale pipeline (inquiry → reserve → sign → pay → complete) plus two
  // unit-type/unit setup steps — more round-trips than the project default budgets for.
  test.setTimeout(60_000);
  const stamp = Date.now();
  const unitTypeName = `E2E Sale Type ${stamp}`;
  const unitNo = `ES-${stamp}`;
  const buyerName = `E2E Buyer ${stamp}`;

  await page.goto("/apartments/unit-types");
  await page.getByRole("button", { name: /new unit type/i }).click();
  let modal = page.locator(".modal-panel");
  await field(modal, "Name *").fill(unitTypeName);
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await page.goto("/apartments/units");
  await page.getByRole("button", { name: /new unit/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Unit number *").fill(unitNo);
  await field(modal, "Unit type *").selectOption({ label: unitTypeName });
  await field(modal, "Listing type").selectOption({ label: "For sale" });
  await field(modal, "Sale price (LKR) *").fill("25000000");
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await ensureTillOpen(page);

  const errors = await collectConsoleErrors(page, async () => {
    await page.goto("/apartments/sales");
    await page.getByRole("button", { name: /new sale inquiry/i }).click();
    modal = page.locator(".modal-panel");
    await field(modal, "Unit for sale *").selectOption({ label: `${unitNo} — ${unitTypeName}` });
    await modal.getByPlaceholder(/new buyer: full name/i).fill(buyerName);
    await modal.getByRole("button", { name: /create inquiry/i }).click();

    await expect(page).toHaveURL(/\/apartments\/sales\/\d+/, { timeout: 10_000 });
    await expect(page.getByRole("heading", { level: 1 })).toContainText("INQUIRY");

    // Reserve.
    await page.getByRole("button", { name: /^reserve$/i }).click();
    const reserveModal = page.locator(".modal-panel");
    await reserveModal.getByRole("button", { name: /reserve unit/i }).click();
    await expect(reserveModal).toBeHidden({ timeout: 10_000 });
    await expect(page.getByRole("heading", { level: 1 })).toContainText("RESERVED");

    // Unit should now be excluded from rental availability / shown Reserved on the Units board.
    await page.goto("/apartments/units");
    await expect(page.locator("tr", { hasText: unitNo }).getByText("Reserved", { exact: true })).toBeVisible();
    await page.goBack();

    // Sign agreement.
    await page.getByRole("button", { name: /sign agreement/i }).click();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("AGREEMENT_SIGNED", { timeout: 10_000 });

    // Pay the balance in full — SplitPay (components/POS.tsx) pre-fills the
    // first row with the exact amount due, so "Confirm ..." is enough.
    await page.getByRole("button", { name: /take payment/i }).click();
    const payModal = page.locator(".modal-panel");
    await expect(payModal.getByText("Balanced ✓")).toBeVisible();
    await payModal.getByRole("button", { name: /^confirm/i }).click();
    await expect(payModal).toBeHidden({ timeout: 10_000 });

    // Complete the sale.
    await page.getByRole("button", { name: /complete sale/i }).click();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("COMPLETED", { timeout: 10_000 });

    await page.goto("/apartments/units");
    await expect(page.locator("tr", { hasText: unitNo }).getByText("Sold", { exact: true })).toBeVisible();
    await page.waitForLoadState("networkidle", { timeout: 10_000 });
  });
  expect(errors, `console/page errors during the sale flow:\n${errors.join("\n")}`).toEqual([]);
});
