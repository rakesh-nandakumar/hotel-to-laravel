import { Router } from "express";
import { z } from "zod";
import { RoomStatus } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { emit } from "../socket";

const router = Router();

// ── Live room board (all staff — housekeeper/chef see status too) ──
router.get(
  "/board",
  asyncHandler(async (_req, res) => {
    const rooms = await prisma.room.findMany({
      include: {
        roomType: { select: { name: true, maxOccupancy: true } },
        reservationRooms: {
          where: { reservation: { status: "CHECKED_IN" } },
          include: { reservation: { select: { id: true, code: true, checkOut: true, guest: { select: { name: true } } } } },
        },
        housekeepingTasks: { where: { status: { not: "DONE" } }, select: { id: true, status: true } },
        maintenanceIssues: { where: { status: { not: "RESOLVED" } }, select: { id: true, description: true, status: true } },
      },
      orderBy: { number: "asc" },
    });
    res.json(
      rooms.map((r) => ({
        id: r.id,
        number: r.number,
        roomTypeId: r.roomTypeId,
        type: r.roomType.name,
        floor: r.floor,
        view: r.view,
        amenities: r.amenities,
        notes: r.notes,
        status: r.status,
        occupant: r.reservationRooms[0]?.reservation ?? null,
        pendingHousekeeping: r.housekeepingTasks.length > 0,
        openIssues: r.maintenanceIssues,
      }))
    );
  })
);

// ── Room types + rates (Manager/Owner edit; all read) ──
router.get(
  "/types",
  asyncHandler(async (_req, res) => {
    const types = await prisma.roomType.findMany({
      include: { seasonalRates: true, rooms: { select: { id: true, number: true } } },
      orderBy: { name: "asc" },
    });
    res.json(types);
  })
);

const roomTypeBody = z.object({
  name: z.string().min(1),
  maxOccupancy: z.number().int().min(1),
  bedConfig: z.string(),
  amenities: z.array(z.string()),
  weekdayRate: z.number().int().min(0),
  weekendRate: z.number().int().min(0),
  itemChecklist: z.array(z.string()),
  cleaningChecklist: z.array(z.string()),
});

router.post(
  "/types",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = roomTypeBody.parse(req.body);
    const rt = await prisma.roomType.create({ data: body });
    audit(req.user!.id, "ROOMTYPE_CREATE", "RoomType", rt.id);
    res.status(201).json(rt);
  })
);

router.put(
  "/types/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = roomTypeBody.partial().parse(req.body);
    const rt = await prisma.roomType.update({ where: { id: req.params.id }, data: body });
    audit(req.user!.id, "ROOMTYPE_UPDATE", "RoomType", rt.id, body as never);
    res.json(rt);
  })
);

// Seasonal/peak rate overrides
router.post(
  "/types/:id/seasonal",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z
      .object({ name: z.string(), startDate: z.string(), endDate: z.string(), rate: z.number().int().min(0) })
      .parse(req.body);
    const sr = await prisma.seasonalRate.create({
      data: { roomTypeId: req.params.id, name: body.name, startDate: new Date(body.startDate), endDate: new Date(body.endDate), rate: body.rate },
    });
    res.status(201).json(sr);
  })
);

router.delete(
  "/seasonal/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    await prisma.seasonalRate.delete({ where: { id: req.params.id } });
    res.json({ ok: true });
  })
);

// ── Rooms ──
router.get(
  "/",
  asyncHandler(async (_req, res) => {
    const rooms = await prisma.room.findMany({ include: { roomType: true }, orderBy: { number: "asc" } });
    res.json(rooms);
  })
);

const roomBody = z.object({
  number: z.string().min(1),
  roomTypeId: z.string(),
  floor: z.string().optional(),
  view: z.string().optional(),
  amenities: z.array(z.string()).optional(),
  notes: z.string().optional(),
});

router.post(
  "/",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = roomBody.parse(req.body);
    const property = await prisma.property.findFirstOrThrow();
    const room = await prisma.room.create({ data: { ...body, propertyId: property.id } });
    audit(req.user!.id, "ROOM_CREATE", "Room", room.id);
    res.status(201).json(room);
  })
);

router.put(
  "/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = roomBody.partial().parse(req.body);
    const room = await prisma.room.update({ where: { id: req.params.id }, data: body });
    res.json(room);
  })
);

/**
 * Manual status change (Manager, or Housekeeper for DIRTY↔MAINTENANCE flags).
 * NOTE: DIRTY → AVAILABLE is BLOCKED here — that transition only happens through
 * housekeeping checklist completion (see routes/housekeeping.ts). Report §4.6.
 */
router.put(
  "/:id/status",
  requireRole("MANAGER", "HOUSEKEEPER"),
  asyncHandler(async (req, res) => {
    const { status } = z.object({ status: z.nativeEnum(RoomStatus) }).parse(req.body);
    const room = await prisma.room.findUnique({ where: { id: req.params.id } });
    if (!room) throw new ApiError(404, "Room not found");
    if (room.status === "DIRTY" && status === "AVAILABLE")
      throw new ApiError(400, "Room can only be marked Available by completing its housekeeping checklist");
    if (room.status === "OCCUPIED" && status === "AVAILABLE")
      throw new ApiError(400, "Guest is checked in — check out first");
    const updated = await prisma.room.update({ where: { id: req.params.id }, data: { status } });
    audit(req.user!.id, "ROOM_STATUS", "Room", room.id, { from: room.status, to: status });
    emit("rooms", { roomId: room.id, status });
    res.json(updated);
  })
);

// ── Packages ──
router.get(
  "/packages",
  asyncHandler(async (_req, res) => {
    res.json(await prisma.package.findMany({ orderBy: { code: "asc" } }));
  })
);

router.put(
  "/packages/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        name: z.string().optional(),
        description: z.string().optional(),
        pricePerPersonPerNight: z.number().int().min(0).optional(),
        mealInclusions: z.array(z.string()).optional(),
        active: z.boolean().optional(),
      })
      .parse(req.body);
    res.json(await prisma.package.update({ where: { id: req.params.id }, data: body }));
  })
);

export default router;
