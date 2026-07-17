/**
 * Unauthenticated guest-facing endpoints:
 *  - branding for public pages
 *  - online pre-check-in (guest submits details before arrival, §4.1)
 *  - venue inquiry form for outside customers (§4.5)
 * TODO(Phase2): website booking engine + Booking.com channel sync live here too.
 */
import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { getStr, getNum } from "../lib/settings";
import { nextVenueBookingCode } from "../lib/codes";
import { notify } from "../lib/notify";

const router = Router();

router.get(
  "/branding",
  asyncHandler(async (_req, res) => {
    res.json({
      name: await getStr("hotel.name", "Mount View Hotel, Badulla"),
      address: await getStr("hotel.address", ""),
      phone: await getStr("hotel.phone", ""),
      email: await getStr("hotel.email", ""),
      checkInTime: await getStr("frontdesk.check_in_time", "14:00"),
      checkOutTime: await getStr("frontdesk.check_out_time", "12:00"),
      usdRate: await getNum("currency.usd_rate", 300),
    });
  })
);

/** Guest looks up their booking by code + phone/email fragment, then submits details. */
router.post(
  "/pre-checkin",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        code: z.string().min(3),
        idNumber: z.string().min(3),
        fullName: z.string().min(1),
        phone: z.string().optional(),
        email: z.string().optional(),
        nationality: z.string().optional(),
        eta: z.string().optional(),
        notes: z.string().optional(),
      })
      .parse(req.body);
    const r = await prisma.reservation.findUnique({ where: { code: body.code.toUpperCase().trim() }, include: { guest: true } });
    if (!r || (r.status !== "CONFIRMED" && r.status !== "PENDING"))
      throw new ApiError(404, "Booking not found or not awaiting arrival — check your booking code");
    await prisma.reservation.update({
      where: { id: r.id },
      data: { preCheckIn: { ...body, submittedAt: new Date().toISOString() } },
    });
    // Pre-fill the guest profile so front desk check-in is instant
    await prisma.guest.update({
      where: { id: r.guestId },
      data: {
        idNumber: body.idNumber,
        phone: body.phone || r.guest.phone,
        email: body.email || r.guest.email,
        nationality: body.nationality || r.guest.nationality,
      },
    });
    res.json({ ok: true, message: "Pre-check-in received — see you soon!" });
  })
);

/** Venue inquiry from an outside customer → recorded as INQUIRY for the manager. */
router.get(
  "/venues",
  asyncHandler(async (_req, res) => {
    const venues = await prisma.venue.findMany({
      where: { active: true },
      select: { id: true, name: true, maxCapacity: true, facilities: true, hourlyRate: true, halfDayRate: true, fullDayRate: true },
      orderBy: { name: "asc" },
    });
    res.json(venues);
  })
);

router.post(
  "/venue-inquiry",
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        venueId: z.string(),
        clientName: z.string().min(1),
        clientPhone: z.string().min(5),
        clientEmail: z.string().optional(),
        eventType: z.string().optional(),
        date: z.string(),
        guestCount: z.number().int().min(1),
        notes: z.string().optional(),
      })
      .parse(req.body);
    const venue = await prisma.venue.findUnique({ where: { id: body.venueId } });
    if (!venue) throw new ApiError(404, "Venue not found");
    if (body.guestCount > venue.maxCapacity) throw new ApiError(400, `Maximum capacity of ${venue.name} is ${venue.maxCapacity} guests`);
    const booking = await prisma.venueBooking.create({
      data: {
        code: await nextVenueBookingCode(),
        venueId: venue.id,
        clientName: body.clientName,
        clientPhone: body.clientPhone,
        clientEmail: body.clientEmail,
        eventType: body.eventType,
        date: new Date(body.date),
        durationType: "FULL_DAY",
        guestCount: body.guestCount,
        notes: `${body.notes ?? ""} [Submitted via public inquiry form]`,
        status: "INQUIRY",
        folio: { create: { type: "VENUE" } },
      },
    });
    const hotelEmail = await getStr("hotel.email", "manager@mountview.lk");
    await notify({
      type: "VENUE_INQUIRY_RECEIVED",
      channel: "EMAIL",
      to: hotelEmail,
      subject: `New venue inquiry — ${venue.name} on ${body.date}`,
      body: `${body.clientName} (${body.clientPhone}) asked about ${venue.name} for ${body.guestCount} guests on ${body.date}. Reference: ${booking.code}`,
      refType: "VENUE_BOOKING",
      refId: booking.id,
    });
    res.status(201).json({ ok: true, reference: booking.code, message: "Inquiry received — our events team will contact you shortly." });
  })
);

export default router;
