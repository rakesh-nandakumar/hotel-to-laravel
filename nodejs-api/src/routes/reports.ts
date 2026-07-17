import { Router } from "express";
import { z } from "zod";
import dayjs from "dayjs";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { audit } from "../lib/audit";
import { dailyReportPdf, monthlyReportPdf, posReportPdf } from "../lib/pdf";

const router = Router();
router.use(requireRole(...MANAGERS));

const dayRange = (dateStr: string) => {
  const start = dayjs(dateStr).startOf("day");
  return { start: start.toDate(), end: start.add(1, "day").toDate() };
};

/** Live owner dashboard — room status, today's arrivals/departures, today's revenue. */
router.get(
  "/dashboard",
  asyncHandler(async (_req, res) => {
    const { start, end } = dayRange(dayjs().format("YYYY-MM-DD"));
    const rooms = await prisma.room.groupBy({ by: ["status"], _count: true });
    const roomCounts = Object.fromEntries(rooms.map((r) => [r.status, r._count]));
    const totalRooms = await prisma.room.count();

    const arrivals = await prisma.reservation.findMany({
      where: { status: { in: ["CONFIRMED", "PENDING"] }, checkIn: { gte: start, lt: end } },
      include: {
        guest: { select: { name: true, loyaltyPoints: true, idNumber: true } },
        rooms: { include: { room: { select: { number: true } } } },
        groupBooking: { select: { reference: true } },
        corporateAccount: { select: { companyName: true } },
      },
    });
    const departures = await prisma.reservation.findMany({
      where: { status: "CHECKED_IN", checkOut: { gte: start, lt: end } },
      include: { guest: { select: { name: true } }, rooms: { include: { room: { select: { number: true } } } } },
    });
    const inHouse = await prisma.reservation.count({ where: { status: "CHECKED_IN" } });
    const venuesToday = await prisma.venueBooking.count({ where: { date: { gte: start, lt: end }, status: { in: ["CONFIRMED", "INQUIRY"] } } });
    const staffOnDuty = await prisma.attendance.count({ where: { clockOut: null } });
    const yesterday = await computeDaily(dayjs(start).subtract(1, "day").format("YYYY-MM-DD"));

    // Cash-basis revenue collected today + accrual charges posted today
    const paymentsToday = await prisma.payment.findMany({ where: { createdAt: { gte: start, lt: end } } });
    const collected = paymentsToday.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0)
      - paymentsToday.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0);
    const linesToday = await prisma.folioLine.findMany({ where: { createdAt: { gte: start, lt: end }, voided: false } });
    const chargesPosted = linesToday.reduce((s, l) => s + l.amount, 0);
    const posToday = await prisma.order.aggregate({
      where: { createdAt: { gte: start, lt: end }, status: { in: ["SETTLED", "CHARGED_TO_ROOM"] } },
      _sum: { total: true },
      _count: true,
    });

    const openKots = await prisma.order.count({ where: { kotStatus: { in: ["NEW", "PREPARING"] }, status: { notIn: ["VOID"] } } });
    const pendingHousekeeping = await prisma.housekeepingTask.count({ where: { status: { not: "DONE" } } });
    const openMaintenance = await prisma.maintenanceIssue.count({ where: { status: { not: "RESOLVED" } } });
    const lowStock = await prisma.$queryRaw<{ count: bigint }[]>`SELECT COUNT(*)::bigint as count FROM "Ingredient" WHERE "stockQty" <= "lowStockThreshold"`;
    const expiryCutoff = new Date();
    expiryCutoff.setHours(0, 0, 0, 0);
    expiryCutoff.setDate(expiryCutoff.getDate() + 3);
    const expiringBatches = await prisma.ingredientBatch.count({ where: { qty: { gt: 0 }, expiryDate: { not: null, lte: expiryCutoff } } });

    res.json({
      rooms: {
        total: totalRooms,
        occupied: roomCounts.OCCUPIED ?? 0,
        available: roomCounts.AVAILABLE ?? 0,
        dirty: roomCounts.DIRTY ?? 0,
        maintenance: roomCounts.MAINTENANCE ?? 0,
        occupancyPct: totalRooms ? Math.round(((roomCounts.OCCUPIED ?? 0) / totalRooms) * 100) : 0,
      },
      arrivals,
      departures,
      inHouse,
      venuesToday,
      staffOnDuty,
      revenueToday: { collected, chargesPosted, posSales: posToday._sum.total ?? 0, posOrders: posToday._count },
      yesterday: {
        occupancyPct: yesterday.occupancy.pct,
        collected: yesterday.payments.net,
        posSales: Object.values(yesterday.pos.byCategory).reduce((s, v) => s + v, 0),
      },
      ops: { openKots, pendingHousekeeping, openMaintenance, lowStockIngredients: Number(lowStock[0]?.count ?? 0), expiringBatches },
    });
  })
);

