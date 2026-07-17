import { Router } from "express";
import { z } from "zod";
import dayjs from "dayjs";
import { Channel, PaymentMethod } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { audit } from "../lib/audit";
import { emit } from "../socket";
import { availableRooms, nights, nightlyRate } from "../lib/booking";
import { folioWithTotals, recordPayment, accrueLoyalty } from "../lib/billing";
import { getNum, getStr, getSetting } from "../lib/settings";
import { nextReservationCode, nextGroupCode, nextInvoiceNo } from "../lib/codes";
import { notifyGuest } from "../lib/notify";

const router = Router();
// Reservations/front-desk are Owner+Manager operations
router.use(requireRole(...MANAGERS));

// ── Availability & quoting ────────────────────────────────────────────────────
router.get(
  "/availability",
  asyncHandler(async (req, res) => {
    const q = z.object({ checkIn: z.string(), checkOut: z.string() }).parse(req.query);
    if (!dayjs(q.checkOut).isAfter(dayjs(q.checkIn), "day")) throw new ApiError(400, "Check-out must be after check-in");
    const rooms = await availableRooms(q.checkIn, q.checkOut);
    const result = [];
    for (const room of rooms) {
      const perNight = [];
      for (const d of nights(q.checkIn, q.checkOut)) {
        perNight.push({ date: d, ...(await nightlyRate(room.roomType, room.roomType.seasonalRates, d)) });
      }
      result.push({
        id: room.id,
        number: room.number,
        status: room.status,
        roomType: { id: room.roomTypeId, name: room.roomType.name, maxOccupancy: room.roomType.maxOccupancy },
        nights: perNight,
        stayTotal: perNight.reduce((s, n) => s + n.rate, 0),
      });
    }
    res.json(result);
  })
);

// ── List / detail ─────────────────────────────────────────────────────────────
router.get(
  "/",
  asyncHandler(async (req, res) => {
    const { status, q } = req.query as { status?: string; q?: string };
    const where = {
      status: status ? (status as never) : undefined,
      OR: q
        ? [
            { code: { contains: q, mode: "insensitive" as const } },
            { guest: { name: { contains: q, mode: "insensitive" as const } } },
            { rooms: { some: { room: { number: { contains: q } } } } },
          ]
        : undefined,
    };
    const include = {
      guest: { select: { id: true, name: true, phone: true, loyaltyPoints: true } },
      rooms: { include: { room: { select: { number: true } } } },
      package: { select: { code: true, name: true } },
      groupBooking: { select: { reference: true, name: true } },
      corporateAccount: { select: { companyName: true } },
      folio: { select: { id: true, status: true, invoiceNo: true } },
    } as const;

    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 20));
      const [rows, total] = await Promise.all([
        prisma.reservation.findMany({ where, include, orderBy: { checkIn: "desc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.reservation.count({ where }),
      ]);
      return res.json({ rows, total, page, pageSize });
    }

    const rows = await prisma.reservation.findMany({ where, include, orderBy: { checkIn: "desc" }, take: 200 });
    res.json(rows);
  })
);

router.get(
  "/groups",
  asyncHandler(async (_req, res) => {
    const groups = await prisma.groupBooking.findMany({
      include: {
        reservations: {
          include: {
            guest: { select: { name: true } },
            rooms: { include: { room: { select: { number: true } }, billToGuest: { select: { id: true, name: true } } } },
            folio: { select: { id: true, status: true } },
          },
        },
      },
      orderBy: { createdAt: "desc" },
    });
    res.json(groups);
  })
);

/** Consolidated group invoice data — one reference, all rooms/charges together. */
router.get(
  "/groups/:id/invoice",
  asyncHandler(async (req, res) => {
    const group = await prisma.groupBooking.findUnique({
      where: { id: req.params.id },
      include: { reservations: { include: { folio: true, guest: true, rooms: { include: { room: true, billToGuest: true } } } } },
    });
    if (!group) throw new ApiError(404, "Group booking not found");
    const folios = [];
    for (const r of group.reservations) {
      if (r.folio) folios.push(await folioWithTotals(r.folio.id));
    }
    res.json({
      group: { id: group.id, reference: group.reference, name: group.name, contactName: group.contactName },
      folios,
      grandTotal: folios.reduce((s, f) => s + f.total, 0),
      totalPaid: folios.reduce((s, f) => s + f.paid, 0),
      balance: folios.reduce((s, f) => s + f.balance, 0),
    });
  })
);

