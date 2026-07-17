import { useEffect, useRef } from "react";
import { getSocket } from "../lib/socket";
import { useToast } from "../lib/toast";

/**
 * Cross-page popups for events OTHER staff trigger elsewhere in the system —
 * complements the rich, context-full toasts each page fires right after its
 * own actions. Mounted once inside Layout (i.e. whenever someone is logged in).
 */
export default function GlobalRealtimeNotifications() {
  const toast = useToast();
  const toastRef = useRef(toast);
  toastRef.current = toast;

  useEffect(() => {
    const s = getSocket();

    // Only fire for signals that don't already have a rich, explicit toast
    // fired by the actor's own page — avoids double-popping the same event.
    const onKot = (payload: unknown) => {
      const p = payload as { order_no?: number; kot_status?: string };
      if (p.kot_status === "READY") toastRef.current.success(`Order ${p.order_no ? `#${p.order_no} ` : ""}ready to serve`, "Kitchen marked it ready — take it out");
    };
    const onRooms = (payload: unknown) => {
      const p = payload as { status?: string };
      if (p.status) toastRef.current.info("Room status changed", p.status);
    };
    const onMenu = (payload: unknown) => {
      const p = payload as { sold_out?: string[]; available?: string[]; removed?: string[] };
      p.sold_out?.forEach((n) => toastRef.current.warning(`${n} — sold out`));
      p.available?.forEach((n) => toastRef.current.success(`${n} — back in stock`));
      p.removed?.forEach((n) => toastRef.current.info(`${n} removed from the menu`));
    };

    s.on("kot", onKot);
    s.on("rooms", onRooms);
    s.on("menu", onMenu);
    return () => {
      s.off("kot", onKot);
      s.off("rooms", onRooms);
      s.off("menu", onMenu);
    };
  }, []);

  return null;
}
