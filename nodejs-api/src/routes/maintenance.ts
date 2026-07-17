import { Router } from "express";
import { z } from "zod";
import { MaintenanceStatus } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { emit } from "../socket";

const router = Router();
// Any staff can log an issue (housekeeper finds broken AC, chef reports fridge…)
router.use(requireRole("MANAGER", "HOUSEKEEPER", "CHEF", "SECURITY"));

const issuesInclude = {
  room: { select: { number: true } },
  venue: { select: { name: true } },
  loggedBy: { select: { name: true } },
} as const;

router.get(
  "/",
  asyncHandler(async (req, res) => {
    const where = req.query.all === "1" ? {} : { status: { not: "RESOLVED" as const } };

    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.maintenanceIssue.findMany({ where, include: issuesInclude, orderBy: { createdAt: "desc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.maintenanceIssue.count({ where }),
      ]);
      return res.json({ rows, total, page, pageSize });
    }

    const issues = await prisma.maintenanceIssue.findMany({ where, include: issuesInclude, orderBy: { createdAt: "desc" } });
    res.json(issues);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        roomId: z.string().optional(),
        venueId: z.string().optional(),
        description: z.string().min(3),
        takeRoomOutOfService: z.boolean().default(false),
      })
      .parse(req.body);
    if (!body.roomId && !body.venueId) throw new ApiError(400, "Issue must be against a room or a venue");
    const issue = await prisma.maintenanceIssue.create({
      data: { roomId: body.roomId, venueId: body.venueId, description: body.description, loggedById: req.user!.id },
    });
    if (body.roomId && body.takeRoomOutOfService) {
      const room = await prisma.room.findUnique({ where: { id: body.roomId } });
      if (room && room.status !== "OCCUPIED") {
        await prisma.room.update({ where: { id: body.roomId }, data: { status: "MAINTENANCE" } });
        emit("rooms", { roomId: body.roomId });
      }
    }
    audit(req.user!.id, "MAINTENANCE_LOG", "MaintenanceIssue", issue.id, { description: body.description });
    res.status(201).json(issue);
  })
);

router.put(
  "/:id",
  asyncHandler(async (req, res) => {
    const body = z
      .object({ status: z.nativeEnum(MaintenanceStatus), resolutionNotes: z.string().optional(), returnRoomToService: z.boolean().default(false) })
      .parse(req.body);
    const issue = await prisma.maintenanceIssue.update({
      where: { id: req.params.id },
      data: {
        status: body.status,
        resolutionNotes: body.resolutionNotes,
        resolvedAt: body.status === "RESOLVED" ? new Date() : null,
      },
      include: { room: true },
    });
    // Resolving can return the room to DIRTY (needs cleaning before sale — checklist gate applies)
    if (body.status === "RESOLVED" && body.returnRoomToService && issue.roomId && issue.room?.status === "MAINTENANCE") {
      await prisma.room.update({ where: { id: issue.roomId }, data: { status: "DIRTY" } });
      await prisma.housekeepingTask.create({
        data: {
          roomId: issue.roomId,
          checklist: (
            (await prisma.room.findUniqueOrThrow({ where: { id: issue.roomId }, include: { roomType: true } })).roomType
              .cleaningChecklist as string[]
          ).map((item) => ({ item, done: false })),
          notes: `Post-maintenance clean: ${issue.description}`,
        },
      });
      emit("rooms", { roomId: issue.roomId });
    }
    audit(req.user!.id, "MAINTENANCE_UPDATE", "MaintenanceIssue", issue.id, { status: body.status });
    res.json(issue);
  })
);

export default router;
