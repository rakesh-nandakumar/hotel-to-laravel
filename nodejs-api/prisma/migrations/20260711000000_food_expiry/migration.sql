-- Food expiry handling: ingredient batches with expiry dates
CREATE TABLE IF NOT EXISTS "IngredientBatch" (
    "id" TEXT NOT NULL,
    "ingredientId" TEXT NOT NULL,
    "qty" DOUBLE PRECISION NOT NULL,
    "initialQty" DOUBLE PRECISION NOT NULL,
    "expiryDate" DATE,
    "receivedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "note" TEXT,
    CONSTRAINT "IngredientBatch_pkey" PRIMARY KEY ("id")
);

CREATE INDEX IF NOT EXISTS "IngredientBatch_ingredientId_expiryDate_idx" ON "IngredientBatch"("ingredientId", "expiryDate");

DO $$ BEGIN
  ALTER TABLE "IngredientBatch" ADD CONSTRAINT "IngredientBatch_ingredientId_fkey" FOREIGN KEY ("ingredientId") REFERENCES "Ingredient"("id") ON DELETE CASCADE ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN null; END $$;
