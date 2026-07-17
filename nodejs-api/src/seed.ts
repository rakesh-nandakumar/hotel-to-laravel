/**
 * Mount View Hotel — database seed.
 * Idempotent-ish: with --if-empty it exits when settings already exist.
 * All money in LKR cents. Values marked "⚠ confirm" are placeholders pending owner.
 */
import "dotenv/config";
import { PrismaClient, Role, SettingType } from "@prisma/client";
import bcrypt from "bcryptjs";
import dayjs from "dayjs";
import { INTEGRATION_SETTINGS, CHANNELS_SETTING, DEFAULT_LAUNDRY_ITEMS } from "./lib/bootstrap";

const prisma = new PrismaClient();
const LKR = (rupees: number) => Math.round(rupees * 100); // → cents

// ── §4.6 default checklists ──────────────────────────────────────────────────
export const ROOM_ITEM_CHECKLIST = [
  "Bed linen, pillows & cushions",
  "Bath towels, hand towels & face towels",
  "TV & remote control",
  "AC unit & remote control",
  "Hangers",
  "Electric kettle & cups/glasses",
  "Toiletries (soap, shampoo, toilet paper)",
  "Slippers",
  "Minibar contents (if applicable)",
  "In-room safe (if applicable)",
  "Curtains & window fittings",
  "Light bulbs / lamps functioning",
  "Bathroom fittings (shower, tap, flush) in working order",
  "WiFi info card / Do Not Disturb sign",
];

export const ROOM_CLEANING_CHECKLIST = [
  "Strip used linen and remake bed with fresh linen",
  "Replace used towels with fresh ones",
  "Dust all surfaces, furniture, and fittings",
  "Vacuum/mop the floor",
  "Clean bathroom — toilet, shower/tub, sink, mirror",
  "Restock toiletries and guest amenities",
  "Empty and reline trash bins",
  "Restock/check minibar items",
  "Clean windows, mirrors, and glass surfaces",
  "Check AC, TV, and lights are functioning",
  "Check for and log any damage or maintenance issue found",
  "Final inspection and mark room status as Clean/Ready in the system",
];

type SettingSeed = {
  key: string;
  value: unknown;
  type: SettingType;
  category: string;
  label: string;
  hint?: string;
};

