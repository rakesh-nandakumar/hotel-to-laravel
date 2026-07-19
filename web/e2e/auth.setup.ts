import { test as setup } from "@playwright/test";
import { USERS, RoleKey, authFile, loginAsUI } from "./fixtures";

for (const role of Object.keys(USERS) as RoleKey[]) {
  setup(`authenticate as ${role}`, async ({ page }) => {
    await loginAsUI(page, role);
    await page.context().storageState({ path: authFile(role) });
  });
}
