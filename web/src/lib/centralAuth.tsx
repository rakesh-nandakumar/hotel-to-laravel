import { createContext, useContext, useEffect, useState, ReactNode, useCallback } from "react";
import { api, post, ensureCsrfCookie, ApiFail } from "./api";

export type CentralAdmin = { id: number; name: string; email: string };

type CentralAuthCtx = {
  admin: CentralAdmin | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<CentralAdmin>;
  logout: () => Promise<void>;
};

const Ctx = createContext<CentralAuthCtx>(null as never);
export const useCentralAuth = () => useContext(Ctx);

/**
 * The "master control" session — a wholly separate principal/guard from the
 * tenant-side AuthProvider (see lib/auth.tsx). Deliberately minimal: no
 * permissions system, no 2FA/PIN — a small, trusted set of platform operators.
 */
export function CentralAuthProvider({ children }: { children: ReactNode }) {
  const [admin, setAdmin] = useState<CentralAdmin | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const data = await api<{ admin: CentralAdmin | null }>("/central/me", { silent401: true });
      setAdmin(data.admin);
    } catch (e) {
      // If we get a 404, it means there's a stale tenant session in the browser
      // Clear it and retry once
      if ((e as ApiFail).status === 404) {
        document.cookie.split(";").forEach((c) => {
          const eqPos = c.indexOf("=");
          const name = eqPos > -1 ? c.slice(0, eqPos).trim() : c.trim();
          if (name === "laravel_session" || name.startsWith("XSRF-")) {
            document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`;
          }
        });
        try {
          const data = await api<{ admin: CentralAdmin | null }>("/central/me", { silent401: true });
          setAdmin(data.admin);
        } catch {
          setAdmin(null);
        }
      } else {
        setAdmin(null);
      }
    }
  }, []);

  useEffect(() => {
    refresh().finally(() => setLoading(false));
  }, [refresh]);

  // Auto-refresh session every 10 minutes to prevent timeout
  useEffect(() => {
    const interval = setInterval(() => {
      if (admin) refresh();
    }, 10 * 60 * 1000); // 10 minutes
    return () => clearInterval(interval);
  }, [admin, refresh]);

  return (
    <Ctx.Provider
      value={{
        admin,
        loading,
        login: async (email, password) => {
          await ensureCsrfCookie(true);
          const r = await post<{ admin: CentralAdmin }>("/central/login", { email, password });
          setAdmin(r.admin);
          return r.admin;
        },
        logout: async () => {
          try {
            await post("/central/logout");
          } finally {
            setAdmin(null);
          }
        },
      }}
    >
      {children}
    </Ctx.Provider>
  );
}
