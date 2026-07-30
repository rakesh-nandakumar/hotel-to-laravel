import { createContext, useContext, useEffect, useState, ReactNode, useCallback } from "react";
import { api, post } from "./api";

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
    } catch {
      setAdmin(null);
    }
  }, []);

  useEffect(() => {
    refresh().finally(() => setLoading(false));
  }, [refresh]);

  return (
    <Ctx.Provider
      value={{
        admin,
        loading,
        login: async (email, password) => {
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
