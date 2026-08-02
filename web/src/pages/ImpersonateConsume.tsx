import { useEffect, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { post } from "../lib/api";

/**
 * Lands here right after a platform operator clicks "Impersonate" in master
 * control. Consumes the single-use token and, on success, sends the browser
 * into the normal authenticated app — the operator never sees a password.
 */
export default function ImpersonateConsume() {
  const { token } = useParams<{ token: string }>();
  const [params] = useSearchParams();
  const nav = useNavigate();
  const [error, setError] = useState("");

  useEffect(() => {
    if (!token) return;
    // Locally the tenant can't be read from the host (no subdomains in DNS),
    // so master control puts it in the link and it has to ride along to the
    // API call too — otherwise IdentifyTenant resolves no tenant and the
    // token, which is tenant-bound, is rejected. Absent in production, where
    // the subdomain itself identifies the tenant.
    const tenant = params.get("tenant");
    const query = tenant ? `?tenant=${encodeURIComponent(tenant)}` : "";

    post(`/impersonate/${token}${query}`)
      .then(() => {
        // Deliberately NOT the response's `home`: that's a backend route URL
        // (/api/dashboard), not one of this SPA's client-side paths, so
        // following it lands on raw JSON. Reload at the root instead and let
        // AuthProvider re-fetch /me and route via landingPath(), exactly as an
        // ordinary sign-in does. A full load, not a client-side nav, so the
        // brand-new session is picked up everywhere.
        window.location.href = "/";
      })
      .catch((err) => setError((err as Error).message));
  }, [token, params, nav]);

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
