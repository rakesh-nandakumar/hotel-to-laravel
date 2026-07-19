/**
 * Turns the 3 admin-picked base colors (Settings → Hotel identity → Theming)
 * into CSS custom properties the whole app's Tailwind palette is built from
 * (see tailwind.config.js's `brand`/`sidebar` colors, all `var(--color-*)`).
 * No page reload needed — colors apply the moment they're set.
 *
 * Each CSS variable holds a space-separated RGB triple ("34 117 232"), not a
 * hex string — tailwind.config.js's `withOpacity()` wraps them in `rgb(... / <alpha>)`
 * so `/opacity` modifiers (`bg-brand-500/40` etc., used throughout the app)
 * keep working. See that file's comment for why hex alone wouldn't do that.
 */

type HSL = { h: number; s: number; l: number };
type RGB = { r: number; g: number; b: number };

function hexToRgb(hex: string): RGB {
  const m = /^#([0-9a-f]{6})$/i.exec(hex);
  if (!m) return { r: 0, g: 0, b: 0 };
  return {
    r: parseInt(m[1].slice(0, 2), 16),
    g: parseInt(m[1].slice(2, 4), 16),
    b: parseInt(m[1].slice(4, 6), 16),
  };
}

function rgbToHsl({ r, g, b }: RGB): HSL {
  const rN = r / 255, gN = g / 255, bN = b / 255;
  const max = Math.max(rN, gN, bN);
  const min = Math.min(rN, gN, bN);
  const l = (max + min) / 2;
  if (max === min) return { h: 0, s: 0, l: l * 100 };
  const d = max - min;
  const s = d / (1 - Math.abs(2 * l - 1));
  let h: number;
  if (max === rN) h = ((gN - bN) / d) % 6;
  else if (max === gN) h = (bN - rN) / d + 2;
  else h = (rN - gN) / d + 4;
  h *= 60;
  if (h < 0) h += 360;
  return { h, s: s * 100, l: l * 100 };
}

function hslToRgb({ h, s, l }: HSL): RGB {
  const sN = Math.min(100, Math.max(0, s)) / 100;
  const lN = Math.min(100, Math.max(0, l)) / 100;
  const c = (1 - Math.abs(2 * lN - 1)) * sN;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = lN - c / 2;
  let [r, g, b] = [0, 0, 0];
  if (h < 60) [r, g, b] = [c, x, 0];
  else if (h < 120) [r, g, b] = [x, c, 0];
  else if (h < 180) [r, g, b] = [0, c, x];
  else if (h < 240) [r, g, b] = [0, x, c];
  else if (h < 300) [r, g, b] = [x, 0, c];
  else [r, g, b] = [c, 0, x];
  return { r: Math.round((r + m) * 255), g: Math.round((g + m) * 255), b: Math.round((b + m) * 255) };
}

const triple = ({ r, g, b }: RGB) => `${r} ${g} ${b}`;

/** Target lightness (%) per Tailwind-style stop, anchored so 600 = the picked color exactly. */
const BRAND_STOPS: Record<string, number> = {
  "50": 97, "100": 94, "200": 86, "300": 74, "400": 60, "500": 50,
  "600": 42, "700": 34, "800": 27, "900": 20, "950": 12,
};

function brandRamp(baseHex: string): Record<string, string> {
  const base = rgbToHsl(hexToRgb(baseHex));
  const delta = base.l - BRAND_STOPS["600"];
  const ramp: Record<string, string> = {};
  for (const stop of Object.keys(BRAND_STOPS)) {
    if (stop === "600") {
      ramp[stop] = triple(hexToRgb(baseHex));
      continue;
    }
    const targetL = Math.min(100, Math.max(0, BRAND_STOPS[stop] + delta));
    ramp[stop] = triple(hslToRgb({ h: base.h, s: base.s, l: targetL }));
  }
  return ramp;
}

function sidebarShades(baseHex: string) {
  const base = rgbToHsl(hexToRgb(baseHex));
  const { h, s, l } = base;
  return {
    DEFAULT: triple(hexToRgb(baseHex)),
    deep: triple(hslToRgb({ h, s, l: Math.max(0, l - 4) })),
    accent: triple(hslToRgb({ h, s, l: Math.min(100, l + 5) })),
    border: triple(hslToRgb({ h, s: Math.max(0, s - 15), l: Math.min(100, l + 7) })),
    fg: triple(hslToRgb({ h, s: Math.min(30, s * 0.3), l: 80 })),
  };
}

/** Applies the 3 base colors to `document.documentElement` as CSS custom properties. */
export function applyTheme(primary: string, secondary: string, sidebar: string): void {
  const root = document.documentElement.style;
  for (const [stop, rgb] of Object.entries(brandRamp(primary))) {
    root.setProperty(`--color-brand-${stop}`, rgb);
  }
  const sb = sidebarShades(sidebar);
  root.setProperty("--color-sidebar-DEFAULT", sb.DEFAULT);
  root.setProperty("--color-sidebar-deep", sb.deep);
  root.setProperty("--color-sidebar-accent", sb.accent);
  root.setProperty("--color-sidebar-border", sb.border);
  root.setProperty("--color-sidebar-fg", sb.fg);
  root.setProperty("--color-sidebar-primary", triple(hexToRgb(secondary)));
}