const SETTINGS: SettingSeed[] = [
  // Hotel identity
  { key: "hotel.name", value: "Mount View Hotel, Badulla", type: "TEXT", category: "hotel", label: "Hotel name" },
  { key: "hotel.address", value: "Address pending — ⚠ confirm with owner, Badulla, Sri Lanka", type: "TEXT", category: "hotel", label: "Address (invoices/receipts)", hint: "⚠ confirm with owner" },
  { key: "hotel.phone", value: "+94 XX XXX XXXX ⚠ confirm", type: "TEXT", category: "hotel", label: "Contact number" },
  { key: "hotel.email", value: "info@mountviewhotel.lk ⚠ confirm", type: "TEXT", category: "hotel", label: "Official email" },
  { key: "hotel.tax_reg_no", value: "TAX-REG-PENDING ⚠ confirm", type: "TEXT", category: "hotel", label: "Tax registration no." },
  { key: "hotel.website", value: "https://mountviewhotel.lk (domain purchased, not live)", type: "TEXT", category: "hotel", label: "Website domain" },
  { key: "hotel.logo_url", value: "", type: "TEXT", category: "hotel", label: "Logo image URL", hint: "Owner has a logo file ready — upload and paste URL/path" },
  // Front desk
  { key: "frontdesk.check_in_time", value: "14:00", type: "TIME", category: "frontdesk", label: "Check-in time" },
  { key: "frontdesk.check_out_time", value: "12:00", type: "TIME", category: "frontdesk", label: "Check-out time" },
  { key: "billing.early_checkin_surcharge", value: 0, type: "MONEY", category: "billing", label: "Early check-in surcharge (LKR cents)", hint: "0 = no surcharge; owner sets" },
  { key: "billing.late_checkout_surcharge", value: 0, type: "MONEY", category: "billing", label: "Late check-out surcharge (LKR cents)", hint: "0 = no surcharge; owner sets" },
  // Taxes & deposits
  { key: "billing.vat_pct", value: 0, type: "PERCENT", category: "billing", label: "VAT %", hint: "Separate line item on every bill. ⚠ owner to set" },
  { key: "billing.service_charge_pct", value: 0, type: "PERCENT", category: "billing", label: "Service charge %", hint: "Separate line item on every bill. ⚠ owner to set" },
  { key: "billing.room_deposit_pct", value: 20, type: "PERCENT", category: "billing", label: "Room advance deposit %" },
  { key: "billing.venue_deposit_pct", value: 25, type: "PERCENT", category: "billing", label: "Venue advance deposit %" },
  // Currency
  { key: "currency.usd_rate", value: 300, type: "NUMBER", category: "currency", label: "LKR per 1 USD (display only)", hint: "Settlement is always LKR. Owner updates periodically." },
  // Policies
  { key: "policies.children_free_under_age", value: 4, type: "NUMBER", category: "policies", label: "Children free under age" },
  { key: "policies.parking_capacity", value: 10, type: "NUMBER", category: "policies", label: "Guest parking capacity (vehicles)" },
  { key: "policies.wifi", value: "WiFi policy pending — ⚠ confirm with owner", type: "TEXT", category: "policies", label: "Guest WiFi policy" },
  { key: "policies.cancellation_text", value: "Free cancellation up to 7 days before check-in; 50% refund 3–7 days; no refund within 3 days. ⚠ confirm with owner", type: "TEXT", category: "policies", label: "Cancellation / refund policy (printed text)" },
  {
    key: "policies.cancellation_rules",
    value: [
      { daysBefore: 7, refundPct: 100 },
      { daysBefore: 3, refundPct: 50 },
      { daysBefore: 0, refundPct: 0 },
    ],
    type: "JSON", category: "policies", label: "Cancellation refund rules",
    hint: "Applied automatically on cancellation: refund % of monies paid by days before check-in (first matching rule, sorted desc).",
  },
  // Loyalty
  { key: "loyalty.points_per_1000lkr", value: 10, type: "NUMBER", category: "loyalty", label: "Loyalty points earned per LKR 1,000 spent", hint: "⚠ owner to confirm earn rate" },
  { key: "loyalty.point_value_cents", value: 100, type: "MONEY", category: "loyalty", label: "Redemption value of 1 point (LKR cents)", hint: "Default: 1 point = LKR 1" },
  {
    key: "loyalty.redemption_catalog",
    value: [
      { name: "LKR 500 off restaurant bill", points: 500 },
      { name: "LKR 2,500 off room booking", points: 2500 },
      { name: "Free Ceylon Tea", points: 300 },
    ],
    type: "JSON", category: "loyalty", label: "Redemption catalog", hint: "⚠ owner to confirm",
  },
  // Pricing
  { key: "pricing.weekend_days", value: [0, 6], type: "JSON", category: "pricing", label: "Weekend days (0=Sun … 6=Sat)" },
  { key: "pricing.public_holidays", value: ["2026-12-25", "2027-01-01", "2027-02-04"], type: "JSON", category: "pricing", label: "Public holidays (charged at weekend rate)" },
  // Notifications
  { key: "notifications.pre_arrival_days", value: 1, type: "NUMBER", category: "notifications", label: "Pre-arrival reminder (days before check-in)" },
];