/** Calendar/tape-chart feed: reservations overlapping [from, to). */
router.get(
  "/calendar",
  asyncHandler(async (req, res) => {
    const q = z.object({ from: z.string(), to: z.string() }).parse(req.query);
    const rows = await prisma.reservation.findMany({
      where: {
        status: { in: ["PENDING", "CONFIRMED", "CHECKED_IN", "CHECKED_OUT"] },
        checkIn: { lt: new Date(q.to) },
        checkOut: { gt: new Date(q.from) },
      },
      include: {
        guest: { select: { name: true } },
        rooms: { select: { roomId: true } },
        groupBooking: { select: { reference: true } },
      },
      orderBy: { checkIn: "asc" },
    });
    res.json(
      rows.map((r) => ({
        id: r.id,
        code: r.code,
        status: r.status,
        checkIn: r.checkIn,
        checkOut: r.checkOut,
        guest: r.guest.name,
        group: r.groupBooking?.reference ?? null,
        roomIds: r.rooms.map((x) => x.roomId),
      }))
    );
  })
);

router.get(
  "/:id",
  asyncHandler(async (req, res) => {
    const r = await prisma.reservation.findUnique({
      where: { id: req.params.id },
      include: {
        guest: true,
        package: true,
        groupBooking: true,
        corporateAccount: true,
        rooms: { include: { room: { include: { roomType: true } }, billToGuest: { select: { id: true, name: true } } } },
        roomItemChecks: { orderBy: { createdAt: "desc" } },
        folio: { select: { id: true } },
      },
    });
    if (!r) throw new ApiError(404, "Reservation not found");
    const folio = r.folio ? await folioWithTotals(r.folio.id) : null;
    res.json({ ...r, folio });
  })
);

// ── Create booking (single or group / multi-room = one consolidated folio) ───
const createBody = z.object({
  guestId: z.string().optional(),
  newGuest: z
    .object({ name: z.string().min(1), phone: z.string().optional(), email: z.string().optional(), idNumber: z.string().optional(), nationality: z.string().optional() })
    .optional(),
  channel: z.nativeEnum(Channel),
  checkIn: z.string(),
  checkOut: z.string(),
  adults: z.number().int().min(1),
  children: z.number().int().min(0).default(0), // under free-age → not charged
  packageId: z.string().optional(),
  corporateAccountId: z.string().optional(),
  rooms: z.array(z.object({ roomId: z.string(), nightlyRate: z.number().int().min(0).optional() })).min(1),
  notes: z.string().optional(),
  group: z.object({ name: z.string().min(1), contactName: z.string().optional(), contactPhone: z.string().optional() }).optional(),
  depositPayment: z.object({ method: z.nativeEnum(PaymentMethod), amount: z.number().int().min(1), reference: z.string().optional() }).optional(),
});

