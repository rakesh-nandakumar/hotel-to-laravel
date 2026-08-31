import { test, expect, Locator } from "@playwright/test";
import { authFile, collectConsoleErrors, appUrl } from "./fixtures";

test.use({ storageState: authFile("manager") });

/** See apartments.spec.ts for why this doesn't use fixtures.ts's fieldInput(). */
function field(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Golden path for the long-term lease engine (M3): set up a leasable unit,
 * create a lease with a security deposit, record a utility reading (posts to
 * the ledger), renew the term, then terminate — confirming the unit frees up
 * again — all with zero console errors.
 */
test("creates a lease, records a utility reading, renews, and terminates it", async ({ page }) => {
  // Lease create → utility reading → renew → terminate, plus setup and two
  // unit-status checks — more round-trips than the project default budgets for.
  test.setTimeout(60_000);
  const stamp = Date.now();
  const unitTypeName = `E2E Lease Type ${stamp}`;
  const unitNo = `EL-${stamp}`;
  const tenantName = `E2E Tenant ${stamp}`;

  await page.goto(appUrl("/apartments/unit-types"));
  await page.getByRole("button", { name: /new unit type/i }).click();
  let modal = page.locator(".modal-panel");
  await field(modal, "Name *").fill(unitTypeName);
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  await page.goto(appUrl("/apartments/units"));
  await page.getByRole("button", { name: /new unit/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Unit number *").fill(unitNo);
  await field(modal, "Unit type *").selectOption({ label: unitTypeName });
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  const errors = await collectConsoleErrors(page, async () => {
    await page.goto(appUrl("/apartments/leases"));
    await page.getByRole("button", { name: /new lease/i }).click();
    modal = page.locator(".modal-panel");
    await field(modal, "Unit *").selectOption({ label: `${unitNo} — ${unitTypeName}` });
    await field(modal, "Monthly rent (LKR) *").fill("250000");
    await modal.getByPlaceholder(/new tenant: full name/i).fill(tenantName);
    await modal.getByRole("button", { name: /create lease/i }).click();

    await expect(page).toHaveURL(/\/apartments\/leases\/\d+/, { timeout: 10_000 });
    await expect(page.getByText(tenantName)).toBeVisible();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("ACTIVE");

    // Unit should now show OCCUPIED on the Units screen.
    await page.goto(appUrl("/apartments/units"));
    await expect(page.locator("tr", { hasText: unitNo }).getByText("Occupied", { exact: true })).toBeVisible();

    await page.goBack();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("ACTIVE");

    // Record a utility reading.
    await page.getByRole("button", { name: /record utility/i }).click();
    const utilModal = page.locator(".modal-panel");
    await field(utilModal, "Previous reading").fill("100");
    await field(utilModal, "Current reading").fill("150");
    await field(utilModal, "Rate per unit (LKR)").fill("50");
    await utilModal.getByRole("button", { name: /record & post charge/i }).click();
    await expect(utilModal).toBeHidden({ timeout: 10_000 });
    await expect(page.getByText(/electricity/i).first()).toBeVisible();

    // Renew.
    await page.getByRole("button", { name: /^renew$/i }).click();
    const renewModal = page.locator(".modal-panel");
    const farFuture = new Date();
    farFuture.setFullYear(farFuture.getFullYear() + 2);
    await renewModal.locator('input[type="date"]').fill(farFuture.toISOString().slice(0, 10));
    await renewModal.getByRole("button", { name: /renew lease/i }).click();
    await expect(renewModal).toBeHidden({ timeout: 10_000 });
    await expect(page.getByRole("heading", { level: 1 })).toContainText("RENEWED");

    // Terminate.
    await page.getByRole("button", { name: /terminate/i }).click();
    const termModal = page.locator(".modal-panel");
    await field(termModal, "Reason (required — recorded in the audit log)").fill("Early move-out by mutual agreement");
    await termModal.getByRole("button", { name: /^confirm$/i }).click();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("TERMINATED", { timeout: 10_000 });

    // Termination frees the unit but leaves it Dirty pending a move-out
    // clean — same turnover pattern as a short-stay checkout (see
    // apartment-ops.spec.ts) — it does not jump straight back to Available.
    await page.goto(appUrl("/apartments/units"));
    await expect(page.locator("tr", { hasText: unitNo }).getByText("Dirty", { exact: true })).toBeVisible();
    await page.waitForLoadState("networkidle", { timeout: 10_000 });
  });
  expect(errors, `console/page errors during the lease flow:\n${errors.join("\n")}`).toEqual([]);
});
