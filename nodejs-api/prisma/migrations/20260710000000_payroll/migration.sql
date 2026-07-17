-- Payroll module
DO $$ BEGIN
  CREATE TYPE "PayrollStatus" AS ENUM ('DRAFT', 'FINALIZED');
EXCEPTION WHEN duplicate_object THEN null; END $$;

ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "baseSalary" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "otHourlyRate" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "monthlyAllowance" INTEGER NOT NULL DEFAULT 0;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "epfEnabled" BOOLEAN NOT NULL DEFAULT true;
ALTER TABLE "User" ADD COLUMN IF NOT EXISTS "epfNumber" TEXT;

CREATE TABLE IF NOT EXISTS "PayrollRun" (
    "id" TEXT NOT NULL,
    "month" TEXT NOT NULL,
    "status" "PayrollStatus" NOT NULL DEFAULT 'DRAFT',
    "runById" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "finalizedAt" TIMESTAMP(3),
    CONSTRAINT "PayrollRun_pkey" PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "PayrollRun_month_key" ON "PayrollRun"("month");

CREATE TABLE IF NOT EXISTS "PayrollLine" (
    "id" TEXT NOT NULL,
    "runId" TEXT NOT NULL,
    "userId" TEXT NOT NULL,
    "baseSalary" INTEGER NOT NULL,
    "workedHours" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "otHours" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "otPay" INTEGER NOT NULL DEFAULT 0,
    "allowance" INTEGER NOT NULL DEFAULT 0,
    "bonus" INTEGER NOT NULL DEFAULT 0,
    "deduction" INTEGER NOT NULL DEFAULT 0,
    "deductionNote" TEXT,
    "gross" INTEGER NOT NULL DEFAULT 0,
    "epfEmployee" INTEGER NOT NULL DEFAULT 0,
    "epfEmployer" INTEGER NOT NULL DEFAULT 0,
    "etf" INTEGER NOT NULL DEFAULT 0,
    "netPay" INTEGER NOT NULL DEFAULT 0,
    "paid" BOOLEAN NOT NULL DEFAULT false,
    "paidAt" TIMESTAMP(3),
    CONSTRAINT "PayrollLine_pkey" PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "PayrollLine_runId_userId_key" ON "PayrollLine"("runId", "userId");

DO $$ BEGIN
  ALTER TABLE "PayrollRun" ADD CONSTRAINT "PayrollRun_runById_fkey" FOREIGN KEY ("runById") REFERENCES "User"("id") ON DELETE RESTRICT ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN null; END $$;
DO $$ BEGIN
  ALTER TABLE "PayrollLine" ADD CONSTRAINT "PayrollLine_runId_fkey" FOREIGN KEY ("runId") REFERENCES "PayrollRun"("id") ON DELETE CASCADE ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN null; END $$;
DO $$ BEGIN
  ALTER TABLE "PayrollLine" ADD CONSTRAINT "PayrollLine_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User"("id") ON DELETE RESTRICT ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN null; END $$;
