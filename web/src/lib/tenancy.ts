/**
 * Browser-side mirror of the backend's path-prefix tenancy
 * (App\Services\TenantHostResolver): the first URL segment is the identity —
 * /wasana/… is tenant "wasana", /admin/… is master control. No domain literal
 * is ever baked into the bundle.
 *
 * The backend is always the real authority: IdentifyTenant resolves the
 * tenant from the X-Tenant-Slug header (which this file's tenantSlugFromPath
 * feeds to /api/host-context and every API call). What's here picks which
 * SPA tree to mount and which basename; the final decision comes from the
 * /api/host-context boot gate (see main.tsx), so the worst a mistake can do
 * is render a panel whose every API call then 404s.
 */

/** The reserved master-control prefix — mirrors TENANCY_CENTRAL_PREFIX. */
export const CENTRAL_PREFIX = (import.meta.env.VITE_TENANCY_CENTRAL_PREFIX ?? "admin").trim().toLowerCase();

/**
 * The tenant slug named by the current URL, e.g. "/wasana/reservations/5" →
 * "wasana". The central prefix and any path without a first segment are not a
 * tenant — they belong to master control (or nothing).
 */
export function tenantSlugFromPath(pathname: string): string | null {
  const first = pathname.replace(/^\/+|\/+$/g, "").split("/")[0];
  if (!first || first.toLowerCase() === CENTRAL_PREFIX) return null;
  return first.toLowerCase();
}

/** True when the path is the master-control panel (/admin/…). */
export function isCentralPath(pathname: string): boolean {
  return pathname.replace(/^\/+|\/+$/g, "").split("/")[0] === CENTRAL_PREFIX;
}

/** The public URL a tenant's app sits at, relative to the host root. */
export function tenantUrl(slug: string): string {
  return `/${slug}`;
}

/** The prefix to mount the SPA tree on: the tenant's slug, or the central panel. */
export function mountBasename(pathname: string): string {
  const slug = tenantSlugFromPath(pathname);
  return slug ? `/${slug}` : `/${CENTRAL_PREFIX}`;
}
