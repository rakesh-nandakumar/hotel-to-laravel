import { useState, useEffect } from "react";
import {
  Palette,
  Check,
  RotateCcw,
  Sparkles,
  Eye,
  Sliders,
  LayoutDashboard,
  BedDouble,
  CreditCard,
  Users,
  Bell,
  Search,
  ArrowUpRight,
  Save,
  ArrowLeftRight,
  ShieldCheck,
  FileText,
  Smartphone,
  Wand2,
  Copy,
  CheckCheck,
  Info,
  ChevronDown,
  ChevronUp
} from "lucide-react";
import clsx from "clsx";
import {
  applyTheme,
  hexToRgb,
  rgbToHex,
  rgbToHsl,
  hslToRgb,
  getContrastRatio,
  getWcagRating,
  suggestAccentColor,
} from "../lib/theme";

export type ThemeColors = {
  primary: string;
  secondary: string;
  sidebar: string;
};

export type ThemePreset = {
  id: string;
  name: string;
  description: string;
  colors: ThemeColors;
  tag?: string;
};

export const THEME_PRESETS: ThemePreset[] = [
  {
    id: "ocean-executive",
    name: "Ocean Executive",
    description: "Classic deep cobalt & dark navy for professional hospitality",
    tag: "Default",
    colors: {
      primary: "#0462d3",
      secondary: "#3783f0",
      sidebar: "#0c182a",
    },
  },
  {
    id: "emerald-luxury",
    name: "Emerald Resort",
    description: "Rich botanical emerald green for eco-resorts & luxury retreats",
    tag: "Popular",
    colors: {
      primary: "#059669",
      secondary: "#10b981",
      sidebar: "#064e3b",
    },
  },
  {
    id: "royal-sovereign",
    name: "Royal Sovereign",
    description: "Sophisticated deep violet & amethyst for boutique hotels",
    tag: "Boutique",
    colors: {
      primary: "#7c3aed",
      secondary: "#8b5cf6",
      sidebar: "#1e1b4b",
    },
  },
  {
    id: "midnight-gold",
    name: "Midnight Gold",
    description: "Warm luxury champagne amber paired with dark onyx slate",
    tag: "Luxury",
    colors: {
      primary: "#d97706",
      secondary: "#f59e0b",
      sidebar: "#1c1917",
    },
  },
  {
    id: "crimson-velvet",
    name: "Crimson Velvet",
    description: "Warm ruby red with deep wine burgundy background",
    colors: {
      primary: "#e11d48",
      secondary: "#f43f5e",
      sidebar: "#380713",
    },
  },
  {
    id: "teal-sanctuary",
    name: "Teal Sanctuary",
    description: "Refreshing tropical turquoise with deep pine contrast",
    colors: {
      primary: "#0d9488",
      secondary: "#14b8a6",
      sidebar: "#134e4a",
    },
  },
  {
    id: "slate-modern",
    name: "Slate Modern",
    description: "Crisp contemporary corporate slate with vibrant sapphire",
    colors: {
      primary: "#2563eb",
      secondary: "#60a5fa",
      sidebar: "#0f172a",
    },
  },
  {
    id: "cyber-dark",
    name: "Cyber Indigo",
    description: "High-contrast electric indigo & deep charcoal night mode",
    tag: "Night Mode",
    colors: {
      primary: "#6366f1",
      secondary: "#818cf8",
      sidebar: "#090d16",
    },
  },
];

// Quick color palette swatches for easy selection
const PRIMARY_SWATCHES = [
  "#0462d3", "#2563eb", "#0284c7", "#0d9488", "#059669",
  "#16a34a", "#d97706", "#ea580c", "#e11d48", "#7c3aed",
  "#9333ea", "#4f46e5"
];

const SECONDARY_SWATCHES = [
  "#3783f0", "#60a5fa", "#38bdf8", "#14b8a6", "#10b981",
  "#4ade80", "#f59e0b", "#fb923c", "#f43f5e", "#8b5cf6",
  "#a855f7", "#818cf8"
];

const SIDEBAR_SWATCHES = [
  "#0c182a", "#0f172a", "#111827", "#1c1917", "#064e3b",
  "#1e1b4b", "#380713", "#134e4a", "#18181b", "#090d16"
];

const HEX_REGEX = /^#[0-9a-fA-F]{6}$/;

export type UseCaseView = "dashboard" | "reservation" | "pos" | "invoice" | "mobile";

interface ThemeCustomizerProps {
  initialPrimary: string;
  initialSecondary: string;
  initialSidebar: string;
  disabled?: boolean;
  onSaveTheme: (colors: ThemeColors) => Promise<void> | void;
}

