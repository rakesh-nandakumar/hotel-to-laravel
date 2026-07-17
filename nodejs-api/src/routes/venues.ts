import { Router } from "express";
import { z } from "zod";
import dayjs from "dayjs";
import { DurationType, PaymentMethod } from "@prisma/client";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole, MANAGERS } from "../lib/auth";
import { audit } from "../lib/audit";
import { getNum, getStr } from "../lib/settings";
import { nextVenueBookingCode, nextInvoiceNo } from "../lib/codes";
import { folioWithTotals, recordPayment, accrueLoyalty } from "../lib/billing";
import { notifyGuest } from "../lib/notify";

const router = Router();
router.use(requireRole(...MANAGERS));

// ── Venues (pricing/facilities = adjustable settings per §9) ──
router.get(
  "/",
  asyncHandler(async (_req, res) => {
    res.json(await prisma.venue.findMany({ orderBy: { name: "asc" } }));
  })
);

const venueBody = z.object({
  name: z.string().min(1).optional(),
  maxCapacity: z.number().int().min(1).optional(),
  facilities: z.array(z.string()).optional(),
  hourlyRate: z.number().int().min(0).optional(),
  halfDayRate: z.number().int().min(0).optional(),
  fullDayRate: z.number().int().min(0).optional(),
  active: z.boolean().optional(),
});

router.put(
  "/:id",
  asyncHandler(async (req, res) => {
    const venue = await prisma.venue.update({ where: { id: req.params.id }, data: venueBody.parse(req.body) });
    audit(req.user!.id, "VENUE_UPDATE", "Venue", venue.id);
    res.json(venue);
  })
);

/** Availability: bookings for a venue in a date range. */
router.get(
  "/:id/calendar",
  asyncHandler(async (req, res) => {
    const from = typeof req.query.from === "string" ? new Date(req.query.from) : new Date();
    const to = typeof req.query.to === "string" ? new Date(req.query.to) : dayjs(from).add(60, "day").toDate();
    const bookings = await prisma.venueBooking.findMany({
      where: { venueId: req.params.id, date: { gte: from, lte: to }, status: { in: ["INQUIRY", "CONFIRMED"] } },
      orderBy: { date: "asc" },
    });
    res.json(bookings);
  })
);

// ── Venue bookings ──
const bookingsInclude = { venue: { select: { name: true, maxCapacity: true } }, folio: { select: { id: true, status: true, invoiceNo: true } } } as const;

async function withFolioTotals(bookings: { folio: { id: string } | null }[]) {
  const withTotals = [];
  for (const b of bookings) {
    const f = b.folio ? await folioWithTotals(b.folio.id) : null;
    withTotals.push({ ...b, total: f?.total ?? 0, paid: f ? f.paid - f.refunded : 0, balance: f?.balance ?? 0 });
  }
  return withTotals;
}

router.get(
  "/bookings/list",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total] = await Promise.all([
        prisma.venueBooking.findMany({ include: bookingsInclude, orderBy: { date: "asc" }, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.venueBooking.count(),
      ]);
      return res.json({ rows: await withFolioTotals(rows), total, page, pageSize });
    }

    const bookings = await prisma.venueBooking.findMany({ include: bookingsInclude, orderBy: { date: "asc" } });
    res.json(await withFolioTotals(bookings));
  })
);

const bookingBody = z.object({
  venueId: z.string(),
  clientName: z.string().min(1),
  clientPhone: z.string().optional(),
  clientEmail: z.string().optional(),
  guestId: z.string().optional(), // link to a hotel guest profile if applicable
  eventType: z.string().optional(),
  date: z.string(),
  startTime: z.string().optional(),
  endTime: z.string().optional(),
  durationType: z.nativeEnum(DurationType),
  hours: z.number().min(0.5).optional(),
  guestCount: z.number().int().min(0).default(0),
  seating: z.string().optional(),
  avNeeds: z.string().optional(),
  decoration: z.string().optional(),
  cateringByHotel: z.boolean().default(false),
  notes: z.string().optional(),
  extras: z.array(z.object({ description: z.string().min(1), amount: z.number().int().min(0) })).default([]),
  confirm: z.boolean().default(false), // false = record as INQUIRY
});

