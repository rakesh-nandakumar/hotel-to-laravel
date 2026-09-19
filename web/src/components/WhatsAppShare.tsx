import { useRef, useState } from "react";
import { Copy, ExternalLink } from "lucide-react";
import clsx from "clsx";
import { useFetch, useSettings } from "../lib/util";
import { useAuth } from "../lib/auth";
import { useToast } from "../lib/toast";
import { Empty, ErrorText, Modal } from "./ui";

/**
 * "Share to WhatsApp group" for reservations. A platform operator sets the
 * group's invite link in master control (Settings → notifications →
 * "WhatsApp Group Link"); every confirmed booking then gets a WhatsApp
 * button that copies the server-composed summary and opens the group.
 *
 * WhatsApp has no URL that opens a group AND pre-fills text (wa.me/?text=
 * only lets the user pick a chat), so this is copy-then-open, with the
 * message shown first so staff can see exactly what they're about to paste.
 */

/** Setting key — see SettingsSeeder / ReservationShareService::GROUP_LINK_SETTING. */
export const WHATSAPP_GROUP_LINK_SETTING = "notifications.whatsapp_group_link";

/** Mirrors ReservationShareService::SHAREABLE_STATUSES — the server enforces it; this only decides whether to show the button. */
const SHAREABLE_STATUSES = ["confirmed", "checked_in", "checked_out"];

/** Brand glyph — lucide dropped brand icons, so this is the Simple Icons path. */
export function WhatsAppIcon({ size = 16, className }: { size?: number; className?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden="true">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
    </svg>
  );
}

/** Whether the WhatsApp button applies at all (link configured + may view bookings), and per booking status. */
export function useWhatsAppShare() {
  const { str } = useSettings();
  const { can } = useAuth();
  const enabled = str(WHATSAPP_GROUP_LINK_SETTING, "").trim() !== "" && can("hotel_reservations.view");
  return {
    enabled,
    canShare: (statusCode: string) => enabled && SHAREABLE_STATUSES.includes(statusCode),
  };
}

/** Small round icon button for table rows — stops the click reaching a row-level onClick. */
export function WhatsAppShareButton({ onClick, className }: { onClick: () => void; className?: string }) {
  return (
    <button
      type="button"
      className={clsx("btn-ghost !p-1.5 text-emerald-600 hover:!bg-emerald-50 hover:text-emerald-700", className)}
      title="Share booking to the WhatsApp group"
      aria-label="Share booking to the WhatsApp group"
      onClick={(e) => {
        e.stopPropagation();
        onClick();
      }}
    >
      <WhatsAppIcon size={17} />
    </button>
  );
}

/**
 * Clipboard write with the legacy execCommand path as a fallback — the async
 * Clipboard API is missing on plain-http LAN hosts (front-desk PCs hitting
 * the server by IP), which is exactly where this gets used most.
 */
async function copyText(text: string, fallbackEl: HTMLTextAreaElement | null): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    // fall through to the legacy path
  }
  if (!fallbackEl) return false;
  fallbackEl.focus();
  fallbackEl.select();
  try {
    return document.execCommand("copy");
  } catch {
    return false;
  }
}

export function WhatsAppShareModal({ reservationId, code, onClose }: { reservationId: number; code: string; onClose: () => void }) {
  const { data, error, loading } = useFetch<{ message: string; group_link: string }>(`/reservations/${reservationId}/whatsapp-message`);
  const toast = useToast();
  const textRef = useRef<HTMLTextAreaElement>(null);
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    if (!data) return false;
    const ok = await copyText(data.message, textRef.current);
    setCopied(ok);
    if (ok) toast.success("Booking details copied", "Paste them into the WhatsApp group");
    else toast.warning("Couldn't copy automatically", "Select the text above and copy it manually");
    return ok;
  };

  return (
    <Modal open onClose={onClose} title={`Share ${code} to WhatsApp group`}>
      {error && <ErrorText error={error} />}
      {!error && (loading || !data) && <Empty text="Preparing message…" />}
      {data && (
        <div className="space-y-3">
          <textarea
            ref={textRef}
            readOnly
            rows={Math.min(14, data.message.split("\n").length + 1)}
            className="input whitespace-pre-wrap font-mono text-xs leading-relaxed"
            value={data.message}
            onFocus={(e) => e.currentTarget.select()}
          />
          <p className="text-xs text-slate-500">
            WhatsApp can't pre-fill a message into a group, so the details are copied to your clipboard — paste them once the group opens.
          </p>
          <div className="flex flex-wrap justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={copy}>
              <Copy size={15} /> {copied ? "Copied ✓" : "Copy message"}
            </button>
            {/*
              A real link, not window.open after an await: the anchor's default
              navigation is what keeps this popup-blocker-proof in every browser,
              and kicking off the clipboard write first (while this document still
              has focus) is what keeps the copy from being rejected.
            */}
            <a
              className="btn-primary !bg-emerald-600 hover:!bg-emerald-700"
              href={data.group_link}
              target="_blank"
              rel="noopener noreferrer"
              onClick={() => {
                void copy();
              }}
            >
              <WhatsAppIcon size={16} /> Copy &amp; open WhatsApp group <ExternalLink size={13} />
            </a>
          </div>
        </div>
      )}
    </Modal>
  );
}
