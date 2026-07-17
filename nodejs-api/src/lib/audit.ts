import { Prisma } from "@prisma/client";
import { prisma } from "./prisma";
import { getRequestContext } from "./requestContext";

/**
 * Every sensitive action (void, refund, discount, setting change, check-in/out,
 * login…) is logged, along with the IP address, user agent and API route of
 * the request that triggered it — captured automatically via AsyncLocalStorage
 * (see requestContext.ts) so call sites never need to pass req around.
 */
export function audit(
  staffId: string | undefined,
  action: string,
  entity: string,
  entityId?: string,
  details?: Prisma.InputJsonValue
) {
  const ctx = getRequestContext();
  // fire-and-forget; audit failure must not block the business action
  prisma.auditLog
    .create({ data: { staffId, action, entity, entityId, details, ipAddress: ctx?.ip, userAgent: ctx?.userAgent, route: ctx?.route } })
    .catch((e) => console.error("audit log failed", e));
}