async function main() {
  const ifEmpty = process.argv.includes("--if-empty");
  const existing = await prisma.setting.count();
  if (existing > 0 && ifEmpty) {
    console.log("Seed skipped — database already has data.");
    return;
  }
  if (existing > 0 && !process.argv.includes("--force")) {
    console.log("Database not empty. Re-run with --force to wipe and reseed.");
    return;
  }
  if (existing > 0) {
    console.log("Wiping database…");
    // Delete in dependency order
    const tables = [
      "NightAudit", "VisitorLog", "AuditLog", "Notification", "LoyaltyTransaction",
      "Payment", "FolioLine", "Folio", "OrderItem", "Order",
      "RoomItemCheck", "HousekeepingTask", "MaintenanceIssue",
      "ReservationRoom", "Reservation", "GroupBooking", "VenueBooking",
      "RecipeItem", "MenuItem", "MenuCategory", "Ingredient",
      "SeasonalRate", "Room", "RoomType", "Package", "Venue",
      "CorporateAccount", "Guest", "Shift", "Attendance", "User", "Setting", "Property",
    ];
    for (const t of tables) await prisma.$executeRawUnsafe(`DELETE FROM "${t}"`);
  }

  console.log("Seeding Mount View Hotel…");
  const property = await prisma.property.create({ data: { name: "Mount View Hotel, Badulla" } });

  // Settings (business + admin-only integrations + channel preference)
  for (const s of [...SETTINGS, ...INTEGRATION_SETTINGS, CHANNELS_SETTING]) {
    await prisma.setting.create({
      data: { key: s.key, value: JSON.stringify(s.value), type: s.type, category: s.category, label: s.label, hint: s.hint },
    });
  }

  // Staff — default logins documented in README
  const hash = (s: string) => bcrypt.hashSync(s, 10);
  const mkUser = (name: string, email: string, role: Role, password: string, pin: string) =>
    prisma.user.create({ data: { name, email, role, passwordHash: hash(password), pinHash: hash(pin), propertyId: property.id } });

  await mkUser("System Admin", "admin@mountview.lk", "SYSTEM_ADMIN", "admin123", "0000");
  const owner = await mkUser("Mr. Perera (Owner)", "owner@mountview.lk", "OWNER", "owner123", "1111");
  const manager = await mkUser("Nimal (Manager)", "manager@mountview.lk", "MANAGER", "manager123", "2222");
  const housekeeper = await mkUser("Kumari (Housekeeper)", "housekeeping@mountview.lk", "HOUSEKEEPER", "house123", "3333");
  const chef = await mkUser("Sunil (Chef)", "chef@mountview.lk", "CHEF", "chef123", "4444");
  await mkUser("Bandara (Security)", "security@mountview.lk", "SECURITY", "security123", "5555");

  // ── Room types (§8) — rates/occupancy are ⚠ placeholders, editable in UI ──
  const defaultAmenities = ["AC", "TV", "WiFi", "Hot water"]; // ⚠ pending from owner
  const roomTypeData = [
    { name: "Family 4-Person", maxOccupancy: 4, weekday: 18000, weekend: 22000, rooms: ["110", "111", "112"] },
    { name: "Family Special", maxOccupancy: 5, weekday: 25000, weekend: 30000, rooms: ["101"] },
    { name: "Two-Person Room", maxOccupancy: 2, weekday: 12000, weekend: 15000, rooms: ["102", "103", "104", "105", "115", "116"] },
    { name: "Special Couple Room", maxOccupancy: 2, weekday: 16000, weekend: 20000, rooms: ["114"] },
    { name: "Triple Room", maxOccupancy: 3, weekday: 15000, weekend: 18000, rooms: ["106", "107"] },
  ];

  const roomsByNumber: Record<string, { id: string; roomTypeId: string }> = {};
  for (const rt of roomTypeData) {
    const roomType = await prisma.roomType.create({
      data: {
        name: rt.name,
        maxOccupancy: rt.maxOccupancy, // ⚠ pending from owner
        bedConfig: "TBC — pending from owner",
        amenities: defaultAmenities,
        weekdayRate: LKR(rt.weekday),
        weekendRate: LKR(rt.weekend),
        itemChecklist: ROOM_ITEM_CHECKLIST,
        cleaningChecklist: ROOM_CLEANING_CHECKLIST,
      },
    });
    // December peak override (sample seasonal rate — editable)
    await prisma.seasonalRate.create({
      data: {
        roomTypeId: roomType.id,
        name: "December Peak",
        startDate: new Date("2026-12-15"),
        endDate: new Date("2027-01-05"),
        rate: Math.round(LKR(rt.weekend) * 1.2),
      },
    });
    for (const num of rt.rooms) {
      const room = await prisma.room.create({
        data: {
          number: num,
          roomTypeId: roomType.id,
          floor: num.startsWith("11") ? "Upper" : "Ground",
          view: "Hill view",
          amenities: defaultAmenities,
          propertyId: property.id,
        },
      });
      roomsByNumber[num] = { id: room.id, roomTypeId: roomType.id };
    }
  }

  // Laundry price list (⚠ placeholder prices — Manager edits in UI)
  await prisma.laundryItem.createMany({ data: DEFAULT_LAUNDRY_ITEMS });

  // Packages
  const packages = await Promise.all([
    prisma.package.create({ data: { code: "RO", name: "Room Only", mealInclusions: [] } }),
    prisma.package.create({ data: { code: "BB", name: "Bed & Breakfast", pricePerPersonPerNight: LKR(1500), mealInclusions: ["Breakfast"] } }),
    prisma.package.create({ data: { code: "HB", name: "Half Board", pricePerPersonPerNight: LKR(3500), mealInclusions: ["Breakfast", "Dinner"] } }),
    prisma.package.create({ data: { code: "FB", name: "Full Board", pricePerPersonPerNight: LKR(5000), mealInclusions: ["Breakfast", "Lunch", "Dinner"] } }),
  ]);
  const pkgBB = packages[1];

  // ── Venues (§9) — pricing/facilities editable ⚠ ──
  const venueData = [
    { name: "Wedding Hall 1 (Lower)", maxCapacity: 300, hourly: 20000, half: 75000, full: 120000 },
    { name: "Wedding Hall 2 (Upper)", maxCapacity: 200, hourly: 15000, half: 60000, full: 100000 },
    { name: "Rooftop", maxCapacity: 200, hourly: 12000, half: 45000, full: 80000 },
  ];
  const venues = [];
  for (const v of venueData) {
    venues.push(await prisma.venue.create({
      data: {
        name: v.name, maxCapacity: v.maxCapacity,
        facilities: ["Stage", "Sound system — ⚠ confirm", "Kitchen access — ⚠ confirm", "Shared parking (10 vehicles)"],
        hourlyRate: LKR(v.hourly), halfDayRate: LKR(v.half), fullDayRate: LKR(v.full),
        propertyId: property.id,
      },
    }));
  }

  // ── Menu, ingredients, recipes ──
  const cats: Record<string, string> = {};
  const catDefs = [
    { name: "Starters", sortOrder: 1 },
    { name: "Main Course", sortOrder: 2 },
    { name: "Beverages", sortOrder: 3 },
    { name: "Desserts", sortOrder: 4 },
    { name: "Minibar", sortOrder: 5, isMinibar: true },
  ];
  for (const c of catDefs) {
    const cat = await prisma.menuCategory.create({ data: c });
    cats[c.name] = cat.id;
  }

  const ing: Record<string, string> = {};
  const ingredientDefs: [string, string, number, number][] = [
    // name, unit, stock, lowThreshold
    ["Chicken", "g", 20000, 3000],
    ["Fish", "g", 10000, 2000],
    ["Rice", "g", 50000, 10000],
    ["Godamba Roti", "pcs", 60, 15],
    ["Dhal", "g", 10000, 2000],
    ["Onion", "pcs", 100, 20],
    ["Tomato", "pcs", 80, 20],
    ["Coconut Milk", "ml", 15000, 3000],
    ["Egg", "pcs", 120, 30],
    ["Potato", "g", 20000, 4000],
    ["Cooking Oil", "ml", 20000, 4000],
    ["Tea Leaves", "g", 3000, 500],
    ["Milk", "ml", 20000, 4000],
    ["Sugar", "g", 10000, 2000],
    ["Lime", "pcs", 60, 15],
    ["Jaggery", "g", 5000, 1000],
    ["Curd", "ml", 8000, 2000],
  ];
  for (const [name, unit, stockQty, low] of ingredientDefs) {
    const i = await prisma.ingredient.create({ data: { name, unit, stockQty, lowStockThreshold: low } });
    ing[name] = i.id;
  }
  // Demo expiry batches: one expired, one expiring soon, one fine
  const day0 = dayjs().startOf("day");
  await prisma.ingredientBatch.createMany({
    data: [
      { ingredientId: ing["Milk"], qty: 5000, initialQty: 5000, expiryDate: day0.subtract(1, "day").toDate(), note: "Weekly dairy delivery" },
      { ingredientId: ing["Chicken"], qty: 8000, initialQty: 8000, expiryDate: day0.add(2, "day").toDate(), note: "Market purchase" },
      { ingredientId: ing["Fish"], qty: 6000, initialQty: 6000, expiryDate: day0.add(6, "day").toDate(), note: "Fresh catch" },
    ],
  });

  type MenuDef = { name: string; cat: string; price: number; recipe?: [string, number][] };
  const menuDefs: MenuDef[] = [
    { name: "Vegetable Spring Rolls", cat: "Starters", price: 900, recipe: [["Cooking Oil", 50], ["Onion", 1], ["Potato", 100]] },
    { name: "Devilled Chicken (Starter)", cat: "Starters", price: 1500, recipe: [["Chicken", 200], ["Onion", 1], ["Tomato", 1], ["Cooking Oil", 30]] },
    { name: "French Fries", cat: "Starters", price: 800, recipe: [["Potato", 250], ["Cooking Oil", 60]] },
    { name: "Chicken Kottu", cat: "Main Course", price: 1800, recipe: [["Godamba Roti", 2], ["Chicken", 150], ["Egg", 1], ["Onion", 1], ["Cooking Oil", 40]] },
    { name: "Vegetable Kottu", cat: "Main Course", price: 1400, recipe: [["Godamba Roti", 2], ["Onion", 1], ["Tomato", 1], ["Cooking Oil", 40]] },
    { name: "Chicken Fried Rice", cat: "Main Course", price: 1600, recipe: [["Rice", 300], ["Chicken", 120], ["Egg", 1], ["Cooking Oil", 40]] },
    { name: "Rice & Curry (Chicken)", cat: "Main Course", price: 1200, recipe: [["Rice", 300], ["Chicken", 150], ["Dhal", 80], ["Coconut Milk", 100]] },
    { name: "Rice & Curry (Fish)", cat: "Main Course", price: 1300, recipe: [["Rice", 300], ["Fish", 150], ["Dhal", 80], ["Coconut Milk", 100]] },
    { name: "Fish Ambul Thiyal with Rice", cat: "Main Course", price: 1700, recipe: [["Fish", 200], ["Rice", 300]] },
    { name: "Egg Hoppers (3 pcs)", cat: "Main Course", price: 700, recipe: [["Egg", 3], ["Coconut Milk", 150]] },
    { name: "Ceylon Tea", cat: "Beverages", price: 300, recipe: [["Tea Leaves", 5], ["Sugar", 10]] },
    { name: "Milk Tea", cat: "Beverages", price: 400, recipe: [["Tea Leaves", 5], ["Milk", 100], ["Sugar", 15]] },
    { name: "Fresh Lime Juice", cat: "Beverages", price: 500, recipe: [["Lime", 2], ["Sugar", 20]] },
    { name: "Soft Drink (330ml)", cat: "Beverages", price: 400 },
    { name: "Watalappan", cat: "Desserts", price: 650, recipe: [["Egg", 2], ["Coconut Milk", 100], ["Jaggery", 60]] },
    { name: "Curd & Treacle", cat: "Desserts", price: 700, recipe: [["Curd", 200], ["Jaggery", 40]] },
    { name: "Ice Cream (2 scoops)", cat: "Desserts", price: 500 },
    { name: "Mineral Water 500ml (Minibar)", cat: "Minibar", price: 250 },
    { name: "Soft Drink (Minibar)", cat: "Minibar", price: 500 },
    { name: "Chocolate Bar (Minibar)", cat: "Minibar", price: 800 },
  ];
  const menu: Record<string, { id: string; price: number }> = {};
  for (const [idx, m] of menuDefs.entries()) {
    const item = await prisma.menuItem.create({
      data: {
        name: m.name, categoryId: cats[m.cat], price: LKR(m.price), itemNo: idx + 1,
        recipe: m.recipe ? { create: m.recipe.map(([n, qty]) => ({ ingredientId: ing[n], qty })) } : undefined,
      },
    });
    menu[m.name] = { id: item.id, price: LKR(m.price) };
  }

  // ── Guests ──
  const gSilva = await prisma.guest.create({
    data: {
      name: "Ruwan Silva", email: "ruwan.silva@example.com", phone: "+94 77 123 4567",
      idNumber: "199012345678", nationality: "Sri Lankan", preferences: "Upper floor, extra pillows",
      loyaltyPoints: 450, lifetimeSpend: LKR(45000),
    },
  });
  const gSmith = await prisma.guest.create({
    data: { name: "Emily Smith", email: "emily.smith@example.com", phone: "+44 7700 900123", idNumber: "GB-P-55511122", nationality: "British" },
  });
  const gFernando = await prisma.guest.create({
    data: { name: "Dilani Fernando", email: "dilani.f@example.com", phone: "+94 71 555 8899", nationality: "Sri Lankan" },
  });
  const gTours = await prisma.guest.create({
    data: { name: "Ceylon Tea Tours — Group Leader", email: "ops@ceylonteatours.lk", phone: "+94 11 234 5678", nationality: "Sri Lankan" },
  });

  const corp = await prisma.corporateAccount.create({
    data: {
      companyName: "Ceylon Tea Tours (Pvt) Ltd", contactName: "Asanka Perera",
      phone: "+94 11 234 5678", email: "ops@ceylonteatours.lk", address: "Colombo 03",
      discountPct: 10, creditLimit: LKR(500000),
    },
  });

  // ── Demo reservations ──
  const today = dayjs().startOf("day");
  const D = (d: dayjs.Dayjs) => d.toDate();

  // 1) Ruwan Silva — checked in yesterday, 3 nights in room 102, BB, restaurant order charged
  const r102 = roomsByNumber["102"];
  const rate102 = LKR(12000);
  const res1 = await prisma.reservation.create({
    data: {
      code: "RSV-0001", guestId: gSilva.id, channel: "PHONE", status: "CHECKED_IN",
      checkIn: D(today.subtract(1, "day")), checkOut: D(today.add(2, "day")),
      adults: 2, children: 0, packageId: pkgBB.id,
      depositDue: Math.round(3 * (rate102 + 2 * pkgBB.pricePerPersonPerNight) * 0.2),
      checkedInAt: today.subtract(1, "day").add(14, "hour").toDate(),
      rooms: { create: [{ roomId: r102.id, nightlyRate: rate102 }] },
    },
  });
  await prisma.room.update({ where: { id: r102.id }, data: { status: "OCCUPIED" } });

  const folio1 = await prisma.folio.create({ data: { type: "GUEST", reservationId: res1.id } });
  // Room + package lines for 3 nights (VAT/SC are 0 by default settings)
  for (let n = 0; n < 3; n++) {
    const night = today.subtract(1, "day").add(n, "day");
    await prisma.folioLine.create({
      data: {
        folioId: folio1.id, source: "ROOM", description: `Room 102 — ${night.format("YYYY-MM-DD")}`,
        qty: 1, unitPrice: rate102, amount: rate102, staffId: manager.id,
      },
    });
    await prisma.folioLine.create({
      data: {
        folioId: folio1.id, source: "PACKAGE", description: `Bed & Breakfast × 2 pax — ${night.format("YYYY-MM-DD")}`,
        qty: 2, unitPrice: pkgBB.pricePerPersonPerNight, amount: 2 * pkgBB.pricePerPersonPerNight, staffId: manager.id,
      },
    });
  }
  // Deposit paid at booking
  await prisma.payment.create({
    data: { kind: "DEPOSIT", method: "CASH", amount: res1.depositDue, folioId: folio1.id, staffId: manager.id },
  });
  // Restaurant order charged to room (last night)
  const ord1Items = [
    { m: "Chicken Kottu", qty: 2 },
    { m: "Fresh Lime Juice", qty: 2 },
    { m: "Watalappan", qty: 1 },
  ];
  const ord1Sub = ord1Items.reduce((s, it) => s + menu[it.m].price * it.qty, 0);
  const ord1 = await prisma.order.create({
    data: {
      type: "ROOM_GUEST", status: "CHARGED_TO_ROOM", kotStatus: "SERVED",
      roomId: r102.id, reservationId: res1.id, staffId: manager.id,
      subtotal: ord1Sub, total: ord1Sub, settledAt: today.subtract(1, "day").add(20, "hour").toDate(),
      items: {
        create: ord1Items.map((it) => ({
          menuItemId: menu[it.m].id, name: it.m, qty: it.qty,
          unitPrice: menu[it.m].price, amount: menu[it.m].price * it.qty,
        })),
      },
    },
  });
  await prisma.folioLine.create({
    data: {
      folioId: folio1.id, source: "RESTAURANT", description: `Restaurant Order #${ord1.orderNo}`,
      qty: 1, unitPrice: ord1Sub, amount: ord1Sub, orderId: ord1.id, staffId: manager.id,
    },
  });
  // Minibar charge
  await prisma.folioLine.create({
    data: {
      folioId: folio1.id, source: "MINIBAR", description: "Minibar — Mineral Water 500ml × 2",
      qty: 2, unitPrice: menu["Mineral Water 500ml (Minibar)"].price,
      amount: 2 * menu["Mineral Water 500ml (Minibar)"].price, staffId: manager.id,
    },
  });

  // 2) Emily Smith — arriving tomorrow (shows in Arrivals), room 114, deposit unpaid
  const r114 = roomsByNumber["114"];
  const res2 = await prisma.reservation.create({
    data: {
      code: "RSV-0002", guestId: gSmith.id, channel: "BOOKING_COM", status: "CONFIRMED",
      checkIn: D(today.add(1, "day")), checkOut: D(today.add(4, "day")),
      adults: 2, packageId: pkgBB.id, depositDue: Math.round(3 * LKR(16000) * 0.2),
      rooms: { create: [{ roomId: r114.id, nightlyRate: LKR(16000) }] },
    },
  });
  await prisma.folio.create({ data: { type: "GUEST", reservationId: res2.id } });

  // 3) Dilani Fernando — checked out this morning, folio settled, housekeeping task done for 104
  const r104 = roomsByNumber["104"];
  const res3 = await prisma.reservation.create({
    data: {
      code: "RSV-0003", guestId: gFernando.id, channel: "WALKIN", status: "CHECKED_OUT",
      checkIn: D(today.subtract(2, "day")), checkOut: D(today),
      adults: 1, depositDue: 0,
      checkedInAt: today.subtract(2, "day").add(15, "hour").toDate(),
      checkedOutAt: today.add(11, "hour").toDate(),
      rooms: { create: [{ roomId: r104.id, nightlyRate: LKR(12000) }] },
    },
  });
  const folio3 = await prisma.folio.create({
    data: { type: "GUEST", reservationId: res3.id, status: "SETTLED", invoiceNo: "INV-2026-0001", settledAt: today.add(11, "hour").toDate() },
  });
  for (let n = 0; n < 2; n++) {
    await prisma.folioLine.create({
      data: {
        folioId: folio3.id, source: "ROOM",
        description: `Room 104 — ${today.subtract(2, "day").add(n, "day").format("YYYY-MM-DD")}`,
        qty: 1, unitPrice: LKR(12000), amount: LKR(12000), staffId: manager.id,
      },
    });
  }
  await prisma.payment.create({
    data: { method: "CARD", amount: 2 * LKR(12000), folioId: folio3.id, staffId: manager.id, reference: "CARD-SLIP-2214" },
  });
  await prisma.loyaltyTransaction.create({
    data: { guestId: gFernando.id, points: 240, reason: "Stay RSV-0003 spend", refType: "FOLIO", refId: folio3.id, staffId: manager.id },
  });
  await prisma.guest.update({ where: { id: gFernando.id }, data: { loyaltyPoints: 240, lifetimeSpend: 2 * LKR(12000) } });
  // Housekeeping task auto-created on checkout — still pending, so room 104 is DIRTY
  await prisma.room.update({ where: { id: r104.id }, data: { status: "DIRTY" } });
  await prisma.housekeepingTask.create({
    data: {
      roomId: r104.id, assignedToId: housekeeper.id, status: "PENDING",
      reservationId: res3.id,
      checklist: ROOM_CLEANING_CHECKLIST.map((item) => ({ item, done: false })),
    },
  });

  // 4) Group booking — Ceylon Tea Tours, 3 rooms next week (corporate rate)
  const grp = await prisma.groupBooking.create({
    data: { reference: "GRP-0001", name: "Ceylon Tea Tours — Ella Excursion", contactName: "Asanka Perera", contactPhone: "+94 11 234 5678" },
  });
  const corpRate = (base: number) => Math.round(base * 0.9); // 10% negotiated discount
  for (const [i, num] of ["110", "111", "112"].entries()) {
    const room = roomsByNumber[num];
    const res = await prisma.reservation.create({
      data: {
        code: `RSV-000${4 + i}`, guestId: gTours.id, channel: "PHONE", status: "CONFIRMED",
        checkIn: D(today.add(7, "day")), checkOut: D(today.add(9, "day")),
        adults: 3, groupBookingId: grp.id, corporateAccountId: corp.id,
        depositDue: Math.round(2 * corpRate(LKR(18000)) * 0.2),
        rooms: { create: [{ roomId: room.id, nightlyRate: corpRate(LKR(18000)) }] },
      },
    });
    await prisma.folio.create({ data: { type: "GUEST", reservationId: res.id } });
  }

  // ── Venue booking — outside client wedding in 2 weeks, deposit paid ──
  const vb = await prisma.venueBooking.create({
    data: {
      code: "VNB-0001", venueId: venues[0].id,
      clientName: "Chamara & Sewwandi Wedding", clientPhone: "+94 76 222 1100", clientEmail: "chamara.w@example.com",
      eventType: "Wedding", date: D(today.add(14, "day")), startTime: "09:00", endTime: "17:00",
      durationType: "FULL_DAY", guestCount: 250, seating: "Round tables of 10, head table for 12",
      avNeeds: "2 wireless mics, projector", decoration: "Gold & white theme (hotel to arrange)",
      cateringByHotel: false, status: "CONFIRMED", depositDue: Math.round(LKR(120000) * 0.25),
    },
  });
  const vfolio = await prisma.folio.create({ data: { type: "VENUE", venueBookingId: vb.id } });
  await prisma.folioLine.create({
    data: {
      folioId: vfolio.id, source: "VENUE", description: "Wedding Hall 1 (Lower) — Full day rental",
      qty: 1, unitPrice: LKR(120000), amount: LKR(120000), staffId: manager.id,
    },
  });
  await prisma.folioLine.create({
    data: {
      folioId: vfolio.id, source: "VENUE", description: "Decoration package (gold & white) — optional extra",
      qty: 1, unitPrice: LKR(35000), amount: LKR(35000), staffId: manager.id,
    },
  });
  await prisma.payment.create({
    data: { kind: "DEPOSIT", method: "BANK_TRANSFER", amount: vb.depositDue, folioId: vfolio.id, staffId: manager.id, reference: "BOC-77812" },
  });

  // ── Walk-in orders ──
  // Settled yesterday with split cash+card
  const yItems = [
    { m: "Rice & Curry (Chicken)", qty: 3 },
    { m: "Ceylon Tea", qty: 3 },
  ];
  const ySub = yItems.reduce((s, it) => s + menu[it.m].price * it.qty, 0);
  const yOrder = await prisma.order.create({
    data: {
      type: "WALKIN", status: "SETTLED", kotStatus: "SERVED", customerName: "Walk-in — table 3",
      staffId: manager.id, subtotal: ySub, total: ySub,
      createdAt: today.subtract(1, "day").add(13, "hour").toDate(),
      settledAt: today.subtract(1, "day").add(14, "hour").toDate(),
      items: {
        create: yItems.map((it) => ({
          menuItemId: menu[it.m].id, name: it.m, qty: it.qty,
          unitPrice: menu[it.m].price, amount: menu[it.m].price * it.qty,
        })),
      },
    },
  });
  await prisma.payment.create({ data: { method: "CASH", amount: LKR(2000), orderId: yOrder.id, staffId: manager.id } });
  await prisma.payment.create({ data: { method: "CARD", amount: ySub - LKR(2000), orderId: yOrder.id, staffId: manager.id, reference: "CARD-SLIP-2215" } });

  // Open walk-in order right now (KOT: NEW → chef sees it)
  const oItems = [
    { m: "Chicken Fried Rice", qty: 2 },
    { m: "Devilled Chicken (Starter)", qty: 1 },
    { m: "Soft Drink (330ml)", qty: 2 },
  ];
  const oSub = oItems.reduce((s, it) => s + menu[it.m].price * it.qty, 0);
  await prisma.order.create({
    data: {
      type: "WALKIN", status: "OPEN", kotStatus: "NEW", customerName: "Walk-in — table 1",
      staffId: manager.id, subtotal: oSub, total: oSub,
      items: {
        create: oItems.map((it) => ({
          menuItemId: menu[it.m].id, name: it.m, qty: it.qty,
          unitPrice: menu[it.m].price, amount: menu[it.m].price * it.qty,
        })),
      },
    },
  });

  // ── Shifts & attendance ──
  await prisma.shift.create({ data: { staffId: manager.id, openingCash: LKR(5000) } });
  for (const u of [manager, housekeeper, chef]) {
    await prisma.attendance.create({ data: { userId: u.id, clockIn: today.add(8, "hour").toDate() } });
  }
  await prisma.attendance.create({
    data: { userId: owner.id, clockIn: today.subtract(1, "day").add(9, "hour").toDate(), clockOut: today.subtract(1, "day").add(17, "hour").toDate() },
  });

  // ── Maintenance ──
  await prisma.maintenanceIssue.create({
    data: { roomId: roomsByNumber["107"].id, description: "AC not cooling properly — needs gas refill", loggedById: housekeeper.id },
  });
  await prisma.room.update({ where: { id: roomsByNumber["107"].id }, data: { status: "MAINTENANCE" } });

  // ── Sample queued notifications (stub providers — Phase 2 sends for real) ──
  await prisma.notification.create({
    data: {
      type: "PRE_ARRIVAL", channel: "EMAIL", to: "emily.smith@example.com",
      subject: "We look forward to welcoming you tomorrow — Mount View Hotel",
      body: "Dear Emily, a reminder that your stay (RSV-0002) begins tomorrow. Check-in from 14:00.",
      refType: "RESERVATION", refId: res2.id,
    },
  });

  console.log("Seed complete.");
  console.log("Logins: owner@mountview.lk/owner123 (PIN 1111), manager@mountview.lk/manager123 (2222),");
  console.log("        housekeeping@mountview.lk/house123 (3333), chef@mountview.lk/chef123 (4444), security@mountview.lk/security123 (5555)");
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(() => prisma.$disconnect());
