import { Router } from "express";
import { z } from "zod";
import { KotStatus, PaymentMethod } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { emit } from "../socket";
import { recordPayment, accrueLoyalty } from "../lib/billing";
import {
  deductStock, sendLowStockAlerts, recomputeOrder, postOrderToFolio, checkedInReservationForRoom, orderPaid,
  InsufficientStock, autoSoldOutSweep,
} from "../lib/pos";
import { orderReceiptPdf, kotTicketPdf, orderSlipPdf } from "../lib/pdf";

const router = Router();

const orderInclude = {
  items: { include: { menuItem: { select: { categoryId: true } } } },
  room: { select: { id: true, number: true } },
  reservation: { select: { id: true, code: true, guest: { select: { id: true, name: true } } } },
  staff: { select: { id: true, name: true } },
  payments: true,
} as const;

/** Active POS orders (open tabs + parked + today's finished). */
router.get(
  "/",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const scope = (req.query.scope as string) || "active";
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const where =
      scope === "active"
        ? { status: { in: ["OPEN", "PARKED"] as never[] } }
        : scope === "today"
          ? { createdAt: { gte: today } }
          : {};
    const orders = await prisma.order.findMany({ where: where as never, include: orderInclude, orderBy: { createdAt: "desc" }, take: 100 });
    res.json(orders);
  })
);

/** Kitchen Order Ticket screen — chef's live queue. */
router.get(
  "/kot",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (_req, res) => {
    const since = new Date(Date.now() - 24 * 3600 * 1000);
    const orders = await prisma.order.findMany({
      where: { status: { notIn: ["VOID"] }, kotStatus: { in: ["NEW", "PREPARING", "READY"] }, createdAt: { gte: since } },
      include: orderInclude,
      orderBy: { createdAt: "asc" },
    });
    res.json(orders);
  })
);

router.get(
  "/:id",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const order = await prisma.order.findUnique({ where: { id: req.params.id }, include: orderInclude });
    if (!order) throw new ApiError(404, "Order not found");
    res.json(order);
  })
);

// ── Create order ──────────────────────────────────────────────────────────────
const itemsSchema = z.array(z.object({ menuItemId: z.string(), qty: z.number().int().min(1), notes: z.string().optional() })).min(1);

router.post(
  "/",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        clientKey: z.string().optional(), // offline-POS idempotency
        type: z.enum(["ROOM_GUEST", "WALKIN"]),
        diningMode: z.enum(["DINE_IN", "TAKEAWAY"]).default("DINE_IN"), // takeaway waives service charge
        roomId: z.string().optional(),
        customerName: z.string().optional(),
        notes: z.string().optional(),
        items: itemsSchema,
      })
      .parse(req.body);
    // Room service is always dine-in — takeaway only applies to walk-ins
    const diningMode = body.type === "ROOM_GUEST" ? "DINE_IN" : body.diningMode;

    // Idempotent replay from the offline queue → return the already-created order
    if (body.clientKey) {
      const existing = await prisma.order.findUnique({ where: { clientKey: body.clientKey }, include: orderInclude });
      if (existing) return res.json(existing);
    }

    let reservationId: string | undefined;
    if (body.type === "ROOM_GUEST") {
      if (!body.roomId) throw new ApiError(400, "Room required for a guest order");
      reservationId = (await checkedInReservationForRoom(body.roomId)).id;
    }

    const menuItems = await prisma.menuItem.findMany({ where: { id: { in: body.items.map((i) => i.menuItemId) } } });
    const byId = new Map(menuItems.map((m) => [m.id, m]));
    for (const it of body.items) {
      const m = byId.get(it.menuItemId);
      if (!m || !m.active) throw new ApiError(400, "Menu item not found");
      if (m.soldOut) throw new ApiError(409, `"${m.name}" is marked sold out`);
    }

    const lowStock: string[] = [];
    let soldOutMarked: string[] = [];
    let order;
    try {
      order = await prisma.$transaction(async (tx) => {
        const created = await tx.order.create({
          data: {
            clientKey: body.clientKey,
            type: body.type,
            diningMode,
            roomId: body.roomId,
            reservationId,
            customerName: body.customerName,
            notes: body.notes,
            staffId: req.user!.id,
            items: {
              create: body.items.map((it) => {
                const m = byId.get(it.menuItemId)!;
                return { menuItemId: m.id, name: m.name, qty: it.qty, unitPrice: m.price, amount: m.price * it.qty, notes: it.notes };
              }),
            },
          },
        });
        // Recipe/BOM auto-deduction — stock can never go below zero
        for (const it of body.items) lowStock.push(...(await deductStock(tx, it.menuItemId, it.qty)));
        // Auto sold-out: items that can no longer make one portion
        soldOutMarked = await autoSoldOutSweep(tx, body.items.map((i) => i.menuItemId));
        return recomputeOrder(tx, created.id);
      }, { timeout: 20000, maxWait: 10000 }); // headroom for high-latency DB links
    } catch (e) {
      if (e instanceof InsufficientStock) {
        // Not enough raw materials → the item goes SOLD OUT and the order is rejected
        const name = byId.get(e.menuItemId)?.name ?? "Item";
        await prisma.menuItem.update({ where: { id: e.menuItemId }, data: { soldOut: true } });
        emit("menu", { soldOut: [name] });
        throw new ApiError(409, `${e.message} — "${name}" is now marked SOLD OUT.`);
      }
      throw e;
    }

    if (soldOutMarked.length > 0) emit("menu", { soldOut: soldOutMarked });
    await sendLowStockAlerts(lowStock);
    audit(req.user!.id, "ORDER_CREATE", "Order", order.id, { orderNo: order.orderNo, type: body.type });
    emit("kot", { orderId: order.id });
    const full = await prisma.order.findUnique({ where: { id: order.id }, include: orderInclude });
    res.status(201).json(full);
  })
);

