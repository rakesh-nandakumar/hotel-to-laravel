/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        // Brand accent — ported from leolanka-inertia's blue/indigo primary
        // (oklch hue ~258). Drives buttons, inputs, focus rings, active states.
        brand: {
          50: "#f2f7ff",
          100: "#d8e9ff",
          200: "#b8d6ff",
          300: "#8bb9fd",
          400: "#4f91f2",
          500: "#2275e8",
          600: "#0462d3", // --primary
          700: "#0052b4",
          800: "#11458c",
          900: "#112d55",
          950: "#09182f",
        },
        // Sidebar / dark chrome — ported from leolanka's navy sidebar tokens.
        sidebar: {
          DEFAULT: "#0c182a", // --sidebar
          deep: "#070f1c", // gradient tail
          accent: "#16273f", // --sidebar-accent
          border: "#1a273a", // --sidebar-border
          fg: "#c4cfdb", // --sidebar-foreground
          primary: "#3783f0", // --sidebar-primary
        },
      },
    },
  },
  plugins: [],
};
