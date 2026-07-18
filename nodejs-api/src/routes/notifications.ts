import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler } from "../lib/http";
import { requireRole, requireSystemAdmin, MANAGERS } from "../lib/auth";
import { runScheduledNotifications } from "../lib/scheduler";
import { notify } from "../lib/notify";

const router = Router();

/** Integration test-send — SYSTEM_ADMIN only (verifies WhatsApp/SMS credentials). */
router.post(
  "/test",
  requireSystemAdmin,
  asyncHandler(async (req, res) => {
    const body = z
      .object({
        channel: z.enum(["WHATSAPP", "SMS", "EMAIL"]),
        to: z.string().min(3),
      })
      .parse(req.body);
    await notify({
      type: "INTEGRATION_TEST",
      channel: body.channel,
      to: body.to,
      subject: "Mount View HMS — integration test",
      body: `Test message from Mount View Hospitality Management System (${new Date().toLocaleString("en-GB")}). If you received this, the ${body.channel} integration works.`,
    });
    const last = await prisma.notification.findFirst({
      where: { type: "INTEGRATION_TEST" },
      orderBy: { createdAt: "desc" },
    });
    res.json(last);
  }),
);

router.use(requireRole(...MANAGERS));

router.get(
  "/",
  asyncHandler(async (req, res) => {
    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(
        200,
        Math.max(1, Number(req.query.pageSize) || 25),
      );
      const [rows, total] = await Promise.all([
        prisma.notification.findMany({
          orderBy: { createdAt: "desc" },
          skip: (page - 1) * pageSize,
          take: pageSize,
        }),
        prisma.notification.count(),
      ]);
      return res.json({ rows, total, page, pageSize });
    }
    res.json(
      await prisma.notification.findMany({
        orderBy: { createdAt: "desc" },
        take: 200,
      }),
    );
  }),
);

/** Manually trigger the scheduled reminder sweep (also runs hourly in-process). */
router.post(
  "/run-scheduled",
  asyncHandler(async (_req, res) => {
    res.json(await runScheduledNotifications());
  }),
);

export default router;
