-- Laundry service
ALTER TYPE "LineSource" ADD VALUE IF NOT EXISTS 'LAUNDRY';

CREATE TABLE IF NOT EXISTS "LaundryItem" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "price" INTEGER NOT NULL,
    "active" BOOLEAN NOT NULL DEFAULT true,
    CONSTRAINT "LaundryItem_pkey" PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX IF NOT EXISTS "LaundryItem_name_key" ON "LaundryItem"("name");