router.post(
  "/bookings",
  asyncHandler(async (req, res) => {
    const body = bookingBody.parse(req.body);
    const venue = await prisma.venue.findUnique({ where: { id: body.venueId } });
    if (!venue) throw new ApiError(404, "Venue not found");
    if (body.guestCount > venue.maxCapacity) throw new ApiError(400, `Capacity of ${venue.name} is ${venue.maxCapacity}`);

    // Double-booking guard: same venue, same date, active booking
    const clash = await prisma.venueBooking.findFirst({
      where: { venueId: venue.id, date: new Date(body.date), status: "CONFIRMED" },
    });
    if (clash && body.confirm) throw new ApiError(409, `${venue.name} already has a confirmed booking on ${body.date} (${clash.code})`);

    // Rental price from venue's editable pricing (rental separate from catering)
    const rental =
      body.durationType === "FULL_DAY" ? venue.fullDayRate :
      body.durationType === "HALF_DAY" ? venue.halfDayRate :
      Math.round(venue.hourlyRate * (body.hours ?? 1));
    const extrasTotal = body.extras.reduce((s, e) => s + e.amount, 0);
    const depositPct = await getNum("billing.venue_deposit_pct", 25);
    const depositDue = Math.round(((rental + extrasTotal) * depositPct) / 100);

    const booking = await prisma.venueBooking.create({
      data: {
        code: await nextVenueBookingCode(),
        venueId: venue.id,
        guestId: body.guestId,
        clientName: body.clientName,
        clientPhone: body.clientPhone,
        clientEmail: body.clientEmail,
        eventType: body.eventType,
        date: new Date(body.date),
        startTime: body.startTime,
        endTime: body.endTime,
        durationType: body.durationType,
        hours: body.hours,
        guestCount: body.guestCount,
        seating: body.seating,
        avNeeds: body.avNeeds,
        decoration: body.decoration,
        cateringByHotel: body.cateringByHotel,
        notes: body.notes,
        status: body.confirm ? "CONFIRMED" : "INQUIRY",
        depositDue,
        folio: { create: { type: "VENUE" } }, // separate invoice type (§4.4)
      },
      include: { folio: true, venue: true },
    });

    // Venue rental + optional extras (catering/decoration/add-ons) as line items
    await prisma.folioLine.create({
      data: {
        folioId: booking.folio!.id, source: "VENUE",
        description: `${venue.name} — ${body.durationType === "HOURLY" ? `${body.hours ?? 1}h rental` : body.durationType === "HALF_DAY" ? "Half-day rental" : "Full-day rental"}`,
        qty: 1, unitPrice: rental, amount: rental, staffId: req.user!.id,
      },
    });
    for (const e of body.extras) {
      await prisma.folioLine.create({
        data: { folioId: booking.folio!.id, source: "VENUE", description: `${e.description} — optional extra`, qty: 1, unitPrice: e.amount, amount: e.amount, staffId: req.user!.id },
      });
    }

    audit(req.user!.id, "VENUE_BOOKING_CREATE", "VenueBooking", booking.id, { code: booking.code, rental, depositDue });
    const hotelName = await getStr("hotel.name", "Mount View Hotel");
    await notifyGuest(
      { email: body.clientEmail, phone: body.clientPhone },
      {
        type: "VENUE_CONFIRMATION",
        subject: `${body.confirm ? "Booking confirmed" : "Inquiry received"} — ${venue.name}, ${hotelName}`,
        body: `Dear ${body.clientName}, your ${body.eventType ?? "event"} at ${venue.name} on ${body.date} is ${body.confirm ? "confirmed" : "recorded as an inquiry"}. Deposit due: LKR ${(depositDue / 100).toLocaleString()}.`,
        refType: "VENUE_BOOKING", refId: booking.id,
      }
    );
    res.status(201).json(booking);
  })
);

router.get(
  "/bookings/:id",
  asyncHandler(async (req, res) => {
    const b = await prisma.venueBooking.findUnique({
      where: { id: req.params.id },
      include: { venue: true, guest: true, folio: { select: { id: true } } },
    });
    if (!b) throw new ApiError(404, "Venue booking not found");
    const folio = b.folio ? await folioWithTotals(b.folio.id) : null;
    res.json({ ...b, folio });
  })
);

