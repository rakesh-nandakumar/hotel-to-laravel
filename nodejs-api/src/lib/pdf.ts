/**
 * Server-generated branded PDFs (logo/name, address, tax reg no. from Settings).
 * Two formats everywhere: "thermal" (80mm bill printer) and "a4".
 */
import PDFDocument from "pdfkit";
import type { Response } from "express";
import fs from "fs";
import { prisma } from "./prisma";
import { ApiError } from "./http";
import { getStr } from "./settings";
import { folioWithTotals } from "./billing";

const THERMAL_WIDTH = 226; // ≈80mm in points

type Fmt = "thermal" | "a4";

function newDoc(fmt: Fmt): PDFKit.PDFDocument {
  return fmt === "thermal"
    ? new PDFDocument({ size: [THERMAL_WIDTH, 800], margins: { top: 10, bottom: 10, left: 8, right: 8 } })
    : new PDFDocument({ size: "A4", margins: { top: 40, bottom: 40, left: 40, right: 40 } });
}

function money(cents: number) {
  return (cents / 100).toLocaleString("en-LK", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function brandHeader(doc: PDFKit.PDFDocument, fmt: Fmt) {
  const name = await getStr("hotel.name", "Mount View Hotel, Badulla");
  const address = await getStr("hotel.address", "");
  const phone = await getStr("hotel.phone", "");
  const email = await getStr("hotel.email", "");
  const taxNo = await getStr("hotel.tax_reg_no", "");
  const logo = await getStr("hotel.logo_url", "");
  const center = { align: "center" as const };

  if (logo && fs.existsSync(logo)) {
    const w = fmt === "thermal" ? 60 : 90;
    try {
      doc.image(logo, (doc.page.width - w) / 2, doc.y, { width: w });
      doc.moveDown(0.5);
    } catch {
      /* bad image file — fall through to text branding */
    }
  }
  doc.font("Helvetica-Bold").fontSize(fmt === "thermal" ? 11 : 18).fillColor("#0f4c81").text(name, center);
  doc.fillColor("black").font("Helvetica").fontSize(fmt === "thermal" ? 7 : 9);
  if (address) doc.text(address, center);
  const contact = [phone, email].filter(Boolean).join("  |  ");
  if (contact) doc.text(contact, center);
  if (taxNo) doc.text(`Tax Reg: ${taxNo}`, center);
  doc.moveDown(0.4);
  if (fmt === "a4") {
    doc.rect(doc.page.margins.left, doc.y, doc.page.width - doc.page.margins.left - doc.page.margins.right, 2.5).fill("#0f4c81");
    doc.fillColor("black");
    doc.moveDown(0.5);
  } else {
    hr(doc);
  }
}

/** Consistent footer across every printed document — thin rule + generation timestamp. */
function pageFooter(doc: PDFKit.PDFDocument, fmt: Fmt, extra?: string) {
  doc.moveDown(fmt === "thermal" ? 0.4 : 0.8);
  hr(doc);
  doc.font("Helvetica").fontSize(fmt === "thermal" ? 6 : 7).fillColor("#999");
  if (extra) doc.text(extra, { align: "center" });
  doc.text(`Generated ${new Date().toLocaleString("en-GB")}`, { align: "center" });
  doc.fillColor("black");
}

function hr(doc: PDFKit.PDFDocument) {
  doc
    .moveTo(doc.page.margins.left, doc.y)
    .lineTo(doc.page.width - doc.page.margins.right, doc.y)
    .lineWidth(0.5)
    .strokeColor("#999")
    .stroke();
  doc.moveDown(0.4);
}

function row(doc: PDFKit.PDFDocument, left: string, right: string, bold = false) {
  const width = doc.page.width - doc.page.margins.left - doc.page.margins.right;
  doc.font(bold ? "Helvetica-Bold" : "Helvetica");
  const y = doc.y;
  doc.text(left, doc.page.margins.left, y, { width: width * 0.68 });
  const yAfterLeft = doc.y;
  doc.text(right, doc.page.margins.left + width * 0.68, y, { width: width * 0.32, align: "right" });
  doc.y = Math.max(yAfterLeft, doc.y);
  doc.x = doc.page.margins.left;
}

function send(doc: PDFKit.PDFDocument, res: Response, filename: string) {
  res.setHeader("Content-Type", "application/pdf");
  res.setHeader("Content-Disposition", `inline; filename=${filename}`);
  doc.pipe(res);
  doc.end();
}

function sectionTitle(doc: PDFKit.PDFDocument, text: string) {
  doc.moveDown(0.5);
  doc.font("Helvetica-Bold").fontSize(11).fillColor("#0f4c81").text(text.toUpperCase());
  doc.fillColor("black");
  doc.moveDown(0.15);
}

/** Light gray band behind a table header row, drawn before the header text. */
function shadedBand(doc: PDFKit.PDFDocument, height: number) {
  const left = doc.page.margins.left;
  const width = doc.page.width - doc.page.margins.left - doc.page.margins.right;
  doc.rect(left, doc.y - 2, width, height).fill("#f1f5f9");
  doc.fillColor("black");
}

/** Simple multi-column table: first column left-aligned, the rest right-aligned. */
function table(doc: PDFKit.PDFDocument, headers: string[], colPcts: number[], rows: (string | number)[][]) {
  const left = doc.page.margins.left;
  const totalWidth = doc.page.width - doc.page.margins.left - doc.page.margins.right;
  const widths = colPcts.map((p) => p * totalWidth);

  const drawRow = (cells: (string | number)[], bold: boolean) => {
    doc.font(bold ? "Helvetica-Bold" : "Helvetica").fontSize(9);
    let x = left;
    const y = doc.y;
    let maxY = y;
    cells.forEach((cell, i) => {
      doc.text(String(cell), x, y, { width: widths[i], align: i === 0 ? "left" : "right" });
      maxY = Math.max(maxY, doc.y);
      x += widths[i];
    });
    doc.y = maxY;
    doc.x = left;
  };

  shadedBand(doc, 16);
  drawRow(headers, true);
  doc.moveDown(0.25);
  hr(doc);
  for (const r of rows) drawRow(r, false);
}

function fmtReportDate(dateStr: string) {
  return new Date(`${dateStr}T00:00:00`).toLocaleDateString("en-GB", { weekday: "long", year: "numeric", month: "long", day: "numeric" });
}

// ── POS receipt ───────────────────────────────────────────────────────────────
export async function orderReceiptPdf(orderId: string, fmt: Fmt, res: Response) {
  const order = await prisma.order.findUnique({
    where: { id: orderId },
    include: { items: true, payments: true, staff: { select: { name: true } }, room: { select: { number: true } } },
  });
  if (!order) throw new ApiError(404, "Order not found");

  const doc = newDoc(fmt);
  await brandHeader(doc, fmt);
  doc.fontSize(fmt === "thermal" ? 8 : 10);
  row(doc, `Receipt — Order #${order.orderNo}`, order.type === "WALKIN" ? (order.diningMode === "TAKEAWAY" ? "TAKEAWAY" : "DINE-IN") : "", true);
  row(doc, order.type === "ROOM_GUEST" ? `Room ${order.room?.number ?? ""} (charged to folio)` : order.customerName || "Walk-in", "");
  row(doc, new Date(order.createdAt).toLocaleString("en-GB"), "");
  row(doc, `Served by: ${order.staff.name}`, "");
  doc.moveDown(0.3);
  hr(doc);
  for (const it of order.items.filter((i) => !i.voided)) {
    row(doc, `${it.qty} × ${it.name}`, money(it.amount));
  }
  hr(doc);
  row(doc, "Subtotal", money(order.subtotal));
  if (order.discount > 0) row(doc, `Discount${order.discountReason ? ` (${order.discountReason})` : ""}`, `-${money(order.discount)}`);
  if (order.serviceCharge > 0) row(doc, "Service Charge", money(order.serviceCharge));
  if (order.vat > 0) row(doc, "VAT", money(order.vat));
  row(doc, "TOTAL (LKR)", money(order.total), true);
  if (order.payments.length) {
    doc.moveDown(0.3);
    hr(doc);
    for (const p of order.payments) {
      row(doc, `${p.kind === "REFUND" ? "Refund — " : ""}${p.method}${p.reference ? ` (${p.reference})` : ""}`, `${p.kind === "REFUND" ? "-" : ""}${money(p.amount)}`);
    }
  }
  const paid = order.payments.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0)
    - order.payments.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0);
  if (paid < order.total) row(doc, "BALANCE DUE", money(order.total - paid), true);
  pageFooter(doc, fmt, "Thank you — please come again!");
  send(doc, res, `receipt-${order.orderNo}.pdf`);
}

