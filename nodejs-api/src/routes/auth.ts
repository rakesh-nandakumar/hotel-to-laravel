import { Router } from "express";
import { z } from "zod";
import bcrypt from "bcryptjs";
import { prisma } from "../lib/prisma";
import { ApiError, asyncHandler } from "../lib/http";
import { requireAuth, signToken, signDeviceToken, verifyDeviceToken } from "../lib/auth";
import { audit } from "../lib/audit";

const router = Router();

const TOKEN_TTL = `${process.env.TOKEN_TTL_HOURS || 12}h`;
const PIN_TTL = `${process.env.PIN_TOKEN_TTL_MIN || 720}m`;

/**
 * Full email + password login. Also issues a device token that unlocks the
 * PIN quick-login FOR THIS USER ONLY on this device. Logging in with a
 * different account replaces the device binding (the previous user's PIN
 * option disappears from this device).
 */
router.post(
  "/login",
  asyncHandler(async (req, res) => {
    const { email, password } = z.object({ email: z.string().email(), password: z.string().min(1) }).parse(req.body);
    const user = await prisma.user.findUnique({ where: { email } });
    if (!user || !user.active || !bcrypt.compareSync(password, user.passwordHash))
      throw new ApiError(401, "Invalid email or password");
    const token = signToken({ id: user.id, name: user.name, role: user.role, via: "PASSWORD" }, TOKEN_TTL);
    audit(user.id, "LOGIN", "User", user.id);
    res.json({
      token,
      deviceToken: signDeviceToken(user.id),
      user: { id: user.id, name: user.name, role: user.role, email: user.email },
    });
  })
);

/**
 * PIN quick-unlock — requires the device token issued at credential login.
 * The PIN is verified against the device-bound user only; you cannot PIN-login
 * as anyone else from this device.
 */
router.post(
  "/pin-login",
  asyncHandler(async (req, res) => {
    const { deviceToken, pin } = z.object({ deviceToken: z.string().min(10), pin: z.string().min(4).max(6) }).parse(req.body);
    let userId: string;
    try {
      userId = verifyDeviceToken(deviceToken);
    } catch {
      throw new ApiError(401, "PIN unlock expired — sign in with email & password first");
    }
    const user = await prisma.user.findUnique({ where: { id: userId } });
    if (!user || !user.active || !user.pinHash || !bcrypt.compareSync(pin, user.pinHash))
      throw new ApiError(401, "Wrong PIN");
    const token = signToken({ id: user.id, name: user.name, role: user.role, via: "PIN" }, PIN_TTL);
    audit(user.id, "PIN_LOGIN", "User", user.id);
    res.json({ token, user: { id: user.id, name: user.name, role: user.role, email: user.email } });
  })
);

/** Staff list — now requires auth (used by housekeeping assignment etc.). */
router.get(
  "/staff-list",
  requireAuth,
  asyncHandler(async (_req, res) => {
    const users = await prisma.user.findMany({
      where: { active: true },
      select: { id: true, name: true, role: true },
      orderBy: { name: "asc" },
    });
    res.json(users);
  })
);

router.get(
  "/me",
  requireAuth,
  asyncHandler(async (req, res) => {
    const user = await prisma.user.findUnique({
      where: { id: req.user!.id },
      select: { id: true, name: true, role: true, email: true, active: true },
    });
    if (!user || !user.active) throw new ApiError(401, "Account disabled");
    res.json(user);
  })
);

export default router;
