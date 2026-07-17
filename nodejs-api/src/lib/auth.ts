import { NextFunction, Request, Response } from "express";
import jwt from "jsonwebtoken";
import { Role } from "@prisma/client";
import { ApiError } from "./http";

const JWT_SECRET = process.env.JWT_SECRET || "dev-secret-change-me";

export type AuthUser = { id: string; name: string; role: Role; via: "PASSWORD" | "PIN" };

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      user?: AuthUser;
    }
  }
}

export function signToken(user: AuthUser, ttl: string): string {
  return jwt.sign(user, JWT_SECRET, { expiresIn: ttl } as jwt.SignOptions);
}

export function verifyToken(token: string): AuthUser {
  return jwt.verify(token, JWT_SECRET) as AuthUser;
}

/**
 * Device trust for PIN quick-unlock: a device token is issued only on a full
 * email+password login and is bound to that user. PIN login requires it, so
 * the PIN pad can never be used for an account that hasn't credential-signed-in
 * on this device (enforced server-side, not just hidden in the UI).
 */
export function signDeviceToken(userId: string): string {
  return jwt.sign({ kind: "device", userId }, JWT_SECRET, { expiresIn: "60d" });
}

export function verifyDeviceToken(token: string): string {
  const payload = jwt.verify(token, JWT_SECRET) as { kind?: string; userId?: string };
  if (payload.kind !== "device" || !payload.userId) throw new Error("not a device token");
  return payload.userId;
}

export function requireAuth(req: Request, _res: Response, next: NextFunction) {
  const header = req.headers.authorization;
  if (!header?.startsWith("Bearer ")) return next(new ApiError(401, "Not authenticated"));
  try {
    req.user = verifyToken(header.slice(7));
    next();
  } catch {
    next(new ApiError(401, "Session expired — please log in again"));
  }
}

/** RBAC — enforced server-side on every endpoint. OWNER and SYSTEM_ADMIN pass all business endpoints. */
export function requireRole(...roles: Role[]) {
  return (req: Request, _res: Response, next: NextFunction) => {
    if (!req.user) return next(new ApiError(401, "Not authenticated"));
    if (req.user.role === "OWNER" || req.user.role === "SYSTEM_ADMIN" || roles.includes(req.user.role)) return next();
    next(new ApiError(403, `Requires role: ${roles.join(" or ")}`));
  };
}

/** STRICT — SYSTEM_ADMIN only, no owner bypass (integrations, gateways, deep settings). */
export function requireSystemAdmin(req: Request, _res: Response, next: NextFunction) {
  if (!req.user) return next(new ApiError(401, "Not authenticated"));
  if (req.user.role !== "SYSTEM_ADMIN") return next(new ApiError(403, "System Admin access required"));
  next();
}

export const MANAGERS: Role[] = ["OWNER", "MANAGER"];
