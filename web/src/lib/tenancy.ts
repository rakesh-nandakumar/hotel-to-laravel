/**
 * Browser-side mirror of the backend's config/tenancy.php. Decides whether the
 * current page is "master control" (CentralApp) or a tenant's own app (App).
 *
 * The backend is always the real authority — App\Http\Middleware\IdentifyTenant
 * resolves the tenant from the Host header and EnsureCentralContext rejects any
 * /api/central/* request that resolved one. What's here only picks which SPA
 * tree to mount, so the worst a mistake can do is render a panel whose every
 * API call then 404s.
 */

/** e.g. "vellixglobal.com". Empty when unset — see isCentralHost(). */
export const BASE_DOMAIN = (import.meta.env.VITE_TENANCY_BASE_DOMAIN ?? "").trim().toLowerCase();

/** The reserved master-control subdomain — matches TENANCY_CENTRAL_SUBDOMAIN. */
export const CENTRAL_SUBDOMAIN = (import.meta.env.VITE_TENANCY_CENTRAL_SUBDOMAIN ?? "admin").trim().toLowerCase();

/** Hosts with no subdomains to speak of, where the /central path fallback is allowed. */
const DEV_HOSTS = ["localhost", "127.0.0.1", "[::1]", "::1"];

export function isDevHost(hostname = window.location.hostname): boolean {
  return DEV_HOSTS.includes(hostname.toLowerCase());
}

/**
 * True on the master-control host: `admin.{base}` or the bare base domain,
 * mirroring IdentifyTenant's own central check.
 */
export function isCentralHost(hostname = window.location.hostname): boolean {
  if (!BASE_DOMAIN) {
    return false;
  }

  const host = hostname.toLowerCase();

  return host === BASE_DOMAIN || host === `${CENTRAL_SUBDOMAIN}.${BASE_DOMAIN}`;
}

/**
 * Whether to mount CentralApp instead of the tenant App.
 *
 * With VITE_TENANCY_BASE_DOMAIN set (production), master control lives on its
 * own host and nowhere else — a tenant subdomain hitting /central renders the
 * tenant app instead, so the panel never even paints somewhere it can't work.
 * With it unset, or on localhost where there are no real subdomains, the
 * legacy `/central/*` path still selects it so local dev and the E2E suite
 * keep working unchanged.
 */
export function shouldMountCentralApp(
  hostname = window.location.hostname,
  pathname = window.location.pathname,
): boolean {
  if (isCentralHost(hostname)) {
    return true;
  }

  const pathFallbackAllowed = !BASE_DOMAIN || isDevHost(hostname);

  return pathFallbackAllowed && pathname.startsWith("/central");
}

/**
 * Router basename for CentralApp. On the central host the panel owns the whole
 * origin and sits at "/"; under the path fallback it stays beneath "/central".
 */
export function centralBasename(hostname = window.location.hostname): string {
  return isCentralHost(hostname) ? "" : "/central";
}

/**
 * The public host a tenant's own app is reached at — display only. Falls back
 * to stripping the central subdomain off the current host when no base domain
 * is configured, which is all local dev can infer.
 */
export function tenantHost(slug: string, hostname = window.location.hostname): string {
  const base = BASE_DOMAIN || hostname.replace(new RegExp(`^${CENTRAL_SUBDOMAIN}\\.`), "");

  return `${slug}.${base}`;
}
