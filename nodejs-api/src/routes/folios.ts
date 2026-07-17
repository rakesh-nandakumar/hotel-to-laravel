import { Router } from "express";
import { z } from "zod";
import { LineSource, PaymentMethod } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { audit } from "../lib/audit";
import { folioWithTotals, recordPayment } from "../lib/billing";
import { folioInvoicePdf } from "../lib/pdf";

const router = Router();
router.use(requireRole(...MANAGERS));

router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    res.json(await folioWithTotals(req.params.id));
  })
);

/** Add a manual charge line: minibar, damage/replacement, adjustment, venue extras. */
router.post(
  "/:id/lines",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        source: z.enum(["MINIBAR", "LAUNDRY", "DAMAGE", "ADJUSTMENT", "VENUE", "SURCHARGE"] satisfies [LineSource, ...LineSource[]]),
        description: z.string().min(1),
        qty: z.number().min(0.01).default(1),
        unitPrice: z.number().int(),
      })
      .parse(req.body);
    const folio = await prisma.folio.findUnique({ where: { id: req.params.id } });
    if (!folio) throw new ApiError(404, "Folio not found");
    if (folio.status !== "OPEN") throw new ApiError(400, "Folio is settled — reopen not allowed");
    const line = await prisma.folioLine.create({
      data: {
        folioId: folio.id, source: body.source, description: body.description,
        qty: body.qty, unitPrice: body.unitPrice, amount: Math.round(body.qty * body.unitPrice),
        staffId: req.user!.id,
      },
    });
    audit(req.user!.id, "FOLIO_LINE_ADD", "FolioLine", line.id, { source: body.source, amount: line.amount });
    res.status(201).json(line);
  })
);

/** Void a line — mandatory reason, keeps the audit trail. */
router.post(
  "/lines/:lineId/void",
  asyncHandler(async (req, res) => {
    const { reason } = z.object({ reason: z.string().min(1, "Void reason required") }).parse(req.body);
    const line = await prisma.folioLine.findUnique({ where: { id: req.params.lineId }, include: { folio: true } });
    if (!line) throw new ApiError(404, "Line not found");
    if (line.folio.status !== "OPEN") throw new ApiError(400, "Folio already settled");
    await prisma.folioLine.update({ where: { id: line.id }, data: { voided: true, voidReason: reason } });
    audit(req.user!.id, "FOLIO_LINE_VOID", "FolioLine", line.id, { reason, amount: line.amount });
    res.json({ ok: true });
  })
);

/** Payment against a folio (deposit / interim / mixed methods). */
router.post(
  "/:id/payments",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        method: z.nativeEnum(PaymentMethod),
        amount: z.number().int().min(1),
        kind: z.enum(["PAYMENT", "DEPOSIT"]).default("PAYMENT"),
        reference: z.string().optional(),
        idempotencyKey: z.string().optional(),
      })
      .parse(req.body);
    const folio = await prisma.folio.findUnique({
      where: { id: req.params.id },
      include: { reservation: { select: { guestId: true, corporateAccountId: true } }, venueBooking: { select: { guestId: true } } },
    });
    if (!folio) throw new ApiError(404, "Folio not found");
    if (body.method === "CORPORATE_CREDIT" && !folio.reservation?.corporateAccountId)
      throw new ApiError(400, "Corporate credit only on corporate bookings");
    const payment = await recordPayment({
      folioId: folio.id, method: body.method, amount: body.amount, kind: body.kind,
      reference: body.reference, idempotencyKey: body.idempotencyKey, staffId: req.user!.id,
      guestIdForLoyalty: folio.reservation?.guestId ?? folio.venueBooking?.guestId ?? undefined,
    });
    res.status(201).json(payment);
  })
);

/** Refund from a folio — mandatory reason. */
router.post(
  "/:id/refund",
  asyncHandler(async (req, res) => {
    const body = z
      .object({ method: z.nativeEnum(PaymentMethod), amount: z.number().int().min(1), reason: z.string().min(1, "Refund reason required") })
      .parse(req.body);
    const f = await folioWithTotals(req.params.id);
    if (body.amount > f.paid - f.refunded) throw new ApiError(400, "Refund exceeds net amount paid");
    const payment = await recordPayment({
      folioId: req.params.id, method: body.method, amount: body.amount, kind: "REFUND", reason: body.reason, staffId: req.user!.id,
    });
    res.status(201).json(payment);
  })
);

/** Branded invoice PDF — ?format=thermal|a4 (guest INV / venue VNU types). */
router.get(
  "/:id/invoice",
  asyncHandler(async (req, res) => {
    const format = req.query.format === "thermal" ? "thermal" : "a4";
    await folioInvoicePdf(req.params.id, format, res);
  })
);

export default router;