// ── Walk-in order slip: BILL + COLLECTION TOKEN in one thermal print ─────────
// Printed right after the order is placed. The printer's cut line separates
// the customer's bill from the numbered token they present to collect food.
export async function orderSlipPdf(orderId: string, res: Response) {
  const order = await prisma.order.findUnique({
    where: { id: orderId },
    include: { items: true, payments: true, staff: { select: { name: true } }, room: { select: { number: true } } },
  });
  if (!order) throw new ApiError(404, "Order not found");

  const doc = newDoc("thermal");
  // ── Part 1: the bill ──
  await brandHeader(doc, "thermal");
  doc.fontSize(8);
  row(doc, `BILL — Order #${order.orderNo}`, order.diningMode === "TAKEAWAY" ? "TAKEAWAY" : "DINE-IN", true);
  row(doc, order.type === "ROOM_GUEST" ? `Room ${order.room?.number ?? ""}` : order.customerName || "Walk-in", "");
  row(doc, new Date(order.createdAt).toLocaleString("en-GB"), "");
  row(doc, `Served by: ${order.staff.name}`, "");
  doc.moveDown(0.3);
  hr(doc);
  for (const it of order.items.filter((i) => !i.voided)) {
    row(doc, `${it.qty} × ${it.name}`, money(it.amount));
  }
  hr(doc);
  row(doc, "Subtotal", money(order.subtotal));
  if (order.discount > 0) row(doc, "Discount", `-${money(order.discount)}`);
  if (order.serviceCharge > 0) row(doc, "Service Charge", money(order.serviceCharge));
  else if (order.diningMode === "TAKEAWAY") row(doc, "Service Charge", "waived (takeaway)");
  if (order.vat > 0) row(doc, "VAT", money(order.vat));
  row(doc, "TOTAL (LKR)", money(order.total), true);
  const paid = order.payments.filter((p) => p.kind !== "REFUND").reduce((s, p) => s + p.amount, 0)
    - order.payments.filter((p) => p.kind === "REFUND").reduce((s, p) => s + p.amount, 0);
  if (paid >= order.total) {
    row(doc, "PAID ✓", money(paid), true);
  } else if (paid > 0) {
    row(doc, "Paid so far", money(paid));
    row(doc, "BALANCE DUE AT COUNTER", money(order.total - paid), true);
  } else {
    row(doc, "PAY AT COUNTER", money(order.total), true);
  }

  // ── Cut line ──
  doc.moveDown(0.8);
  doc.font("Helvetica").fontSize(8).text("✂" + " -".repeat(30), { align: "center" });
  doc.moveDown(0.8);

  // ── Part 2: collection token ──
  doc.font("Helvetica").fontSize(9).text("COLLECTION TOKEN", { align: "center" });
  doc.moveDown(0.2);
  doc.font("Helvetica-Bold").fontSize(46).text(`#${order.orderNo}`, { align: "center" });
  doc.moveDown(0.2);
  doc.font("Helvetica").fontSize(9).text(order.customerName || "Walk-in", { align: "center" });
  doc.fontSize(8).text(new Date(order.createdAt).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }), { align: "center" });
  doc.moveDown(0.4);
  doc.fontSize(7).text("Please present this number at the counter", { align: "center" });
  doc.text("when your order is called / marked READY.", { align: "center" });
  send(doc, res, `order-slip-${order.orderNo}.pdf`);
}

