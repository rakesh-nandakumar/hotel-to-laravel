import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from "react";
import { api } from "./api";
import { applyTheme } from "./theme";

/**
 * Site identity — hotel name, tagline and logo — pulled from the business
 * Settings (Hotel identity section). On a tenant host the boot gate
 * (/api/host-context) already returns the full public branding payload, so
 * the provider seeds from that (setBrandingBootHint in main.tsx) and the
 * tenant shell paints its identity and theme with zero extra round-trips.
 * refresh() re-pulls /api/public/branding after settings change. Nothing here
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
  /** Base colors the whole UI's brand/sidebar palettes are generated from — see lib/theme.ts. */
  theme_primary: string;
  theme_secondary: string;
  theme_sidebar: string;
};

const DEFAULTS: Branding = {
  name: "Hotel Management System",
  tagline: "Hospitality Management System",
  login_tagline: "Hospitality Management System",
  logo: "",
  check_in_time: "14:00",
  check_out_time: "12:00",
  theme_primary: "#0462d3",
  theme_secondary: "#3783f0",
  theme_sidebar: "#0c182a",
};

type BrandingCtx = { branding: Branding; loading: boolean; refresh: () => void };

const Ctx = createContext<BrandingCtx>({ branding: DEFAULTS, loading: true, refresh: () => {} });
export const useBranding = () => useContext(Ctx);

let bootHint: Partial<Branding> | null = null;

/**
 * Seed identity from the boot gate's tenant payload (main.tsx, before
 * mounting). Cleared when there is no tenant (the provider won't fetch then,
 * but the tenant shell never mounts without a hint anyway).
 */
export function setBrandingBootHint(hint: Partial<Branding> | null) {
  bootHint = hint;
}

/** Two-letter fallback mark (e.g. "Mount View Hotel" → "MV") when no logo is set. */
export function brandInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "H";
  return (parts[0][0] + (parts[1]?.[0] ?? "")).toUpperCase();
}

export function BrandingProvider({ children }: { children: ReactNode }) {
  const [branding, setBranding] = useState<Branding>({ ...DEFAULTS, ...bootHint });
  const [loading, setLoading] = useState(bootHint === null);

  const refresh = useCallback(() => {
    api<Partial<Branding>>("/public/branding")
      .then((b) => setBranding((prev) => ({ ...prev, ...b })))
      .catch(() => {}) // keep what we have if branding can't be reached (e.g. offline)
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    // The boot gate already carried the full identity on a tenant host; only
    // re-fetch when there was no hint (or when settings changed).
    if (bootHint === null) refresh();
  }, [refresh]);

  useEffect(() => {
    document.title = branding.tagline ? `${branding.name} — ${branding.tagline}` : branding.name;
  }, [branding]);

  useEffect(() => {
    applyTheme(branding.theme_primary, branding.theme_secondary, branding.theme_sidebar);
  }, [branding.theme_primary, branding.theme_secondary, branding.theme_sidebar]);

  return <Ctx.Provider value={{ branding, loading, refresh }}>{children}</Ctx.Provider>;
}
