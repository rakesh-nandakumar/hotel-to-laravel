import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { audit } from "../lib/audit";

const router = Router();
router.use(requireRole(...MANAGERS));

/** My open shift (POS shows drawer state). */
router.get(
  "/current",
  asyncHandler(async (req, res) => {
    const shift = await prisma.shift.findFirst({ where: { staffId: req.user!.id, closedAt: null } });
    if (!shift) return res.json(null);
    const cash = await cashForShift(shift.id);
    res.json({ ...shift, ...cash, expectedNow: shift.openingCash + cash.cashIn - cash.cashOut });
  })
);

router.post(
  "/open",
  asyncHandler(async (req, res) => {
    const { openingCash } = z.object({ openingCash: z.number().int().min(0) }).parse(req.body);
    const open = await prisma.shift.findFirst({ where: { staffId: req.user!.id, closedAt: null } });
    if (open) throw new ApiError(400, "You already have an open shift — close it first");
    const shift = await prisma.shift.create({ data: { staffId: req.user!.id, openingCash } });
    audit(req.user!.id, "SHIFT_OPEN", "Shift", shift.id, { openingCash });
    res.status(201).json(shift);
  })
);

/** Close with counted cash → automatic drawer reconciliation + variance. */
router.post(
  "/:id/close",
  asyncHandler(async (req, res) => {
    const body = z.object({ closingCash: z.number().int().min(0), notes: z.string().optional() }).parse(req.body);
    const shift = await prisma.shift.findUnique({ where: { id: req.params.id } });
    if (!shift || shift.closedAt) throw new ApiError(400, "Shift not open");
    if (shift.staffId !== req.user!.id && req.user!.role !== "OWNER" && req.user!.role !== "MANAGER")
      throw new ApiError(403, "Not your shift");
    const cash = await cashForShift(shift.id);
    const expectedCash = shift.openingCash + cash.cashIn - cash.cashOut;
    const variance = body.closingCash - expectedCash;
    const closed = await prisma.shift.update({
      where: { id: shift.id },
      data: { closedAt: new Date(), closingCash: body.closingCash, expectedCash, variance, notes: body.notes },
    });
    audit(req.user!.id, "SHIFT_CLOSE", "Shift", shift.id, { expectedCash, closingCash: body.closingCash, variance });
    res.json(closed);
  })
);

router.get(
  "/",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.shift.findMany({
          include: { staff: { select: { name: true } } },
          orderBy: { openedAt: "desc" },
          skip: (page - 1) * pageSize,
          take: pageSize,
        }),
        prisma.shift.count(),
      ]);
      return res.json({ rows, total, page, pageSize });
    }

    const shifts = await prisma.shift.findMany({
      include: { staff: { select: { name: true } } },
      orderBy: { openedAt: "desc" },
      take: 60,
    });
    res.json(shifts);
  })
);

async function cashForShift(shiftId: string) {
  const payments = await prisma.payment.findMany({ where: { shiftId, method: "CASH" } });
  const cashIn = payments.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0);
  const cashOut = payments.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0);
  return { cashIn, cashOut };
}

export default router;
