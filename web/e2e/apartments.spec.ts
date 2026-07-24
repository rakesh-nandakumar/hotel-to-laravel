import { test, expect, Locator } from "@playwright/test";
import { authFile, collectConsoleErrors } from "./fixtures";

test.use({ storageState: authFile("manager") });

/**
 * Alternative to fixtures.ts's fieldInput(): that helper's div-filter
 * heuristic times out intermittently in this environment (reproduced even on
 * pre-existing, untouched forms — e2e\seed-demo-data.setup.ts's menu-item
 * modal hits the exact same timeout) — an existing test-infra flakiness, not
 * a regression here. This walks the DOM directly instead: `<Field>`
 * (components/ui.tsx) always renders `<label>{text}</label>` immediately
 * followed by the control, so the label's next sibling is the input/select.
 */
function field(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Golden path for the new Apartments module (separate from Hotel
 * Rooms/Reservations — see App\Models\Apartment): create a property, a unit
 * type, a unit under it, confirm it lands "Available", confirm the
 * workflow-only statuses (occupied/reserved/sold) aren't offered as manual
 * status buttons, and create a customer.
 */
test("creates a property, unit type, and unit; the unit starts Available with only manual statuses offered; creates a customer", async ({ page }) => {
  const stamp = Date.now();
  const propertyName = `E2E Property ${stamp}`;
  const unitTypeName = `E2E Studio ${stamp}`;
  const unitNo = `E2E-${stamp}`;
  const customerName = `E2E Tenant ${stamp}`;

  // ── Property ────────────────────────────────────────────────────────────
  await page.goto("/apartments/properties");
  await page.getByRole("button", { name: /new property/i }).click();
  let modal = page.locator(".modal-panel");
  await field(modal, "Name *").fill(propertyName);
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();
  await expect(page.getByText(propertyName)).toBeVisible();

  // ── Unit type ───────────────────────────────────────────────────────────
  await page.goto("/apartments/unit-types");
  await page.getByRole("button", { name: /new unit type/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Name *").fill(unitTypeName);
  await field(modal, "Nightly rate (LKR)").fill("15000");
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();
  await expect(page.getByText(unitTypeName)).toBeVisible();

  // ── Unit ────────────────────────────────────────────────────────────────
  await page.goto("/apartments/units");
  await page.getByRole("button", { name: /new unit/i }).click();
  modal = page.locator(".modal-panel");
  await field(modal, "Unit number *").fill(unitNo);
  await field(modal, "Property").selectOption({ label: propertyName });
  await field(modal, "Unit type *").selectOption({ label: unitTypeName });
  await modal.getByRole("button", { name: /^save$/i }).click();
  await expect(modal).toBeHidden();

  const unitRow = page.locator("tr", { hasText: unitNo });
  await expect(unitRow).toBeVisible();
  await expect(unitRow.getByText("Available", { exact: true })).toBeVisible();

  // ── Status change: only the manual statuses are offered ───────────────────
  await unitRow.click();
  modal = page.locator(".modal-panel");
  await expect(modal.getByText(/change status/i)).toBeVisible();
  for (const manual of ["available", "maintenance", "blocked", "off market"]) {
    await expect(modal.getByRole("button", { name: new RegExp(`^${manual}$`, "i") })).toBeVisible();
  }
  for (const workflowOnly of ["occupied", "reserved", "sold"]) {
    await expect(modal.getByRole("button", { name: new RegExp(`^${workflowOnly}$`, "i") })).toHaveCount(0);
  }
  await modal.getByRole("button", { name: /^maintenance$/i }).click();
  await expect(modal).toBeHidden({ timeout: 10_000 });

  const updatedRow = page.locator("tr", { hasText: unitNo });
  await expect(updatedRow.getByText("Maintenance", { exact: true })).toBeVisible();

  // ── Customer ────────────────────────────────────────────────────────────
  const errors = await collectConsoleErrors(page, async () => {
    await page.goto("/apartments/customers");
    await page.getByRole("button", { name: /new customer/i }).click();
    const custModal = page.locator(".modal-panel");
    await field(custModal, "Full name *").fill(customerName);
    await field(custModal, "Phone").fill("0771234567");
    await custModal.getByRole("button", { name: /^save$/i }).click();
    await expect(custModal).toBeHidden();
    await expect(page.getByText(customerName)).toBeVisible();
    await page.waitForLoadState("networkidle");
  });
  expect(errors, `console/page errors creating a customer:\n${errors.join("\n")}`).toEqual([]);
});
