import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { post } from "../lib/api";

/**
 * Lands here right after a platform operator clicks "Impersonate" in master
 * control. Consumes the single-use token and, on success, sends the browser
 * into the normal authenticated app — the operator never sees a password.
 */
export default function ImpersonateConsume() {
  const { token } = useParams<{ token: string }>();
  const nav = useNavigate();
  const [error, setError] = useState("");

  useEffect(() => {
    if (!token) return;
    post<{ home: string }>(`/impersonate/${token}`)
      .then((r) => {
        window.location.href = r.home || "/";
      })
      .catch((err) => setError((err as Error).message));
  }, [token, nav]);

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
