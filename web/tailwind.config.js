/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        brand: {
          50: "#f0f7f4",
          100: "#dcebe3",
          500: "#1a7a5e",
          600: "#156750",
          700: "#115542",
          900: "#0a3328",
        },
      },
    },
  },
  plugins: [],
};
