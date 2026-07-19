import { test, expect } from "@playwright/test";
import { authFile } from "./fixtures";

test.use({ storageState: authFile("fullAdmin") });

test.describe("Exports", () => {
  test("daily report CSV export downloads a file", async ({ page }) => {
    await page.goto("/reports");
    const [download] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("button", { name: /export csv/i }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/daily-report-.*\.csv/);
  });

  test("daily report PDF export opens a real PDF in a new tab, not a blocked popup", async ({ page, context }) => {
    await page.goto("/reports");
    const [pdfTab] = await Promise.all([
      context.waitForEvent("page"),
      page.getByRole("button", { name: /download pdf/i }).click(),
    ]);
    await pdfTab.waitForLoadState();
    // openPdf() navigates the pre-opened tab to a blob: URL once the PDF response arrives —
    // it must NOT be left on "about:blank" (that's exactly what a silently-blocked popup looks like).
    expect(pdfTab.url()).toMatch(/^blob:/);
    await pdfTab.close();
  });

  test("attendance CSV export downloads a file", async ({ page }) => {
    await page.goto("/attendance");
    const [download] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("button", { name: /^csv$/i }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/attendance-.*\.csv/);
  });

  test("audit log CSV export downloads a file", async ({ page }) => {
    await page.goto("/audit-log");
    const [download] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("button", { name: /export csv/i }).click(),
    ]);
    expect(download.suggestedFilename()).toBe("audit-logs.csv");
  });
});
