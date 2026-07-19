import { test, expect } from "@playwright/test";
import { authFile, fieldInput } from "./fixtures";

test.use({ storageState: authFile("manager") });

// A valid, minimal 1x1 PNG (same fixture used by the backend Pest test for this feature).
const PNG_BASE64 =
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=";

test("uploads a menu item photo and it shows on the POS grid", async ({ page }) => {
  const itemName = `E2E Dish ${Date.now()}`;

  // "E2E Seed Category" is created once for the whole suite by seed-demo-data.setup.ts.
  await page.goto("/menu");
  await page.getByRole("button", { name: /new item/i }).click();
  const itemModal = page.locator(".modal-panel");
  await expect(itemModal.getByText(/new menu item/i)).toBeVisible();

  await fieldInput(itemModal, "Name").fill(itemName);
  await fieldInput(itemModal, "Category").selectOption({ label: /E2E Seed Category/ });
  await fieldInput(itemModal, "Price (LKR)").fill("850");

  const fileInput = itemModal.locator('input[type="file"]');
  await fileInput.setInputFiles({ name: "dish.png", mimeType: "image/png", buffer: Buffer.from(PNG_BASE64, "base64") });
  await expect(itemModal.getByAltText("Preview")).toBeVisible({ timeout: 10_000 });

  await itemModal.getByRole("button", { name: /^save item$/i }).click();
  await expect(itemModal).toBeHidden();
  await expect(page.getByText(itemName)).toBeVisible();

  await page.goto("/pos");
  const posCard = page.locator("button.card", { hasText: itemName });
  await expect(posCard).toBeVisible({ timeout: 10_000 });
  await expect(posCard.locator("img")).toHaveAttribute("src", /^data:image\/png/);
});
