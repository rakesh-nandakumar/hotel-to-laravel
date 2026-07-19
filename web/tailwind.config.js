/**
 * Each shade below is a CSS custom property holding a space-separated RGB
 * triple (e.g. "34 117 232"), NOT a hex string — that's what lets Tailwind's
 * `/opacity` modifiers (`bg-brand-500/40`, `text-sidebar-fg/60`, used all
 * over the app) keep working once the value is swapped at runtime. `withOpacity`
 * is the officially documented Tailwind pattern for CSS-variable-driven colors
 * that still support those modifiers — see
 * https://tailwindcss.com/docs/customizing-colors#using-css-variables.
 * The variables themselves are set at runtime by web/src/lib/theme.ts, driven
 * by Settings → Hotel identity → Theming; the numbers baked in here as `var()`
 * fallbacks are just the original leolanka-inertia design (so nothing changes
 * visually until an admin picks new colors, and static tooling that doesn't
 * run JS still renders something sane).
 */
function withOpacity(variableName) {
  return ({ opacityValue }) =>
    opacityValue === undefined ? `rgb(var(${variableName}))` : `rgb(var(${variableName}) / ${opacityValue})`;
}

/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        brand: {
          50: withOpacity("--color-brand-50, 242 247 255"),
          100: withOpacity("--color-brand-100, 216 233 255"),
          200: withOpacity("--color-brand-200, 184 214 255"),
          300: withOpacity("--color-brand-300, 139 185 253"),
          400: withOpacity("--color-brand-400, 79 145 242"),
          500: withOpacity("--color-brand-500, 34 117 232"),
          600: withOpacity("--color-brand-600, 4 98 211"),
          700: withOpacity("--color-brand-700, 0 82 180"),
          800: withOpacity("--color-brand-800, 17 69 140"),
          900: withOpacity("--color-brand-900, 17 45 85"),
          950: withOpacity("--color-brand-950, 9 24 47"),
        },
        sidebar: {
          DEFAULT: withOpacity("--color-sidebar-DEFAULT, 12 24 42"),
          deep: withOpacity("--color-sidebar-deep, 7 15 28"), // gradient tail
          accent: withOpacity("--color-sidebar-accent, 22 39 63"),
          border: withOpacity("--color-sidebar-border, 26 39 58"),
          fg: withOpacity("--color-sidebar-fg, 196 207 219"),
          primary: withOpacity("--color-sidebar-primary, 55 131 240"),
        },
      },
    },
  },
  plugins: [],
};