// ── Folio invoice (guest stay = INV-…, venue = VNU-… separate invoice type) ──
export async function folioInvoicePdf(folioId: string, fmt: Fmt, res: Response) {
  const f = await folioWithTotals(folioId);
  const doc = newDoc(fmt);
  await brandHeader(doc, fmt);
  doc.fontSize(fmt === "thermal" ? 8 : 10);

  const title = f.type === "VENUE" ? "VENUE EVENT INVOICE" : "GUEST STAY INVOICE";
  row(doc, title, f.invoiceNo ?? "PROFORMA", true);
  if (f.reservation) {
    row(doc, `Guest: ${f.reservation.guest.name}`, "");
    if (f.reservation.guest.idNumber) row(doc, `ID/Passport: ${f.reservation.guest.idNumber}`, "");
    row(doc, `Booking: ${f.reservation.code}`, "");
    row(doc, `Rooms: ${f.reservation.rooms.map((r) => r.room.number).join(", ")}`, "");
    row(doc, `Stay: ${f.reservation.checkIn.toISOString().slice(0, 10)} → ${f.reservation.checkOut.toISOString().slice(0, 10)}`, "");
  }
  if (f.venueBooking) {
    row(doc, `Venue: ${f.venueBooking.venue.name}`, "");
    row(doc, `Client: ${f.venueBooking.clientName}`, "");
    row(doc, `Event date: ${f.venueBooking.date.toISOString().slice(0, 10)}`, "");
  }
  doc.moveDown(0.3);
  hr(doc);
  if (fmt === "a4") {
    // Itemized expense table for the guest's A4 bill: date | description | amount
    const width = doc.page.width - doc.page.margins.left - doc.page.margins.right;
    const col = { date: 0.13, desc: 0.62, amt: 0.25 };
    shadedBand(doc, 16);
    doc.font("Helvetica-Bold").fontSize(9);
    const hy = doc.y;
    doc.text("DATE", doc.page.margins.left, hy, { width: width * col.date });
    doc.text("DESCRIPTION", doc.page.margins.left + width * col.date, hy, { width: width * col.desc });
    doc.text("AMOUNT (LKR)", doc.page.margins.left + width * (col.date + col.desc), hy, { width: width * col.amt, align: "right" });
    doc.moveDown(0.3);
    hr(doc);
    doc.font("Helvetica").fontSize(9);
    for (const l of f.lines) {
      const y = doc.y;
      doc.text(new Date(l.createdAt).toLocaleDateString("en-GB", { day: "2-digit", month: "2-digit" }), doc.page.margins.left, y, { width: width * col.date });
      doc.text(l.description, doc.page.margins.left + width * col.date, y, { width: width * col.desc });
      const yDesc = doc.y;
      doc.text(money(l.amount), doc.page.margins.left + width * (col.date + col.desc), y, { width: width * col.amt, align: "right" });
      doc.y = Math.max(yDesc, doc.y);
      doc.x = doc.page.margins.left;
      doc.moveDown(0.15);
    }
  } else {
    for (const l of f.lines) {
      row(doc, l.description, money(l.amount));
    }
  }
  hr(doc);
  row(doc, "TOTAL (LKR)", money(f.total), true);
  doc.moveDown(0.3);
  for (const p of f.payments) {
    row(doc, `${p.kind === "REFUND" ? "Refund" : p.kind === "DEPOSIT" ? "Deposit" : "Payment"} — ${p.method}${p.reference ? ` (${p.reference})` : ""} ${new Date(p.createdAt).toLocaleDateString("en-GB")}`, `${p.kind === "REFUND" ? "" : "-"}${money(p.amount)}`);
  }
  row(doc, f.balance > 0 ? "BALANCE DUE" : "BALANCE", money(Math.abs(f.balance)), true);
  pageFooter(doc, fmt, "Settlement currency: LKR. Thank you for choosing us!");
  send(doc, res, `${f.invoiceNo ?? "proforma"}.pdf`);
}

