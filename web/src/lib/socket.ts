/**
 * Realtime refresh — POLLING implementation (no websocket server required).
 *
 * The app is hosted on shared cPanel, which can't run a persistent websocket
 * server (Laravel Reverb) or expose a ws port. So instead of *pushing* changes
 * from the server, we re-emit the same four "something changed, refetch"
 * signals ("kot"/"rooms"/"orders"/"menu") on a short interval on the client.
 *
 * Page code is unchanged: it still calls getSocket().on(event, fn) and off(...).
 * The only difference is `fn` now runs on a timer and refetches, instead of
 * running when a websocket message arrives. Listeners are invoked with an EMPTY
 * payload ({}), so payload-gated cross-page popups (GlobalRealtimeNotifications)
 * stay quiet and only genuine data refreshes happen.
 *
 * To switch back to true push later (e.g. a managed Pusher/Ably account or a
 * Reverb box), this is the only file that needs to change — the on/off/reset
 * surface stays identical.
 */
const EVENTS = ["kot", "rooms", "orders", "menu"] as const;
type RealtimeEvent = (typeof EVENTS)[number];
type Listener = (payload: unknown) => void;

/** How often subscribed pages refetch. Modest so shared hosting isn't hammered. */
const POLL_MS = 10_000;

const listeners = new Map<RealtimeEvent, Set<Listener>>();
let timer: ReturnType<typeof setInterval> | null = null;

function tick() {
  // Don't poll a backgrounded tab or while offline — saves the host needless load.
  if (document.hidden || !navigator.onLine) return;
  for (const set of listeners.values()) {
    set.forEach((fn) => fn({}));
  }
}

/** Refetch immediately when the operator returns to the tab. */
function onVisible() {
  if (!document.hidden) tick();
}

function start() {
  if (timer) return;
  timer = setInterval(tick, POLL_MS);
  document.addEventListener("visibilitychange", onVisible);
}

type SocketHandle = { on: (event: RealtimeEvent, fn: Listener) => void; off: (event: RealtimeEvent, fn: Listener) => void };

export function getSocket(): SocketHandle {
  start();
  return {
    on(event, fn) {
      if (!listeners.has(event)) listeners.set(event, new Set());
      listeners.get(event)!.add(fn);
    },
    off(event, fn) {
      listeners.get(event)?.delete(fn);
    },
  };
}

export function resetSocket() {
  if (timer) clearInterval(timer);
  timer = null;
  document.removeEventListener("visibilitychange", onVisible);
  listeners.clear();
}
