import { Router } from "express";
import { z } from "zod";
import { prisma } from "../lib/prisma";
import { asyncHandler, ApiError } from "../lib/http";
import { requireRole } from "../lib/auth";
import { audit } from "../lib/audit";
import { getNum } from "../lib/settings";
import { canMake } from "../lib/pos";
import { emit } from "../socket";

const router = Router();

/** Full menu for the POS grid — all staff. */
router.get(
  "/full",
  asyncHandler(async (_req, res) => {
    const categories = await prisma.menuCategory.findMany({
      where: { active: true },
      orderBy: { sortOrder: "asc" },
      include: { items: { where: { active: true }, orderBy: [{ itemNo: "asc" }, { name: "asc" }] } },
    });
    res.json(categories);
  })
);

// ── Categories (Owner/Manager editable — no developer needed) ──
router.get(
  "/categories",
  asyncHandler(async (_req, res) => {
    res.json(await prisma.menuCategory.findMany({ orderBy: { sortOrder: "asc" }, include: { _count: { select: { items: true } } } }));
  })
);

router.post(
  "/categories",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z.object({ name: z.string().min(1), sortOrder: z.number().int().default(0), isMinibar: z.boolean().default(false) }).parse(req.body);
    res.status(201).json(await prisma.menuCategory.create({ data: body }));
  })
);

router.put(
  "/categories/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = z.object({ name: z.string().min(1).optional(), sortOrder: z.number().int().optional(), isMinibar: z.boolean().optional(), active: z.boolean().optional() }).parse(req.body);
    res.json(await prisma.menuCategory.update({ where: { id: req.params.id }, data: body }));
  })
);

// ── Items ──
const itemsInclude = { category: true, recipe: { include: { ingredient: true } }, _count: { select: { orderItems: true } } } as const;
const itemsOrderBy = [{ category: { sortOrder: "asc" as const } }, { itemNo: "asc" as const }, { name: "asc" as const }];

router.get(
  "/items",
  asyncHandler(async (req, res) => {
    const q = typeof req.query.q === "string" ? req.query.q : undefined;
    const categoryId = typeof req.query.categoryId === "string" && req.query.categoryId ? req.query.categoryId : undefined;
    const activeParam = req.query.active;
    const active = activeParam === "false" ? false : activeParam === "true" ? true : undefined;

    const where: Record<string, unknown> = {};
    if (active !== undefined) where.active = active;
    if (categoryId) where.categoryId = categoryId;
    if (q) {
      const or: Record<string, unknown>[] = [{ name: { contains: q, mode: "insensitive" as const } }];
      if (/^\d+$/.test(q)) or.push({ itemNo: parseInt(q) });
      where.OR = or;
    }

    if (req.query.page) {
      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const [rows, total, onMenu, soldOut, archived] = await Promise.all([
        prisma.menuItem.findMany({ where, include: itemsInclude, orderBy: itemsOrderBy, skip: (page - 1) * pageSize, take: pageSize }),
        prisma.menuItem.count({ where }),
        prisma.menuItem.count({ where: { active: true } }),
        prisma.menuItem.count({ where: { active: true, soldOut: true } }),
        prisma.menuItem.count({ where: { active: false } }),
      ]);
      return res.json({ rows, total, page, pageSize, stats: { onMenu, soldOut, archived } });
    }

    res.json(await prisma.menuItem.findMany({ include: itemsInclude, orderBy: itemsOrderBy }));
  })
);

const itemBody = z.object({
  name: z.string().min(1),
  categoryId: z.string(),
  price: z.number().int().min(0),
  itemNo: z.number().int().min(1).nullable().optional(),
  description: z.string().optional(),
  active: z.boolean().optional(),
  recipe: z.array(z.object({ ingredientId: z.string(), qty: z.number().min(0) })).optional(),
});