/** Shared daily computation — powers the daily report and the night audit. */
async function computeDaily(dateStr: string) {
  const { start, end } = dayRange(dateStr);

  const lines = await prisma.folioLine.findMany({ where: { createdAt: { gte: start, lt: end }, voided: false } });
  const revenueBySource: Record<string, number> = {};
  for (const l of lines) revenueBySource[l.source] = (revenueBySource[l.source] ?? 0) + l.amount;

  // Walk-in POS revenue isn't folio-based — count settled walk-in orders too
  const walkinOrders = await prisma.order.findMany({
    where: { type: "WALKIN", status: "SETTLED", settledAt: { gte: start, lt: end } },
  });
  const walkinTotal = walkinOrders.reduce((s, o) => s + o.total, 0);

  const payments = await prisma.payment.findMany({ where: { createdAt: { gte: start, lt: end } } });
  const byMethod: Record<string, number> = {};
  for (const p of payments) {
    const sign = p.kind === "REFUND" ? -1 : 1;
    byMethod[p.method] = (byMethod[p.method] ?? 0) + sign * p.amount;
  }
  const refunds = payments.filter((p) => p.kind === "REFUND");

  const totalRooms = await prisma.room.count();
  const occupied = await prisma.reservation.findMany({
    where: { status: { in: ["CHECKED_IN", "CHECKED_OUT"] }, checkIn: { lt: end }, checkOut: { gt: start } },
    include: { rooms: true },
  });
  const occupiedRooms = new Set(occupied.flatMap((r) => r.rooms.map((rr) => rr.roomId))).size;

  // POS item sales by category + best sellers
  const orderItems = await prisma.orderItem.findMany({
    where: { voided: false, order: { createdAt: { gte: start, lt: end }, status: { in: ["SETTLED", "CHARGED_TO_ROOM"] } } },
    include: { menuItem: { include: { category: { select: { name: true } } } } },
  });
  const byCategory: Record<string, number> = {};
  const byItem: Record<string, { qty: number; amount: number }> = {};
  for (const it of orderItems) {
    byCategory[it.menuItem.category.name] = (byCategory[it.menuItem.category.name] ?? 0) + it.amount;
    byItem[it.name] = { qty: (byItem[it.name]?.qty ?? 0) + it.qty, amount: (byItem[it.name]?.amount ?? 0) + it.amount };
  }
  const bestSellers = Object.entries(byItem)
    .map(([name, v]) => ({ name, ...v }))
    .sort((a, b) => b.qty - a.qty)
    .slice(0, 10);

  const shifts = await prisma.shift.findMany({
    where: { closedAt: { gte: start, lt: end } },
    include: { staff: { select: { name: true } } },
  });

  const collected = payments.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0);
  const refunded = refunds.reduce((s, p) => s + p.amount, 0);

  return {
    date: dateStr,
    occupancy: { totalRooms, occupiedRooms, pct: totalRooms ? Math.round((occupiedRooms / totalRooms) * 100) : 0 },
    revenueBySource,
    walkinPosRevenue: walkinTotal,
    totalChargesPosted: lines.reduce((s, l) => s + l.amount, 0) + walkinTotal,
    payments: { byMethod, collected, refunded, net: collected - refunded },
    cashCollected: byMethod.CASH ?? 0,
    pos: { byCategory, bestSellers, orderCount: new Set(orderItems.map((i) => i.orderId)).size },
    shifts: shifts.map((s) => ({ staff: s.staff.name, openingCash: s.openingCash, closingCash: s.closingCash, expectedCash: s.expectedCash, variance: s.variance })),
  };
}

