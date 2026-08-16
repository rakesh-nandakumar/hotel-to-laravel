/**
 * Browser-side mirror of the backend's relative host resolution
 * (App\Services\TenantHostResolver): the first DNS label of the hostname is
 * the identity and everything after it is the base — no domain literal is
 * ever baked into the bundle.
 *
 * The backend is always the real authority: IdentifyTenant resolves the
 * tenant from the Host header and EnsureCentralContext rejects any
 * /api/central/* request that resolved one. What's here only picks which SPA
 * tree to mount; the final decision actually comes from the /api/host-context
 * boot gate (see main.tsx), so the worst a mistake can do is render a panel
 * whose every API call then 404s.
 */

/** The reserved master-control first label — mirrors TENANCY_CENTRAL_SUBDOMAIN. */
export const CENTRAL_SUBDOMAIN = (import.meta.env.VITE_TENANCY_CENTRAL_SUBDOMAIN ?? "admin").trim().toLowerCase();

/** A bare "co.uk"-style base has no room for a tenant label beneath it. */
const MIN_BASE_LABELS = 2;

/** *.localhost always gets a floor of 1 label so local subdomains resolve. */
function isLocalhost(host: string): boolean {
  return host === "localhost" || host.endsWith(".localhost");
}

/** Lowercased, trailing-dot stripped, IPv6 brackets removed. */
function normalise(hostname: string): string {
  return hostname.toLowerCase().replace(/\.$/, "").replace(/^\[|\]$/g, "");
}

/** Everything after the first label; the whole host when it is the apex. */
export function baseOf(hostname: string): string {
  const host = normalise(hostname);
  if (!host) return "";
  const labels = host.split(".");
  if (labels.length <= 1) return host;
  const base = labels.slice(1).join(".");
  return isApex(host, base) ? host : base;
}

function isApex(host: string, base: string): boolean {
  if (!base) return true;
  const min = isLocalhost(host) ? 1 : MIN_BASE_LABELS;
  return base.split(".").length < min;
}

/**
 * True on the master-control host: the apex (bare base domain, e.g.
 * "vellixglobal.com" or "localhost") or a host whose first label is the
 * central subdomain ("admin.vellixglobal.com", "admin.localhost", ...) —
 * the same rules TenantHostResolver applies server-side.
 */
export function isCentralHost(hostname: string): boolean {
  const host = normalise(hostname);
  if (!host) return false;

  const labels = host.split(".");
  const base = labels.slice(1).join(".");

  return isApex(host, base) || labels[0] === CENTRAL_SUBDOMAIN;
}

/**
 * The public host a tenant's own app is reached at — display only. Derived
 * relatively from the current host's base, exactly like the backend derives
 * landing URLs (Impersonation::landingUrl).
 */
export function tenantHost(slug: string, hostname = window.location.hostname): string {
  return `${slug}.${baseOf(hostname)}`;
}