router.post(
  "/items",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = itemBody.parse(req.body);
    // Auto-assign the next menu number unless one was chosen
    const itemNo = body.itemNo ?? ((await prisma.menuItem.aggregate({ _max: { itemNo: true } }))._max.itemNo ?? 0) + 1;
    const item = await prisma.menuItem.create({
      data: {
        name: body.name, categoryId: body.categoryId, price: body.price, itemNo, description: body.description ?? "",
        recipe: body.recipe ? { create: body.recipe } : undefined,
      },
    });
    audit(req.user!.id, "MENUITEM_CREATE", "MenuItem", item.id, { itemNo });
    res.status(201).json(item);
  })
);

router.put(
  "/items/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const body = itemBody.partial().parse(req.body);
    const item = await prisma.$transaction(async (tx) => {
      if (body.recipe) {
        await tx.recipeItem.deleteMany({ where: { menuItemId: req.params.id } });
        await tx.recipeItem.createMany({ data: body.recipe.map((r) => ({ ...r, menuItemId: req.params.id })) });
      }
      return tx.menuItem.update({
        where: { id: req.params.id },
        data: { name: body.name, categoryId: body.categoryId, price: body.price, itemNo: body.itemNo, description: body.description, active: body.active },
      });
    });
    audit(req.user!.id, "MENUITEM_UPDATE", "MenuItem", item.id, body as never);
    res.json(item);
  })
);

/**
 * Remove a menu item. Items that appear in past orders are ARCHIVED
 * (deactivated — order history must stay intact); never-ordered items are
 * hard-deleted along with their recipe.
 */
router.delete(
  "/items/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const item = await prisma.menuItem.findUnique({
      where: { id: req.params.id },
      include: { _count: { select: { orderItems: true } } },
    });
    if (!item) throw new ApiError(404, "Menu item not found");
    if (item._count.orderItems > 0) {
      await prisma.menuItem.update({ where: { id: item.id }, data: { active: false, soldOut: false } });
      audit(req.user!.id, "MENUITEM_ARCHIVE", "MenuItem", item.id, { name: item.name, pastOrders: item._count.orderItems });
      emit("menu", { removed: [item.name] });
      return res.json({ archived: true, message: `"${item.name}" appears in ${item._count.orderItems} past order(s) — archived instead of deleted (order history preserved). Restore anytime from the Archived filter.` });
    }
    await prisma.menuItem.delete({ where: { id: item.id } }); // recipe cascades
    audit(req.user!.id, "MENUITEM_DELETE", "MenuItem", item.id, { name: item.name });
    emit("menu", { removed: [item.name] });
    res.json({ archived: false, message: `"${item.name}" removed.` });
  })
);

/** Remove an empty category (must contain no items, active or archived). */
router.delete(
  "/categories/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const cat = await prisma.menuCategory.findUnique({ where: { id: req.params.id }, include: { _count: { select: { items: true } } } });
    if (!cat) throw new ApiError(404, "Category not found");
    if (cat._count.items > 0) throw new ApiError(400, `"${cat.name}" still has ${cat._count.items} item(s) — move or remove them first`);
    await prisma.menuCategory.delete({ where: { id: cat.id } });
    audit(req.user!.id, "MENUCATEGORY_DELETE", "MenuCategory", cat.id, { name: cat.name });
    res.json({ ok: true });
  })
);

/**
 * Sold-out toggle — Chef or Manager. Items also go sold-out automatically when
 * raw materials run short. Re-enabling requires enough stock for at least one
 * portion — otherwise the request is rejected listing what's missing.
 */
router.put(
  "/items/:id/sold-out",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const { soldOut } = z.object({ soldOut: z.boolean() }).parse(req.body);
    if (!soldOut) {
      const chk = await canMake(prisma, req.params.id, 1);
      if (!chk.ok)
        throw new ApiError(400, `Cannot mark available — insufficient raw materials: ${chk.missing.join("; ")}. Restock first.`);
    }
    const item = await prisma.menuItem.update({ where: { id: req.params.id }, data: { soldOut } });
    audit(req.user!.id, "MENUITEM_SOLDOUT", "MenuItem", item.id, { soldOut });
    emit("menu", { soldOut: soldOut ? [item.name] : [], available: soldOut ? [] : [item.name] });
    res.json(item);
  })
);

