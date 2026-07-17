import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { audit } from "../lib/audit";

const router = Router();
router.use(requireRole(...MANAGERS));

/**
 * Search — used both for the full Guests list (paginated: pass ?page=) and
 * for the lightweight returning-guest autocomplete at booking/check-in
 * (no ?page= → same bare-array shape as before, unpaginated top 100).
 */
const SORTS = {
  recent: { createdAt: "desc" as const },
  spend: { lifetimeSpend: "desc" as const },
  points: { loyaltyPoints: "desc" as const },
  name: { name: "asc" as const },
};

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const q = typeof req.query.q === "string" ? req.query.q : undefined;
    const where = q
      ? {
          OR: [
            { name: { contains: q, mode: "insensitive" as const } },
            { phone: { contains: q } },
            { email: { contains: q, mode: "insensitive" as const } },
            { idNumber: { contains: q } },
          ],
        }
      : undefined;
    const sortKey = typeof req.query.sort === "string" && req.query.sort in SORTS ? (req.query.sort as keyof typeof SORTS) : "recent";

    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 20));
      const [rows, total, agg] = await Promise.all([
        prisma.guest.findMany({ where, orderBy: SORTS[sortKey], skip: (page - 1) * pageSize, take: pageSize }),
        prisma.guest.count({ where }),
        prisma.guest.aggregate({ where, _sum: { lifetimeSpend: true, loyaltyPoints: true } }),
      ]);
      return res.json({
        rows, total, page, pageSize,
        stats: { totalLifetimeSpend: agg._sum.lifetimeSpend ?? 0, totalLoyaltyPoints: agg._sum.loyaltyPoints ?? 0 },
      });
    }

    const guests = await prisma.guest.findMany({ where, orderBy: SORTS[sortKey], take: 100 });
    res.json(guests);
  })
);

/** Profile: lifetime stays, spend, points, history (report §4.3). */
router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    const guest = await prisma.guest.findUnique({
      where: { id: req.params.id },
      include: {
        reservations: {
          include: { rooms: { include: { room: { select: { number: true } } } }, folio: { select: { invoiceNo: true, status: true } } },
          orderBy: { checkIn: "desc" },
        },
        loyaltyTxns: { orderBy: { createdAt: "desc" }, take: 50 },
        venueBookings: { include: { venue: { select: { name: true } } }, orderBy: { date: "desc" } },
      },
    });
    if (!guest) throw new ApiError(404, "Guest not found");
    res.json({ ...guest, totalStays: guest.reservations.filter((r) => r.status === "CHECKED_OUT").length });
  })
);

const guestBody = z.object({
  name: z.string().min(1),
  email: z.string().optional().nullable(),
  phone: z.string().optional().nullable(),
  idNumber: z.string().optional().nullable(),
  nationality: z.string().optional().nullable(),
  preferences: z.string().optional().nullable(),
});

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const guest = await prisma.guest.create({ data: guestBody.parse(req.body) });
    audit(req.user!.id, "GUEST_CREATE", "Guest", guest.id, { name: guest.name });
    res.status(201).json(guest);
  })
);

router.put(
  "/:id",
  asyncHandler(async (req, res) => {
    const guest = await prisma.guest.update({ where: { id: req.params.id }, data: guestBody.partial().parse(req.body) });
    audit(req.user!.id, "GUEST_UPDATE", "Guest", guest.id);
    res.json(guest);
  })
);

/** Manual loyalty adjustment (e.g. goodwill points) — audited via transaction row. */
router.post(
  "/:id/loyalty-adjust",
  asyncHandler(async (req, res) => {
    const body = z.object({ points: z.number().int(), reason: z.string().min(1) }).parse(req.body);
    const guest = await prisma.guest.findUnique({ where: { id: req.params.id } });
    if (!guest) throw new ApiError(404, "Guest not found");
    if (guest.loyaltyPoints + body.points < 0) throw new ApiError(400, "Adjustment would make points negative");
    await prisma.$transaction([
      prisma.guest.update({ where: { id: guest.id }, data: { loyaltyPoints: { increment: body.points } } }),
      prisma.loyaltyTransaction.create({
        data: { guestId: guest.id, points: body.points, reason: body.reason, staffId: req.user!.id },
      }),
    ]);
    audit(req.user!.id, "GUEST_LOYALTY_ADJUST", "Guest", guest.id, { points: body.points, reason: body.reason });
    res.json({ ok: true });
  })
);

export default router;
