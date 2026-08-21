import { useCallback, useEffect, useState } from "react";
import { api } from "./api";
import { Paged } from "../components/ui";

/** Money: everything from the API is LKR cents. */
export function lkr(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return "—";
  return "LKR " + (cents / 100).toLocaleString("en-LK", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** USD display conversion — display only, settlement is always LKR. */
export function usd(cents: number, rate: number): string {
  if (!rate) return "";
  return "≈ $" + (cents / 100 / rate).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Parse a rupee text input ("1,500.50") into integer cents. */
export function toCents(rupees: string | number): number {
  const n = typeof rupees === "number" ? rupees : parseFloat(String(rupees).replace(/[^\d.]/g, ""));
  if (Number.isNaN(n)) return 0;
  return Math.round(n * 100);
}

export function centsToRupees(cents: number): string {
  return (cents / 100).toFixed(2);
}

export function fmtDate(d: string | Date | null | undefined): string {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
}

export function fmtDateTime(d: string | Date | null | undefined): string {
  if (!d) return "—";
  return new Date(d).toLocaleString("en-GB", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });
}

export function todayStr(offsetDays = 0): string {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}

/** Client-side CSV export — every Reports page uses this, no backend round trip. */
export function downloadCsv(filename: string, rows: (string | number)[][]) {
  const csv = rows.map((r) => r.map((v) => (typeof v === "string" && /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

/** Small data-fetch hook with manual reload. */
export function useFetch<T>(path: string | null, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string>("");
  const [loading, setLoading] = useState(true);
  const reload = useCallback(() => {
    if (!path) return;
    api<T>(path)
      .then((d) => {
        setData(d);
        setError("");
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [path, ...deps]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    setLoading(true);
    reload();
  }, [reload]);
  return { data, error, loading, reload, setData };
}

type LaravelPaginator<T> = { data: T[]; current_page: number; per_page: number; total: number };

/**
 * Paginated fetch — Laravel's `paginate()` response, wrapped in a named key
 * (e.g. `{tasks: {data, current_page, per_page, total}}`), adapted to this
 * app's `Paged<T>` shape ({rows, total, page, pageSize}) the Pagination UI expects.
 */
export function usePagedFetch<T>(path: string | null, key: string, deps: unknown[] = []) {
  const { data, error, loading, reload } = useFetch<Record<string, LaravelPaginator<T>>>(path, deps);
  const lp = data?.[key];
  const paged: Paged<T> | null = lp ? { rows: lp.data, total: lp.total, page: lp.current_page, pageSize: lp.per_page } : null;
  return { data: paged, error, loading, reload };
}

/** Settings map (key → value) cached per page load. `value` is a JSON-encoded string column — decode before use. */
export function useSettings() {
  const { data, reload } = useFetch<{ settings: { key: string; value: string }[] }>("/hotel-settings");
  const map = new Map<string, unknown>(
    (data?.settings ?? []).map((s) => {
      try {
        return [s.key, JSON.parse(s.value)];
      } catch {
        return [s.key, s.value];
      }
    }),
  );
  return {
    settings: data?.settings,
    reload,
    num: (key: string, fallback = 0) => (typeof map.get(key) === "number" ? (map.get(key) as number) : fallback),
    str: (key: string, fallback = "") => (typeof map.get(key) === "string" ? (map.get(key) as string) : fallback),
    bool: (key: string, fallback = false) => (typeof map.get(key) === "boolean" ? (map.get(key) as boolean) : fallback),
  };
}

/** Turn a business name into a slug-safe subdomain label (lowercase, dashes). */
export function slugify(value: string): string {
  return value
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[^\w\s-]/g, "")
    .trim()
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "");
}

/**
 * Mirrors the backend's Settings::depositAmount() — a deposit/advance that's
 * either a percentage of the total or a flat LKR-cents figure, capped to the
 * total. Used to preview the suggested deposit before submitting a booking.
 */
export function depositAmount(total: number, mode: string, pct: number, fixed: number): number {
  if (mode === "fixed") return Math.min(Math.round(fixed), total);
  return Math.round((total * pct) / 100);
}
