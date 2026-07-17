/**
 * POS core: stock auto-deduction (recipe/BOM), order total recompute
 * (VAT + Service Charge as two separate figures), folio posting.
 */
import { Prisma } from "@prisma/client";
import { prisma } from "./prisma";
import { ApiError } from "./http";
import { getNum, getStr } from "./settings";
import { calcTotals } from "./money";
import { notify } from "./notify";

type Tx = Prisma.TransactionClient;

/**
 * Drain ingredient batches FEFO (first-expiring-first-out) so expiry tracking
 * mirrors real kitchen usage. Batch levels are informational; the ingredient's
 * stockQty stays the authoritative total.
 */
async function drainBatches(tx: Tx, ingredientId: string, qty: number) {
  let remaining = qty;
  const batches = await tx.ingredientBatch.findMany({
    where: { ingredientId, qty: { gt: 0 } },
    orderBy: [{ expiryDate: "asc" }, { receivedAt: "asc" }],
  });
  for (const b of batches) {
    if (remaining <= 0) break;
    const take = Math.min(b.qty, remaining);
    await tx.ingredientBatch.update({ where: { id: b.id }, data: { qty: b.qty - take } });
    remaining -= take;
  }
}

/** Return stock to batches on void — best effort into the most recent batch. */
async function restockBatches(tx: Tx, ingredientId: string, qty: number) {
  const latest = await tx.ingredientBatch.findFirst({ where: { ingredientId }, orderBy: { receivedAt: "desc" } });
  if (latest) await tx.ingredientBatch.update({ where: { id: latest.id }, data: { qty: latest.qty + qty } });
}

/** Thrown when an order needs more raw material than is in stock. */
export class InsufficientStock extends ApiError {
  menuItemId: string;
  constructor(menuItemId: string, message: string) {
    super(409, message);
    this.menuItemId = menuItemId;
  }
}

/** Can the kitchen make `portions` of this item with current raw-material stock? */
export async function canMake(db: Tx | typeof prisma, menuItemId: string, portions = 1) {
  const recipe = await db.recipeItem.findMany({ where: { menuItemId }, include: { ingredient: true } });
  const missing = recipe
    .filter((r) => r.ingredient.stockQty < r.qty * portions)
    .map((r) => `${r.ingredient.name} (needs ${r.qty * portions}${r.ingredient.unit}, has ${r.ingredient.stockQty}${r.ingredient.unit})`);
  return { ok: missing.length === 0, missing };
}

/**
 * Deduct ingredient stock for qty portions of a menu item; alert on low stock.
 * HARD RULE: raw-material stock can never go below zero — insufficient stock
 * throws InsufficientStock (rolling back the enclosing transaction).
 */
export async function deductStock(tx: Tx, menuItemId: string, portions: number, direction: 1 | -1 = 1) {
  const recipe = await tx.recipeItem.findMany({ where: { menuItemId }, include: { ingredient: true } });
  const lowNow: string[] = [];
  for (const r of recipe) {
    const change = r.qty * portions * direction;
    if (direction === 1 && r.ingredient.stockQty < change) {
      throw new InsufficientStock(
        menuItemId,
        `Not enough ${r.ingredient.name} in stock (${r.ingredient.stockQty}${r.ingredient.unit} left, needs ${change}${r.ingredient.unit})`
      );
    }
    const updated = await tx.ingredient.update({
      where: { id: r.ingredientId },
      data: { stockQty: { decrement: change } },
    });
    if (direction === 1) await drainBatches(tx, r.ingredientId, change);
    else await restockBatches(tx, r.ingredientId, -change);
    if (direction === 1 && updated.stockQty <= updated.lowStockThreshold && updated.stockQty + change > updated.lowStockThreshold) {
      lowNow.push(`${updated.name} (${updated.stockQty}${updated.unit} left)`);
    }
  }
  return lowNow;
}

/**
 * After deductions: auto-mark as SOLD OUT any active menu item sharing these
 * ingredients that can no longer make a single portion. Returns marked names.
 * Fixed-cost implementation (3 queries) — safe inside latency-bound transactions.
 */