router.post(
  "/",
  asyncHandler(async (req, res) => {
    const body = createBody.parse(req.body);
    if (!dayjs(body.checkOut).isAfter(dayjs(body.checkIn), "day")) throw new ApiError(400, "Check-out must be after check-in");

    // Guest: existing profile (auto-recognition) or create new
    let guestId = body.guestId;
    if (!guestId) {
      if (!body.newGuest) throw new ApiError(400, "guestId or newGuest required");
      guestId = (await prisma.guest.create({ data: body.newGuest })).id;
    }

    // Availability check for every requested room
    const free = await availableRooms(body.checkIn, body.checkOut);
    const freeIds = new Map(free.map((r) => [r.id, r]));
    for (const rr of body.rooms) {
      if (!freeIds.has(rr.roomId)) {
        const room = await prisma.room.findUnique({ where: { id: rr.roomId } });
        throw new ApiError(409, `Room ${room?.number ?? rr.roomId} is not available for those dates`);
      }
    }

    // Rate per room: manual/negotiated override, else dynamic (first night's computed rate,
    // reduced by corporate negotiated discount)
    const corp = body.corporateAccountId
      ? await prisma.corporateAccount.findUniqueOrThrow({ where: { id: body.corporateAccountId } })
      : null;
    const roomRates: { roomId: string; nightlyRate: number }[] = [];
    let stayTotal = 0;
    const nightList = nights(body.checkIn, body.checkOut);
    for (const rr of body.rooms) {
      const room = freeIds.get(rr.roomId)!;
      let rate = rr.nightlyRate;
      if (rate === undefined) {
        const first = await nightlyRate(room.roomType, room.roomType.seasonalRates, nightList[0]);
        rate = first.rate;
        if (corp) rate = Math.round(rate * (1 - corp.discountPct / 100));
      }
      roomRates.push({ roomId: rr.roomId, nightlyRate: rate });
      stayTotal += rate * nightList.length;
    }
    const pkg = body.packageId ? await prisma.package.findUniqueOrThrow({ where: { id: body.packageId } }) : null;
    if (pkg) stayTotal += pkg.pricePerPersonPerNight * body.adults * nightList.length;

    const depositPct = await getNum("billing.room_deposit_pct", 20);
    const depositDue = Math.round((stayTotal * depositPct) / 100);

    const group = body.group
      ? await prisma.groupBooking.create({
          data: { reference: await nextGroupCode(), name: body.group.name, contactName: body.group.contactName, contactPhone: body.group.contactPhone },
        })
      : null;

    const reservation = await prisma.reservation.create({
      data: {
        code: await nextReservationCode(),
        guestId,
        channel: body.channel,
        checkIn: new Date(body.checkIn),
        checkOut: new Date(body.checkOut),
        adults: body.adults,
        children: body.children,
        packageId: body.packageId,
        corporateAccountId: body.corporateAccountId,
        groupBookingId: group?.id,
        notes: body.notes,
        depositDue,
        rooms: { create: roomRates },
        folio: { create: { type: "GUEST" } },
      },
      include: { guest: true, folio: true, rooms: { include: { room: true } } },
    });

    // Deposit / prepayment at booking (mixed methods possible via later folio payments too)
    if (body.depositPayment) {
      await recordPayment({
        folioId: reservation.folio!.id,
        method: body.depositPayment.method,
        amount: body.depositPayment.amount,
        kind: "DEPOSIT",
        reference: body.depositPayment.reference,
        staffId: req.user!.id,
        guestIdForLoyalty: guestId,
      });
    }

    audit(req.user!.id, "RESERVATION_CREATE", "Reservation", reservation.id, { code: reservation.code, stayTotal, depositDue });

    // Automated booking confirmation (email + WhatsApp)
    const hotelName = await getStr("hotel.name", "Mount View Hotel");
    await notifyGuest(reservation.guest, {
      type: "BOOKING_CONFIRMATION",
      subject: `Booking confirmed — ${reservation.code} at ${hotelName}`,
      body: `Dear ${reservation.guest.name}, your booking ${reservation.code} (${body.checkIn} → ${body.checkOut}, room${reservation.rooms.length > 1 ? "s" : ""} ${reservation.rooms.map((r) => r.room.number).join(", ")}) is confirmed.${depositDue > 0 ? ` Advance deposit due: LKR ${(depositDue / 100).toLocaleString()}.` : ""}`,
      refType: "RESERVATION",
      refId: reservation.id,
    });

    res.status(201).json(reservation);
  })
);

router.put(
  "/:id",
  asyncHandler(async (req, res) => {
    const body = z
      .object({ notes: z.string().optional(), adults: z.number().int().min(1).optional(), children: z.number().int().min(0).optional(), packageId: z.string().nullable().optional() })
      .parse(req.body);
    const r = await prisma.reservation.update({ where: { id: req.params.id }, data: body });
    res.json(r);
  })
);

