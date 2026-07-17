/**
 * Automated notification triggers — pre-arrival reminders (1 day before, setting),
 * venue payment reminders and pre-event reminders. Runs hourly in-process; can
 * also be fired manually from the Notifications screen.
 */
import dayjs from "dayjs";
import { prisma } from "./prisma";
import { getNum, getStr } from "./settings";
import { notify, notifyGuest } from "./notify";
import { folioWithTotals } from "./billing";

export async function runScheduledNotifications(): Promise<{ sent: number }> {
  let sent = 0;
  const hotelName = await getStr("hotel.name", "Mount View Hotel");
  const preDays = await getNum("notifications.pre_arrival_days", 1);

  // Pre-arrival reminders
  const target = dayjs().add(preDays, "day").startOf("day");
  const arrivals = await prisma.reservation.findMany({
    where: { status: "CONFIRMED", checkIn: { gte: target.toDate(), lt: target.add(1, "day").toDate() } },
    include: { guest: true, rooms: { include: { room: true } } },
  });
  for (const r of arrivals) {
    const already = await prisma.notification.findFirst({ where: { type: "PRE_ARRIVAL", refId: r.id } });
    if (already) continue;
    const checkInTime = await getStr("frontdesk.check_in_time", "14:00");
    await notifyGuest(r.guest, {
      type: "PRE_ARRIVAL",
      subject: `We look forward to welcoming you — ${hotelName}`,
      body: `Dear ${r.guest.name}, a reminder that your stay (${r.code}) begins on ${dayjs(r.checkIn).format("YYYY-MM-DD")}. Check-in from ${checkInTime}. You can speed things up with online pre-check-in.`,
      refType: "RESERVATION",
      refId: r.id,
    });
    sent++;
  }

  // Venue pre-event reminders (1 day before)
  const eventDay = dayjs().add(1, "day").startOf("day");
  const events = await prisma.venueBooking.findMany({
    where: { status: "CONFIRMED", date: { gte: eventDay.toDate(), lt: eventDay.add(1, "day").toDate() } },
    include: { venue: true },
  });
  for (const b of events) {
    const already = await prisma.notification.findFirst({ where: { type: "VENUE_PRE_EVENT", refId: b.id } });
    if (already) continue;
    await notifyGuest(
      { email: b.clientEmail, phone: b.clientPhone },
      {
        type: "VENUE_PRE_EVENT",
        subject: `Your event at ${b.venue.name} is tomorrow — ${hotelName}`,
        body: `Dear ${b.clientName}, a reminder of your ${b.eventType ?? "event"} at ${b.venue.name} tomorrow (${b.startTime ?? ""}–${b.endTime ?? ""}). Expected guests: ${b.guestCount}.`,
        refType: "VENUE_BOOKING",
        refId: b.id,
      }
    );
    sent++;
  }

  // Venue payment reminders (7 days before, if balance outstanding)
  const payDay = dayjs().add(7, "day").startOf("day");
  const upcoming = await prisma.venueBooking.findMany({
    where: { status: "CONFIRMED", date: { gte: payDay.toDate(), lt: payDay.add(1, "day").toDate() } },
    include: { venue: true, folio: true },
  });
  for (const b of upcoming) {
    if (!b.folio) continue;
    const f = await folioWithTotals(b.folio.id);
    if (f.balance <= 0) continue;
    const already = await prisma.notification.findFirst({ where: { type: "VENUE_PAYMENT_REMINDER", refId: b.id } });
    if (already) continue;
    await notifyGuest(
      { email: b.clientEmail, phone: b.clientPhone },
      {
        type: "VENUE_PAYMENT_REMINDER",
        subject: `Payment reminder — ${b.venue.name} on ${dayjs(b.date).format("YYYY-MM-DD")}`,
        body: `Dear ${b.clientName}, the outstanding balance for your event is LKR ${(f.balance / 100).toLocaleString()}. Please settle before the event date.`,
        refType: "VENUE_BOOKING",
        refId: b.id,
      }
    );
    sent++;
  }

  // Food expiry alert — one summary per day to the manager (report kitchen hygiene)
  const warnDays = await getNum("inventory.expiry_warn_days", 3);
  const cutoff = dayjs().startOf("day").add(warnDays, "day").toDate();
  const expiring = await prisma.ingredientBatch.findMany({
    where: { qty: { gt: 0 }, expiryDate: { not: null, lte: cutoff } },
    include: { ingredient: { select: { name: true, unit: true } } },
    orderBy: { expiryDate: "asc" },
  });
  if (expiring.length > 0) {
    const todayStart = dayjs().startOf("day").toDate();
    const alreadyToday = await prisma.notification.findFirst({ where: { type: "FOOD_EXPIRY", createdAt: { gte: todayStart } } });
    if (!alreadyToday) {
      const lines = expiring.map((b) => {
        const days = dayjs(b.expiryDate).startOf("day").diff(dayjs().startOf("day"), "day");
        return `- ${b.ingredient.name}: ${b.qty}${b.ingredient.unit} ${days < 0 ? `EXPIRED ${-days}d ago` : days === 0 ? "expires TODAY" : `expires in ${days}d`}`;
      });
      const hotelEmail = await getStr("hotel.email", "manager@mountview.lk");
      await notify({
        type: "FOOD_EXPIRY",
        channel: "EMAIL",
        to: hotelEmail,
        subject: `Food expiry alert — ${expiring.length} batch(es) need attention`,
        body: `The following ingredient batches are expired or expiring soon:\n${lines.join("\n")}\n\nWrite off spoiled stock from the Inventory screen.`,
      });
      sent++;
    }
  }

  return { sent };
}