// ── Ingredients & stock ──
router.get(
  "/ingredients",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const rows = await prisma.ingredient.findMany({
      orderBy: { name: "asc" },
      include: {
        batches: { where: { qty: { gt: 0 }, expiryDate: { not: null } }, orderBy: { expiryDate: "asc" }, take: 5 },
        recipeItems: { select: { menuItem: { select: { name: true } } } },
      },
    });
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const all = rows.map((r) => ({
      ...r,
      recipeItems: undefined,
      usedIn: [...new Set(r.recipeItems.map((x) => x.menuItem.name))],
      low: r.stockQty <= r.lowStockThreshold,
      nextExpiry: r.batches[0]?.expiryDate ?? null,
      hasExpired: r.batches.some((b) => b.expiryDate && new Date(b.expiryDate) < today),
    }));

    if (req.query.page) {
      const q = typeof req.query.q === "string" ? req.query.q.toLowerCase() : "";
      const filter = typeof req.query.filter === "string" ? req.query.filter : "ALL";
      let filtered = all;
      if (filter === "LOW") filtered = filtered.filter((r) => r.low);
      if (filter === "EXPIRING") filtered = filtered.filter((r) => r.nextExpiry || r.hasExpired);
      if (filter === "UNTRACKED") filtered = filtered.filter((r) => !r.nextExpiry);
      if (q) filtered = filtered.filter((r) => r.name.toLowerCase().includes(q));

      const page = Math.max(1, Number(req.query.page) || 1);
      const pageSize = Math.min(200, Math.max(1, Number(req.query.pageSize) || 25));
      const rowsPage = filtered.slice((page - 1) * pageSize, page * pageSize);
      return res.json({
        rows: rowsPage,
        total: filtered.length,
        page,
        pageSize,
        counts: {
          total: all.length,
          low: all.filter((r) => r.low).length,
          expiryTracked: all.filter((r) => r.nextExpiry || r.hasExpired).length,
          untracked: all.filter((r) => !r.nextExpiry).length,
        },
      });
    }

    res.json(all);
  })
);

/**
 * Remove an ingredient — Manager only. Blocked while any menu recipe uses it
 * (deleting would silently corrupt recipes); remove it from those recipes first.
 */
router.delete(
  "/ingredients/:id",
  requireRole("MANAGER"),
  asyncHandler(async (req, res) => {
    const ingredient = await prisma.ingredient.findUnique({
      where: { id: req.params.id },
      include: { recipeItems: { select: { menuItem: { select: { name: true } } } } },
    });
    if (!ingredient) throw new ApiError(404, "Ingredient not found");
    const usedIn = [...new Set(ingredient.recipeItems.map((x) => x.menuItem.name))];
    if (usedIn.length > 0)
      throw new ApiError(400, `Cannot remove — used in ${usedIn.length} recipe(s): ${usedIn.slice(0, 5).join(", ")}${usedIn.length > 5 ? "…" : ""}. Edit those menu items first.`);
    await prisma.ingredient.delete({ where: { id: ingredient.id } }); // batches cascade
    audit(req.user!.id, "INGREDIENT_DELETE", "Ingredient", ingredient.id, { name: ingredient.name, stockAtDeletion: ingredient.stockQty });
    res.json({ ok: true });
  })
);

/** Expiry board: batches expired or expiring within the warn window (Setting). */
router.get(
  "/ingredients/expiry",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (_req, res) => {
    const warnDays = await getNum("inventory.expiry_warn_days", 3);
    const cutoff = new Date();
    cutoff.setHours(0, 0, 0, 0);
    cutoff.setDate(cutoff.getDate() + warnDays);
    const batches = await prisma.ingredientBatch.findMany({
      where: { qty: { gt: 0 }, expiryDate: { not: null, lte: cutoff } },
      include: { ingredient: { select: { name: true, unit: true } } },
      orderBy: { expiryDate: "asc" },
    });
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    res.json(
      batches.map((b) => ({
        ...b,
        daysLeft: Math.ceil((+new Date(b.expiryDate!) - +today) / 86400000),
        expired: new Date(b.expiryDate!) < today,
      }))
    );
  })
);