router.get(
  "/daily",
  asyncHandler(async (req, res) => {
    const date = typeof req.query.date === "string" ? req.query.date : dayjs().format("YYYY-MM-DD");
    res.json(await computeDaily(date));
  })
);

/** Branded A4 PDF of the daily report. */
router.get(
  "/daily/pdf",
  asyncHandler(async (req, res) => {
    const date = typeof req.query.date === "string" ? req.query.date : dayjs().format("YYYY-MM-DD");
    await dailyReportPdf(await computeDaily(date), { title: "DAILY OPERATIONS REPORT" }, res);
  })
);

/** Night audit: computes + permanently stores the day's snapshot. */
router.post(
  "/night-audit/run",
  asyncHandler(async (req, res) => {
    const { date } = z.object({ date: z.string().optional() }).parse(req.body ?? {});
    const dateStr = date ?? dayjs().format("YYYY-MM-DD");
    const data = await computeDaily(dateStr);
    const existing = await prisma.nightAudit.findUnique({ where: { businessDate: new Date(dateStr) } });
    if (existing) throw new ApiError(409, `Night audit for ${dateStr} was already run`);
    const na = await prisma.nightAudit.create({
      data: { businessDate: new Date(dateStr), data: data as never, runById: req.user!.id },
    });
    audit(req.user!.id, "NIGHT_AUDIT_RUN", "NightAudit", na.id, { date: dateStr });
    res.status(201).json(na);
  })
);

router.get(
  "/night-audit",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.nightAudit.findMany({ orderBy: { businessDate: "desc" }, include: { runBy: { select: { name: true } } }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.nightAudit.count(),
      ]);
      return res.json({ rows, total, page, pageSize });
    }
    res.json(await prisma.nightAudit.findMany({ orderBy: { businessDate: "desc" }, take: 60, include: { runBy: { select: { name: true } } } }));
  })
);

/** Branded A4 PDF of a stored night-audit snapshot. */
router.get(
  "/night-audit/:id/pdf",
  asyncHandler(async (req, res) => {
    const na = await prisma.nightAudit.findUnique({ where: { id: req.params.id }, include: { runBy: { select: { name: true } } } });
    if (!na) throw new ApiError(404, "Night audit not found");
    await dailyReportPdf(na.data as never, { title: "NIGHT AUDIT SNAPSHOT", runBy: na.runBy.name }, res);
  })
);

