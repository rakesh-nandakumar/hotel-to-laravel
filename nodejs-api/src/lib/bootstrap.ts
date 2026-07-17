/**
 * Startup bootstrap — idempotent. Ensures:
 *  1. Integration settings exist (category "integrations", SYSTEM_ADMIN-only)
 *  2. A SYSTEM_ADMIN account exists (created with a default password on first
 *     boot — MUST be changed immediately).
 * Runs on every API start so existing deployments pick up new settings without
 * reseeding.
 */
import bcrypt from "bcryptjs";
import { SettingType } from "@prisma/client";
import { prisma } from "./prisma";
import { invalidateSettings } from "./settings";

export type SettingDef = {
  key: string;
  value: unknown;
  type: SettingType;
  category: string;
  label: string;
  hint?: string;
};

/** Deep/technical settings — visible & editable ONLY by SYSTEM_ADMIN. */
export const INTEGRATION_SETTINGS: SettingDef[] = [
  // WhatsApp (Meta Cloud API)
  { key: "integrations.whatsapp_enabled", value: false, type: "BOOLEAN", category: "integrations", label: "WhatsApp — enabled", hint: "Turn on only after URL + token below are set. Off = messages are simulated (logged only)." },
  { key: "integrations.whatsapp_api_url", value: "", type: "TEXT", category: "integrations", label: "WhatsApp — API URL", hint: "Meta Cloud API messages endpoint, e.g. https://graph.facebook.com/v19.0/<PHONE_NUMBER_ID>/messages" },
  { key: "integrations.whatsapp_api_token", value: "", type: "TEXT", category: "integrations", label: "WhatsApp — access token (secret)", hint: "Permanent token from Meta Business / WhatsApp app" },
  // SMS (generic HTTP gateway — notify.lk / Dialog eSMS / Textit etc.)
  { key: "integrations.sms_enabled", value: false, type: "BOOLEAN", category: "integrations", label: "SMS — enabled", hint: "Turn on only after gateway URL + key below are set. Off = simulated." },
  { key: "integrations.sms_api_url", value: "", type: "TEXT", category: "integrations", label: "SMS — gateway URL", hint: "POST endpoint of your SMS provider (e.g. notify.lk, Dialog eSMS). JSON body: { to, message, sender_id }" },
  { key: "integrations.sms_api_key", value: "", type: "TEXT", category: "integrations", label: "SMS — API key (secret)", hint: "Sent as Authorization: Bearer <key>" },
  { key: "integrations.sms_sender_id", value: "MountView", type: "TEXT", category: "integrations", label: "SMS — sender ID" },
  // Booking.com channel sync — TODO(Phase2): sync engine consumes these
  { key: "integrations.bookingcom_hotel_id", value: "", type: "TEXT", category: "integrations", label: "Booking.com — hotel ID", hint: "TODO(Phase2): live channel sync uses these credentials" },
  { key: "integrations.bookingcom_api_key", value: "", type: "TEXT", category: "integrations", label: "Booking.com — API key (secret)" },
  // Online payment gateway — TODO(Phase2): booking-engine checkout uses these
  { key: "integrations.gateway_provider", value: "payhere", type: "TEXT", category: "integrations", label: "Payment gateway — provider", hint: "TODO(Phase2): e.g. payhere" },
  { key: "integrations.gateway_merchant_id", value: "", type: "TEXT", category: "integrations", label: "Payment gateway — merchant ID" },
  { key: "integrations.gateway_secret", value: "", type: "TEXT", category: "integrations", label: "Payment gateway — secret" },
];

/** Inventory settings. */
export const INVENTORY_SETTINGS: SettingDef[] = [
  { key: "inventory.expiry_warn_days", value: 3, type: "NUMBER", category: "inventory", label: "Expiry warning (days ahead)", hint: "Ingredient batches expiring within this many days raise alerts to the chef/manager" },
];

/** Payroll settings — Sri Lankan statutory defaults, Owner-adjustable. */
export const PAYROLL_SETTINGS: SettingDef[] = [
  { key: "payroll.epf_employee_pct", value: 8, type: "PERCENT", category: "payroll", label: "EPF — employee contribution % (deducted)", hint: "Statutory 8% of basic salary" },
  { key: "payroll.epf_employer_pct", value: 12, type: "PERCENT", category: "payroll", label: "EPF — employer contribution %", hint: "Statutory 12% — paid by hotel, not deducted" },
  { key: "payroll.etf_pct", value: 3, type: "PERCENT", category: "payroll", label: "ETF — employer contribution %", hint: "Statutory 3% — paid by hotel, not deducted" },
  { key: "payroll.standard_monthly_hours", value: 200, type: "NUMBER", category: "payroll", label: "Standard monthly hours", hint: "Attendance hours beyond this count as overtime" },
];

/** Business-level notification preference (Owner/Manager editable). */
export const CHANNELS_SETTING: SettingDef = {
  key: "notifications.channels",
  value: ["EMAIL", "WHATSAPP", "SMS"],
  type: "JSON",
  category: "notifications",
  label: "Guest notification channels",
  hint: 'Which channels guests receive: any of "EMAIL", "WHATSAPP", "SMS". Channels without configured credentials are simulated (logged only).',
};

/** Default laundry price list (LKR cents) — ⚠ placeholder prices, Manager edits in UI. */
export const DEFAULT_LAUNDRY_ITEMS: { name: string; price: number }[] = [
  { name: "Shirt / T-shirt — wash & iron", price: 35000 },
  { name: "Trousers — wash & iron", price: 40000 },
  { name: "Dress / Frock — wash & iron", price: 60000 },
  { name: "Sarong / Skirt — wash & iron", price: 40000 },
  { name: "Jacket / Coat", price: 80000 },
  { name: "Undergarments (per piece)", price: 15000 },
  { name: "Bed sheet set", price: 90000 },
  { name: "Towel", price: 25000 },
  { name: "Ironing only (per piece)", price: 15000 },
];

export async function bootstrap() {
  // Ensure settings exist (never overwrites values already set)
  for (const s of [...INTEGRATION_SETTINGS, ...PAYROLL_SETTINGS, ...INVENTORY_SETTINGS, CHANNELS_SETTING]) {
    await prisma.setting.upsert({
      where: { key: s.key },
      update: {}, // keep existing value
      create: { key: s.key, value: JSON.stringify(s.value), type: s.type, category: s.category, label: s.label, hint: s.hint },
    });
  }
  invalidateSettings();

  // Ensure the laundry price list exists (first boot only — never overwrites edits)
  if ((await prisma.laundryItem.count()) === 0) {
    await prisma.laundryItem.createMany({ data: DEFAULT_LAUNDRY_ITEMS });
  }

  // Ensure a SYSTEM_ADMIN exists
  const admin = await prisma.user.findFirst({ where: { role: "SYSTEM_ADMIN" } });
  if (!admin) {
    await prisma.user.create({
      data: {
        name: "System Admin",
        email: "admin@mountview.lk",
        role: "SYSTEM_ADMIN",
        passwordHash: bcrypt.hashSync("admin123", 10),
        pinHash: bcrypt.hashSync("0000", 10),
      },
    });
    console.warn("⚠ SYSTEM_ADMIN created: admin@mountview.lk / admin123 (PIN 0000) — CHANGE THIS PASSWORD IMMEDIATELY");
  }
}
