import Echo from "laravel-echo";
import Pusher from "pusher-js";

/**
 * Realtime channel — Laravel Reverb (Pusher protocol), single public "hotel"
 * channel, four event streams: "kot" (kitchen queue), "rooms" (live room
 * status), "orders", "menu" (sold-out changes). Mirrors the Node app's
 * Socket.IO events 1:1 — see App\Events\Hotel\RealtimeUpdate on the backend.
 *
 * Exposes the same on/off surface the old socket.io client did, so page code
 * didn't need to change — only this file talks to Echo directly.
 */
const EVENTS = ["kot", "rooms", "orders", "menu"] as const;
type RealtimeEvent = (typeof EVENTS)[number];
type Listener = (payload: unknown) => void;

let echo: Echo<"reverb"> | null = null;
const listeners = new Map<RealtimeEvent, Set<Listener>>();

function connect(): Echo<"reverb"> {
  (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

  const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
  const instance = new Echo<"reverb">({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: port,
    wssPort: port,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? "http") === "https",
    enabledTransports: ["ws", "wss"],
  });

  const channel = instance.channel("hotel");
  for (const event of EVENTS) {
    channel.listen(`.${event}`, (payload: unknown) => {
      listeners.get(event)?.forEach((fn) => fn(payload));
    });
  }

  return instance;
}

type SocketHandle = { on: (event: RealtimeEvent, fn: Listener) => void; off: (event: RealtimeEvent, fn: Listener) => void };

export function getSocket(): SocketHandle {
  echo ??= connect();
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
  echo?.disconnect();
  echo = null;
  listeners.clear();
}
