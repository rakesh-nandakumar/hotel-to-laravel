import { AsyncLocalStorage } from "node:async_hooks";
import { NextFunction, Request, Response } from "express";

export type RequestContext = { ip: string; userAgent: string; route: string };

const als = new AsyncLocalStorage<RequestContext>();

/** Best-effort real client IP — trusts X-Forwarded-For (Render sits behind a proxy). */
function clientIp(req: Request): string {
  const fwd = req.headers["x-forwarded-for"];
  if (typeof fwd === "string" && fwd.length > 0) return fwd.split(",")[0].trim();
  return req.ip || req.socket.remoteAddress || "unknown";
}

/** Captures IP / user agent / route per request so audit() can attach them without threading req through every call site. */
export function captureRequestContext(req: Request, _res: Response, next: NextFunction) {
  als.run({ ip: clientIp(req), userAgent: req.headers["user-agent"] || "unknown", route: `${req.method} ${req.path}` }, next);
}

export function getRequestContext(): RequestContext | undefined {
  return als.getStore();
}
