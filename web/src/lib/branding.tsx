import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from "react";
import { api } from "./api";

/**
 * Site identity — hotel name, tagline and logo — pulled from the business
 * Settings (Hotel identity section) via the unauthenticated `/public/branding`
 * endpoint. Fetched once for the whole app so the login screen, sidebar and
 * guest pages all render the same, admin-configurable identity. Nothing here
 * is hard-coded: the Settings screen drives every value.
 */
export type Branding = {
  name: string;
  /** Shown under the hotel name in the sidebar. */
  tagline: string;
  /** Shown under the hotel name on the login screen. */
  login_tagline: string;
  /** Data URI of the uploaded logo, or "" when none is set. */
  logo: string;
  check_in_time: string;
  check_out_time: string;
};

const DEFAULTS: Branding = {
  name: "Hotel Management System",
  tagline: "Hospitality Management System",
  login_tagline: "Hospitality Management System",
  logo: "",
  check_in_time: "14:00",
  check_out_time: "12:00",
};

type BrandingCtx = { branding: Branding; loading: boolean; refresh: () => void };

const Ctx = createContext<BrandingCtx>({ branding: DEFAULTS, loading: true, refresh: () => {} });
export const useBranding = () => useContext(Ctx);

/** Two-letter fallback mark (e.g. "Mount View Hotel" → "MV") when no logo is set. */
export function brandInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "H";
  return (parts[0][0] + (parts[1]?.[0] ?? "")).toUpperCase();
}

export function BrandingProvider({ children }: { children: ReactNode }) {
  const [branding, setBranding] = useState<Branding>(DEFAULTS);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(() => {
    api<Partial<Branding>>("/public/branding")
      .then((b) => setBranding({ ...DEFAULTS, ...b }))
      .catch(() => {}) // keep defaults if branding can't be reached (e.g. offline)
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  useEffect(() => {
    document.title = branding.tagline ? `${branding.name} — ${branding.tagline}` : branding.name;
  }, [branding]);

  return <Ctx.Provider value={{ branding, loading, refresh }}>{children}</Ctx.Provider>;
}
