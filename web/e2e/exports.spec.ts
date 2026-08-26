import { test, expect } from "@playwright/test";
import { authFile } from "./fixtures";

test.use({ storageState: authFile("fullAdmin") });

test.describe("Exports", () => {
  test("daily report CSV export downloads a file", async ({ page }) => {
    // "/reports" is the report-picker grid — the Daily view (and its export
    // buttons) only renders at "/reports/daily".
    await page.goto("/reports/daily");
    const [download] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("button", { name: /export csv/i }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/daily-report-.*\.csv/);
  });

  test("daily report PDF export downloads a real PDF file", async ({ page }) => {
    // downloadFile() (web/src/lib/api.ts) fetches the PDF as a blob and
    // triggers it via a plain <a download> click — a real browser download,
    // not a new tab. (Printing the same report is a separate "Print" button
    // next to this one, using printDocument()'s hidden-iframe approach.)
    // "/reports" is the report-picker grid — the Daily view (and its export
    // buttons) only renders at "/reports/daily".
    await page.goto("/reports/daily");
    const [download] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("button", { name: /download pdf/i }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/report-.*\.pdf/);
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
