import { prisma } from "./prisma";

let cache: Map<string, unknown> | null = null;

export async function loadSettings(): Promise<Map<string, unknown>> {
  if (cache) return cache;
  const rows = await prisma.setting.findMany();
  cache = new Map(rows.map((r) => [r.key, safeParse(r.value)]));
  return cache;
}

function safeParse(v: string): unknown {
  try {
    return JSON.parse(v);
  } catch {
    return v;
  }
}

export function invalidateSettings() {
  cache = null;
}

export async function getSetting<T>(key: string, fallback: T): Promise<T> {
  const map = await loadSettings();
  const v = map.get(key);
  return (v === undefined || v === null ? fallback : v) as T;
}

export const getNum = (key: string, fallback = 0) => getSetting<number>(key, fallback);
export const getStr = (key: string, fallback = "") => getSetting<string>(key, fallback);
