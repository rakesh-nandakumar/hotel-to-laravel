import "dotenv/config";
import express from "express";
import cors from "cors";
import http from "http";
import path from "path";
import fs from "fs";
import { errorMiddleware } from "./lib/http";
import { requireAuth } from "./lib/auth";
import { captureRequestContext } from "./lib/requestContext";
import { initSocket } from "./socket";

import authRoutes from "./routes/auth";
import publicRoutes from "./routes/public";
import settingsRoutes from "./routes/settings";
import staffRoutes from "./routes/staff";
import attendanceRoutes from "./routes/attendance";
import roomRoutes from "./routes/rooms";
import reservationRoutes from "./routes/reservations";
import guestRoutes from "./routes/guests";
import corporateRoutes from "./routes/corporate";
import folioRoutes from "./routes/folios";
import menuRoutes from "./routes/menu";
import orderRoutes from "./routes/orders";
import shiftRoutes from "./routes/shifts";
import venueRoutes from "./routes/venues";
import housekeepingRoutes from "./routes/housekeeping";
import maintenanceRoutes from "./routes/maintenance";
import reportRoutes from "./routes/reports";
import laundryRoutes from "./routes/laundry";
import payrollRoutes from "./routes/payroll";
import auditLogRoutes from "./routes/auditLog";
import notificationRoutes from "./routes/notifications";
import visitorRoutes from "./routes/visitors";

const app = express();
app.set("trust proxy", 1); // Render sits behind a proxy — needed for req.ip / X-Forwarded-For to be real
app.use(cors());
app.use(express.json({ limit: "2mb" }));
app.use(captureRequestContext); // makes IP/user-agent/route available to audit() everywhere below

app.get("/api/health", (_req, res) => res.json({ ok: true, service: "mountview-api" }));

// Unauthenticated: login + public guest-facing forms (pre-check-in, venue inquiry, branding)
app.use("/api/auth", authRoutes);
app.use("/api/public", publicRoutes);

// Everything below requires a valid staff JWT; per-route RBAC inside each router.
app.use("/api", requireAuth);
app.use("/api/settings", settingsRoutes);
app.use("/api/staff", staffRoutes);
app.use("/api/attendance", attendanceRoutes);
app.use("/api/rooms", roomRoutes);
app.use("/api/reservations", reservationRoutes);
app.use("/api/guests", guestRoutes);
app.use("/api/corporate", corporateRoutes);
app.use("/api/folios", folioRoutes);
app.use("/api/menu", menuRoutes);
app.use("/api/orders", orderRoutes);
app.use("/api/shifts", shiftRoutes);
app.use("/api/venues", venueRoutes);
app.use("/api/housekeeping", housekeepingRoutes);
app.use("/api/maintenance", maintenanceRoutes);
app.use("/api/reports", reportRoutes);
app.use("/api/laundry", laundryRoutes);
app.use("/api/payroll", payrollRoutes);
app.use("/api/audit-log", auditLogRoutes);
app.use("/api/notifications", notificationRoutes);
app.use("/api/visitors", visitorRoutes);

// Production single-service deploy (e.g. Render): serve the built frontend
// from the same process so websockets and /api share one origin.
const webDist = process.env.WEB_DIST || path.join(__dirname, "..", "..", "web", "dist");
if (fs.existsSync(path.join(webDist, "index.html"))) {
  app.use(express.static(webDist));
  app.get("*", (req, res, next) => {
    if (req.path.startsWith("/api") || req.path.startsWith("/socket.io")) return next();
    res.sendFile(path.join(webDist, "index.html"));
  });
  console.log(`Serving web app from ${webDist}`);
}

app.use(errorMiddleware);

const server = http.createServer(app);
initSocket(server);

// Hourly sweep: pre-arrival reminders, venue payment/pre-event reminders
import { runScheduledNotifications } from "./lib/scheduler";
setInterval(() => runScheduledNotifications().catch((e) => console.error("scheduler", e)), 60 * 60 * 1000);

import { bootstrap } from "./lib/bootstrap";

const PORT = Number(process.env.PORT || 4000);
bootstrap()
  .catch((e) => console.error("bootstrap failed", e))
  .finally(() => server.listen(PORT, () => console.log(`Mount View API listening on :${PORT}`)));