/** Group option: bill an individual room to a specific guest. */
router.put(
  "/rooms/:rrId/bill-to",
  asyncHandler(async (req, res) => {
    const { billToGuestId } = z.object({ billToGuestId: z.string().nullable() }).parse(req.body);
    const rr = await prisma.reservationRoom.update({ where: { id: req.params.rrId }, data: { billToGuestId } });
    res.json(rr);
  })
);

// ── Check-in ──────────────────────────────────────────────────────────────────
router.post(
  "/:id/check-in",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        idNumber: z.string().optional(), // required unless guest already has one
        applyEarlySurcharge: z.boolean().default(false),
        itemChecks: z.array(z.object({ roomId: z.string(), items: z.array(z.object({ item: z.string(), ok: z.boolean(), note: z.string().optional() })) })).optional(),
      })
      .parse(req.body);

    const r = await prisma.reservation.findUnique({
      where: { id: req.params.id },
      include: { guest: true, package: true, folio: true, rooms: { include: { room: { include: { roomType: { include: { seasonalRates: true } } } } } } },
    });
    if (!r) throw new ApiError(404, "Reservation not found");
    if (r.status !== "CONFIRMED" && r.status !== "PENDING") throw new ApiError(400, `Cannot check in a ${r.status} reservation`);

    // Guest ID/passport is REQUIRED at check-in (government reporting, §4.1)
    const idNumber = body.idNumber?.trim() || r.guest.idNumber;
    if (!idNumber) throw new ApiError(400, "Guest ID/passport number is required at check-in");
    if (body.idNumber?.trim()) await prisma.guest.update({ where: { id: r.guestId }, data: { idNumber: body.idNumber.trim() } });

    // Rooms must be physically sellable — a DIRTY room cannot be sold until
    // its housekeeping checklist is submitted (report §4.6).
    for (const rr of r.rooms) {
      if (rr.room.status !== "AVAILABLE")
        throw new ApiError(409, `Room ${rr.room.number} is ${rr.room.status} — cannot check in${rr.room.status === "DIRTY" ? " until the cleaning checklist is submitted" : ""}`);
    }

    const folioId = r.folio!.id;
    const nightList = nights(r.checkIn, r.checkOut);

    await prisma.$transaction(async (tx) => {
      // Post room charges — one auditable line per room per night
      for (const rr of r.rooms) {
        for (const d of nightList) {
          await tx.folioLine.create({
            data: {
              folioId, source: "ROOM",
              description: `Room ${rr.room.number} — ${d}`,
              qty: 1, unitPrice: rr.nightlyRate, amount: rr.nightlyRate, staffId: req.user!.id,
            },
          });
        }
      }
      // Package (B&B / HB / FB) per adult per night — children under free-age not charged
      if (r.package && r.package.pricePerPersonPerNight > 0) {
        for (const d of nightList) {
          await tx.folioLine.create({
            data: {
              folioId, source: "PACKAGE",
              description: `${r.package.name} × ${r.adults} pax — ${d}`,
              qty: r.adults, unitPrice: r.package.pricePerPersonPerNight,
              amount: r.package.pricePerPersonPerNight * r.adults, staffId: req.user!.id,
            },
          });
        }
      }
      // Configurable early check-in surcharge
      if (body.applyEarlySurcharge) {
        const amt = await getNum("billing.early_checkin_surcharge", 0);
        if (amt > 0) {
          await tx.folioLine.create({
            data: { folioId, source: "SURCHARGE", description: "Early check-in surcharge", qty: 1, unitPrice: amt, amount: amt, staffId: req.user!.id },
          });
        }
      }
      for (const rr of r.rooms) {
        await tx.room.update({ where: { id: rr.roomId }, data: { status: "OCCUPIED" } });
      }
      await tx.reservation.update({ where: { id: r.id }, data: { status: "CHECKED_IN", checkedInAt: new Date() } });
    });

    // Room item checklist at check-in (items present/undamaged)
    for (const check of body.itemChecks ?? []) {
      await prisma.roomItemCheck.create({
        data: { reservationId: r.id, roomId: check.roomId, kind: "CHECK_IN", items: check.items, staffId: req.user!.id },
      });
    }

    audit(req.user!.id, "CHECKIN", "Reservation", r.id, { code: r.code });
    emit("rooms", { changed: r.rooms.map((rr) => rr.roomId) });
    res.json(await folioWithTotals(folioId));
  })
);

