/**
 * Offline shell for POS resilience: caches the app shell + static assets so the
 * POS keeps loading during an internet outage. Write operations are queued in
 * IndexedDB by the app (src/lib/offline.ts) and replayed with idempotency keys.
 */
const CACHE = "mountview-shell-v2";

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(["/"])));
  self.skipWaiting();
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.origin !== location.origin) return;
  // Auth/CSRF and broadcasting must ALWAYS hit the network — never intercept or
  // cache them (a cached 404/CSRF cookie breaks login on the deployed server).
  if (url.pathname.startsWith("/sanctum") || url.pathname.startsWith("/broadcasting")) return;
  // Never cache API responses except menu/board reads used by the offline POS UI
  const isApi = url.pathname.startsWith("/api");
  const cacheableApi = ["/api/menu/full", "/api/rooms/board", "/api/settings"].some((p) => url.pathname === p);
  if (isApi && !cacheableApi) return;

  e.respondWith(
    fetch(e.request)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copy));
        return res;
      })
      .catch(async () => {
        const cached = await caches.match(e.request);
        if (cached) return cached;
        // SPA navigation fallback
        if (e.request.mode === "navigate") {
          const shell = await caches.match("/");
          if (shell) return shell;
        }
        return new Response("Offline", { status: 503 });
      })
  );
});
