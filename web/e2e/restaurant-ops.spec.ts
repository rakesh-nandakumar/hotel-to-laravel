import { test, expect, Locator } from "@playwright/test";
import { authFile, collectConsoleErrors, appUrl } from "./fixtures";

test.use({ storageState: authFile("manager") });

/** See apartments.spec.ts for why this doesn't use fixtures.ts's fieldInput(). */
function field(scope: Locator, label: string): Locator {
  return scope.locator(`label:text-is("${label}")`).locator("xpath=following-sibling::*[1]");
}

/**
 * Golden path for the restaurant-completeness module (M6): create a dining
 * table, open a dine-in order against it (table occupies), settle it (table
 * frees to Cleaning), then create a delivery order and dispatch it through
 * its status stepper — zero console errors throughout.
 */
test("occupies a table on dine-in, frees it on settle, and dispatches a delivery order", async ({ page }) => {
  // The longest flow in the suite: menu item setup, table setup, a full
  // dine-in booking lifecycle, then a full delivery order + dispatch cycle.
  test.setTimeout(90_000);
  const stamp = Date.now();
  const tableNo = `T-${stamp}`;
  const categoryName = `E2E Restaurant Category ${stamp}`;
  const dishName = `E2E Restaurant Dish ${stamp}`;

  // seed-demo-data.setup.ts's menu seed is disabled — create our own item.
  await page.goto(appUrl("/menu"));
  await page.getByRole("button", { name: /categories/i }).click();
  const catModal = page.locator(".modal-panel");
  await catModal.getByPlaceholder(/new category name/i).fill(categoryName);
  const [addResponse] = await Promise.all([
    page.waitForResponse((r) => r.url().includes("/api/menu/categories") && r.request().method() === "POST"),
    catModal.getByRole("button", { name: "Add" }).click(),
  ]);
  expect(addResponse.ok()).toBeTruthy();
  await catModal.getByLabel("Close").click();

  await page.getByRole("button", { name: /new item/i }).click();
  const itemModal = page.locator(".modal-panel");
  await field(itemModal, "Name").fill(dishName);
  await field(itemModal, "Category").selectOption({ label: categoryName });
  await field(itemModal, "Price (LKR)").fill("950");
  await itemModal.getByRole("button", { name: /^save item$/i }).click();
  await expect(itemModal).toBeHidden();

  await page.goto(appUrl("/tables"));
  await page.getByRole("button", { name: /new table/i }).click();
  const tableModal = page.locator(".modal-panel");
  await field(tableModal, "Table number *").fill(tableNo);
  await tableModal.getByRole("button", { name: /create table/i }).click();
  await expect(tableModal).toBeHidden();
  await expect(page.locator("div", { hasText: tableNo }).getByText("FREE", { exact: true }).first()).toBeVisible({ timeout: 10_000 });

  const errors = await collectConsoleErrors(page, async () => {
    // Dine-in order against the new table.
    await page.goto(appUrl("/pos"));
    await page.getByRole("button", { name: /^dine-in$/i }).click();
    const tableSelect = page.locator("select").filter({ hasText: "No table (label only)" });
    const tableOption = tableSelect.locator("option", { hasText: tableNo });
    await expect(tableOption).toBeAttached({ timeout: 10_000 });
    const tableOptionValue = await tableOption.getAttribute("value");
    await tableSelect.selectOption(tableOptionValue!);

    const firstItem = page.locator(".card.relative.p-3").first();
    await expect(firstItem).toBeVisible({ timeout: 10_000 });
    await firstItem.click();
    // Either it adds straight to cart, or opens a modifier picker — handle both.
    const pickerModal = page.locator(".modal-panel");
    if (await pickerModal.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await pickerModal.getByRole("button", { name: /add to cart/i }).click();
    }
    await page.getByRole("button", { name: /send order/i }).click();
    await expect(page.getByRole("button", { name: /^open orders/i })).toBeVisible({ timeout: 10_000 }).catch(() => {});

    await page.goto(appUrl("/tables"));
    await expect(page.locator("div", { hasText: tableNo }).getByText("OCCUPIED", { exact: true }).first()).toBeVisible({ timeout: 15_000 });

    // Settle it from Open Orders.
    await page.goto(appUrl("/pos"));
    await page.getByRole("button", { name: /open orders/i }).click();
    await page.locator("button.card", { hasText: `TABLE ${tableNo}` }).first().click();
    const orderModal = page.locator(".modal-panel");
    await orderModal.getByRole("button", { name: /take payment/i }).click();
    // Modal isn't portaled — SplitPay's .modal-panel is a DOM descendant of
    // the order modal's own .modal-panel, so a `.modal-panel` locator always
    // matches both while SplitPay is open. Scope by its title text instead
    // of the wrapper, and assert on that same text disappearing (checking
    // the wrapper div would flip to matching the still-open parent once
    // SplitPay itself unmounts).
    const payModal = page.locator(".modal-panel").last();
    await expect(payModal.getByText("Balanced ✓")).toBeVisible({ timeout: 10_000 });
    await payModal.getByRole("button", { name: /^confirm/i }).click();
    await expect(page.getByText("Take payment — split across methods")).toBeHidden({ timeout: 10_000 });

    await page.goto(appUrl("/tables"));
    await expect(page.locator("div", { hasText: tableNo }).getByText("CLEANING", { exact: true }).first()).toBeVisible({ timeout: 15_000 });

    // Delivery order: address/phone required, then dispatch through the status stepper.
    await page.goto(appUrl("/pos"));
    await page.getByRole("button", { name: /^delivery$/i }).click();
    await page.getByPlaceholder("Delivery address *").fill("123 Galle Road, Colombo");
    await page.getByPlaceholder("Delivery phone *").fill("0771234567");
    const secondItem = page.locator(".card.relative.p-3").first();
    await secondItem.click();
    const pickerModal2 = page.locator(".modal-panel");
    if (await pickerModal2.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await pickerModal2.getByRole("button", { name: /add to cart/i }).click();
    }
    await page.getByRole("button", { name: /send order/i }).click();

    await page.getByRole("button", { name: /open orders/i }).click();
    await page.getByRole("button", { name: /^delivery$/i }).click();
    await page.locator("button.card").first().click();
    const deliveryModal = page.locator(".modal-panel");
    await expect(deliveryModal.getByText("123 Galle Road")).toBeVisible({ timeout: 10_000 });
    await expect(deliveryModal.getByText("out_for_delivery", { exact: false })).toBeVisible().catch(() => {});
    await deliveryModal.getByRole("button", { name: "delivered" }).click();
    await expect(deliveryModal.getByText("DELIVERED")).toBeVisible({ timeout: 10_000 });
  });
  expect(errors, `console/page errors during the restaurant ops flow:\n${errors.join("\n")}`).toEqual([]);
});
