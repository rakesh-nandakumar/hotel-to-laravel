import { NextFunction, Request, Response } from "express";
import { ZodError } from "zod";

export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export const asyncHandler =
  (fn: (req: Request, res: Response, next: NextFunction) => Promise<unknown>) =>
  (req: Request, res: Response, next: NextFunction) =>
    fn(req, res, next).catch(next);

export function errorMiddleware(err: unknown, _req: Request, res: Response, _next: NextFunction) {
  if (err instanceof ApiError) return res.status(err.status).json({ error: err.message });
  if (err instanceof ZodError)
    return res.status(400).json({ error: err.errors.map((e) => `${e.path.join(".")}: ${e.message}`).join("; ") });
  // Prisma unique violations etc.
  const anyErr = err as { code?: string; message?: string };
  if (anyErr?.code === "P2002") return res.status(409).json({ error: "Duplicate value — record already exists." });
  console.error(err);
  return res.status(500).json({ error: "Internal server error" });
}
