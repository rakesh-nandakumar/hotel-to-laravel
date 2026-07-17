import { createContext, useContext, useEffect, useState, ReactNode, useCallback } from "react";
import { api, post } from "./api";

export type User = {
  id: number;
  name: string;
  email: string;
  email_verified: boolean;
  status: "active" | "suspended" | "inactive";
  phone: string | null;
  profile_image: string | null;
  two_factor_confirmed: boolean;
  role: { id: number; name: string } | null;
  roles: { id: number; name: string }[];
};

export type MenuNode = {
  id: number;
  name: string;
  icon: string | null;
  href: string | null;
  route: string | null;
  children: MenuNode[];
};

export type Branch = { id: number; name: string };

export type Me = {
  user: User;
  is_full_admin: boolean;
  permissions: string[];
  home: string;
  menu: MenuNode[];
  branch: { branches: Branch[]; selected_id: number | null; show_selector: boolean };
};

type Challenge = "two-factor" | "otp";
type LoginOutcome = { status: "ok"; me: Me | null } | { status: "challenge"; challenge: Challenge };

type PinUser = { id: number; name: string; roleName: string };

type AuthCtx = {
  me: Me | null;
  loading: boolean;
  /** True if the user holds this permission (module_key.action), or is a Full Administrator. */
  can: (permission: string) => boolean;
  /** True if the user holds *any* of these permissions, or is a Full Administrator. */
  canAny: (permissions: string[]) => boolean;
  refresh: () => Promise<Me | null>;
  login: (email: string, password: string, remember?: boolean) => Promise<LoginOutcome>;
  completeTwoFactor: (payload: { code?: string; recovery_code?: string }) => Promise<Me | null>;
  completeOtp: (payload: { code?: string; recovery_code?: string }) => Promise<Me | null>;
  pinLogin: (pin: string) => Promise<Me | null>;
  logout: () => Promise<void>;
};

/** The account bound to this device by its last credential login — the only one allowed to PIN-unlock here. */
export function getPinUser(): PinUser | null {
  if (!localStorage.getItem("mv.device")) return null;
  const raw = localStorage.getItem("mv.pinUser");
  return raw ? (JSON.parse(raw) as PinUser) : null;
}

const Ctx = createContext<AuthCtx>(null as never);
export const useAuth = () => useContext(Ctx);

/**
 * Permission-only view of the auth context — `const { can, canAny } = usePermissions()`.
 * Server-side `CheckPermission` (`can_do`) is always the real gate; this just
 * hides/disables UI the current user can't action.
 */
export function usePermissions(): { can: AuthCtx["can"]; canAny: AuthCtx["canAny"] } {
  const { can, canAny } = useAuth();
  return { can, canAny };
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async (): Promise<Me | null> => {
    try {
      const data = await api<Me>("/me");
      setMe(data);
      return data;
    } catch {
      setMe(null);
      return null;
    }
  }, []);

  useEffect(() => {
    refresh().finally(() => setLoading(false));
  }, [refresh]);

  /** After any successful login/challenge — load the session, mint a fresh PIN device token. */
  const establishSession = async (): Promise<Me | null> => {
    const data = await refresh();
    if (!data) return null;
    try {
      const { device_token } = await post<{ device_token: string }>("/device-token");
      localStorage.setItem("mv.device", device_token);
      localStorage.setItem(
        "mv.pinUser",
        JSON.stringify({ id: data.user.id, name: data.user.name, roleName: data.user.role?.name ?? "Staff" } satisfies PinUser),
      );
    } catch {
      // PIN quick-unlock is a convenience — a failure here shouldn't block sign-in.
    }
    return data;
  };

  return (
    <Ctx.Provider
      value={{
        me,
        loading,
        can: (permission) => !!me && (me.is_full_admin || me.permissions.includes(permission)),
        canAny: (permissions) => !!me && (me.is_full_admin || permissions.some((p) => me.permissions.includes(p))),
        refresh,
        login: async (email, password, remember) => {
          const r = await post<{ home?: string; challenge?: Challenge }>("/login", { email, password, remember });
          if (r.challenge) return { status: "challenge", challenge: r.challenge };
          return { status: "ok", me: await establishSession() };
        },
        completeTwoFactor: async (payload) => {
          await api("/two-factor-challenge", { method: "POST", body: payload });
          return establishSession();
        },
        completeOtp: async (payload) => {
          await post("/otp-challenge", payload);
          return establishSession();
        },
        pinLogin: async (pin) => {
          const deviceToken = localStorage.getItem("mv.device");
          if (!deviceToken) throw new Error("Sign in with email & password first");
          await post("/pin-login", { device_token: deviceToken, pin });
          return refresh();
        },
        logout: async () => {
          try {
            await post("/logout");
          } finally {
            // Device/PIN binding is device-bound, not session-bound — it survives sign-out.
            setMe(null);
          }
        },
      }}
    >
      {children}
    </Ctx.Provider>
  );
}
