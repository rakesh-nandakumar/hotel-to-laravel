import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { emit } from "../socket";

const router = Router();
// Housekeepers work their own tasks; managers assign & oversee.
router.use(requireRole("MANAGER", "HOUSEKEEPER"));

const tasksInclude = {
  room: { select: { number: true, status: true, roomType: { select: { name: true } } } },
  assignedTo: { select: { id: true, name: true } },
} as const;

router.get(
  "/tasks",
  asyncHandler(async (req, res) => {
    const mine = req.query.mine === "1";
    const where = {
      ...(mine ? { assignedToId: req.user!.id } : {}),
      ...(req.query.all === "1" ? {} : { status: { not: "DONE" as const } }),
    };

    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.housekeepingTask.findMany({ where, include: tasksInclude, orderBy: { createdAt: "desc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.housekeepingTask.count({ where }),
      ]);
      return res.json({ rows, total, page, pageSize });
    }

    const tasks = await prisma.housekeepingTask.findMany({ where, include: tasksInclude, orderBy: { createdAt: "asc" } });
    res.json(tasks);
  })
);

/** Manager creates ad-hoc cleaning task (checkout tasks are auto-created). */
router.post(
  "/tasks",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z.object({ roomId: z.string(), assignedToId: z.string().optional(), notes: z.string().optional() }).parse(req.body);
    const room = await prisma.room.findUnique({ where: { id: body.roomId }, include: { roomType: true } });
    if (!room) throw new ApiError(404, "Room not found");
    const task = await prisma.housekeepingTask.create({
      data: {
        roomId: room.id,
        assignedToId: body.assignedToId,
        notes: body.notes,
        checklist: (room.roomType.cleaningChecklist as string[]).map((item) => ({ item, done: false })),
      },
    });
    if (room.status === "AVAILABLE") await prisma.room.update({ where: { id: room.id }, data: { status: "DIRTY" } });
    emit("rooms", { roomId: room.id });
    res.status(201).json(task);
  })
);

router.put(
  "/tasks/:id/assign",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const { assignedToId } = z.object({ assignedToId: z.string().nullable() }).parse(req.body);
    res.json(await prisma.housekeepingTask.update({ where: { id: req.params.id }, data: { assignedToId, status: assignedToId ? "IN_PROGRESS" : "PENDING" } }));
  })
);

/** Housekeeper ticks checklist items from their phone. */
router.put(
  "/tasks/:id/checklist",
  asyncHandler(async (req, res) => {
    const { checklist } = z
      .object({ checklist: z.array(z.object({ item: z.string(), done: z.boolean() })) })
      .parse(req.body);
    const task = await prisma.housekeepingTask.findUnique({ where: { id: req.params.id } });
    if (!task) throw new ApiError(404, "Task not found");
    if (task.status === "DONE") throw new ApiError(400, "Task already completed");
    const updated = await prisma.housekeepingTask.update({
      where: { id: task.id },
      data: { checklist, status: "IN_PROGRESS", assignedToId: task.assignedToId ?? req.user!.id },
    });
    res.json(updated);
  })
);

/**
 * Submit the completed checklist → room becomes AVAILABLE (Clean/Ready).
 * THE GATE: this is the only path from DIRTY to AVAILABLE — a room cannot be
 * sold again until this checklist is submitted (report §4.6).
 */
router.post(
  "/tasks/:id/complete",
  asyncHandler(async (req, res) => {
    const body = z
      .object({ checklist: z.array(z.object({ item: z.string(), done: z.boolean() })).optional(), notes: z.string().optional() })
      .parse(req.body);
    const task = await prisma.housekeepingTask.findUnique({ where: { id: req.params.id }, include: { room: true } });
    if (!task) throw new ApiError(404, "Task not found");
    if (task.status === "DONE") throw new ApiError(400, "Task already completed");
    const checklist = body.checklist ?? (task.checklist as { item: string; done: boolean }[]);
    const unfinished = checklist.filter((c) => !c.done);
    if (unfinished.length > 0)
      throw new ApiError(400, `Checklist incomplete — ${unfinished.length} item(s) remaining: ${unfinished.slice(0, 3).map((c) => c.item).join("; ")}${unfinished.length > 3 ? "…" : ""}`);

    await prisma.$transaction([
      prisma.housekeepingTask.update({
        where: { id: task.id },
        data: { status: "DONE", checklist, completedAt: new Date(), notes: body.notes, assignedToId: task.assignedToId ?? req.user!.id },
      }),
      // Only flip to AVAILABLE if no other blocking state (maintenance keeps priority)
      ...(task.room.status === "DIRTY"
        ? [prisma.room.update({ where: { id: task.roomId }, data: { status: "AVAILABLE" } })]
        : []),
    ]);
    audit(req.user!.id, "HOUSEKEEPING_COMPLETE", "HousekeepingTask", task.id, { room: task.room.number });
    emit("rooms", { roomId: task.roomId });
    res.json({ ok: true, roomStatus: task.room.status === "DIRTY" ? "AVAILABLE" : task.room.status });
  })
);

export default router;
