import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";

const router = Router();
// Security role: visitor/vehicle log — no financial access (§4.8, permissions to confirm with owner)
router.use(requireRole("MANAGER", "SECURITY"));

router.get(
  "/",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.visitorLog.findMany({ include: { loggedBy: { select: { name: true } } }, orderBy: { timeIn: "desc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.visitorLog.count(),
      ]);
      return res.json({ rows, total, page, pageSize });
    }
    res.json(await prisma.visitorLog.findMany({ include: { loggedBy: { select: { name: true } } }, orderBy: { timeIn: "desc" }, take: 200 }));
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const body = z.object({ name: z.string().min(1), vehicleNo: z.string().optional(), purpose: z.string().optional() }).parse(req.body);
    const log = await prisma.visitorLog.create({ data: { ...body, loggedById: req.user!.id } });
    audit(req.user!.id, "VISITOR_SIGN_IN", "VisitorLog", log.id, { name: body.name, vehicleNo: body.vehicleNo });
    res.status(201).json(log);
  })
);

router.post(
  "/:id/out",
  asyncHandler(async (req, res) => {
    const log = await prisma.visitorLog.findUnique({ where: { id: req.params.id } });
    if (!log) throw new ApiError(404, "Log entry not found");
    if (log.timeOut) throw new ApiError(400, "Already signed out");
    const updated = await prisma.visitorLog.update({ where: { id: log.id }, data: { timeOut: new Date() } });
    audit(req.user!.id, "VISITOR_SIGN_OUT", "VisitorLog", log.id, { name: log.name });
    res.json(updated);
  })
);

export default router;