// ── Checkout ──────────────────────────────────────────────────────────────────
/** Preview: consolidated bill incl. VAT + service charge as separate lines. */
router.get(
  "/:id/checkout-quote",
  asyncHandler(async (req, res) => {
    const applyLate = req.query.late === "1";
    const r = await prisma.reservation.findUnique({ where: { id: req.params.id }, include: { folio: true } });
    if (!r?.folio) throw new ApiError(404, "Reservation/folio not found");
    const f = await folioWithTotals(r.folio.id);
    const lateAmt = applyLate ? await getNum("billing.late_checkout_surcharge", 0) : 0;
    // Lines from a previously-interrupted checkout attempt must not double up:
    // exclude folio-level tax + late-surcharge lines and recompute them fresh.
    const isStaleCheckoutLine = (l: (typeof f.lines)[number]) =>
      !l.orderId && (l.source === "SERVICE_CHARGE" || l.source === "VAT" || (l.source === "SURCHARGE" && l.description === "Late check-out surcharge"));
    const cleanLines = f.lines.filter((l) => !isStaleCheckoutLine(l));
    // Order-linked lines were taxed at order time; everything else is taxed now.
    const base = cleanLines.filter((l) => !l.orderId).reduce((s, l) => s + l.amount, 0) + lateAmt;
    const scPct = await getNum("billing.service_charge_pct", 0);
    const vatPct = await getNum("billing.vat_pct", 0);
    const serviceCharge = Math.round((base * scPct) / 100);
    const vat = Math.round(((base + serviceCharge) * vatPct) / 100);
    const total = cleanLines.reduce((s, l) => s + l.amount, 0) + lateAmt + serviceCharge + vat;
    res.json({ ...f, lines: cleanLines, lateSurcharge: lateAmt, serviceCharge, scPct, vat, vatPct, grandTotal: total, balanceDue: total - f.paid + f.refunded });
  })
);

