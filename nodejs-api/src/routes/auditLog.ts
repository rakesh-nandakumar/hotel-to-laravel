/**
 * Audit Log — read-only view over every audit() call written across the
 * system (logins, checkins/checkouts, voids/refunds/discounts, settings
 * changes, staff/payroll changes, stock adjustments, room status changes,
 * night-audit runs, and more — see lib/audit.ts callers). Owner, Manager and
 * System Admin only (requireRole("MANAGER") already grants exactly those
 * three via the built-in Owner/SystemAdmin bypass).
 */
import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";

const router = Router();
router.use(requireRole(...MANAGERS));

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const q = z
      .object({
        staffId: z.string().optional(),
        action: z.string().optional(),
        entity: z.string().optional(),
        q: z.string().optional(), // free-text over action/entity/entityId
        from: z.string().optional(),
        to: z.string().optional(),
        page: z.coerce.number().int().min(1).default(1),
        pageSize: z.coerce.number().int().min(1).max(200).default(50),
      })
      .parse(req.query);

    const where = {
      staffId: q.staffId || undefined,
      action: q.action ? { equals: q.action } : undefined,
      entity: q.entity ? { equals: q.entity } : undefined,
      createdAt: q.from || q.to ? { gte: q.from ? new Date(q.from) : undefined, lte: q.to ? new Date(`${q.to}T23:59:59`) : undefined } : undefined,
      OR: q.q
        ? [
            { action: { contains: q.q, mode: "insensitive" as const } },
            { entity: { contains: q.q, mode: "insensitive" as const } },
            { entityId: { contains: q.q, mode: "insensitive" as const } },
          ]
        : undefined,
    };

    const [rows, total] = await Promise.all([
      prisma.auditLog.findMany({
        where,
        include: { staff: { select: { name: true, role: true } } },
        orderBy: { createdAt: "desc" },
        skip: (q.page - 1) * q.pageSize,
        take: q.pageSize,
      }),
      prisma.auditLog.count({ where }),
    ]);

    res.json({ rows, total, page: q.page, pageSize: q.pageSize });
  })
);

/** Distinct action/entity values — powers the filter dropdowns. */
router.get(
  "/facets",
  asyncHandler(async (_req, res) => {
    const [actions, entities] = await Promise.all([
      prisma.auditLog.findMany({ distinct: ["action"], select: { action: true }, orderBy: { action: "asc" } }),
      prisma.auditLog.findMany({ distinct: ["entity"], select: { entity: true }, orderBy: { entity: "asc" } }),
    ]);
    res.json({ actions: actions.map((a) => a.action), entities: entities.map((e) => e.entity) });
  })
);

export default router;
