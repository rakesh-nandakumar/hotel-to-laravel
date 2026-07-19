import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Search, Settings as SettingsIcon, Image as ImageIcon } from "lucide-react";
import { put } from "../lib/api";
import { useFetch, fmtDateTime } from "../lib/util";
import { Empty, ErrorText } from "../components/ui";
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

  if (!data) return <Empty text="Loading settings…" />;

  const save = (s: Setting, value: unknown) => {
    if (!canUpdate) return Promise.resolve(); // read-only viewer — controls are disabled too
    return put(`/hotel-settings/${s.key}`, { value })
      .then(() => {
        setError("");
        setSaved(s.key);
        setTimeout(() => setSaved(""), 1500);
        reload();
        // Identity keys (name/tagline/logo) drive the sidebar & login — refresh live.
        if (s.key.startsWith("hotel.")) refreshBranding();
      })
      .catch((e) => setError(`${s.label}: ${e.message}`));
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
          <div className="grid gap-3 xl:grid-cols-2">
            {shown.map((s) => (
              <div key={s.key} className="card p-4">
                <div className="mb-1.5 flex items-start justify-between gap-2">
                  <label className="text-sm font-bold text-slate-800">
                    {s.label}
                    {saved === s.key && <span className="ml-2 text-xs font-semibold text-emerald-600">saved ✓</span>}
                  </label>
                  {q && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-slate-400">{CATEGORY_META[s.category]?.label ?? s.category}</span>}
                </div>
                <SettingControl s={s} onSave={(v) => save(s, v)} />
                {s.hint && <p className="mt-1.5 text-[11px] leading-snug text-slate-400">{s.hint}</p>}
                {s.updated_at && <p className="mt-1 text-[10px] text-slate-300">Last changed {fmtDateTime(s.updated_at)}</p>}
              </div>
            ))}
          </div>
          </fieldset>
        </div>
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
          <input className="input !w-16 !py-1 text-right" inputMode="numeric" value={r.daysBefore}
            onChange={(e) => upd(rules.map((x, j) => (j === i ? { ...x, daysBefore: parseInt(e.target.value) || 0 } : x)))} />
          <span className="text-xs text-slate-500">days before check-in →</span>
          <input className="input !w-16 !py-1 text-right" inputMode="numeric" value={r.refundPct}
            onChange={(e) => upd(rules.map((x, j) => (j === i ? { ...x, refundPct: Math.min(100, parseInt(e.target.value) || 0) } : x)))} />
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
          <input className="input !w-20 !py-1 text-right" inputMode="numeric" value={it.points} onChange={(e) => upd(items.map((x, j) => (j === i ? { ...x, points: parseInt(e.target.value) || 0 } : x)))} />
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
 * Logo picker — drag & drop, paste from clipboard, or browse. The image is
 * downscaled and stored inline as a data URI in the setting value (no separate
 * file host needed), so it shows everywhere branding is read: sidebar, login
 * screen, guest pages and printed documents.
 */
function LogoUpload({ value, onSave }: { value: string; onSave: (v: unknown) => void }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [dragOver, setDragOver] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFile = useCallback(
    async (file: File | null | undefined) => {
      if (!file) return;
      if (!file.type.startsWith("image/")) {
        setErr("Please choose an image file.");
        return;
      }
      setErr("");
      setBusy(true);
      try {
        onSave(await fileToLogoDataUrl(file));
      } catch {
        setErr("Could not read that image.");
      } finally {
        setBusy(false);
      }
    },
    [onSave],
  );

  // Paste an image anywhere on the page while this field is on screen. Gating
  // this on the drop zone being focused doesn't work: clicking it to focus it
  // also opens the native file picker (see onClick below), which blurs the
  // zone and detaches the listener before Ctrl/⌘+V can be pressed.
  useEffect(() => {
    const onPaste = (e: ClipboardEvent) => {
      const item = Array.from(e.clipboardData?.items ?? []).find((i) => i.type.startsWith("image/"));
      if (item) {
        e.preventDefault();
        void handleFile(item.getAsFile());
      }
    };
    document.addEventListener("paste", onPaste);
    return () => document.removeEventListener("paste", onPaste);
  }, [handleFile]);

  return (
    <div>
      <div
        role="button"
        tabIndex={0}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          void handleFile(e.dataTransfer.files?.[0]);
        }}
        className={clsx(
          "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center outline-none transition",
          dragOver ? "border-brand-500 bg-brand-50" : "border-slate-300 hover:border-brand-400 focus:border-brand-500",
        )}
      >
        {value ? (
          <img src={value} alt="Logo preview" className="max-h-20 max-w-[160px] object-contain" />
        ) : (
          <ImageIcon className="h-8 w-8 text-slate-300" />
        )}
        <div className="text-xs text-slate-500">
          {busy ? (
            "Processing…"
          ) : (
            <>
              Drag &amp; drop, paste, or <span className="font-semibold text-brand-600">browse</span>
            </>
          )}
        </div>
      </div>
      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={(e) => {
          void handleFile(e.target.files?.[0]);
          e.target.value = ""; // allow re-selecting the same file
        }}
      />
      {value && (
        <button className="mt-1.5 text-xs font-semibold text-red-500 hover:text-red-600" onClick={() => onSave("")}>
          Remove logo
        </button>
      )}
      {err && <p className="mt-1 text-xs text-red-500">{err}</p>}
    </div>
  );
}

/**
 * Turn a chosen file into a small data URI. SVGs are kept as-is (crisp & tiny);
 * raster images are downscaled to a 320px box and re-encoded as PNG so the
 * stored value stays small.
 */
async function fileToLogoDataUrl(file: File): Promise<string> {
  const dataUrl = await readAsDataUrl(file);
  if (file.type === "image/svg+xml") return dataUrl;

  const img = await loadImage(dataUrl);
  const max = 320;
  const scale = Math.min(1, max / Math.max(img.width, img.height));
  const w = Math.max(1, Math.round(img.width * scale));
  const h = Math.max(1, Math.round(img.height * scale));
  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  if (!ctx) return dataUrl;
  ctx.drawImage(img, 0, 0, w, h);
  return canvas.toDataURL("image/png");
}

function readAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error("read failed"));
    reader.readAsDataURL(file);
  });
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error("load failed"));
    img.src = src;
  });
}
