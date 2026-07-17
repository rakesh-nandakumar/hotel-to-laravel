/**
 * Notification layer — automated WhatsApp + SMS with pluggable providers.
 *
 * Providers are resolved PER SEND from Settings (category "integrations",
 * managed by SYSTEM_ADMIN in the Integrations screen):
 *  - WhatsApp: Meta Cloud API (integrations.whatsapp_*)
 *  - SMS: generic HTTP JSON gateway (integrations.sms_*) — works with
 *    notify.lk / Dialog eSMS style endpoints: POST { to, message, sender_id }
 *    with Authorization: Bearer <key>
 *  - Email: console stub. TODO(Phase2): SMTP provider (nodemailer).
 *
 * If a channel is disabled or unconfigured, sends are SIMULATED (logged to the
 * Notification table + console) so business flows keep working end to end.
 * Which channels guests receive is a business setting: notifications.channels.
 */
import { NotificationChannel } from "@prisma/client";
import { prisma } from "./prisma";
import { getSetting, getStr } from "./settings";

export interface NotificationProvider {
  send(to: string, subject: string, body: string): Promise<void>;
  simulated: boolean;
}

class ConsoleStubProvider implements NotificationProvider {
  simulated = true;
  constructor(private channel: string) {}
  async send(to: string, subject: string, body: string) {
    console.log(`[notify:${this.channel}:SIMULATED] → ${to} | ${subject}\n${body.slice(0, 200)}`);
  }
}

/** Meta WhatsApp Cloud API. */
class WhatsAppCloudProvider implements NotificationProvider {
  simulated = false;
  constructor(private url: string, private token: string) {}
  async send(to: string, subject: string, body: string) {
    const res = await fetch(this.url, {
      method: "POST",
      headers: { Authorization: `Bearer ${this.token}`, "Content-Type": "application/json" },
      body: JSON.stringify({
        messaging_product: "whatsapp",
        to: normalizePhone(to),
        type: "text",
        text: { body: `*${subject}*\n\n${body}` },
      }),
    });
    if (!res.ok) throw new Error(`WhatsApp API ${res.status}: ${(await res.text()).slice(0, 300)}`);
  }
}

/** Generic HTTP SMS gateway (notify.lk / Dialog eSMS / similar). */
class HttpSmsProvider implements NotificationProvider {
  simulated = false;
  constructor(private url: string, private key: string, private senderId: string) {}
  async send(to: string, _subject: string, body: string) {
    const res = await fetch(this.url, {
      method: "POST",
      headers: { Authorization: `Bearer ${this.key}`, "Content-Type": "application/json" },
      body: JSON.stringify({ to: normalizePhone(to), message: body, sender_id: this.senderId }),
    });
    if (!res.ok) throw new Error(`SMS gateway ${res.status}: ${(await res.text()).slice(0, 300)}`);
  }
}

function normalizePhone(phone: string): string {
  return phone.replace(/[^\d+]/g, "");
}

async function providerFor(channel: NotificationChannel): Promise<NotificationProvider> {
  if (channel === "WHATSAPP") {
    const enabled = await getSetting<boolean>("integrations.whatsapp_enabled", false);
    const url = await getStr("integrations.whatsapp_api_url", "");
    const token = await getStr("integrations.whatsapp_api_token", "");
    if (enabled && url && token) return new WhatsAppCloudProvider(url, token);
    return new ConsoleStubProvider("whatsapp");
  }
  if (channel === "SMS") {
    const enabled = await getSetting<boolean>("integrations.sms_enabled", false);
    const url = await getStr("integrations.sms_api_url", "");
    const key = await getStr("integrations.sms_api_key", "");
    const sender = await getStr("integrations.sms_sender_id", "MountView");
    if (enabled && url && key) return new HttpSmsProvider(url, key, sender);
    return new ConsoleStubProvider("sms");
  }
  // TODO(Phase2): SmtpEmailProvider (nodemailer + SMTP settings)
  return new ConsoleStubProvider("email");
}

export async function notify(opts: {
  type: string;
  channel: NotificationChannel;
  to: string;
  subject: string;
  body: string;
  refType?: string;
  refId?: string;
}) {
  const row = await prisma.notification.create({ data: { ...opts } });
  try {
    const provider = await providerFor(opts.channel);
    await provider.send(opts.to, opts.subject, opts.body);
    await prisma.notification.update({
      where: { id: row.id },
      data: { status: "SENT", sentAt: new Date(), error: provider.simulated ? "SIMULATED — channel not configured/enabled (Integrations)" : null },
    });
  } catch (e) {
    await prisma.notification.update({
      where: { id: row.id },
      data: { status: "FAILED", error: String(e).slice(0, 500) },
    });
  }
}

/** Send to a guest on every enabled channel they have contact details for. */
export async function notifyGuest(
  contact: { email?: string | null; phone?: string | null },
  msg: { type: string; subject: string; body: string; refType?: string; refId?: string }
) {
  const channels = await getSetting<string[]>("notifications.channels", ["EMAIL", "WHATSAPP", "SMS"]);
  if (contact.email && channels.includes("EMAIL")) {
    await notify({ ...msg, channel: "EMAIL", to: contact.email });
  }
  if (contact.phone) {
    if (channels.includes("WHATSAPP")) await notify({ ...msg, channel: "WHATSAPP", to: contact.phone });
    if (channels.includes("SMS")) await notify({ ...msg, channel: "SMS", to: contact.phone });
  }
}
