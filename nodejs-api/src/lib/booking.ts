import dayjs from "dayjs";
import { RoomType, SeasonalRate } from "@prisma/client";
import { prisma } from "./prisma";
import { getSetting } from "./settings";

export type Night = { date: string; rate: number; reason: string };

/** Every night of the stay [checkIn, checkOut). */
export function nights(checkIn: Date | string, checkOut: Date | string): string[] {
  const out: string[] = [];
  let d = dayjs(checkIn).startOf("day");
  const end = dayjs(checkOut).startOf("day");
  while (d.isBefore(end)) {
    out.push(d.format("YYYY-MM-DD"));
    d = d.add(1, "day");
  }
  return out;
}

/**
 * Seasonal/dynamic pricing (report §4.1): seasonal override wins, then
 * weekend rate on configured weekend days + public holidays, else weekday rate.
 */
export async function nightlyRate(
  roomType: Pick<RoomType, "weekdayRate" | "weekendRate">,
  seasonal: SeasonalRate[],
  dateStr: string
): Promise<{ rate: number; reason: string }> {
  const date = dayjs(dateStr);
  for (const s of seasonal) {
    if (!date.isBefore(dayjs(s.startDate), "day") && !date.isAfter(dayjs(s.endDate), "day"))
      return { rate: s.rate, reason: s.name };
  }
  const weekendDays = await getSetting<number[]>("pricing.weekend_days", [0, 6]);
  const holidays = await getSetting<string[]>("pricing.public_holidays", []);
  if (holidays.includes(dateStr)) return { rate: roomType.weekendRate, reason: "Public holiday" };
  if (weekendDays.includes(date.day())) return { rate: roomType.weekendRate, reason: "Weekend" };
  return { rate: roomType.weekdayRate, reason: "Weekday" };
}

export async function stayQuote(roomTypeId: string, checkIn: string, checkOut: string) {
  const rt = await prisma.roomType.findUniqueOrThrow({ where: { id: roomTypeId }, include: { seasonalRates: true } });
  const perNight: Night[] = [];
  for (const d of nights(checkIn, checkOut)) {
    const { rate, reason } = await nightlyRate(rt, rt.seasonalRates, d);
    perNight.push({ date: d, rate, reason });
  }
  return { roomType: rt.name, nights: perNight, total: perNight.reduce((s, n) => s + n.rate, 0) };
}

/** Rooms free for the whole [checkIn, checkOut) window and physically sellable. */
export async function availableRooms(checkIn: string, checkOut: string, excludeReservationId?: string) {
  const busy = await prisma.reservationRoom.findMany({
    where: {
      reservation: {
        id: excludeReservationId ? { not: excludeReservationId } : undefined,
        status: { in: ["PENDING", "CONFIRMED", "CHECKED_IN"] },
        checkIn: { lt: new Date(checkOut) },
        checkOut: { gt: new Date(checkIn) },
      },
    },
    select: { roomId: true },
  });
  const busyIds = new Set(busy.map((b) => b.roomId));
  const rooms = await prisma.room.findMany({
    where: { status: { not: "MAINTENANCE" } },
    include: { roomType: { include: { seasonalRates: true } } },
    orderBy: { number: "asc" },
  });
  return rooms.filter((r) => !busyIds.has(r.id));
}
