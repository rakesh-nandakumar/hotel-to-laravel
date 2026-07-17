import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { invalidateSettings } from "../lib/settings";
import { audit } from "../lib/audit";

const router = Router();

/**
 * Deep/technical settings (integration credentials, gateways) belong to
 * SYSTEM_ADMIN only — hidden from the Owner and everyone else, and blocked
 * from modification server-side. Owner/Manager keep all business settings.
 */
const ADMIN_ONLY_CATEGORIES = new Set(["integrations"]);

/** All staff can read business settings; integration secrets only for SYSTEM_ADMIN. */
router.get(
  "/",
  asyncHandler(async (req, res) => {
    const rows = await prisma.setting.findMany({ orderBy: [{ category: "asc" }, { key: "asc" }] });
    const visible = rows.filter((r) => req.user!.role === "SYSTEM_ADMIN" || !ADMIN_ONLY_CATEGORIES.has(r.category));
    res.json(visible.map((r) => ({ ...r, value: JSON.parse(r.value) })));
  })
);

router.put(
  "/:key",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const { value } = z.object({ value: z.any() }).parse(req.body);
    const existing = await prisma.setting.findUnique({ where: { key: req.params.key } });
    if (!existing) throw new ApiError(404, "Unknown setting");
    if (ADMIN_ONLY_CATEGORIES.has(existing.category) && req.user!.role !== "SYSTEM_ADMIN")
      throw new ApiError(403, "Integration settings can only be changed by the System Admin");
    if (existing.type === "NUMBER" || existing.type === "PERCENT" || existing.type === "MONEY") {
      if (typeof value !== "number" || Number.isNaN(value)) throw new ApiError(400, "Value must be a number");
      if (existing.type === "PERCENT" && (value < 0 || value > 100)) throw new ApiError(400, "Percent must be 0–100");
      if (value < 0) throw new ApiError(400, "Value cannot be negative");
    }
    if (existing.type === "BOOLEAN" && typeof value !== "boolean") throw new ApiError(400, "Value must be true/false");
    const updated = await prisma.setting.update({
      where: { key: req.params.key },
      data: { value: JSON.stringify(value), updatedBy: req.user!.id },
    });
    invalidateSettings();
    audit(req.user!.id, "SETTING_CHANGE", "Setting", req.params.key, {
      // never write secrets into the audit log
      from: ADMIN_ONLY_CATEGORIES.has(existing.category) ? "[redacted]" : existing.value,
      to: ADMIN_ONLY_CATEGORIES.has(existing.category) ? "[redacted]" : updated.value,
    });
    res.json({ ...updated, value: JSON.parse(updated.value) });
  })
);

export default router;