// ── Payslip (A4, branded) ─────────────────────────────────────────────────────
export async function payslipPdf(lineId: string, res: Response) {
  const line = await prisma.payrollLine.findUnique({
    where: { id: lineId },
    include: { user: { select: { name: true, role: true, epfNumber: true } }, run: true },
  });
  if (!line) throw new ApiError(404, "Payroll line not found");

  const doc = newDoc("a4");
  await brandHeader(doc, "a4");
  doc.fontSize(10);
  row(doc, "PAYSLIP", line.run.month, true);
  row(doc, `Employee: ${line.user.name}`, "");
  row(doc, `Role: ${line.user.role}`, "");
  if (line.user.epfNumber) row(doc, `EPF No: ${line.user.epfNumber}`, "");
  row(doc, `Hours worked: ${line.workedHours}  ·  OT hours: ${line.otHours}`, "");
  doc.moveDown(0.4);
  hr(doc);
  row(doc, "Basic salary", money(line.baseSalary));
  if (line.otPay > 0) row(doc, `Overtime (${line.otHours} h)`, money(line.otPay));
  if (line.allowance > 0) row(doc, "Allowance", money(line.allowance));
  if (line.bonus > 0) row(doc, "Bonus", money(line.bonus));
  hr(doc);
  row(doc, "GROSS PAY", money(line.gross), true);
  if (line.epfEmployee > 0) row(doc, "EPF employee contribution (deducted)", `-${money(line.epfEmployee)}`);
  if (line.deduction > 0) row(doc, `Deduction${line.deductionNote ? ` (${line.deductionNote})` : ""}`, `-${money(line.deduction)}`);
  hr(doc);
  row(doc, "NET PAY (LKR)", money(line.netPay), true);
  doc.moveDown(0.4);
  doc.font("Helvetica").fontSize(8).fillColor("#666");
  doc.text(`Employer contributions (not deducted from pay): EPF ${money(line.epfEmployer)} · ETF ${money(line.etf)}`);
  doc.text(`Status: ${line.paid ? `PAID on ${line.paidAt?.toLocaleDateString("en-GB")}` : "PENDING PAYMENT"}`);
  doc.fillColor("black");
  pageFooter(doc, "a4");
  send(doc, res, `payslip-${line.run.month}-${line.user.name.replace(/\W+/g, "-")}.pdf`);
}

