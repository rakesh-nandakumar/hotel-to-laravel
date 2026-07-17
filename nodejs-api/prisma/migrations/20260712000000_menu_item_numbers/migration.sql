-- Menu item numbers (printed menu no. / quick POS entry)
ALTER TABLE "MenuItem" ADD COLUMN IF NOT EXISTS "itemNo" INTEGER;

-- Backfill: number existing items by category order, then name
WITH numbered AS (
  SELECT mi."id", ROW_NUMBER() OVER (ORDER BY mc."sortOrder", mi."name") AS rn
  FROM "MenuItem" mi
  JOIN "MenuCategory" mc ON mc."id" = mi."categoryId"
)
UPDATE "MenuItem" m SET "itemNo" = n.rn
FROM numbered n
WHERE m."id" = n."id" AND m."itemNo" IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "MenuItem_itemNo_key" ON "MenuItem"("itemNo");