/** Add items to a running tab (post-paid walk-in or guest order). */
router.post(
  "/:id/items",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const items = itemsSchema.parse(req.body.items);
    const order = await prisma.order.findUnique({ where: { id: req.params.id } });
    if (!order) throw new ApiError(404, "Order not found");
    if (order.status !== "OPEN" && order.status !== "PARKED") throw new ApiError(400, `Order is ${order.status}`);

    const menuItems = await prisma.menuItem.findMany({ where: { id: { in: items.map((i) => i.menuItemId) } } });
    const byId = new Map(menuItems.map((m) => [m.id, m]));
    for (const it of items) {
      const m = byId.get(it.menuItemId);
      if (!m || m.soldOut) throw new ApiError(409, `"${m?.name ?? "item"}" is unavailable`);
    }
    const lowStock: string[] = [];
    let soldOutMarked: string[] = [];
    let updated;
    try {
      updated = await prisma.$transaction(async (tx) => {
        for (const it of items) {
          const m = byId.get(it.menuItemId)!;
          await tx.orderItem.create({
            data: { orderId: order.id, menuItemId: m.id, name: m.name, qty: it.qty, unitPrice: m.price, amount: m.price * it.qty, notes: it.notes },
          });
          lowStock.push(...(await deductStock(tx, it.menuItemId, it.qty)));
        }
        soldOutMarked = await autoSoldOutSweep(tx, items.map((i) => i.menuItemId));
        // New food arrived → kitchen needs to see it again
        await tx.order.update({ where: { id: order.id }, data: { kotStatus: "NEW", status: "OPEN" } });
        return recomputeOrder(tx, order.id);
      }, { timeout: 20000, maxWait: 10000 });
    } catch (e) {
      if (e instanceof InsufficientStock) {
        const name = byId.get(e.menuItemId)?.name ?? "Item";
        await prisma.menuItem.update({ where: { id: e.menuItemId }, data: { soldOut: true } });
        emit("menu", { soldOut: [name] });
        throw new ApiError(409, `${e.message} — "${name}" is now marked SOLD OUT.`);
      }
      throw e;
    }
    if (soldOutMarked.length > 0) emit("menu", { soldOut: soldOutMarked });
    await sendLowStockAlerts(lowStock);
    emit("kot", { orderId: order.id });
    res.json(updated);
  })
);

/**
 * Void a single line — mandatory reason.
 * KOT rules: only allowed when the order is NEW (not started) or SERVED.
 * PREPARING / READY cannot be voided (food is being made / waiting).
 * NEW → raw materials restocked; SERVED → consumed, NOT restocked.
 */