export function ThemeCustomizer({
  initialPrimary,
  initialSecondary,
  initialSidebar,
  disabled = false,
  onSaveTheme,
}: ThemeCustomizerProps) {
  // Saved colors from backend
  const savedColors: ThemeColors = {
    primary: initialPrimary || "#0462d3",
    secondary: initialSecondary || "#3783f0",
    sidebar: initialSidebar || "#0c182a",
  };

  // Draft working state for real-time live preview
  const [colors, setColors] = useState<ThemeColors>(savedColors);
  const [activeTab, setActiveTab] = useState<"presets" | "custom">("presets");
  const [previewView, setPreviewView] = useState<UseCaseView>("dashboard");
  const [isSaving, setIsSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);

  // Sync if initial props change
  useEffect(() => {
    setColors({
      primary: initialPrimary || "#0462d3",
      secondary: initialSecondary || "#3783f0",
      sidebar: initialSidebar || "#0c182a",
    });
  }, [initialPrimary, initialSecondary, initialSidebar]);

  // Track if current draft differs from saved backend state
  useEffect(() => {
    const changed =
      colors.primary.toLowerCase() !== savedColors.primary.toLowerCase() ||
      colors.secondary.toLowerCase() !== savedColors.secondary.toLowerCase() ||
      colors.sidebar.toLowerCase() !== savedColors.sidebar.toLowerCase();
    setHasChanges(changed);
  }, [colors, savedColors]);

  // Live effect: apply draft colors to the actual document root live as user tweaks them!
  useEffect(() => {
    if (HEX_REGEX.test(colors.primary) && HEX_REGEX.test(colors.secondary) && HEX_REGEX.test(colors.sidebar)) {
      applyTheme(colors.primary, colors.secondary, colors.sidebar);
    }
  }, [colors]);

  const handleColorChange = (key: keyof ThemeColors, value: string) => {
    setColors((prev) => ({ ...prev, [key]: value }));
  };

  const applyPreset = (preset: ThemePreset) => {
    setColors(preset.colors);
  };

  const resetToSaved = () => {
    setColors(savedColors);
    applyTheme(savedColors.primary, savedColors.secondary, savedColors.sidebar);
  };

  const handleSave = async () => {
    if (disabled || isSaving) return;
    setIsSaving(true);
    try {
      await onSaveTheme(colors);
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 2500);
    } catch {
      // Error handled by parent handler
    } finally {
      setIsSaving(false);
    }
  };

  // Swap primary and secondary colors
  const swapPrimarySecondary = () => {
    setColors((prev) => ({
      ...prev,
      primary: prev.secondary,
      secondary: prev.primary,
    }));
  };

  // Auto-generate complementary accent color
  const generateAccent = () => {
    const suggested = suggestAccentColor(colors.primary);
    setColors((prev) => ({ ...prev, secondary: suggested }));
  };

  return (
    <div className="space-y-6">
      {/* Top Banner / Tab Switcher & Global Actions */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm border border-slate-100">
        <div>
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-100 text-brand-600 font-bold">
              <Palette size={18} />
            </div>
            <div>
              <h2 className="text-base font-extrabold text-slate-800">Theme & Brand Color Customizer</h2>
              <p className="text-xs text-slate-500">
                Customize brand accent & sidebar colors with live operational preview across hotel use cases.
              </p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* Tab buttons */}
          <div className="flex rounded-xl bg-slate-100 p-1">
            <button
              onClick={() => setActiveTab("presets")}
              className={clsx(
                "flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition",
                activeTab === "presets"
                  ? "bg-white text-slate-800 shadow-sm"
                  : "text-slate-600 hover:text-slate-900"
              )}
            >
              <Sparkles size={14} className="text-amber-500" />
              Theme Presets
            </button>
            <button
              onClick={() => setActiveTab("custom")}
              className={clsx(
                "flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition",
                activeTab === "custom"
                  ? "bg-white text-slate-800 shadow-sm"
                  : "text-slate-600 hover:text-slate-900"
              )}
            >
              <Sliders size={14} />
              Custom Color Picker
            </button>
          </div>

          {/* Action buttons */}
          {hasChanges && (
            <button
              onClick={resetToSaved}
              className="flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-sm hover:bg-slate-50 hover:text-slate-900 transition"
              title="Revert all unsaved theme changes"
            >
              <RotateCcw size={13} />
              Reset
            </button>
          )}

          <button
            onClick={handleSave}
            disabled={disabled || isSaving || !hasChanges}
            className={clsx(
              "flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold shadow-sm transition",
              saveSuccess
                ? "bg-emerald-600 text-white"
                : hasChanges
                ? "bg-brand-600 text-white hover:bg-brand-700 active:scale-95"
                : "bg-slate-200 text-slate-400 cursor-not-allowed"
            )}
          >
            {saveSuccess ? (
              <>
                <Check size={14} /> Saved ✓
              </>
            ) : isSaving ? (
              <>Saving...</>
            ) : (
              <>
                <Save size={14} /> Save Theme Colors
              </>
            )}
          </button>
        </div>
      </div>

      {/* Main Content Split: Left (Controls / Presets) vs Right (Live Sample Preview) */}
      <div className="grid gap-6 lg:grid-cols-12">
        {/* Left Column: Preset Palette Selection or Custom Color Picker (6 cols) */}
        <div className="lg:col-span-6 space-y-4">
          {activeTab === "presets" ? (
            <div className="rounded-2xl bg-white p-4 shadow-sm border border-slate-100 space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">
                  Curated Luxury Theme Presets
                </h3>
                <span className="text-[11px] font-semibold text-slate-400">
                  Click any preset to preview live
                </span>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                {THEME_PRESETS.map((preset) => {
                  const isSelected =
                    colors.primary.toLowerCase() === preset.colors.primary.toLowerCase() &&
                    colors.sidebar.toLowerCase() === preset.colors.sidebar.toLowerCase();

                  return (
                    <button
                      key={preset.id}
                      onClick={() => applyPreset(preset)}
                      className={clsx(
                        "group relative flex flex-col justify-between rounded-xl p-3.5 text-left transition-all border",
                        isSelected
                          ? "border-brand-500 bg-brand-50/40 ring-2 ring-brand-500/20 shadow-sm"
                          : "border-slate-200/80 bg-white hover:border-slate-300 hover:shadow-md"
                      )}
                    >
                      <div>
                        <div className="flex items-center justify-between gap-1 mb-1">
                          <span className="font-bold text-sm text-slate-800 group-hover:text-brand-600 transition">
                            {preset.name}
                          </span>
                          {preset.tag && (
                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[9px] font-extrabold uppercase tracking-wide text-amber-800">
                              {preset.tag}
                            </span>
                          )}
                          {isSelected && (
                            <span className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-600 text-white">
                              <Check size={12} />
                            </span>
                          )}
                        </div>
                        <p className="text-[11px] leading-tight text-slate-500 mb-3">
                          {preset.description}
                        </p>
                      </div>

                      {/* Color Preview Swatches Strip */}
                      <div className="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                        <div className="flex items-center gap-1.5">
                          {/* Sidebar color circle */}
                          <span
                            className="h-6 w-6 rounded-full border border-black/10 shadow-inner"
                            style={{ backgroundColor: preset.colors.sidebar }}
                            title={`Sidebar: ${preset.colors.sidebar}`}
                          />
                          {/* Primary color circle */}
                          <span
                            className="h-6 w-6 rounded-full border border-black/10 shadow-inner"
                            style={{ backgroundColor: preset.colors.primary }}
                            title={`Primary: ${preset.colors.primary}`}
                          />
                          {/* Secondary color circle */}
                          <span
                            className="h-6 w-6 rounded-full border border-black/10 shadow-inner"
                            style={{ backgroundColor: preset.colors.secondary }}
                            title={`Accent: ${preset.colors.secondary}`}
                          />
                        </div>
                        <span className="text-[10px] font-mono font-medium text-slate-400">
                          {preset.colors.primary}
                        </span>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          ) : (
            /* Custom Color Pickers with HSL Fine Tuning & Contrast Analysis */
            <div className="rounded-2xl bg-white p-4 shadow-sm border border-slate-100 space-y-5">
              <div className="flex items-center justify-between">
                <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">
                  Custom Color Palette Fine-Tuning
                </h3>
                <div className="flex items-center gap-2">
                  <button
                    onClick={generateAccent}
                    className="flex items-center gap-1 text-[11px] font-bold text-amber-600 hover:text-amber-700 transition"
                    title="Auto-generate complementary accent color based on primary color"
                  >
                    <Wand2 size={12} /> Auto Accent
                  </button>
                  <button
                    onClick={swapPrimarySecondary}
                    className="flex items-center gap-1 text-[11px] font-bold text-brand-600 hover:text-brand-700 transition"
                    title="Swap Primary and Accent colors"
                  >
                    <ArrowLeftRight size={12} /> Swap
                  </button>
                </div>
              </div>

              {/* 1. Primary Brand Color */}
              <ColorPickerRow
                label="Primary Brand Color"
                hint="Buttons, primary badges, active state indicators & emphasis"
                color={colors.primary}
                swatches={PRIMARY_SWATCHES}
                contrastBg="#FFFFFF"
                contrastLabel="against light backgrounds"
                disabled={disabled}
                onChange={(val) => handleColorChange("primary", val)}
              />

              <hr className="border-slate-100" />

              {/* 2. Secondary / Accent Color */}
              <ColorPickerRow
                label="Secondary Accent Color"
                hint="Active menu highlight ring, subtle badges & secondary focus"
                color={colors.secondary}
                swatches={SECONDARY_SWATCHES}
                contrastBg="#FFFFFF"
                contrastLabel="against light backgrounds"
                disabled={disabled}
                onChange={(val) => handleColorChange("secondary", val)}
              />

              <hr className="border-slate-100" />

              {/* 3. Sidebar Background Color */}
              <ColorPickerRow
                label="Sidebar Background Color"
                hint="Base color shading the main left navigation drawer & borders"
                color={colors.sidebar}
                swatches={SIDEBAR_SWATCHES}
                contrastBg="#FFFFFF"
                contrastText="#FFFFFF"
                contrastLabel="white text legibility"
                disabled={disabled}
                onChange={(val) => handleColorChange("sidebar", val)}
              />
            </div>
          )}
        </div>

        {/* Right Column: Multi-Use Case Live Preview Container (6 cols) */}
        <div className="lg:col-span-6 space-y-3">
          <div className="flex items-center justify-between rounded-2xl bg-slate-900 px-4 py-2.5 text-white shadow-sm">
            <div className="flex items-center gap-2">
              <Eye size={15} className="text-emerald-400" />
              <span className="text-xs font-extrabold uppercase tracking-wide">
                Operational Use-Case Live Preview
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <span className="relative flex h-2 w-2">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
              </span>
              <span className="text-[10px] font-bold text-slate-300">Live Updating</span>
            </div>
          </div>

          {/* Interactive Mockup Frame */}
          <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-slate-50 shadow-md">
            {/* Use-Case View Mode Switcher Header */}
            <div className="flex items-center justify-between border-b border-slate-200 bg-white px-3 py-2 text-xs overflow-x-auto">
              <div className="flex gap-1">
                {[
                  { id: "dashboard", label: "Dashboard", icon: LayoutDashboard },
                  { id: "reservation", label: "Reservations", icon: BedDouble },
                  { id: "pos", label: "POS & KOT", icon: CreditCard },
                  { id: "invoice", label: "Invoice PDF", icon: FileText },
                  { id: "mobile", label: "Mobile Drawer", icon: Smartphone },
                ].map((v) => {
                  const Icon = v.icon;
                  const active = previewView === v.id;
                  return (
                    <button
                      key={v.id}
                      onClick={() => setPreviewView(v.id as UseCaseView)}
                      className={clsx(
                        "flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-bold transition whitespace-nowrap",
                        active
                          ? "bg-slate-900 text-white shadow-xs"
                          : "text-slate-500 hover:bg-slate-100"
                      )}
                    >
                      <Icon size={12} />
                      <span>{v.label}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Mock Application Frame Container */}
            <div className="min-h-[380px] bg-white text-slate-800">
              {/* USE CASE 1: DASHBOARD */}
              {previewView === "dashboard" && (
                <div className="flex min-h-[380px]">
                  {/* Mini Sidebar */}
                  <div
                    className="w-44 shrink-0 p-3 flex flex-col justify-between transition-colors duration-200"
                    style={{ backgroundColor: colors.sidebar }}
                  >
                    <div className="space-y-4">
                      {/* Hotel Identity Header */}
                      <div className="flex items-center gap-2.5 pb-2 border-b border-white/10">
                        <div
                          className="flex h-7 w-7 items-center justify-center rounded-lg text-white font-black text-xs shadow-sm"
                          style={{ backgroundColor: colors.primary }}
                        >
                          MV
                        </div>
                        <div className="overflow-hidden">
                          <div className="truncate text-xs font-bold text-white">
                            Mount View
                          </div>
                          <div className="truncate text-[9px] text-slate-400">
                            Luxury Hotel & Spa
                          </div>
                        </div>
                      </div>

                      {/* Sidebar Nav Items */}
                      <div className="space-y-1">
                        <div
                          className="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-bold text-white transition-all shadow-sm"
                          style={{ backgroundColor: colors.secondary }}
                        >
                          <div className="flex items-center gap-2">
                            <LayoutDashboard size={13} />
                            <span>Dashboard</span>
                          </div>
                          <span className="rounded-full bg-white/20 px-1.5 py-0.2 text-[9px]">
                            8
                          </span>
                        </div>

                        <div className="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:bg-white/5 transition">
                          <div className="flex items-center gap-2">
                            <BedDouble size={13} />
                            <span>Rooms</span>
                          </div>
                          <span className="text-[10px] text-slate-400">12</span>
                        </div>

                        <div className="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:bg-white/5 transition">
                          <div className="flex items-center gap-2">
                            <Users size={13} />
                            <span>Guests</span>
                          </div>
                        </div>

                        <div className="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:bg-white/5 transition">
                          <div className="flex items-center gap-2">
                            <CreditCard size={13} />
                            <span>POS Orders</span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div className="pt-3 border-t border-white/10 text-[10px] text-slate-400 flex items-center gap-1.5">
                      <ShieldCheck size={12} className="text-emerald-400" />
                      <span>Admin Mode</span>
                    </div>
                  </div>

                  {/* Main Content Area */}
                  <div className="flex-1 p-3.5 space-y-3 bg-white">
                    {/* Topbar Header */}
                    <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                      <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 text-slate-400 text-xs w-36">
                        <Search size={12} />
                        <span className="text-[10px]">Search guests...</span>
                      </div>

                      <div className="flex items-center gap-2">
                        <div className="relative p-1 text-slate-400 hover:text-slate-600">
                          <Bell size={13} />
                          <span
                            className="absolute top-0.5 right-0.5 h-1.5 w-1.5 rounded-full"
                            style={{ backgroundColor: colors.primary }}
                          />
                        </div>
                        <div
                          className="h-6 w-6 rounded-full text-white text-[10px] font-bold flex items-center justify-center shadow-xs"
                          style={{ backgroundColor: colors.primary }}
                        >
                          AD
                        </div>
                      </div>
                    </div>

                    {/* Operational Summary */}
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <div>
                          <h4 className="text-xs font-extrabold text-slate-800">
                            Overview Summary
                          </h4>
                          <p className="text-[10px] text-slate-400">
                            Today's live hotel operations
                          </p>
                        </div>

                        <button
                          className="flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-bold text-white shadow-sm transition active:scale-95"
                          style={{ backgroundColor: colors.primary }}
                        >
                          + New Check-in
                        </button>
                      </div>

                      <div className="grid grid-cols-2 gap-2">
                        <div className="rounded-xl border border-slate-100 bg-slate-50 p-2.5 space-y-1">
                          <div className="text-[10px] font-semibold text-slate-500">
                            Occupancy Rate
                          </div>
                          <div className="flex items-baseline justify-between">
                            <span className="text-sm font-black text-slate-800">84.2%</span>
                            <span className="text-[9px] font-bold text-emerald-600 flex items-center">
                              +4.1% <ArrowUpRight size={10} />
                            </span>
                          </div>
                          <div className="h-1.5 w-full rounded-full bg-slate-200 overflow-hidden">
                            <div
                              className="h-full rounded-full transition-all duration-300"
                              style={{ backgroundColor: colors.primary, width: "84%" }}
                            />
                          </div>
                        </div>

                        <div className="rounded-xl border border-slate-100 bg-slate-50 p-2.5 space-y-1">
                          <div className="text-[10px] font-semibold text-slate-500">
                            Today's Revenue
                          </div>
                          <div className="text-sm font-black text-slate-800">
                            LKR 425,000
                          </div>
                          <div
                            className="inline-flex rounded px-1.5 py-0.5 text-[9px] font-bold text-white"
                            style={{ backgroundColor: colors.secondary }}
                          >
                            24 Bookings
                          </div>
                        </div>
                      </div>

                      <div className="rounded-xl border border-slate-100 overflow-hidden text-[10px]">
                        <div className="bg-slate-100 px-2.5 py-1.5 font-bold text-slate-600 flex justify-between">
                          <span>Guest / Room</span>
                          <span>Status</span>
                        </div>
                        <div className="divide-y divide-slate-100">
                          <div className="px-2.5 py-1.5 flex items-center justify-between">
                            <div>
                              <span className="font-bold text-slate-800 block">
                                Samantha Silva
                              </span>
                              <span className="text-slate-400">Suite 302 • LKR 45,000</span>
                            </div>
                            <span
                              className="rounded-full px-2 py-0.5 text-[9px] font-bold text-white"
                              style={{ backgroundColor: colors.primary }}
                            >
                              Confirmed
                            </span>
                          </div>
                          <div className="px-2.5 py-1.5 flex items-center justify-between">
                            <div>
                              <span className="font-bold text-slate-800 block">
                                David Perera
                              </span>
                              <span className="text-slate-400">Deluxe 104 • LKR 22,000</span>
                            </div>
                            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold text-emerald-800">
                              Checked In
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* USE CASE 2: RESERVATIONS */}
              {previewView === "reservation" && (
                <div className="p-4 space-y-4">
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                    <div>
                      <h4 className="text-xs font-black text-slate-800 uppercase tracking-wide">
                        Front Desk Reservation Detail
                      </h4>
                      <p className="text-[10px] text-slate-400">
                        Guest check-in & room assignment preview
                      </p>
                    </div>
                    <span
                      className="rounded-full px-2.5 py-0.5 text-[10px] font-bold text-white"
                      style={{ backgroundColor: colors.secondary }}
                    >
                      #RES-9482
                    </span>
                  </div>

                  <div className="grid grid-cols-2 gap-3 text-[11px]">
                    <div className="rounded-xl border border-slate-100 p-3 space-y-2 bg-slate-50/60">
                      <div className="flex justify-between">
                        <span className="text-slate-500">Guest Name:</span>
                        <span className="font-bold text-slate-800">Ruwan Jayasinghe</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-500">Stay Duration:</span>
                        <span className="font-semibold text-slate-700">22 Jul – 25 Jul (3 nights)</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-500">Assigned Room:</span>
                        <span className="font-bold text-slate-900">Executive Deluxe #304</span>
                      </div>
                    </div>

                    <div className="rounded-xl border border-slate-100 p-3 space-y-2 bg-slate-50/60">
                      <div className="flex justify-between">
                        <span className="text-slate-500">Nightly Rate:</span>
                        <span className="font-semibold text-slate-700">LKR 32,000 / night</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-500">Deposit Paid:</span>
                        <span className="font-bold text-emerald-600">LKR 32,000 (100%)</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-slate-500">Folio Total:</span>
                        <span
                          className="font-black text-sm"
                          style={{ color: colors.primary }}
                        >
                          LKR 96,000
                        </span>
                      </div>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 pt-2">
                    <button
                      className="flex-1 rounded-xl py-2 text-xs font-bold text-white text-center shadow-sm transition active:scale-95"
                      style={{ backgroundColor: colors.primary }}
                    >
                      Complete Guest Check-In
                    </button>
                    <button
                      className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                    >
                      Print Folio Invoice
                    </button>
                  </div>
                </div>
              )}

              {/* USE CASE 3: RESTAURANT POS & KOT */}
              {previewView === "pos" && (
                <div className="p-4 space-y-3 text-[11px]">
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                    <div className="flex items-center gap-2">
                      <span
                        className="flex h-6 w-6 items-center justify-center rounded-lg text-white font-bold text-[10px]"
                        style={{ backgroundColor: colors.primary }}
                      >
                        T4
                      </span>
                      <div>
                        <h4 className="font-extrabold text-slate-800">
                          Restaurant Order #POS-4819
                        </h4>
                        <p className="text-[9px] text-slate-400">Table #04 • Main Dining Hall</p>
                      </div>
                    </div>

                    <div className="flex gap-1.5">
                      <span className="rounded-md bg-amber-100 px-2 py-0.5 text-[9px] font-bold text-amber-800">
                        KOT: PREPARING
                      </span>
                      <span
                        className="rounded-md px-2 py-0.5 text-[9px] font-bold text-white"
                        style={{ backgroundColor: colors.secondary }}
                      >
                        DINE-IN
                      </span>
                    </div>
                  </div>

                  <div className="rounded-xl border border-slate-100 divide-y divide-slate-100">
                    <div className="p-2.5 flex justify-between items-center bg-slate-50/50">
                      <div>
                        <span className="font-bold text-slate-800 block">
                          2x Grilled Jumbo Lobster
                        </span>
                        <span className="text-[10px] text-slate-400">Chef Special • No Butter</span>
                      </div>
                      <span className="font-extrabold text-slate-900">LKR 8,400</span>
                    </div>
                    <div className="p-2.5 flex justify-between items-center bg-slate-50/50">
                      <div>
                        <span className="font-bold text-slate-800 block">
                          3x King Coconut Water
                        </span>
                        <span className="text-[10px] text-slate-400">Chilled</span>
                      </div>
                      <span className="font-extrabold text-slate-900">LKR 1,500</span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between rounded-xl bg-slate-100 p-2.5">
                    <span className="font-bold text-slate-700">Grand Total (Tax Included):</span>
                    <span className="text-base font-black" style={{ color: colors.primary }}>
                      LKR 9,900
                    </span>
                  </div>

                  <button
                    className="w-full rounded-xl py-2.5 text-xs font-bold text-white text-center shadow-sm transition active:scale-95"
                    style={{ backgroundColor: colors.secondary }}
                  >
                    Settle Bill & Charge to Guest Room #304
                  </button>
                </div>
              )}

              {/* USE CASE 4: BRANDED INVOICE PDF */}
              {previewView === "invoice" && (
                <div className="p-4 space-y-3 text-[11px] bg-slate-50/40">
                  {/* Branded PDF Header Banner */}
                  <div
                    className="rounded-xl p-3 text-white flex items-center justify-between shadow-xs"
                    style={{ backgroundColor: colors.sidebar }}
                  >
                    <div>
                      <div className="flex items-center gap-1.5">
                        <div
                          className="h-4 w-4 rounded text-[9px] font-black flex items-center justify-center text-white"
                          style={{ backgroundColor: colors.primary }}
                        >
                          MV
                        </div>
                        <span className="font-extrabold text-sm tracking-wide">
                          MOUNT VIEW LUXURY HOTEL
                        </span>
                      </div>
                      <p className="text-[9px] text-slate-300">Official Tax Invoice & Receipt</p>
                    </div>

                    <div className="text-right">
                      <span
                        className="inline-block rounded px-2 py-0.5 text-[9px] font-bold text-white"
                        style={{ backgroundColor: colors.secondary }}
                      >
                        INVOICE #INV-2026-8092
                      </span>
                      <p className="text-[9px] text-slate-300 mt-0.5">Date: 22 Jul 2026</p>
                    </div>
                  </div>

                  {/* Bill To Info */}
                  <div className="rounded-xl border border-slate-200 bg-white p-3 space-y-1.5">
                    <div className="flex justify-between border-b border-slate-100 pb-1 font-semibold text-slate-600">
                      <span>Billed To:</span>
                      <span>Ruwan Jayasinghe (Room #304)</span>
                    </div>

                    {/* Line Items */}
                    <div className="space-y-1 text-[10px]">
                      <div className="flex justify-between">
                        <span>3x Executive Suite Nights (@ LKR 32,000)</span>
                        <span className="font-bold text-slate-800">LKR 96,000</span>
                      </div>
                      <div className="flex justify-between">
                        <span>Restaurant Charges (#POS-4819)</span>
                        <span className="font-bold text-slate-800">LKR 9,900</span>
                      </div>
                      <div className="flex justify-between">
                        <span>10% Service Charge</span>
                        <span className="font-bold text-slate-800">LKR 10,590</span>
                      </div>
                      <div className="flex justify-between">
                        <span>18% VAT Tax</span>
                        <span className="font-bold text-slate-800">LKR 19,062</span>
                      </div>
                    </div>

                    <div className="flex items-center justify-between border-t border-slate-200 pt-1.5 font-bold text-slate-900">
                      <span>Total Amount Payable:</span>
                      <span className="text-sm font-black" style={{ color: colors.primary }}>
                        LKR 135,552
                      </span>
                    </div>
                  </div>
                </div>
              )}

              {/* USE CASE 5: MOBILE COMPACT DRAWER */}
              {previewView === "mobile" && (
                <div className="p-4 flex justify-center bg-slate-100">
                  {/* Smartphone Frame Simulation */}
                  <div className="w-64 rounded-3xl border-4 border-slate-800 bg-white shadow-xl overflow-hidden text-[11px] space-y-2 pb-3">
                    {/* Status Bar */}
                    <div className="bg-slate-900 text-white text-[9px] px-3 py-1 flex justify-between items-center font-mono">
                      <span>9:41 AM</span>
                      <span>100% 🔋</span>
                    </div>

                    {/* Mobile App Header */}
                    <div
                      className="px-3 py-2 text-white flex items-center justify-between"
                      style={{ backgroundColor: colors.sidebar }}
                    >
                      <div className="flex items-center gap-2">
                        <div
                          className="h-6 w-6 rounded-lg text-white font-black text-[10px] flex items-center justify-center"
                          style={{ backgroundColor: colors.primary }}
                        >
                          MV
                        </div>
                        <span className="font-extrabold text-xs">Mount View</span>
                      </div>

                      <div className="flex items-center gap-1">
                        <Bell size={13} className="text-slate-300" />
                        <div
                          className="h-5 w-5 rounded-full text-white text-[9px] font-bold flex items-center justify-center"
                          style={{ backgroundColor: colors.secondary }}
                        >
                          AD
                        </div>
                      </div>
                    </div>

                    {/* Mobile Drawer Menu Mock */}
                    <div className="px-3 space-y-1.5">
                      <div
                        className="rounded-lg p-2 font-bold text-white flex justify-between items-center"
                        style={{ backgroundColor: colors.secondary }}
                      >
                        <span>📱 Quick POS Terminal</span>
                        <span className="text-[9px] bg-white/20 px-1.5 py-0.5 rounded">Active</span>
                      </div>

                      <div className="rounded-lg border border-slate-200 p-2 font-semibold text-slate-700 flex justify-between items-center bg-slate-50">
                        <span>🏨 Express Guest Check-in</span>
                        <span className="text-[10px] text-slate-400">→</span>
                      </div>

                      <div className="rounded-lg border border-slate-200 p-2 font-semibold text-slate-700 flex justify-between items-center bg-slate-50">
                        <span>🧹 Housekeeping Dispatch</span>
                        <span className="text-[10px] text-slate-400">→</span>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// Sub-component for individual color picker rows with swatches, hex input, HSL sliders & WCAG contrast score
function ColorPickerRow({
  label,
  hint,
  color,
  swatches,
  contrastBg = "#FFFFFF",
  contrastText = "#000000",
  contrastLabel = "readability",
  disabled,
  onChange,
}: {
  label: string;
  hint: string;
  color: string;
  swatches: string[];
  contrastBg?: string;
  contrastText?: string;
  contrastLabel?: string;
  disabled?: boolean;
  onChange: (hex: string) => void;
}) {
  const [text, setText] = useState(color);
  const [copied, setCopied] = useState(false);
  const [showHsl, setShowHsl] = useState(false);

  const isValid = HEX_REGEX.test(text);

  useEffect(() => {
    setText(color);
  }, [color]);

  const commitHex = (val: string) => {
    if (HEX_REGEX.test(val)) {
      onChange(val.toLowerCase());
    }
  };

  const copyHex = () => {
    navigator.clipboard?.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  // HSL calculations for sliders
  const currentHsl = isValid ? rgbToHsl(hexToRgb(text)) : { h: 0, s: 0, l: 0 };

  const handleHslChange = (key: "h" | "s" | "l", val: number) => {
    const nextHsl = { ...currentHsl, [key]: val };
    const nextRgb = hslToRgb(nextHsl);
    const nextHex = rgbToHex(nextRgb);
    setText(nextHex);
    commitHex(nextHex);
  };

  // Calculate WCAG contrast ratio score
  const contrastRatio = isValid
    ? getContrastRatio(text, contrastBg === "#FFFFFF" ? text : contrastText)
    : 1;
  const rating = getWcagRating(contrastRatio);

  return (
    <div className="space-y-2.5">
      <div className="flex items-start justify-between gap-2">
        <div>
          <label className="text-xs font-bold text-slate-800 block">
            {label}
          </label>
          <span className="text-[11px] text-slate-400 block leading-tight">{hint}</span>
        </div>

        {/* Input & Swatch Trigger */}
        <div className="flex items-center gap-1.5 shrink-0">
          <div className="relative">
            <input
              type="color"
              disabled={disabled}
              value={isValid ? text : color}
              onChange={(e) => {
                setText(e.target.value);
                commitHex(e.target.value);
              }}
              className="h-8 w-8 cursor-pointer rounded-lg border border-slate-300 p-0.5 shadow-xs disabled:cursor-not-allowed"
              title="Open system color wheel picker"
            />
          </div>

          <div className="relative">
            <input
              type="text"
              disabled={disabled}
              value={text}
              onChange={(e) => {
                const val = e.target.value;
                setText(val);
                commitHex(val);
              }}
              onBlur={() => {
                if (!isValid) setText(color);
              }}
              className={clsx(
                "input !w-24 font-mono text-xs uppercase text-center font-bold tracking-wider",
                !isValid && "!border-red-400 !bg-red-50"
              )}
              placeholder="#000000"
              maxLength={7}
            />
          </div>

          {/* Copy Hex Button */}
          <button
            type="button"
            onClick={copyHex}
            className="p-1.5 rounded-lg border border-slate-200 bg-white text-slate-500 hover:text-slate-800 hover:bg-slate-50 transition"
            title="Copy Hex Code"
          >
            {copied ? <CheckCheck size={13} className="text-emerald-600" /> : <Copy size={13} />}
          </button>

          {/* HSL Expander Toggle */}
          <button
            type="button"
            onClick={() => setShowHsl(!showHsl)}
            className={clsx(
              "p-1.5 rounded-lg border text-xs font-bold transition flex items-center gap-0.5",
              showHsl ? "bg-slate-800 text-white border-slate-800" : "bg-white text-slate-600 border-slate-200 hover:bg-slate-50"
            )}
            title="Fine-tune HSL (Hue, Saturation, Lightness)"
          >
            <Sliders size={12} />
            {showHsl ? <ChevronUp size={11} /> : <ChevronDown size={11} />}
          </button>
        </div>
      </div>

      {/* WCAG Contrast Score Badge */}
      {isValid && (
        <div className="flex items-center justify-between text-[10px] rounded-lg bg-slate-50 px-2.5 py-1 border border-slate-100">
          <span className="text-slate-500 font-medium">
            Contrast ratio ({contrastLabel}): <strong className="text-slate-800">{contrastRatio.toFixed(2)}:1</strong>
          </span>
          <span
            className={clsx(
              "rounded px-1.5 py-0.2 font-extrabold uppercase",
              rating.level === "AAA"
                ? "bg-emerald-100 text-emerald-800"
                : rating.level === "AA"
                ? "bg-blue-100 text-blue-800"
                : "bg-amber-100 text-amber-800"
            )}
          >
            WCAG {rating.level}
          </span>
        </div>
      )}

      {/* Fine-Tuning HSL Sliders */}
      {showHsl && (
        <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3 space-y-2 text-xs">
          <div className="flex items-center justify-between font-bold text-slate-700">
            <span>HSL Fine-Tuning</span>
            <span className="font-mono text-[10px] text-slate-500">
              H: {currentHsl.h}° S: {currentHsl.s}% L: {currentHsl.l}%
            </span>
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center gap-2">
              <span className="w-14 text-[10px] font-semibold text-slate-500">Hue</span>
              <input
                type="range"
                min="0"
                max="360"
                value={currentHsl.h}
                onChange={(e) => handleHslChange("h", parseInt(e.target.value))}
                className="flex-1 accent-brand-600 h-1.5 bg-slate-200 rounded-lg cursor-pointer"
              />
              <span className="w-8 text-right font-mono text-[10px] text-slate-600">{currentHsl.h}°</span>
            </div>

            <div className="flex items-center gap-2">
              <span className="w-14 text-[10px] font-semibold text-slate-500">Saturation</span>
              <input
                type="range"
                min="0"
                max="100"
                value={currentHsl.s}
                onChange={(e) => handleHslChange("s", parseInt(e.target.value))}
                className="flex-1 accent-brand-600 h-1.5 bg-slate-200 rounded-lg cursor-pointer"
              />
              <span className="w-8 text-right font-mono text-[10px] text-slate-600">{currentHsl.s}%</span>
            </div>

            <div className="flex items-center gap-2">
              <span className="w-14 text-[10px] font-semibold text-slate-500">Lightness</span>
              <input
                type="range"
                min="0"
                max="100"
                value={currentHsl.l}
                onChange={(e) => handleHslChange("l", parseInt(e.target.value))}
                className="flex-1 accent-brand-600 h-1.5 bg-slate-200 rounded-lg cursor-pointer"
              />
              <span className="w-8 text-right font-mono text-[10px] text-slate-600">{currentHsl.l}%</span>
            </div>
          </div>
        </div>
      )}

      {/* Preset Swatches Row */}
      <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
        <span className="text-[10px] font-semibold text-slate-400 mr-1">
          Swatches:
        </span>
        {swatches.map((swatchHex) => {
          const isCurrent = color.toLowerCase() === swatchHex.toLowerCase();
          return (
            <button
              key={swatchHex}
              type="button"
              disabled={disabled}
              onClick={() => {
                setText(swatchHex);
                onChange(swatchHex);
              }}
              className={clsx(
                "h-5 w-5 rounded-full border border-black/15 transition-transform hover:scale-110 active:scale-95 shadow-xs flex items-center justify-center",
                isCurrent && "ring-2 ring-offset-1 ring-slate-800 scale-110"
              )}
              style={{ backgroundColor: swatchHex }}
              title={swatchHex}
            >
              {isCurrent && (
                <span className="h-1.5 w-1.5 rounded-full bg-white shadow-xs" />
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