// ── Printed KOT ticket (redundant copy of the live kitchen screen) ──
export async function kotTicketPdf(orderId: string, res: Response) {
  const order = await prisma.order.findUnique({
    where: { id: orderId },
    include: { items: true, room: { select: { number: true } } },
  });
  if (!order) throw new ApiError(404, "Order not found");
  const doc = newDoc("thermal");
  doc.font("Helvetica-Bold").fontSize(12).text(`KOT — Order #${order.orderNo}`, { align: "center" });
  if (order.type === "WALKIN") {
    doc.font("Helvetica-Bold").fontSize(9).text(order.diningMode === "TAKEAWAY" ? "*** TAKEAWAY ***" : "DINE-IN", { align: "center" });
  }
  doc.font("Helvetica").fontSize(9).text(order.type === "ROOM_GUEST" ? `Room ${order.room?.number}` : order.customerName || "Walk-in", { align: "center" });
  doc.text(new Date(order.createdAt).toLocaleTimeString("en-GB"), { align: "center" });
  doc.moveDown(0.5);
  hr(doc);
  doc.fontSize(11);
  for (const it of order.items.filter((i) => !i.voided)) {
    doc.font("Helvetica-Bold").text(`${it.qty} × ${it.name}`);
    if (it.notes) doc.font("Helvetica").fontSize(9).text(`   → ${it.notes}`).fontSize(11);
  }
  if (order.notes) {
    hr(doc);
    doc.font("Helvetica").fontSize(9).text(`Note: ${order.notes}`);
  }
  pageFooter(doc, "thermal");
  send(doc, res, `kot-${order.orderNo}.pdf`);
}

// ── Report PDFs (A4, branded) — daily / night-audit snapshot, monthly, POS ──
type DailyReportData = {
  date: string;
  occupancy: { totalRooms: number; occupiedRooms: number; pct: number };
  revenueBySource: Record<string, number>;
  walkinPosRevenue: number;
  totalChargesPosted: number;
  payments: { byMethod: Record<string, number>; collected: number; refunded: number; net: number };
  cashCollected: number;
  pos: { byCategory: Record<string, number>; bestSellers: { name: string; qty: number; amount: number }[]; orderCount: number };
  shifts: { staff: string; openingCash: number; closingCash?: number | null; expectedCash?: number | null; variance?: number | null }[];
};

export async function dailyReportPdf(data: DailyReportData, meta: { title: string; runBy?: string }, res: Response) {
  const doc = newDoc("a4");
  await brandHeader(doc, "a4");
  doc.font("Helvetica-Bold").fontSize(14).text(meta.title, { align: "center" });
  doc.font("Helvetica").fontSize(10).text(fmtReportDate(data.date), { align: "center" });
  if (meta.runBy) doc.fontSize(8).fillColor("#666").text(`Run by ${meta.runBy}`, { align: "center" }).fillColor("black");

  sectionTitle(doc, "Occupancy");
  table(doc, ["Total rooms", "Occupied", "Occupancy %"], [0.4, 0.3, 0.3], [[data.occupancy.totalRooms, data.occupancy.occupiedRooms, `${data.occupancy.pct}%`]]);

  sectionTitle(doc, "Revenue by source");
  const sources = Object.entries(data.revenueBySource);
  table(doc, ["Source", "Amount (LKR)"], [0.6, 0.4], sources.length ? sources.map(([k, v]) => [k, money(v)]) : [["—", "0.00"]]);
  doc.moveDown(0.2);
  row(doc, "Walk-in POS revenue", money(data.walkinPosRevenue));
  row(doc, "TOTAL CHARGES POSTED", money(data.totalChargesPosted), true);

  sectionTitle(doc, "Payments by method");
  const methods = Object.entries(data.payments.byMethod);
  table(doc, ["Method", "Amount (LKR)"], [0.6, 0.4], methods.length ? methods.map(([k, v]) => [k, money(v)]) : [["—", "0.00"]]);
  doc.moveDown(0.2);
  row(doc, "Collected", money(data.payments.collected));
  row(doc, "Refunded", money(data.payments.refunded));
  row(doc, "NET COLLECTED", money(data.payments.net), true);
  row(doc, "Cash collected", money(data.cashCollected));

  if (data.pos.bestSellers.length) {
    sectionTitle(doc, "POS — best sellers");
    table(doc, ["Item", "Qty", "Amount (LKR)"], [0.55, 0.15, 0.3], data.pos.bestSellers.slice(0, 10).map((b) => [b.name, b.qty, money(b.amount)]));
  }

  if (data.shifts.length) {
    sectionTitle(doc, "Shift / cash-drawer reconciliation");
    table(
      doc,
      ["Staff", "Opening", "Expected", "Counted", "Variance"],
      [0.28, 0.18, 0.18, 0.18, 0.18],
      data.shifts.map((s) => [
        s.staff, money(s.openingCash), s.expectedCash != null ? money(s.expectedCash) : "—",
        s.closingCash != null ? money(s.closingCash) : "—", s.variance != null ? money(s.variance) : "—",
      ])
    );
  }

  pageFooter(doc, "a4");
  send(doc, res, `report-${data.date}.pdf`);
}