router.post(
  "/:id/items/:itemId/void",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const { reason } = z.object({ reason: z.string().min(1, "Void reason required") }).parse(req.body);
    const item = await prisma.orderItem.findUnique({ where: { id: req.params.itemId } });
    if (!item || item.orderId !== req.params.id) throw new ApiError(404, "Order item not found");
    if (item.voided) throw new ApiError(400, "Already voided");
    const order = await prisma.order.findUniqueOrThrow({ where: { id: req.params.id } });
    if (order.status === "SETTLED" || order.status === "CHARGED_TO_ROOM") throw new ApiError(400, "Order already settled — use refund instead");
    if (order.kotStatus === "PREPARING" || order.kotStatus === "READY")
      throw new ApiError(400, `Cannot void while the kitchen is ${order.kotStatus === "PREPARING" ? "preparing" : "ready to serve"} — void before it starts or after it is served`);

    const restock = order.kotStatus === "NEW"; // not started → return raw materials
    const updated = await prisma.$transaction(async (tx) => {
      await tx.orderItem.update({ where: { id: item.id }, data: { voided: true, voidReason: reason } });
      if (restock) await deductStock(tx, item.menuItemId, item.qty, -1);
      return recomputeOrder(tx, order.id);
    });
    audit(req.user!.id, "ORDER_ITEM_VOID", "OrderItem", item.id, { reason, name: item.name, restocked: restock });
    emit("kot", { orderId: order.id });
    res.json(updated);
  })
);

/** KOT status — Chef updates New → Preparing → Ready; reception sees it live. */
router.put(
  "/:id/kot",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const { status } = z.object({ status: z.nativeEnum(KotStatus) }).parse(req.body);
    const order = await prisma.order.update({ where: { id: req.params.id }, data: { kotStatus: status }, include: orderInclude });
    emit("kot", { orderId: order.id, orderNo: order.orderNo, kotStatus: status });
    res.json(order);
  })
);

/** Hold / park order (§4.4) and resume. */
router.put(
  "/:id/park",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const order = await prisma.order.update({ where: { id: req.params.id }, data: { status: "PARKED" }, include: orderInclude });
    res.json(order);
  })
);

router.put(
  "/:id/resume",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const order = await prisma.order.update({ where: { id: req.params.id }, data: { status: "OPEN" }, include: orderInclude });
    res.json(order);
  })
);

/** Discount — MANAGER-authorized only; % or fixed; reason logged (§4.4). */
router.put(
  "/:id/discount",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z
      .object({ mode: z.enum(["PCT", "FIXED"]), value: z.number().min(0), reason: z.string().min(1, "Discount reason required") })
      .parse(req.body);
    const order = await prisma.order.findUnique({ where: { id: req.params.id }, include: { items: true } });
    if (!order) throw new ApiError(404, "Order not found");
    if (order.status === "SETTLED" || order.status === "CHARGED_TO_ROOM") throw new ApiError(400, "Order already settled");
    const subtotal = order.items.filter((i) => !i.voided).reduce((s, i) => s + i.amount, 0);
    const discount = body.mode === "PCT" ? Math.round((subtotal * Math.min(body.value, 100)) / 100) : Math.min(Math.round(body.value), subtotal);
    const updated = await prisma.$transaction(async (tx) => {
      await tx.order.update({ where: { id: order.id }, data: { discount, discountReason: body.reason, discountById: req.user!.id } });
      return recomputeOrder(tx, order.id);
    });
    audit(req.user!.id, "DISCOUNT", "Order", order.id, { mode: body.mode, value: body.value, discount, reason: body.reason });
    res.json(updated);
  })
);

// ── Settle (walk-in, post-paid) — split bill across multiple payment methods ──
router.post(
  "/:id/settle",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        payments: z
          .array(z.object({ method: z.nativeEnum(PaymentMethod), amount: z.number().int().min(1), reference: z.string().optional(), idempotencyKey: z.string().optional() }))
          .min(1),
      })
      .parse(req.body);
    const order = await prisma.order.findUnique({ where: { id: req.params.id }, include: { payments: true, reservation: true } });
    if (!order) throw new ApiError(404, "Order not found");
    if (order.status === "SETTLED" || order.status === "CHARGED_TO_ROOM") {
      // Idempotent replay: if the offline queue already settled it, return as-is
      const same = body.payments.every((p) => p.idempotencyKey && order.payments.some((x) => x.idempotencyKey === p.idempotencyKey));
      if (same) return res.json(order);
      throw new ApiError(400, "Order already settled");
    }
    if (body.payments.some((p) => p.method === "CORPORATE_CREDIT")) throw new ApiError(400, "Corporate credit applies to room folios only");

    const paidAlready = orderPaid(order);
    const newSum = body.payments.reduce((s, p) => s + p.amount, 0);
    if (paidAlready + newSum !== order.total)
      throw new ApiError(400, `Split payments must total LKR ${((order.total - paidAlready) / 100).toFixed(2)}`);

    for (const p of body.payments) {
      await recordPayment({
        orderId: order.id, method: p.method, amount: p.amount, reference: p.reference,
        idempotencyKey: p.idempotencyKey, staffId: req.user!.id,
        guestIdForLoyalty: order.reservation?.guestId,
      });
    }
    const settled = await prisma.order.update({ where: { id: order.id }, data: { status: "SETTLED", settledAt: new Date() }, include: orderInclude });
    if (order.reservation?.guestId) await accrueLoyalty(order.reservation.guestId, order.total, "ORDER", order.id, req.user!.id);
    audit(req.user!.id, "ORDER_SETTLE", "Order", order.id, { total: order.total, methods: body.payments.map((p) => p.method) });
    emit("orders", { orderId: order.id });
    res.json(settled);
  })
);

