/**
 * All amounts are integer LKR minor units ("cents"). 1 LKR = 100 cents.
 * USD is display-only; conversion happens in the frontend with Setting currency.usd_rate.
 */

export const fmtLKR = (cents: number) =>
  "LKR " + (cents / 100).toLocaleString("en-LK", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * VAT and Service Charge are ALWAYS two separate line items (report §4.4).
 * Service charge applies to (subtotal - discount); VAT applies on top of service charge
 * (standard Sri Lankan hospitality practice).
 */
export function calcTotals(subtotal: number, discount: number, serviceChargePct: number, vatPct: number) {
  const base = Math.max(0, subtotal - discount);
  const serviceCharge = Math.round((base * serviceChargePct) / 100);
  const vat = Math.round(((base + serviceCharge) * vatPct) / 100);
  return { subtotal, discount, serviceCharge, vat, total: base + serviceCharge + vat };
}