router.put(
  "/bookings/:id",
  asyncHandler(async (req, res) => {
    const body = bookingBody.partial().omit({ venueId: true, extras: true, confirm: true }).parse(req.body);
    const b = await prisma.venueBooking.update({
      where: { id: req.params.id },
      data: { ...body, date: body.date ? new Date(body.date) : undefined },
    });
    res.json(b);
  })
);

/** Confirm an inquiry (requires deposit recorded or explicit override). */
router.post(
  "/bookings/:id/confirm",
  asyncHandler(async (req, res) => {
    const b = await prisma.venueBooking.findUnique({ where: { id: req.params.id }, include: { folio: true } });
    if (!b) throw new ApiError(404, "Venue booking not found");
    const clash = await prisma.venueBooking.findFirst({
      where: { venueId: b.venueId, date: b.date, status: "CONFIRMED", id: { not: b.id } },
    });
    if (clash) throw new ApiError(409, `Venue already confirmed for that date (${clash.code})`);
    const updated = await prisma.venueBooking.update({ where: { id: b.id }, data: { status: "CONFIRMED" } });
    audit(req.user!.id, "VENUE_BOOKING_CONFIRM", "VenueBooking", b.id);
    res.json(updated);
  })
);

/** Complete event → assign VNU invoice number, settle folio, loyalty accrual. */
router.post(
  "/bookings/:id/complete",
  asyncHandler(async (req, res) => {
    const b = await prisma.venueBooking.findUnique({ where: { id: req.params.id }, include: { folio: true } });
    if (!b?.folio) throw new ApiError(404, "Venue booking not found");
    const f = await folioWithTotals(b.folio.id);
    if (f.balance > 0) throw new ApiError(400, `Balance LKR ${(f.balance / 100).toFixed(2)} outstanding — collect payment first`);
    const invoiceNo = await nextInvoiceNo("VENUE");
    await prisma.$transaction([
      prisma.folio.update({ where: { id: b.folio.id }, data: { status: "SETTLED", invoiceNo, settledAt: new Date() } }),
      prisma.venueBooking.update({ where: { id: b.id }, data: { status: "COMPLETED" } }),
    ]);
    if (b.guestId) await accrueLoyalty(b.guestId, f.total, "VENUE", b.id, req.user!.id);
    audit(req.user!.id, "VENUE_BOOKING_COMPLETE", "VenueBooking", b.id, { invoiceNo });
    res.json({ invoiceNo });
  })
);

router.post(
  "/bookings/:id/cancel",
  asyncHandler(async (req, res) => {
    const body = z.object({ reason: z.string().min(1), refundMethod: z.nativeEnum(PaymentMethod).default("CASH") }).parse(req.body);
    const b = await prisma.venueBooking.findUnique({ where: { id: req.params.id }, include: { folio: true } });
    if (!b) throw new ApiError(404, "Venue booking not found");
    if (b.status === "COMPLETED" || b.status === "CANCELLED") throw new ApiError(400, `Booking is ${b.status}`);
    let refunded = 0;
    if (b.folio) {
      const f = await folioWithTotals(b.folio.id);
      const rules = [{ daysBefore: 7, refundPct: 100 }, { daysBefore: 0, refundPct: 0 }];
      const daysUntil = dayjs(b.date).diff(dayjs().startOf("day"), "day");
      const pct = rules.find((r) => daysUntil >= r.daysBefore)?.refundPct ?? 0;
      refunded = Math.round(((f.paid - f.refunded) * pct) / 100);
      if (refunded > 0)
        await recordPayment({
          folioId: b.folio.id, method: body.refundMethod, amount: refunded, kind: "REFUND",
          reason: `Venue cancellation (${daysUntil} days before): ${body.reason}`, staffId: req.user!.id,
        });
      await prisma.folio.update({ where: { id: b.folio.id }, data: { status: "VOID" } });
    }
    await prisma.venueBooking.update({ where: { id: b.id }, data: { status: "CANCELLED", cancelledAt: new Date(), cancelReason: body.reason } });
    audit(req.user!.id, "VENUE_BOOKING_CANCEL", "VenueBooking", b.id, { reason: body.reason, refunded });
    res.json({ ok: true, refunded });
  })
);

export default router;
