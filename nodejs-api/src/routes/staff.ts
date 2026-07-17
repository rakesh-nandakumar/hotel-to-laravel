import { Router } from "express";
import { z } from "zod";
import bcrypt from "bcryptjs";
import { Role } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";

const router = Router();
router.use(requireRole("MANAGER"));

const userBody = z.object({
  name: z.string().min(1),
  email: z.string().email(),
  role: z.nativeEnum(Role),
  password: z.string().min(6).optional(),
  pin: z.string().regex(/^\d{4,6}$/, "PIN must be 4–6 digits").optional(),
  active: z.boolean().optional(),
});

router.get(
  "/",
  asyncHandler(async (_req, res) => {
    const users = await prisma.user.findMany({
      select: { id: true, name: true, email: true, role: true, active: true, createdAt: true },
      orderBy: { name: "asc" },
    });
    res.json(users);
  })
);

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const body = userBody.parse(req.body);
    if (!body.password) throw new ApiError(400, "Password required for new staff");
    // SYSTEM_ADMIN accounts can only be managed by a SYSTEM_ADMIN
    if (body.role === "SYSTEM_ADMIN" && req.user!.role !== "SYSTEM_ADMIN")
      throw new ApiError(403, "Only a System Admin can create System Admin accounts");
    // Owner/Manager accounts need Owner (or System Admin) privilege
    if ((body.role === "OWNER" || body.role === "MANAGER") && !["OWNER", "SYSTEM_ADMIN"].includes(req.user!.role))
      throw new ApiError(403, "Only the Owner can create Owner/Manager accounts");
    const user = await prisma.user.create({
      data: {
        name: body.name,
        email: body.email,
        role: body.role,
        passwordHash: bcrypt.hashSync(body.password, 10),
        pinHash: body.pin ? bcrypt.hashSync(body.pin, 10) : null,
      },
    });
    audit(req.user!.id, "STAFF_CREATE", "User", user.id, { role: body.role });
    res.status(201).json({ id: user.id });
  })
);

router.put(
  "/:id",
  asyncHandler(async (req, res) => {
    const body = userBody.partial().parse(req.body);
    const target = await prisma.user.findUnique({ where: { id: req.params.id } });
    if (!target) throw new ApiError(404, "Staff member not found");
    if ((target.role === "SYSTEM_ADMIN" || body.role === "SYSTEM_ADMIN") && req.user!.role !== "SYSTEM_ADMIN")
      throw new ApiError(403, "Only a System Admin can modify System Admin accounts");
    if ((target.role === "OWNER" || body.role === "OWNER") && !["OWNER", "SYSTEM_ADMIN"].includes(req.user!.role))
      throw new ApiError(403, "Only the Owner can modify Owner accounts");
    const user = await prisma.user.update({
      where: { id: req.params.id },
      data: {
        name: body.name,
        email: body.email,
        role: body.role,
        active: body.active,
        ...(body.password ? { passwordHash: bcrypt.hashSync(body.password, 10) } : {}),
        ...(body.pin ? { pinHash: bcrypt.hashSync(body.pin, 10) } : {}),
      },
    });
    audit(req.user!.id, "STAFF_UPDATE", "User", user.id);
    res.json({ ok: true });
  })
);

export default router;
