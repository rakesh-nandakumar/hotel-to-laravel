import { useMemo, useState, useEffect } from "react";
import { Search, Settings as SettingsIcon, Eye, Copy, Check } from "lucide-react";
import { put } from "../lib/api";
import { useFetch, fmtDateTime } from "../lib/util";
import { Empty, ErrorText } from "../components/ui";
import { ImageDropUpload } from "../components/ImageUpload";
import { ThemeCustomizer, ThemeColors } from "../components/ThemeCustomizer";
import { getContrastRatio, getWcagRating } from "../lib/theme";
import { useAuth } from "../lib/auth";
import { useBranding } from "../lib/branding";
import clsx from "clsx";

type Setting = { key: string; value: string; type: string; category: string; label: string; hint?: string; updated_at?: string };
type Parsed = Setting & { parsed: unknown };

const CATEGORY_META: Record<string, { label: string; blurb: string }> = {
  hotel: { label: "Hotel identity", blurb: "Name, tagline and logo shown across the app (sidebar, login, guest pages) plus the address, contacts and tax registration printed on every invoice and receipt." },
  frontdesk: { label: "Front desk", blurb: "Check-in and check-out times used for early/late surcharges." },
  billing: { label: "Billing & taxes", blurb: "VAT and service charge (always two separate bill lines), surcharges and deposit percentages." },
  currency: { label: "Currency", blurb: "Settlement is always LKR; the USD rate is display-only for foreign guests." },
  policies: { label: "Policies", blurb: "Cancellation/refund rules (enforced automatically), children, parking and WiFi." },
  pricing: { label: "Dynamic pricing", blurb: "Weekend days and public holidays that trigger the weekend/peak rate." },
  loyalty: { label: "Loyalty", blurb: "Earn rate, point value and the redemption catalog." },
  inventory: { label: "Inventory", blurb: "Food expiry alert window for kitchen stock batches." },
  payroll: { label: "Payroll", blurb: "EPF/ETF statutory percentages and the standard monthly hours before overtime." },
  notifications: { label: "Notifications", blurb: "Reminder timing and which channels guests receive." },
};

