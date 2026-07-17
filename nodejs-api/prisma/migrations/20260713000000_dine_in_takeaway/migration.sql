-- Dine-in vs takeaway: takeaway is exempt from service charge (VAT still applies)
DO $$ BEGIN
  CREATE TYPE "DiningMode" AS ENUM ('DINE_IN', 'TAKEAWAY');
EXCEPTION WHEN duplicate_object THEN null; END $$;

ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS "diningMode" "DiningMode" NOT NULL DEFAULT 'DINE_IN';
