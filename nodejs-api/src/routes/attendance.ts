import { Router } from "express";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";

const router = Router();

/** Clock in — any staff member, via their own login/PIN. */
router.post(
  "/clock-in",
  asyncHandler(async (req, res) => {
    const open = await prisma.attendance.findFirst({ where: { userId: req.user!.id, clockOut: null } });
    if (open) throw new ApiError(400, "Already clocked in — clock out first");
    const row = await prisma.attendance.create({ data: { userId: req.user!.id, clockIn: new Date() } });
    res.status(201).json(row);
  })
);

router.post(
  "/clock-out",
  asyncHandler(async (req, res) => {
    const open = await prisma.attendance.findFirst({ where: { userId: req.user!.id, clockOut: null } });
    if (!open) throw new ApiError(400, "Not clocked in");
    const row = await prisma.attendance.update({ where: { id: open.id }, data: { clockOut: new Date() } });
    res.json(row);
  })
);

/** My own status/history — all staff. */
router.get(
  "/me",
  asyncHandler(async (req, res) => {
    const rows = await prisma.attendance.findMany({
      where: { userId: req.user!.id },
      orderBy: { clockIn: "desc" },
      take: 30,
    });
    res.json(rows);
  })
);

/** Who's currently clocked in — for the dashboard "staff on duty" widget. */
router.get(
  "/on-duty",
  requireRole("MANAGER"),
  asyncHandler(async (_req, res) => {
    const rows = await prisma.attendance.findMany({
      where: { clockOut: null },
      include: { user: { select: { name: true, role: true } } },
      orderBy: { clockIn: "asc" },
    });
    res.json(rows.map((r) => ({ id: r.id, name: r.user.name, role: r.user.role, clockIn: r.clockIn })));
  })
);

/** Full attendance (Manager/Owner) with optional month filter, hours computed. */
router.get(
  "/",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const month = typeof req.query.month === "string" ? req.query.month : undefined; // "2026-07"
    const where = month
      ? { clockIn: { gte: new Date(`${month}-01T00:00:00Z`), lt: nextMonth(month) } }
      : {};
    const withHours = (r: { clockIn: Date; clockOut: Date | null }) => ({
      ...r,
      hours: r.clockOut ? Math.round(((+r.clockOut - +r.clockIn) / 3600000) * 100) / 100 : null,
    });

    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.attendance.findMany({ where, include: { user: { select: { name: true, role: true } } }, orderBy: { clockIn: "desc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.attendance.count({ where }),
      ]);
      return res.json({ rows: rows.map(withHours), total, page, pageSize });
    }

    const rows = await prisma.attendance.findMany({ where, include: { user: { select: { name: true, role: true } } }, orderBy: { clockIn: "desc" }, take: 500 });
    res.json(rows.map(withHours));
  })
);

/** CSV export for payroll reference (system does NOT run payroll). */
router.get(
  "/export",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const month = typeof req.query.month === "string" ? req.query.month : new Date().toISOString().slice(0, 7);
    const rows = await prisma.attendance.findMany({
      where: { clockIn: { gte: new Date(`${month}-01T00:00:00Z`), lt: nextMonth(month) } },
      include: { user: { select: { name: true, role: true } } },
      orderBy: [{ userId: "asc" }, { clockIn: "asc" }],
    });
    const lines = ["Staff,Role,Clock In,Clock Out,Hours"];
    for (const r of rows) {
      const hours = r.clockOut ? ((+r.clockOut - +r.clockIn) / 3600000).toFixed(2) : "";
      lines.push(`"${r.user.name}",${r.user.role},${r.clockIn.toISOString()},${r.clockOut?.toISOString() ?? ""},${hours}`);
    }
    res.setHeader("Content-Type", "text/csv");
    res.setHeader("Content-Disposition", `attachment; filename=attendance-${month}.csv`);
    res.send(lines.join("\n"));
  })
);

function nextMonth(month: string): Date {
  const [y, m] = month.split("-").map(Number);
  return new Date(Date.UTC(m === 12 ? y + 1 : y, m === 12 ? 0 : m, 1));
}

export default router;