export async function autoSoldOutSweep(tx: Tx, menuItemIds: string[]): Promise<string[]> {
  const recipes = await tx.recipeItem.findMany({ where: { menuItemId: { in: menuItemIds } }, select: { ingredientId: true } });
  const ingredientIds = [...new Set(recipes.map((r) => r.ingredientId))];
  if (ingredientIds.length === 0) return [];
  // Full recipes of every available item that uses any touched ingredient
  const affectedRecipes = await tx.recipeItem.findMany({
    where: { menuItem: { active: true, soldOut: false, recipe: { some: { ingredientId: { in: ingredientIds } } } } },
    include: {
      ingredient: { select: { stockQty: true } },
      menuItem: { select: { id: true, name: true } },
    },
  });
  const short = new Map<string, string>(); // id → name
  for (const r of affectedRecipes) {
    if (r.ingredient.stockQty < r.qty) short.set(r.menuItem.id, r.menuItem.name);
  }
  if (short.size === 0) return [];
  await tx.menuItem.updateMany({ where: { id: { in: [...short.keys()] } }, data: { soldOut: true } });
  return [...short.values()];
}

/** Low-stock alert to chef/manager (report §4.4 Inventory). */
export async function sendLowStockAlerts(items: string[]) {
  if (!items.length) return;
  const managerEmail = await getStr("hotel.email", "manager@mountview.lk");
  await notify({
    type: "LOW_STOCK",
    channel: "EMAIL",
    to: managerEmail,
    subject: "Low stock alert — kitchen inventory",
    body: `The following ingredients are at or below their low-stock threshold:\n- ${items.join("\n- ")}`,
  });
}

/** Recompute order money fields from its non-voided items + current tax settings. */
export async function recomputeOrder(tx: Tx, orderId: string) {
  const order = await tx.order.findUniqueOrThrow({ where: { id: orderId }, include: { items: true } });
  const subtotal = order.items.filter((i) => !i.voided).reduce((s, i) => s + i.amount, 0);
  // Takeaway is exempt from service charge (no table service) — VAT still applies.
  const scPct = order.diningMode === "TAKEAWAY" ? 0 : await getNum("billing.service_charge_pct", 0);
  const vatPct = await getNum("billing.vat_pct", 0);
  const t = calcTotals(subtotal, order.discount, scPct, vatPct);
  return tx.order.update({
    where: { id: orderId },
    data: { subtotal: t.subtotal, serviceCharge: t.serviceCharge, vat: t.vat, total: t.total },
    include: { items: true, room: { select: { number: true } }, staff: { select: { name: true } } },
  });
}

export function orderPaid(order: { payments: { kind: string; amount: number }[] }) {
  return (
    order.payments.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0) -
    order.payments.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0)
  );
}

/**
 * Post a finished order to the guest's room folio as auditable line items:
 * restaurant/minibar split, discount, then its own SC + VAT lines (all tagged
 * with orderId so checkout doesn't tax them again).
 */
export async function postOrderToFolio(tx: Tx, orderId: string, folioId: string, staffId: string) {
  const order = await tx.order.findUniqueOrThrow({
    where: { id: orderId },
    include: { items: { include: { menuItem: { include: { category: true } } } } },
  });
  const live = order.items.filter((i) => !i.voided);
  const minibar = live.filter((i) => i.menuItem.category.isMinibar).reduce((s, i) => s + i.amount, 0);
  const restaurant = live.reduce((s, i) => s + i.amount, 0) - minibar;

  const mk = (source: "RESTAURANT" | "MINIBAR" | "DISCOUNT" | "SERVICE_CHARGE" | "VAT", description: string, amount: number) =>
    tx.folioLine.create({
      data: { folioId, source, description, qty: 1, unitPrice: amount, amount, orderId, staffId },
    });

  if (restaurant > 0) await mk("RESTAURANT", `Restaurant Order #${order.orderNo}`, restaurant);
  if (minibar > 0) await mk("MINIBAR", `Minibar Order #${order.orderNo}`, minibar);
  if (order.discount > 0) await mk("DISCOUNT", `Discount on Order #${order.orderNo}${order.discountReason ? ` (${order.discountReason})` : ""}`, -order.discount);
  if (order.serviceCharge > 0) await mk("SERVICE_CHARGE", `Service charge — Order #${order.orderNo}`, order.serviceCharge);
  if (order.vat > 0) await mk("VAT", `VAT — Order #${order.orderNo}`, order.vat);
}

/** Room-guest orders must map to a checked-in reservation with an open folio. */
export async function checkedInReservationForRoom(roomId: string) {
  const rr = await prisma.reservationRoom.findFirst({
    where: { roomId, reservation: { status: "CHECKED_IN" } },
    include: { reservation: { include: { folio: true, guest: true } } },
  });
  if (!rr?.reservation.folio) throw new ApiError(400, "No checked-in guest in that room");
  return rr.reservation;
}