/** Charge a room-guest order to the guest folio — flows to unified checkout. */
router.post(
  "/:id/charge-to-room",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const order = await prisma.order.findUnique({ where: { id: req.params.id } });
    if (!order) throw new ApiError(404, "Order not found");
    if (order.status === "SETTLED" || order.status === "CHARGED_TO_ROOM") throw new ApiError(400, "Order already settled");
    if (order.type !== "ROOM_GUEST" || !order.roomId) throw new ApiError(400, "Not a room-guest order");
    const reservation = await checkedInReservationForRoom(order.roomId);
    await prisma.$transaction(async (tx) => {
      const fresh = await recomputeOrder(tx, order.id); // lock in current VAT/SC
      await postOrderToFolio(tx, fresh.id, reservation.folio!.id, req.user!.id);
      await tx.order.update({ where: { id: order.id }, data: { status: "CHARGED_TO_ROOM", reservationId: reservation.id, settledAt: new Date() } });
    });
    audit(req.user!.id, "ORDER_CHARGE_TO_ROOM", "Order", order.id, { reservation: reservation.code });
    emit("orders", { orderId: order.id });
    res.json(await prisma.order.findUnique({ where: { id: order.id }, include: orderInclude }));
  })
);

/**
 * Void an entire order — mandatory reason, refunds must be done first.
 * KOT rules: only when NEW (restocks raw materials) or SERVED (no restock).
 */
router.post(
  "/:id/void",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const { reason } = z.object({ reason: z.string().min(1, "Void reason required") }).parse(req.body);
    const order = await prisma.order.findUnique({ where: { id: req.params.id }, include: { items: true, payments: true } });
    if (!order) throw new ApiError(404, "Order not found");
    if (order.status === "CHARGED_TO_ROOM") throw new ApiError(400, "Charged to room — void the folio lines instead");
    if (orderPaid(order) > 0) throw new ApiError(400, "Order has payments — refund them first");
    if (order.kotStatus === "PREPARING" || order.kotStatus === "READY")
      throw new ApiError(400, `Cannot void while the kitchen is ${order.kotStatus === "PREPARING" ? "preparing" : "ready to serve"} — wait until served or void before it starts`);

    const restock = order.kotStatus === "NEW";
    await prisma.$transaction(async (tx) => {
      if (restock) {
        for (const it of order.items.filter((i) => !i.voided)) await deductStock(tx, it.menuItemId, it.qty, -1);
      }
      await tx.order.update({ where: { id: order.id }, data: { status: "VOID", voidReason: reason } });
    }, { timeout: 20000, maxWait: 10000 });
    audit(req.user!.id, "ORDER_VOID", "Order", order.id, { reason, restocked: restock });
    emit("kot", { orderId: order.id });
    res.json({ ok: true, restocked: restock });
  })
);

/** Refund on a settled order — mandatory reason (§4.4). */
router.post(
  "/:id/refund",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z
      .object({ amount: z.number().int().min(1), method: z.nativeEnum(PaymentMethod), reason: z.string().min(1, "Refund reason required") })
      .parse(req.body);
    const order = await prisma.order.findUnique({ where: { id: req.params.id }, include: { payments: true } });
    if (!order) throw new ApiError(404, "Order not found");
    if (body.amount > orderPaid(order)) throw new ApiError(400, "Refund exceeds amount paid");
    const payment = await recordPayment({
      orderId: order.id, method: body.method, amount: body.amount, kind: "REFUND", reason: body.reason, staffId: req.user!.id,
    });
    res.status(201).json(payment);
  })
);

// ── Printing: receipt (thermal/A4) + KOT ticket ──
router.get(
  "/:id/receipt",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const format = req.query.format === "a4" ? "a4" : "thermal";
    await orderReceiptPdf(req.params.id, format, res);
  })
);

/** Walk-in double slip: bill + numbered collection token (thermal, one print). */
router.get(
  "/:id/slip",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    await orderSlipPdf(req.params.id, res);
  })
);

router.get(
  "/:id/kot-ticket",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    await kotTicketPdf(req.params.id, res);
  })
);

export default router;