router.post(
  "/:id/checkout",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        applyLateSurcharge: z.boolean().default(false),
        payments: z.array(z.object({ method: z.nativeEnum(PaymentMethod), amount: z.number().int().min(1), reference: z.string().optional() })).default([]),
        refundMethod: z.nativeEnum(PaymentMethod).default("CASH"),
        itemChecks: z.array(z.object({ roomId: z.string(), items: z.array(z.object({ item: z.string(), ok: z.boolean(), note: z.string().optional() })) })).optional(),
      })
      .parse(req.body);

    const r = await prisma.reservation.findUnique({
      where: { id: req.params.id },
      include: { guest: true, folio: true, rooms: { include: { room: { include: { roomType: true } } } }, corporateAccount: true },
    });
    if (!r?.folio) throw new ApiError(404, "Reservation not found");
    if (r.status !== "CHECKED_IN") throw new ApiError(400, "Guest is not checked in");
    if (body.payments.some((p) => p.method === "CORPORATE_CREDIT") && !r.corporateAccountId)
      throw new ApiError(400, "Corporate credit is only available for corporate account bookings");

    const folioId = r.folio.id;
    const scPct = await getNum("billing.service_charge_pct", 0);
    const vatPct = await getNum("billing.vat_pct", 0);
    const lateAmt = body.applyLateSurcharge ? await getNum("billing.late_checkout_surcharge", 0) : 0;

    const result = await prisma.$transaction(async (tx) => {
      // Retry safety: remove folio-level tax/late-surcharge lines left by a
      // previously-interrupted checkout so they are never applied twice.
      await tx.folioLine.deleteMany({
        where: { folioId, orderId: null, OR: [{ source: { in: ["SERVICE_CHARGE", "VAT"] } }, { source: "SURCHARGE", description: "Late check-out surcharge" }] },
      });
      if (lateAmt > 0) {
        await tx.folioLine.create({
          data: { folioId, source: "SURCHARGE", description: "Late check-out surcharge", qty: 1, unitPrice: lateAmt, amount: lateAmt, staffId: req.user!.id },
        });
      }
      // VAT + Service Charge — two separate line items, computed over lines not
      // already taxed at POS-order level (those have orderId set).
      const lines = await tx.folioLine.findMany({ where: { folioId, voided: false } });
      const base = lines.filter((l) => !l.orderId && l.source !== "SERVICE_CHARGE" && l.source !== "VAT").reduce((s, l) => s + l.amount, 0);
      const serviceCharge = Math.round((base * scPct) / 100);
      const vat = Math.round(((base + serviceCharge) * vatPct) / 100);
      if (serviceCharge > 0)
        await tx.folioLine.create({
          data: { folioId, source: "SERVICE_CHARGE", description: `Service charge ${scPct}%`, qty: 1, unitPrice: serviceCharge, amount: serviceCharge, staffId: req.user!.id },
        });
      if (vat > 0)
        await tx.folioLine.create({
          data: { folioId, source: "VAT", description: `VAT ${vatPct}%`, qty: 1, unitPrice: vat, amount: vat, staffId: req.user!.id },
        });

      const allLines = await tx.folioLine.findMany({ where: { folioId, voided: false } });
      const grandTotal = allLines.reduce((s, l) => s + l.amount, 0);
      const pays = await tx.payment.findMany({ where: { folioId } });
      const paidSoFar = pays.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0) - pays.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0);
      const newTotal = body.payments.reduce((s, p) => s + p.amount, 0);
      const balance = grandTotal - paidSoFar - newTotal;
      if (balance > 0) throw new ApiError(400, `Payment short by LKR ${(balance / 100).toFixed(2)} — bill must be settled in full at checkout`);
      return { grandTotal, overpaid: -balance };
    });

    // Record payments outside the totals transaction (each is individually audited)
    for (const p of body.payments) {
      await recordPayment({
        folioId, method: p.method, amount: p.amount, reference: p.reference,
        staffId: req.user!.id, guestIdForLoyalty: r.guestId,
      });
    }
    if (result.overpaid > 0) {
      await recordPayment({
        folioId, method: body.refundMethod, amount: result.overpaid, kind: "REFUND",
        reason: "Deposit/overpayment refund at checkout", staffId: req.user!.id,
      });
    }

    // Room item checklist at check-out (damage detection → charge via folio line before checkout)
    for (const check of body.itemChecks ?? []) {
      await prisma.roomItemCheck.create({
        data: { reservationId: r.id, roomId: check.roomId, kind: "CHECK_OUT", items: check.items, staffId: req.user!.id },
      });
    }

    // Settle folio + release rooms as DIRTY + auto-create housekeeping tasks
    const invoiceNo = await nextInvoiceNo("GUEST");
    await prisma.$transaction(async (tx) => {
      await tx.folio.update({ where: { id: folioId }, data: { status: "SETTLED", invoiceNo, settledAt: new Date() } });
      await tx.reservation.update({ where: { id: r.id }, data: { status: "CHECKED_OUT", checkedOutAt: new Date() } });
      for (const rr of r.rooms) {
        await tx.room.update({ where: { id: rr.roomId }, data: { status: "DIRTY" } });
        await tx.housekeepingTask.create({
          data: {
            roomId: rr.roomId,
            reservationId: r.id,
            checklist: (rr.room.roomType.cleaningChecklist as string[]).map((item) => ({ item, done: false })),
          },
        });
      }
    });

    const points = await accrueLoyalty(r.guestId, result.grandTotal, "FOLIO", folioId, req.user!.id);
    audit(req.user!.id, "CHECKOUT", "Reservation", r.id, { invoiceNo, total: result.grandTotal, loyaltyEarned: points });
    emit("rooms", { changed: r.rooms.map((rr) => rr.roomId) });

    // Post-stay feedback request (auto)
    const hotelName = await getStr("hotel.name", "Mount View Hotel");
    await notifyGuest(r.guest, {
      type: "FEEDBACK_REQUEST",
      subject: `How was your stay at ${hotelName}?`,
      body: `Dear ${r.guest.name}, thank you for staying with us (invoice ${invoiceNo}). We'd love your feedback!`,
      refType: "RESERVATION", refId: r.id,
    });

    res.json({ ...(await folioWithTotals(folioId)), invoiceNo });
  })
);