function parseValue(raw: string): unknown {
  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

export default function Settings() {
  const { can } = useAuth();
  const { refresh: refreshBranding } = useBranding();
  const canUpdate = can("hotel_settings.update");
  const { data, reload } = useFetch<{ settings: Setting[] }>("/hotel-settings");
  const [error, setError] = useState("");
  const [saved, setSaved] = useState("");
  const [q, setQ] = useState("");
  const [cat, setCat] = useState<string>("");

  const business = useMemo(
    () => (data?.settings ?? []).filter((s) => s.category !== "integrations").map((s) => ({ ...s, parsed: parseValue(s.value) }) as Parsed),
    [data],
  );
  const categories = useMemo(() => [...new Set(business.map((s) => s.category))], [business]);
  const activeCat = cat || categories[0] || "";

  const shown = useMemo(() => {
    if (q.trim()) {
      const needle = q.toLowerCase();
      return business.filter((s) => s.label.toLowerCase().includes(needle) || s.key.toLowerCase().includes(needle) || (s.hint ?? "").toLowerCase().includes(needle));
    }
    return business.filter((s) => s.category === activeCat);
  }, [business, q, activeCat]);

  const themePrimary = useMemo(
    () => String(business.find((s) => s.key === "theme.primary")?.parsed ?? "#0462d3"),
    [business]
  );
  const themeSecondary = useMemo(
    () => String(business.find((s) => s.key === "theme.secondary")?.parsed ?? "#3783f0"),
    [business]
  );
  const themeSidebar = useMemo(
    () => String(business.find((s) => s.key === "theme.sidebar")?.parsed ?? "#0c182a"),
    [business]
  );

  if (!data) return <Empty text="Loading settings…" />;

  const save = (s: Setting, value: unknown) => {
    if (!canUpdate) return Promise.resolve(); // read-only viewer — controls are disabled too
    return put(`/hotel-settings/${s.key}`, { value })
      .then(() => {
        setError("");
        setSaved(s.key);
        setTimeout(() => setSaved(""), 1500);
        reload();
        // Identity keys (name/tagline/logo) and theme colors drive the sidebar & login — refresh live.
        if (s.key.startsWith("hotel.") || s.key.startsWith("theme.")) refreshBranding();
      })
      .catch((e) => setError(`${s.label}: ${e.message}`));
  };

  const saveThemeColors = async (colors: ThemeColors) => {
    if (!canUpdate) return;
    try {
      await Promise.all([
        put(`/hotel-settings/theme.primary`, { value: colors.primary }),
        put(`/hotel-settings/theme.secondary`, { value: colors.secondary }),
        put(`/hotel-settings/theme.sidebar`, { value: colors.sidebar }),
      ]);
      setError("");
      setSaved("theme.primary");
      setTimeout(() => setSaved(""), 1500);
      reload();
      refreshBranding();
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      setError(`Theme save failed: ${msg}`);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="flex items-center gap-2 text-xl font-extrabold"><SettingsIcon /> Settings</h1>
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input className="input !w-64 !pl-8" placeholder="Search all settings…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
      </div>
      <p className="text-xs text-slate-500">Nothing business-specific is hard-coded — every value below takes effect immediately. Items marked ⚠ await owner confirmation. Integration credentials live on the Full Administrator's Integrations page.</p>
      {!canUpdate && <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Read-only — you don't have permission to change settings.</div>}
      <ErrorText error={error} />

      <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
        {/* Category navigation */}
        <div className="flex gap-1 overflow-x-auto lg:flex-col">
          {categories.map((c) => (
            <button
              key={c}
              onClick={() => {
                setCat(c);
                setQ("");
              }}
              className={clsx(
                "whitespace-nowrap rounded-lg px-3 py-2 text-left text-sm font-semibold transition",
                !q && activeCat === c ? "bg-brand-600 text-white shadow-sm" : "bg-white text-slate-600 shadow-sm hover:bg-slate-50"
              )}
            >
              {CATEGORY_META[c]?.label ?? c}
              <span className={clsx("ml-1.5 text-[10px]", !q && activeCat === c ? "text-brand-100" : "text-slate-400")}>
                {business.filter((s) => s.category === c).length}
              </span>
            </button>
          ))}
        </div>

        {/* Settings panel */}
        <div className="space-y-3">
          {!q && CATEGORY_META[activeCat] && (
            <p className="rounded-xl bg-white px-4 py-3 text-xs text-slate-500 shadow-sm">{CATEGORY_META[activeCat].blurb}</p>
          )}
          {shown.length === 0 && <Empty text="No settings match" />}
          <fieldset disabled={!canUpdate} className="contents">
          {!q && activeCat === "hotel" ? (
            <div className="space-y-6">
              <SettingGroup title="Identity" fields={shown.filter((s) => !s.key.startsWith("theme."))} saved={saved} onSave={save} />
              <div>
                <h2 className="mb-2 text-xs font-black uppercase tracking-wide text-slate-400">Theming & Live Preview</h2>
                <ThemeCustomizer
                  initialPrimary={themePrimary}
                  initialSecondary={themeSecondary}
                  initialSidebar={themeSidebar}
                  disabled={!canUpdate}
                  onSaveTheme={saveThemeColors}
                />
              </div>
            </div>
          ) : (
            <SettingGroup fields={shown} saved={saved} onSave={save} showCategoryBadge={!!q} />
          )}
          </fieldset>
        </div>
      </div>
    </div>
  );
}

/** One labeled block of setting cards — used to split "Hotel identity" into Identity vs. Theming sub-sections. */
function SettingGroup({
  title, blurb, fields, saved, onSave, showCategoryBadge,
}: {
  title?: string; blurb?: string; fields: Parsed[]; saved: string; onSave: (s: Setting, v: unknown) => void; showCategoryBadge?: boolean;
}) {
  if (fields.length === 0) return null;
  return (
    <div>
      {title && <h2 className="mb-2 text-xs font-black uppercase tracking-wide text-slate-400">{title}</h2>}
      {blurb && <p className="mb-2 text-[11px] leading-snug text-slate-400">{blurb}</p>}
      <div className="grid gap-3 xl:grid-cols-2">
        {fields.map((s) => (
          <div key={s.key} className="card p-4">
            <div className="mb-1.5 flex items-start justify-between gap-2">
              <label className="text-sm font-bold text-slate-800">
                {s.label}
                {saved === s.key && <span className="ml-2 text-xs font-semibold text-emerald-600">saved ✓</span>}
              </label>
              {showCategoryBadge && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-400">{CATEGORY_META[s.category]?.label ?? s.category}</span>}
            </div>
            <SettingControl s={s} onSave={(v) => onSave(s, v)} />
            {s.hint && <p className="mt-1.5 text-[11px] leading-snug text-slate-400">{s.hint}</p>}
            {s.updated_at && <p className="mt-1 text-[10px] text-slate-300">Last changed {fmtDateTime(s.updated_at)}</p>}
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Typed / structured controls ───────────────────────────────────────────────
function SettingControl({ s, onSave }: { s: Parsed; onSave: (v: unknown) => void }) {
  // Special structured editors
  if (s.key === "policies.cancellation_rules") return <CancellationRules value={s.parsed as { daysBefore: number; refundPct: number }[]} onSave={onSave} />;
  if (s.key === "pricing.public_holidays") return <HolidayList value={s.parsed as string[]} onSave={onSave} />;
  if (s.key === "pricing.weekend_days") return <WeekdayPicker value={s.parsed as number[]} onSave={onSave} />;
  if (s.key === "loyalty.redemption_catalog") return <RedemptionCatalog value={s.parsed as { name: string; points: number }[]} onSave={onSave} />;
  if (s.key === "notifications.channels") return <ChannelPicker value={s.parsed as string[]} onSave={onSave} />;

  if (s.type === "image") return <LogoUpload value={String(s.parsed ?? "")} onSave={onSave} />;
  if (s.type === "color") return <ColorPicker value={String(s.parsed ?? "#000000")} onSave={onSave} />;

  if (s.type === "boolean") {
    const on = s.parsed === true;
    return (
      <button onClick={() => onSave(!on)} className={`relative h-6 w-11 rounded-full transition ${on ? "bg-brand-600" : "bg-slate-300"}`}>
        <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${on ? "left-[22px]" : "left-0.5"}`} />
      </button>
    );
  }
  if (s.type === "time") {
    return <input type="time" className="input !w-36" defaultValue={String(s.parsed)} onBlur={(e) => e.target.value !== s.parsed && onSave(e.target.value)} />;
  }
  if (s.type === "money") {
    const rupees = ((s.parsed as number) / 100).toFixed(2);
    return (
      <div className="flex items-center gap-2">
        <span className="text-xs font-bold text-slate-400">LKR</span>
        <input className="input !w-40 text-right" inputMode="decimal" defaultValue={rupees} onBlur={(e) => e.target.value !== rupees && onSave(Math.round((parseFloat(e.target.value) || 0) * 100))} />
      </div>
    );
  }
  if (s.type === "percent" || s.type === "number") {
    return (
      <div className="flex items-center gap-2">
        <input className="input !w-32 text-right" inputMode="decimal" defaultValue={String(s.parsed)} onBlur={(e) => parseFloat(e.target.value) !== s.parsed && onSave(parseFloat(e.target.value) || 0)} />
        {s.type === "percent" && <span className="text-xs font-bold text-slate-400">%</span>}
      </div>
    );
  }
  if (s.type === "json") {
    const str = JSON.stringify(s.parsed, null, 1);
    return (
      <textarea
        className="input font-mono text-xs"
        rows={3}
        defaultValue={str}
        onBlur={(e) => {
          if (e.target.value === str) return;
          try {
            onSave(JSON.parse(e.target.value));
          } catch {
            e.target.classList.add("!border-red-400");
          }
        }}
      />
    );
  }
  return <input className="input" defaultValue={String(s.parsed ?? "")} onBlur={(e) => e.target.value !== s.parsed && onSave(e.target.value)} />;
}

/** Cancellation refund rules — enforced automatically at cancellation. */
function CancellationRules({ value, onSave }: { value: { daysBefore: number; refundPct: number }[]; onSave: (v: unknown) => void }) {
  const [rules, setRules] = useState(value ?? []);
  const upd = (next: typeof rules) => {
    setRules(next);
    onSave(next);
  };
  return (
    <div className="space-y-1.5">
      {rules.map((r, i) => (
        <div key={i} className="flex items-center gap-2 text-sm">
          <span className="text-xs text-slate-400">≥</span>
          <input className="input !w-16 !py-1 text-right" inputMode="numeric" defaultValue={r.daysBefore}
            onBlur={(e) => {
              const n = parseInt(e.target.value) || 0;
              if (n !== r.daysBefore) upd(rules.map((x, j) => (j === i ? { ...x, daysBefore: n } : x)));
            }} />
          <span className="text-xs text-slate-500">days before check-in →</span>
          <input className="input !w-16 !py-1 text-right" inputMode="numeric" defaultValue={r.refundPct}
            onBlur={(e) => {
              const n = Math.min(100, parseInt(e.target.value) || 0);
              if (n !== r.refundPct) upd(rules.map((x, j) => (j === i ? { ...x, refundPct: n } : x)));
            }} />
          <span className="text-xs text-slate-500">% refund</span>
          <button className="ml-auto text-xs font-bold text-red-400 hover:text-red-600" onClick={() => upd(rules.filter((_, j) => j !== i))}>✕</button>
        </div>
      ))}
      <button className="btn-secondary !py-1 text-xs" onClick={() => upd([...rules, { daysBefore: 0, refundPct: 0 }])}>+ Add rule</button>
    </div>
  );
}

function HolidayList({ value, onSave }: { value: string[]; onSave: (v: unknown) => void }) {
  const [dates, setDates] = useState(value ?? []);
  const [add, setAdd] = useState("");
  const upd = (next: string[]) => {
    const sorted = [...new Set(next)].sort();
    setDates(sorted);
    onSave(sorted);
  };
  return (
    <div>
      <div className="mb-1.5 flex flex-wrap gap-1.5">
        {dates.map((d) => (
          <span key={d} className="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-800">
            {d}
            <button className="text-brand-400 hover:text-red-600" onClick={() => upd(dates.filter((x) => x !== d))}>✕</button>
          </span>
        ))}
        {dates.length === 0 && <span className="text-xs text-slate-400">No holidays configured</span>}
      </div>
      <div className="flex gap-2">
        <input type="date" className="input !w-40 !py-1" value={add} onChange={(e) => setAdd(e.target.value)} />
        <button className="btn-secondary !py-1 text-xs" disabled={!add} onClick={() => { upd([...dates, add]); setAdd(""); }}>Add holiday</button>
      </div>
    </div>
  );
}

function WeekdayPicker({ value, onSave }: { value: number[]; onSave: (v: unknown) => void }) {
  const [days, setDays] = useState(value ?? []);
  const names = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  const toggle = (d: number) => {
    const next = days.includes(d) ? days.filter((x) => x !== d) : [...days, d].sort();
    setDays(next);
    onSave(next);
  };
  return (
    <div className="flex gap-1">
      {names.map((n, d) => (
        <button key={d} onClick={() => toggle(d)} className={`rounded-lg px-2.5 py-1.5 text-xs font-bold transition ${days.includes(d) ? "bg-brand-600 text-white" : "bg-slate-100 text-slate-500 hover:bg-slate-200"}`}>
          {n}
        </button>
      ))}
    </div>
  );
}

function RedemptionCatalog({ value, onSave }: { value: { name: string; points: number }[]; onSave: (v: unknown) => void }) {
  const [items, setItems] = useState(value ?? []);
  const upd = (next: typeof items) => {
    setItems(next);
    onSave(next);
  };
  return (
    <div className="space-y-1.5">
      {items.map((it, i) => (
        <div key={i} className="flex items-center gap-2">
          <input className="input !py-1" value={it.name} onChange={(e) => upd(items.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
          <input className="input !w-20 !py-1 text-right" inputMode="numeric" defaultValue={it.points}
            onBlur={(e) => {
              const n = parseInt(e.target.value) || 0;
              if (n !== it.points) upd(items.map((x, j) => (j === i ? { ...x, points: n } : x)));
            }} />
          <span className="text-xs text-slate-400">pts</span>
          <button className="text-xs font-bold text-red-400 hover:text-red-600" onClick={() => upd(items.filter((_, j) => j !== i))}>✕</button>
        </div>
      ))}
      <button className="btn-secondary !py-1 text-xs" onClick={() => upd([...items, { name: "", points: 0 }])}>+ Add reward</button>
    </div>
  );
}

function ChannelPicker({ value, onSave }: { value: string[]; onSave: (v: unknown) => void }) {
  const [chs, setChs] = useState(value ?? []);
  const all = ["email", "whatsapp", "sms"];
  const toggle = (c: string) => {
    const next = chs.includes(c) ? chs.filter((x) => x !== c) : [...chs, c];
    setChs(next);
    onSave(next);
  };
  return (
    <div className="flex gap-1.5">
      {all.map((c) => (
        <button key={c} onClick={() => toggle(c)} className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${chs.includes(c) ? "bg-brand-600 text-white" : "bg-slate-100 text-slate-500 hover:bg-slate-200"}`}>
          {c.toUpperCase()}
        </button>
      ))}
    </div>
  );
}

/**
 * Logo picker — the image is downscaled and stored inline as a data URI in
 * the setting value (no separate file host needed), so it shows everywhere
 * branding is read: sidebar, login screen, guest pages and printed documents.
 */
function LogoUpload({ value, onSave }: { value: string; onSave: (v: unknown) => void }) {
  return <ImageDropUpload value={value} onChange={onSave} maxBox={320} removeLabel="Remove logo" />;
}

const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const COMMON_SWATCHES = [
  "#0462d3", "#3783f0", "#0c182a", "#059669", "#10b981",
  "#7c3aed", "#d97706", "#e11d48", "#0d9488", "#2563eb"
];

/** Advanced color picker with swatches, hex input, contrast analysis, and live use-case preview toggle. */
function ColorPicker({ value, onSave }: { value: string; onSave: (v: unknown) => void }) {
  const [text, setText] = useState(value);
  const [showPreview, setShowPreview] = useState(false);
  const valid = HEX_RE.test(text);

  useEffect(() => {
    setText(value);
  }, [value]);

  const commit = (next: string) => {
    if (HEX_RE.test(next) && next.toLowerCase() !== value.toLowerCase()) onSave(next.toLowerCase());
  };

  const contrast = valid ? getContrastRatio(text, "#FFFFFF") : 1;
  const wcag = getWcagRating(contrast);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <input
          type="color"
          className="h-9 w-9 shrink-0 cursor-pointer rounded-lg border border-slate-300 p-0.5 shadow-xs"
          value={valid ? text : value}
          onChange={(e) => {
            setText(e.target.value);
            commit(e.target.value);
          }}
          title="Open color wheel"
        />
        <input
          className={clsx("input !w-28 font-mono uppercase font-bold text-xs tracking-wider", !valid && "!border-red-400 !bg-red-50")}
          value={text}
          onChange={(e) => {
            const val = e.target.value;
            setText(val);
            if (HEX_RE.test(val)) commit(val);
          }}
          onBlur={() => (valid ? commit(text) : setText(value))}
          maxLength={7}
        />

        <button
          type="button"
          onClick={() => setShowPreview(!showPreview)}
          className={clsx(
            "flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-bold transition",
            showPreview ? "bg-slate-800 text-white border-slate-800" : "bg-white text-slate-600 border-slate-200 hover:bg-slate-50"
          )}
        >
          <Eye size={13} />
          {showPreview ? "Hide Preview" : "Live Preview"}
        </button>

        {valid && (
          <span className={clsx(
            "rounded px-2 py-0.5 text-[10px] font-extrabold uppercase",
            wcag.level === "AAA" ? "bg-emerald-100 text-emerald-800" : wcag.level === "AA" ? "bg-blue-100 text-blue-800" : "bg-amber-100 text-amber-800"
          )}>
            WCAG {wcag.level} ({contrast.toFixed(1)}:1)
          </span>
        )}
      </div>

      {/* Quick Swatches Row */}
      <div className="flex flex-wrap items-center gap-1.5">
        <span className="text-[10px] font-semibold text-slate-400 mr-1">Swatches:</span>
        {COMMON_SWATCHES.map((swatch) => {
          const active = (valid ? text : value).toLowerCase() === swatch.toLowerCase();
          return (
            <button
              key={swatch}
              type="button"
              onClick={() => {
                setText(swatch);
                commit(swatch);
              }}
              className={clsx(
                "h-5 w-5 rounded-full border border-black/15 transition-transform hover:scale-110 shadow-xs flex items-center justify-center",
                active && "ring-2 ring-offset-1 ring-slate-800 scale-110"
              )}
              style={{ backgroundColor: swatch }}
              title={swatch}
            >
              {active && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
            </button>
          );
        })}
      </div>

      {/* Live Sample Use-Case Widget */}
      {showPreview && (
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-3 space-y-2 text-xs">
          <div className="flex items-center justify-between font-bold text-slate-700">
            <span>Use-Case Live Sample Preview</span>
            <span className="text-[10px] text-slate-400 font-mono">{text}</span>
          </div>

          <div className="grid grid-cols-2 gap-2 text-[11px]">
            {/* CTA Button Sample */}
            <div className="rounded-lg bg-white p-2 border border-slate-200 space-y-1">
              <span className="text-[10px] font-semibold text-slate-400 block">Primary Action Button</span>
              <button
                className="w-full rounded-md py-1 text-xs font-bold text-white text-center shadow-xs transition"
                style={{ backgroundColor: valid ? text : value }}
              >
                + Check-In Guest
              </button>
            </div>

            {/* Badge & Highlight Sample */}
            <div className="rounded-lg bg-white p-2 border border-slate-200 space-y-1">
              <span className="text-[10px] font-semibold text-slate-400 block">Active Status Pill</span>
              <div className="flex items-center justify-between">
                <span className="font-bold text-slate-700">Room 304</span>
                <span
                  className="rounded-full px-2 py-0.5 text-[9px] font-bold text-white"
                  style={{ backgroundColor: valid ? text : value }}
                >
                  Confirmed
                </span>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