type MonthlyReportData = {
  month: string;
  days: { date: string; revenue: number; occupancyPct: number }[];
  totalRevenue: number;
  avgOccupancy: number;
};

export async function monthlyReportPdf(data: MonthlyReportData, res: Response) {
  const doc = newDoc("a4");
  await brandHeader(doc, "a4");
  doc.font("Helvetica-Bold").fontSize(14).text("MONTHLY PERFORMANCE REPORT", { align: "center" });
  doc.font("Helvetica").fontSize(10).text(new Date(`${data.month}-01T00:00:00`).toLocaleDateString("en-GB", { year: "numeric", month: "long" }), { align: "center" });

  const bestDay = data.days.reduce((best, d) => (d.revenue > (best?.revenue ?? -1) ? d : best), data.days[0]);
  sectionTitle(doc, "Summary");
  row(doc, "Total revenue", money(data.totalRevenue), true);
  row(doc, "Average occupancy", `${data.avgOccupancy}%`);
  if (bestDay) row(doc, "Best day", `${bestDay.date} — ${money(bestDay.revenue)}`);

  sectionTitle(doc, "Daily breakdown");
  table(doc, ["Date", "Revenue (LKR)", "Occupancy %"], [0.4, 0.35, 0.25], data.days.map((d) => [d.date, money(d.revenue), `${d.occupancyPct}%`]));

  pageFooter(doc, "a4");
  send(doc, res, `monthly-report-${data.month}.pdf`);
}

type PosReportData = {
  from: string;
  to: string;
  byCategory: Record<string, number>;
  bestSellers: { name: string; qty: number; amount: number }[];
  paymentMethodBreakdown: Record<string, number>;
  totalSales: number;
};

export async function posReportPdf(data: PosReportData, res: Response) {
  const doc = newDoc("a4");
  await brandHeader(doc, "a4");
  doc.font("Helvetica-Bold").fontSize(14).text("POS SALES REPORT", { align: "center" });
  doc.font("Helvetica").fontSize(10).text(`${data.from} → ${data.to}`, { align: "center" });

  sectionTitle(doc, "Sales by category");
  const cats = Object.entries(data.byCategory);
  table(doc, ["Category", "Amount (LKR)"], [0.6, 0.4], cats.length ? cats.map(([k, v]) => [k, money(v)]) : [["—", "0.00"]]);

  sectionTitle(doc, "Best sellers");
  table(doc, ["Item", "Qty", "Amount (LKR)"], [0.55, 0.15, 0.3], data.bestSellers.map((b) => [b.name, b.qty, money(b.amount)]));

  sectionTitle(doc, "Payment method breakdown");
  const methods = Object.entries(data.paymentMethodBreakdown);
  table(doc, ["Method", "Amount (LKR)"], [0.6, 0.4], methods.length ? methods.map(([k, v]) => [k, money(v)]) : [["—", "0.00"]]);

  doc.moveDown(0.4);
  row(doc, "TOTAL SALES (LKR)", money(data.totalSales), true);

  pageFooter(doc, "a4");
  send(doc, res, `pos-report-${data.from}_${data.to}.pdf`);
}
