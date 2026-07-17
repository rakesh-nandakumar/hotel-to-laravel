import { prisma } from "./prisma";

/**
 * Human-friendly sequential codes (RSV-0007, GRP-0002, VNB-0003, INV-2026-0012…).
 * Single-property system: simple max+1 with retry on unique collision.
 */
async function next(prefix: string, lastCode: string | null | undefined, pad = 4): Promise<string> {
  const n = lastCode ? parseInt(lastCode.slice(lastCode.lastIndexOf("-") + 1), 10) + 1 : 1;
  return `${prefix}${String(n).padStart(pad, "0")}`;
}

export async function nextReservationCode() {
  const last = await prisma.reservation.findFirst({ orderBy: { createdAt: "desc" }, select: { code: true } });
  return next("RSV-", last?.code);
}

export async function nextGroupCode() {
  const last = await prisma.groupBooking.findFirst({ orderBy: { createdAt: "desc" }, select: { reference: true } });
  return next("GRP-", last?.reference);
}

export async function nextVenueBookingCode() {
  const last = await prisma.venueBooking.findFirst({ orderBy: { createdAt: "desc" }, select: { code: true } });
  return next("VNB-", last?.code);
}

/** Invoice numbers per type: guest stays INV-YYYY-NNNN, venues VNU-YYYY-NNNN (separate invoice type). */
export async function nextInvoiceNo(type: "GUEST" | "VENUE"): Promise<string> {
  const year = new Date().getFullYear();
  const prefix = type === "VENUE" ? `VNU-${year}-` : `INV-${year}-`;
  const last = await prisma.folio.findFirst({
    where: { invoiceNo: { startsWith: prefix } },
    orderBy: { invoiceNo: "desc" },
    select: { invoiceNo: true },
  });
  return next(prefix, last?.invoiceNo);
}
