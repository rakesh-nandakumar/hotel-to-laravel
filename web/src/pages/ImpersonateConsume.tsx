import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { post } from "../lib/api";
import { tenantSlugFromPath } from "../lib/tenancy";

/**
 * Lands here right after a platform operator clicks "Impersonate" in master
 * control. Consumes the single-use token and, on success, sends the browser
 * into the normal authenticated app — the operator never sees a password.
 * The minted link is {frontend_url}/{slug}/impersonate/{token}, so the tenant
 * is named by this page's own path prefix (no ?tenant= hand-off needed — the
 * tenant app calls identify themselves via X-Tenant-Slug).
 */
export default function ImpersonateConsume() {
  const { token } = useParams<{ token: string }>();
  const [error, setError] = useState("");

  useEffect(() => {
    if (!token) return;

    post(`/impersonate/${token}`)
      .then(() => {
        // Deliberately NOT the response's `home`: that's a backend route URL
        // (/api/dashboard), not one of this SPA's client-side paths, so
        // following it lands on raw JSON. Reload at the tenant's own root
        // (under its path prefix — the router's basename is the only thing
        // making /{slug}/ dashboards render here) and let AuthProvider
        // re-fetch /me and route via landingPath(), exactly as an ordinary
        // sign-in does. A full load, not a client-side nav, so the brand-new
        // session is picked up everywhere.
        window.location.href = `/${tenantSlugFromPath(window.location.pathname) ?? ""}`;
      })
      .catch((err) => setError((err as Error).message));
  }, [token]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-6">
      <div className="text-center">
        {error ? (
          <p className="text-sm font-medium text-red-600">{error}</p>
        ) : (
          <p className="text-sm text-slate-500">Signing you in…</p>
        )}
      </div>
    </div>
  );
}
