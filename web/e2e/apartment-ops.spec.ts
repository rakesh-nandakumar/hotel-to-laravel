import { test, expect, Locator } from "@playwright/test";
import { authFile, collectConsoleErrors, ensureTillOpen } from "./fixtures";

test.use({ storageState: authFile("manager") });

/** See apartments.spec.ts for why this doesn't use fixtures.ts's fieldInput(). */
function field(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Golden path for apartments ops (M5): a booking checkout leaves the unit
 * Dirty with a turnover task; completing the housekeeping checklist frees it
 * back to Available. Separately, logging and resolving a maintenance issue
 * against another unit. Zero console errors throughout.
 */
test("checks a booking out to Dirty, completes the housekeeping checklist, and resolves a maintenance issue", async ({ page }) => {
  // This flow spans a full booking lifecycle plus housekeeping and
  // maintenance — noticeably more page navigations/API round-trips than a
  // typical spec, so it gets a longer budget than the project default.
  test.setTimeout(60_000);
  const stamp = Date.now();
  const unitTypeName = `E2E Ops Type ${stamp}`;
  const unitNo = `EO-${stamp}`;
  const guestName = `E2E Ops Guest ${stamp}`;
  const maintUnitNo = `EM-${stamp}`;

  await page.goto("/apartments/unit-types");
  await page.getByRole("button", { name: /new unit type/i }).click();
  let modal = page.locator(".modal-panel");
  await field(modal, "Name *").fill(unitTypeName);
  await field(modal, "Nightly rate (LKR)").fill("10000");
  await field(modal, "Cleaning checklist").fill("Vacuum floors\nChange linens");
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await page.goto("/apartments/units");
  await page.getByRole("button", { name: /new unit/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Unit number *").fill(unitNo);
  await field(modal, "Unit type *").selectOption({ label: unitTypeName });
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await page.getByRole("button", { name: /new unit/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Unit number *").fill(maintUnitNo);
  await field(modal, "Unit type *").selectOption({ label: unitTypeName });
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await ensureTillOpen(page);

  const errors = await collectConsoleErrors(page, async () => {
    // Book, check in, check out.
    await page.goto("/apartments/bookings");
    await page.getByRole("button", { name: /new booking/i }).click();
    modal = page.locator(".modal-panel");
    await expect(modal.getByText(unitNo)).toBeVisible({ timeout: 10_000 });
    await modal.getByText(unitNo).click();
    await modal.getByPlaceholder(/new customer: full name/i).fill(guestName);
    await modal.getByRole("button", { name: /create booking/i }).click();
    await expect(page).toHaveURL(/\/apartments\/bookings\/\d+/, { timeout: 10_000 });

    await page.getByRole("button", { name: /check in/i }).click();
    const checkinModal = page.locator(".modal-panel");
    await checkinModal.getByPlaceholder(/NIC or passport/i).fill("911111111V");
    await checkinModal.getByRole("button", { name: /confirm check-in/i }).click();
    await expect(page.getByRole("button", { name: /check out/i })).toBeVisible({ timeout: 10_000 });

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

    await page.goto("/apartments/units");
    await expect(page.locator("tr", { hasText: unitNo }).getByText("Dirty", { exact: true })).toBeVisible({ timeout: 20_000 });

    // Complete the housekeeping checklist.
    await page.goto("/apartments/housekeeping");
    await page.getByText(unitNo, { exact: true }).click({ timeout: 20_000 });
    const hkModal = page.locator(".modal-panel");
    await expect(hkModal.getByText("cleaning checklist")).toBeVisible();
    const checkboxes = hkModal.locator('input[type="checkbox"]');
    await expect(checkboxes.first()).toBeVisible();
    const count = await checkboxes.count();
    expect(count).toBeGreaterThan(0);
    for (let i = 0; i < count; i++) await checkboxes.nth(i).check();
    const submitBtn = hkModal.getByRole("button", { name: /submit checklist/i });
    await expect(submitBtn).toBeEnabled();
    await submitBtn.click();
    await expect(hkModal).toBeHidden({ timeout: 10_000 });

    await page.goto("/apartments/units");
    await expect(page.locator("tr", { hasText: unitNo }).getByText("Available", { exact: true })).toBeVisible({ timeout: 20_000 });

    // Maintenance: log then resolve an issue on the other unit.
    await page.goto("/apartments/maintenance");
    await page.getByRole("button", { name: /log issue/i }).click();
    const maintModal = page.locator(".modal-panel");
    const unitSelect = field(maintModal, "Unit");
    // The modal renders its <select> before the async unit list resolves —
    // wait for the target unit's <option> to actually attach (not just the
    // element to be visible/enabled) before selecting it, otherwise this
    // races the fetch under server contention.
    await expect(unitSelect.locator("option", { hasText: maintUnitNo })).toBeAttached({ timeout: 15_000 });
    await unitSelect.selectOption({ label: maintUnitNo });
    await field(maintModal, "Describe the problem").fill("Air conditioner not cooling properly");
    await maintModal.getByRole("button", { name: /log issue/i }).click();
    await expect(maintModal).toBeHidden({ timeout: 10_000 });
    // Scoped to `main`: the just-fired success toast ("Issue logged — …")
    // repeats this same description text in its own portal outside `main`,
    // which would otherwise make this a strict-mode-violating ambiguous match.
    await expect(page.getByRole("main").getByText("Air conditioner not cooling properly")).toBeVisible();

    await page.getByRole("button", { name: /^resolve$/i }).click();
    const resolveModal = page.locator(".modal-panel");
    await resolveModal.getByRole("button", { name: /mark resolved/i }).click();
    await expect(resolveModal).toBeHidden({ timeout: 10_000 });

    await page.goto("/apartments/reports");
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Reports");
    await page.waitForLoadState("networkidle", { timeout: 10_000 });
  });
  expect(errors, `console/page errors during the ops flow:\n${errors.join("\n")}`).toEqual([]);
});
