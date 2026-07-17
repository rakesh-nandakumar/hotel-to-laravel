-- New highest-privilege role for technical/integration management
ALTER TYPE "Role" ADD VALUE IF NOT EXISTS 'SYSTEM_ADMIN';

-- SMS notification channel
ALTER TYPE "NotificationChannel" ADD VALUE IF NOT EXISTS 'SMS';