// ── Cancellation — policy from Settings enforced automatically ────────────────
router.post(
  "/:id/cancel",
  asyncHandler(async (req, res) => {
    const body = z.object({ reason: z.string().min(1, "Cancellation reason required"), refundMethod: z.nativeEnum(PaymentMethod).default("CASH") }).parse(req.body);
    const r = await prisma.reservation.findUnique({ where: { id: req.params.id }, include: { folio: true, guest: true } });
    if (!r) throw new ApiError(404, "Reservation not found");
    if (r.status !== "CONFIRMED" && r.status !== "PENDING") throw new ApiError(400, `Cannot cancel a ${r.status} reservation`);

    const rules = await getSetting<{ daysBefore: number; refundPct: number }[]>("policies.cancellation_rules", []);
    const daysUntil = dayjs(r.checkIn).startOf("day").diff(dayjs().startOf("day"), "day");
    const rule = [...rules].sort((a, b) => b.daysBefore - a.daysBefore).find((x) => daysUntil >= x.daysBefore);
    const refundPct = rule?.refundPct ?? 0;

    let refunded = 0;
    if (r.folio) {
      const f = await folioWithTotals(r.folio.id);
      const paidNet = f.paid - f.refunded;
      refunded = Math.round((paidNet * refundPct) / 100);
      if (refunded > 0) {
        await recordPayment({
          folioId: r.folio.id, method: body.refundMethod, amount: refunded, kind: "REFUND",
          reason: `Cancellation policy: ${refundPct}% refund (${daysUntil} days before check-in). ${body.reason}`,
          staffId: req.user!.id,
        });
      }
      await prisma.folio.update({ where: { id: r.folio.id }, data: { status: "VOID" } });
    }
    await prisma.reservation.update({
      where: { id: r.id },
      data: { status: "CANCELLED", cancelledAt: new Date(), cancelReason: body.reason },
    });
    audit(req.user!.id, "RESERVATION_CANCEL", "Reservation", r.id, { reason: body.reason, refundPct, refunded });
    await notifyGuest(r.guest, {
      type: "BOOKING_CANCELLED",
      subject: `Booking ${r.code} cancelled`,
      body: `Dear ${r.guest.name}, booking ${r.code} has been cancelled. ${refunded > 0 ? `Refund per policy: LKR ${(refunded / 100).toLocaleString()} (${refundPct}%).` : "No refund is due per the cancellation policy."}`,
      refType: "RESERVATION", refId: r.id,
    });
    res.json({ ok: true, refundPct, refunded });
  })
);

/** Standalone room item check (either kind) — e.g. re-verify during stay. */
router.post(
  "/:id/item-check",
  asyncHandler(async (req, res) => {
    const body = z
      .object({ roomId: z.string(), kind: z.enum(["CHECK_IN", "CHECK_OUT"]), items: z.array(z.object({ item: z.string(), ok: z.boolean(), note: z.string().optional() })) })
      .parse(req.body);
    const check = await prisma.roomItemCheck.create({
      data: { reservationId: req.params.id, roomId: body.roomId, kind: body.kind, items: body.items, staffId: req.user!.id },
    });
    res.status(201).json(check);
  })
);

export default router;
