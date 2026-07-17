import { Me } from "./auth";

/**
 * Where to send a user right after sign-in / when they hit a page they can't
 * access. The backend's own `home`/menu `href` fields are backend route URLs
 * (this API used to double as an Inertia app before Phase 1's REST
 * conversion) — not this SPA's client-side paths — so landing is derived
 * client-side from permissions instead, mirroring UserLanding::urlFor()'s
 * intent (dashboard first, else the first reachable operational area).
 */
const PRIORITY: [string, string][] = [
  ["dashboard.access", "/"],
  ["hotel_housekeeping.access", "/housekeeping"],
  ["hotel_orders.kot", "/kot"],
  ["hotel_visitors.access", "/visitors"],
  ["hotel_settings.access", "/settings"],
];

export function landingPath(me: Me): string {
  if (me.is_full_admin) return "/";
  for (const [perm, path] of PRIORITY) {
    if (me.permissions.includes(perm)) return path;
  }
  return "/";
}
