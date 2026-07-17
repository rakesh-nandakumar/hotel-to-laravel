import { Router } from "express";
import { z } from "zod";
import { PaymentMethod } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { recordPayment } from "../lib/billing";
import { audit } from "../lib/audit";

const router = Router();
router.use(requireRole(...MANAGERS));

async function withOutstanding(accounts: { id: string }[]) {
  // Outstanding credit = CORPORATE_CREDIT charges on their reservations' folios minus settlements
  const result = [];
  for (const acc of accounts) {
    const charges = await prisma.payment.aggregate({
      where: { method: "CORPORATE_CREDIT", folio: { reservation: { corporateAccountId: acc.id } } },
      _sum: { amount: true },
    });
    const settlements = await prisma.payment.aggregate({
      where: { corporateAccountId: acc.id },
      _sum: { amount: true },
    });
    result.push({ ...acc, outstanding: (charges._sum.amount ?? 0) - (settlements._sum.amount ?? 0) });
  }
  return result;
}

router.get(
  "/",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.corporateAccount.findMany({ orderBy: { companyName: "asc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.corporateAccount.count(),
      ]);
      return res.json({ rows: await withOutstanding(rows), total, page, pageSize });
    }

    const accounts = await prisma.corporateAccount.findMany({ orderBy: { companyName: "asc" } });
    res.json(await withOutstanding(accounts));
  })
);

const accBody = z.object({
  companyName: z.string().min(1),
  contactName: z.string().optional().nullable(),
  phone: z.string().optional().nullable(),
  email: z.string().optional().nullable(),
  address: z.string().optional().nullable(),
  discountPct: z.number().min(0).max(100).optional(),
  creditLimit: z.number().int().min(0).optional(),
  active: z.boolean().optional(),
});

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const acc = await prisma.corporateAccount.create({ data: accBody.parse(req.body) });
    audit(req.user!.id, "CORPORATE_CREATE", "CorporateAccount", acc.id, { companyName: acc.companyName });
    res.status(201).json(acc);
  })
);

router.put(
  "/:id",
  asyncHandler(async (req, res) => {
    const acc = await prisma.corporateAccount.update({ where: { id: req.params.id }, data: accBody.partial().parse(req.body) });
    audit(req.user!.id, "CORPORATE_UPDATE", "CorporateAccount", acc.id);
    res.json(acc);
  })
);

/** Month-end statement: all credit charges in the month + settlements. */
router.get(
  "/:id/statement",
  asyncHandler(async (req, res) => {
    const month = typeof req.query.month === "string" ? req.query.month : new Date().toISOString().slice(0, 7);
    const [y, m] = month.split("-").map(Number);
    const from = new Date(Date.UTC(y, m - 1, 1));
    const to = new Date(Date.UTC(m === 12 ? y + 1 : y, m === 12 ? 0 : m, 1));
    const acc = await prisma.corporateAccount.findUnique({ where: { id: req.params.id } });
    if (!acc) throw new ApiError(404, "Corporate account not found");
    const charges = await prisma.payment.findMany({
      where: { method: "CORPORATE_CREDIT", createdAt: { gte: from, lt: to }, folio: { reservation: { corporateAccountId: acc.id } } },
      include: { folio: { include: { reservation: { include: { guest: { select: { name: true } } } } } } },
      orderBy: { createdAt: "asc" },
    });
    const settlements = await prisma.payment.findMany({
      where: { corporateAccountId: acc.id, createdAt: { gte: from, lt: to } },
      orderBy: { createdAt: "asc" },
    });
    res.json({
      account: acc,
      month,
      charges: charges.map((c) => ({
        id: c.id, date: c.createdAt, amount: c.amount,
        reservation: c.folio?.reservation?.code, guest: c.folio?.reservation?.guest?.name, invoiceNo: c.folio?.invoiceNo,
      })),
      settlements,
      totalCharges: charges.reduce((s, c) => s + c.amount, 0),
      totalSettled: settlements.reduce((s, c) => s + c.amount, 0),
    });
  })
);

/** Record a month-end settlement payment from the company. */
router.post(
  "/:id/settle",
  asyncHandler(async (req, res) => {
    const body = z.object({ amount: z.number().int().min(1), method: z.nativeEnum(PaymentMethod), reference: z.string().optional() }).parse(req.body);
    if (body.method === "CORPORATE_CREDIT" || body.method === "LOYALTY_POINTS")
      throw new ApiError(400, "Settlement must be a real payment method");
    const payment = await recordPayment({
      corporateAccountId: req.params.id, method: body.method, amount: body.amount,
      reference: body.reference, staffId: req.user!.id,
    });
    res.status(201).json(payment);
  })
);

export default router;
