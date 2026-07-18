/**
 * POS offline resilience (report §5): writes are queued in IndexedDB when the
 * network is down and replayed on reconnect. Every queued request carries an
 * idempotency key (clientKey / idempotencyKey) so replays can never
 * double-post an order or a payment.
 */
import { ensureCsrfCookie, xsrfHeader, API_ORIGIN } from "./api";

const DB_NAME = "mountview-pos";
const STORE = "queue";

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: "id" });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

type QueuedReq = { id: string; path: string; method: string; body: unknown; createdAt: number };

async function enqueue(item: QueuedReq) {
  const db = await openDb();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE, "readwrite");
    tx.objectStore(STORE).put(item);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  notifyListeners();
}

async function allQueued(): Promise<QueuedReq[]> {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const req = db.transaction(STORE).objectStore(STORE).getAll();
    req.onsuccess = () => resolve(req.result as QueuedReq[]);
    req.onerror = () => reject(req.error);
  });
}

async function remove(id: string) {
  const db = await openDb();
  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction(STORE, "readwrite");
    tx.objectStore(STORE).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
  notifyListeners();
}

export async function queuedCount() {
  return (await allQueued()).length;
}

const listeners = new Set<() => void>();
export function onQueueChange(fn: () => void) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
function notifyListeners() {
  listeners.forEach((fn) => fn());
}

/**
 * Fire a POS write. On network failure the request is queued and
 * { queued: true } is returned so the UI can show "will sync".
 * Server errors (4xx/5xx) are NOT queued — they are real rejections.
 */
export async function posRequest<T = unknown>(path: string, body: unknown, method = "POST"): Promise<T | { queued: true }> {
  try {
    await ensureCsrfCookie();
    const res = await fetch(`${API_ORIGIN}/api${path}`, {
      method,
      credentials: "include",
      headers: { Accept: "application/json", "Content-Type": "application/json", ...xsrfHeader() },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      let msg = res.statusText;
      try {
        msg = (await res.json()).error ?? msg;
      } catch {}
      throw Object.assign(new Error(msg), { status: res.status, isServerReject: true });
    }
    return res.json();
  } catch (e) {
    if ((e as { isServerReject?: boolean }).isServerReject) throw e;
    // Network failure → queue for replay
    await enqueue({ id: crypto.randomUUID(), path, method, body, createdAt: Date.now() });
    return { queued: true };
  }
}

let flushing = false;
export async function flushQueue(): Promise<number> {
  if (flushing) return 0;
  flushing = true;
  let done = 0;
  try {
    const items = (await allQueued()).sort((a, b) => a.createdAt - b.createdAt);
    for (const item of items) {
      try {
        await ensureCsrfCookie();
        const res = await fetch(`${API_ORIGIN}/api${item.path}`, {
          method: item.method,
          credentials: "include",
          headers: { Accept: "application/json", "Content-Type": "application/json", ...xsrfHeader() },
          body: JSON.stringify(item.body),
        });
        // Success or a definitive server rejection (e.g. duplicate) → drop from queue.
        // Only network-level failures keep the item queued.
        if (res.ok || (res.status >= 400 && res.status < 500)) {
          await remove(item.id);
          done++;
        } else {
          break;
        }
      } catch {
        break; // still offline
      }
    }
  } finally {
    flushing = false;
  }
  return done;
}

// Auto-replay on reconnect + periodic safety net
if (typeof window !== "undefined") {
  window.addEventListener("online", () => void flushQueue());
  setInterval(() => {
    if (navigator.onLine) void flushQueue();
  }, 30000);
}
