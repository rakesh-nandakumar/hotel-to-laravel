-- Audit log request context: IP address, user agent, route
ALTER TABLE "AuditLog" ADD COLUMN IF NOT EXISTS "ipAddress" TEXT;
ALTER TABLE "AuditLog" ADD COLUMN IF NOT EXISTS "userAgent" TEXT;
ALTER TABLE "AuditLog" ADD COLUMN IF NOT EXISTS "route" TEXT;
