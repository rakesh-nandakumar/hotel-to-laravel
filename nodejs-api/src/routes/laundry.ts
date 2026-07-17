import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { checkedInReservationForRoom } from "../lib/pos";

const router = Router();
// Housekeeper collects/returns laundry; Manager manages prices & charges too.
router.use(requireRole("MANAGER", "HOUSEKEEPER"));

router.get(
  "/items",
  asyncHandler(async (_req, res) => {
    res.json(await prisma.laundryItem.findMany({ orderBy: { name: "asc" } }));
  })
);

const itemBody = z.object({ name: z.string().min(1), price: z.number().int().min(0), active: z.boolean().optional() });

router.post(
  "/items",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    res.status(201).json(await prisma.laundryItem.create({ data: itemBody.parse(req.body) }));
  })
);

router.put(
  "/items/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    res.json(await prisma.laundryItem.update({ where: { id: req.params.id }, data: itemBody.partial().parse(req.body) }));
  })
);

/**
 * Charge laundry to a checked-in guest's folio — one auditable LAUNDRY line
 * per item type; appears on the consolidated A4 bill at checkout.
 */
router.post(
  "/charge",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        roomId: z.string(),
        items: z.array(z.object({ laundryItemId: z.string(), qty: z.number().int().min(1) })).min(1),
        note: z.string().optional(),
      })
      .parse(req.body);

    const reservation = await checkedInReservationForRoom(body.roomId);
    const priceList = await prisma.laundryItem.findMany({ where: { id: { in: body.items.map((i) => i.laundryItemId) } } });
    const byId = new Map(priceList.map((p) => [p.id, p]));

    const lines = [];
    for (const it of body.items) {
      const item = byId.get(it.laundryItemId);
      if (!item || !item.active) throw new ApiError(400, "Laundry item not found");
      lines.push(
        await prisma.folioLine.create({
          data: {
            folioId: reservation.folio!.id,
            source: "LAUNDRY",
            description: `Laundry — ${item.name} × ${it.qty}${body.note ? ` (${body.note})` : ""}`,
            qty: it.qty,
            unitPrice: item.price,
            amount: item.price * it.qty,
            staffId: req.user!.id,
          },
        })
      );
    }
    const total = lines.reduce((s, l) => s + l.amount, 0);
    audit(req.user!.id, "LAUNDRY_CHARGE", "Folio", reservation.folio!.id, { room: body.roomId, total, items: body.items.length });
    res.status(201).json({ ok: true, reservation: reservation.code, guest: reservation.guest.name, total, lines: lines.length });
  })
);

export default router;
