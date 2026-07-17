/**
 * Billing core — folio totals, payment recording, loyalty accrual.
 * MONEY INTEGRITY RULE: a folio's total is ALWAYS the sum of its non-voided
 * line items; payments/refunds are tracked separately. Nothing is re-entered
 * manually at checkout — charges flow in as auditable line items.
 */
import { PaymentKind, PaymentMethod, Prisma } from "@prisma/client";
import { prisma } from "./prisma";
import { ApiError } from "./http";
import { getNum } from "./settings";
import { audit } from "./audit";

export async function folioWithTotals(folioId: string) {
  const folio = await prisma.folio.findUnique({
    where: { id: folioId },
    include: {
      lines: { where: { voided: false }, orderBy: { createdAt: "asc" }, include: { staff: { select: { name: true } } } },
      payments: { orderBy: { createdAt: "asc" }, include: { staff: { select: { name: true } } } },
      reservation: { include: { guest: true, rooms: { include: { room: true } } } },
      venueBooking: { include: { venue: true } },
    },
  });
  if (!folio) throw new ApiError(404, "Folio not found");
  const total = folio.lines.reduce((s, l) => s + l.amount, 0);
  const paid = folio.payments.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0);
  const refunded = folio.payments.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0);
  return { ...folio, total, paid, refunded, balance: total - paid + refunded };
}

/** The staff member's open shift (cash payments attach to it for reconciliation). */
export async function openShiftFor(staffId: string) {
  return prisma.shift.findFirst({ where: { staffId, closedAt: null } });
}

export async function recordPayment(opts: {
  folioId?: string;
  orderId?: string;
  corporateAccountId?: string;
  method: PaymentMethod;
  amount: number;
  kind?: PaymentKind;
  reference?: string;
  reason?: string;
  staffId: string;
  idempotencyKey?: string;
  guestIdForLoyalty?: string; // when paying with LOYALTY_POINTS
  tx?: Prisma.TransactionClient;
}) {
  const db = opts.tx ?? prisma;
  const kind = opts.kind ?? "PAYMENT";
  if (!Number.isInteger(opts.amount) || opts.amount <= 0) throw new ApiError(400, "Amount must be a positive integer (LKR cents)");
  if (kind === "REFUND" && !opts.reason?.trim()) throw new ApiError(400, "A reason is required for every refund");

  // Offline replay safety: same idempotency key → return the original payment.
  if (opts.idempotencyKey) {
    const existing = await db.payment.findUnique({ where: { idempotencyKey: opts.idempotencyKey } });
    if (existing) return existing;
  }

  // Loyalty redemption: converts points to LKR value and deducts them.
  if (opts.method === "LOYALTY_POINTS") {
    if (!opts.guestIdForLoyalty) throw new ApiError(400, "Loyalty payment requires a guest");
    const pointValue = await getNum("loyalty.point_value_cents", 100);
    const pointsNeeded = Math.ceil(opts.amount / pointValue);
    const guest = await db.guest.findUniqueOrThrow({ where: { id: opts.guestIdForLoyalty } });
    if (guest.loyaltyPoints < pointsNeeded)
      throw new ApiError(400, `Not enough points: needs ${pointsNeeded}, has ${guest.loyaltyPoints}`);
    await db.guest.update({ where: { id: guest.id }, data: { loyaltyPoints: { decrement: pointsNeeded } } });
    await db.loyaltyTransaction.create({
      data: {
        guestId: guest.id, points: -pointsNeeded, reason: opts.reason || "Redeemed against bill",
        refType: opts.folioId ? "FOLIO" : "ORDER", refId: opts.folioId || opts.orderId, staffId: opts.staffId,
      },
    });
  }

  const shift = await (opts.tx ? opts.tx.shift.findFirst({ where: { staffId: opts.staffId, closedAt: null } }) : openShiftFor(opts.staffId));
  const payment = await db.payment.create({
    data: {
      folioId: opts.folioId,
      orderId: opts.orderId,
      corporateAccountId: opts.corporateAccountId,
      method: opts.method,
      amount: opts.amount,
      kind,
      reference: opts.reference,
      reason: opts.reason,
      staffId: opts.staffId,
      shiftId: shift?.id,
      idempotencyKey: opts.idempotencyKey,
    },
  });
  audit(opts.staffId, kind === "REFUND" ? "REFUND" : "PAYMENT", "Payment", payment.id, {
    method: opts.method, amount: opts.amount, reason: opts.reason ?? null,
  });
  return payment;
}

/** Loyalty accrual on settled spend (rooms + restaurant + venues). Earn rate is a Setting. */
export async function accrueLoyalty(guestId: string, spentCents: number, refType: string, refId: string, staffId: string) {
  const per1000 = await getNum("loyalty.points_per_1000lkr", 0);
  const points = Math.floor(spentCents / 100000) * per1000; // spentCents/100000 = spend in thousands of LKR
  await prisma.guest.update({
    where: { id: guestId },
    data: { lifetimeSpend: { increment: spentCents }, ...(points > 0 ? { loyaltyPoints: { increment: points } } : {}) },
  });
  if (points > 0) {
    await prisma.loyaltyTransaction.create({
      data: { guestId, points, reason: `Earned on ${refType} spend`, refType, refId, staffId },
    });
  }
  return points;
}