/** Write off an expired/spoiled batch — deducts stock, mandatory reason, audited. */
router.post(
  "/ingredients/batches/:batchId/write-off",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const { reason } = z.object({ reason: z.string().min(1, "Write-off reason required") }).parse(req.body);
    const batch = await prisma.ingredientBatch.findUnique({ where: { id: req.params.batchId }, include: { ingredient: true } });
    if (!batch) throw new ApiError(404, "Batch not found");
    if (batch.qty <= 0) throw new ApiError(400, "Batch already empty");
    await prisma.$transaction([
      prisma.ingredient.update({ where: { id: batch.ingredientId }, data: { stockQty: { decrement: Math.min(batch.qty, batch.ingredient.stockQty) } } }),
      prisma.ingredientBatch.update({ where: { id: batch.id }, data: { qty: 0, note: `${batch.note ?? ""} [written off: ${reason}]`.trim() } }),
    ]);
    audit(req.user!.id, "STOCK_WRITE_OFF", "IngredientBatch", batch.id, { ingredient: batch.ingredient.name, qty: batch.qty, reason });
    res.json({ ok: true, writtenOff: batch.qty, unit: batch.ingredient.unit });
  })
);

const ingredientBody = z.object({
  name: z.string().min(1),
  unit: z.string().min(1),
  stockQty: z.number().min(0),
  lowStockThreshold: z.number().min(0),
});

router.post(
  "/ingredients",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    res.status(201).json(await prisma.ingredient.create({ data: ingredientBody.parse(req.body) }));
  })
);

router.put(
  "/ingredients/:id",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    res.json(await prisma.ingredient.update({ where: { id: req.params.id }, data: ingredientBody.partial().parse(req.body) }));
  })
);

/** Stock receive/adjust with audit trail. Positive receives create an expiry-tracked batch. */
router.post(
  "/ingredients/:id/adjust",
  requireRole("MANAGER", "CHEF"),
  asyncHandler(async (req, res) => {
    const { delta, reason, expiryDate } = z
      .object({ delta: z.number(), reason: z.string().min(1), expiryDate: z.string().optional() })
      .parse(req.body);
    const ingredient = await prisma.ingredient.findUnique({ where: { id: req.params.id } });
    if (!ingredient) throw new ApiError(404, "Ingredient not found");
    if (ingredient.stockQty + delta < 0) throw new ApiError(400, "Stock cannot go negative");
    const updated = await prisma.$transaction(async (tx) => {
      const u = await tx.ingredient.update({ where: { id: ingredient.id }, data: { stockQty: { increment: delta } } });
      if (delta > 0) {
        await tx.ingredientBatch.create({
          data: { ingredientId: ingredient.id, qty: delta, initialQty: delta, expiryDate: expiryDate ? new Date(expiryDate) : null, note: reason },
        });
      } else if (delta < 0) {
        // Manual write-down drains batches FEFO too
        let remaining = -delta;
        const batches = await tx.ingredientBatch.findMany({
          where: { ingredientId: ingredient.id, qty: { gt: 0 } },
          orderBy: [{ expiryDate: "asc" }, { receivedAt: "asc" }],
        });
        for (const b of batches) {
          if (remaining <= 0) break;
          const take = Math.min(b.qty, remaining);
          await tx.ingredientBatch.update({ where: { id: b.id }, data: { qty: b.qty - take } });
          remaining -= take;
        }
      }
      return u;
    });
    audit(req.user!.id, "STOCK_ADJUST", "Ingredient", ingredient.id, { delta, reason, expiryDate: expiryDate ?? null });
    res.json(updated);
  })
);

export default router;