/** Shared monthly computation — powers the monthly report and its PDF. */
async function computeMonthly(month: string) {
  const daysInMonth = dayjs(`${month}-01`).daysInMonth();
  const days = [];
  let totalRevenue = 0;
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${month}-${String(d).padStart(2, "0")}`;
    if (dayjs(dateStr).isAfter(dayjs(), "day")) break;
    const { start, end } = dayRange(dateStr);
    const lines = await prisma.folioLine.aggregate({ where: { createdAt: { gte: start, lt: end }, voided: false }, _sum: { amount: true } });
    const walkin = await prisma.order.aggregate({ where: { type: "WALKIN", status: "SETTLED", settledAt: { gte: start, lt: end } }, _sum: { total: true } });
    const totalRooms = await prisma.room.count();
    const occ = await prisma.reservation.findMany({
      where: { status: { in: ["CHECKED_IN", "CHECKED_OUT", "CONFIRMED"] }, checkIn: { lt: end }, checkOut: { gt: start } },
      include: { rooms: true },
    });
    const occupiedRooms = new Set(occ.filter((r) => r.status !== "CONFIRMED" || dayjs(r.checkIn).isBefore(end)).flatMap((r) => r.rooms.map((rr) => rr.roomId))).size;
    const revenue = (lines._sum.amount ?? 0) + (walkin._sum.total ?? 0);
    totalRevenue += revenue;
    days.push({ date: dateStr, revenue, occupancyPct: totalRooms ? Math.round((occupiedRooms / totalRooms) * 100) : 0 });
  }
  return { month, days, totalRevenue, avgOccupancy: days.length ? Math.round(days.reduce((s, d) => s + d.occupancyPct, 0) / days.length) : 0 };
}

/** Monthly performance: per-day revenue + occupancy. */
router.get(
  "/monthly",
  asyncHandler(async (req, res) => {
    const month = typeof req.query.month === "string" ? req.query.month : dayjs().format("YYYY-MM");
    res.json(await computeMonthly(month));
  })
);

/** Branded A4 PDF of the monthly report. */
router.get(
  "/monthly/pdf",
  asyncHandler(async (req, res) => {
    const month = typeof req.query.month === "string" ? req.query.month : dayjs().format("YYYY-MM");
    await monthlyReportPdf(await computeMonthly(month), res);
  })
);

/** Shared POS-range computation — powers the POS report and its PDF. */
async function computePos(from: string, to: string) {
  const start = dayjs(from).startOf("day").toDate();
  const end = dayjs(to).add(1, "day").startOf("day").toDate();

  const orderItems = await prisma.orderItem.findMany({
    where: { voided: false, order: { createdAt: { gte: start, lt: end }, status: { in: ["SETTLED", "CHARGED_TO_ROOM"] } } },
    include: { menuItem: { include: { category: { select: { name: true } } } } },
  });
  const byCategory: Record<string, number> = {};
  const byItem: Record<string, { qty: number; amount: number }> = {};
  for (const it of orderItems) {
    byCategory[it.menuItem.category.name] = (byCategory[it.menuItem.category.name] ?? 0) + it.amount;
    byItem[it.name] = { qty: (byItem[it.name]?.qty ?? 0) + it.qty, amount: (byItem[it.name]?.amount ?? 0) + it.amount };
  }
  const payments = await prisma.payment.findMany({
    where: { createdAt: { gte: start, lt: end }, orderId: { not: null } },
  });
  const byMethod: Record<string, number> = {};
  for (const p of payments) byMethod[p.method] = (byMethod[p.method] ?? 0) + (p.kind === "REFUND" ? -p.amount : p.amount);

  return {
    from, to,
    byCategory,
    bestSellers: Object.entries(byItem).map(([name, v]) => ({ name, ...v })).sort((a, b) => b.qty - a.qty).slice(0, 15),
    paymentMethodBreakdown: byMethod,
    totalSales: orderItems.reduce((s, i) => s + i.amount, 0),
  };
}

/** POS sales report for a range: category totals, best sellers, method breakdown. */
router.get(
  "/pos",
  asyncHandler(async (req, res) => {
    const from = typeof req.query.from === "string" ? req.query.from : dayjs().subtract(6, "day").format("YYYY-MM-DD");
    const to = typeof req.query.to === "string" ? req.query.to : dayjs().format("YYYY-MM-DD");
    res.json(await computePos(from, to));
  })
);

/** Branded A4 PDF of the POS sales report. */
router.get(
  "/pos/pdf",
  asyncHandler(async (req, res) => {
    const from = typeof req.query.from === "string" ? req.query.from : dayjs().subtract(6, "day").format("YYYY-MM-DD");
    const to = typeof req.query.to === "string" ? req.query.to : dayjs().format("YYYY-MM-DD");
    await posReportPdf(await computePos(from, to), res);
  })
);

export default router;
